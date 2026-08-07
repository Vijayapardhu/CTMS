<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentType;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bus\StoreBusDocumentRequest;
use App\Http\Requests\Bus\UpdateBusDocumentRequest;
use App\Models\Bus;
use App\Models\BusDocument;
use App\Services\Fleet\BusDocumentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statutory vehicle documents (FR-02, AD-17).
 */
class BusDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BusDocumentService $documents) {}

    /**
     * GET /api/v1/buses/{id}/documents
     */
    public function index(Request $request, string $busId): JsonResponse
    {
        $bus = $this->findBus($busId);

        $this->authorize('view', $bus);

        $query = $bus->documents()->with('recordedBy')->latest('expires_on');

        // Superseded certificates are history and are hidden unless asked for.
        if (! $request->boolean('include_history')) {
            $query->current();
        }

        $documents = $query->get();

        return $this->success([
            'documents' => $documents,
            'compliance' => [
                'is_compliant' => $bus->hasValidDocuments(),
                'missing_or_expired' => array_map(
                    fn (DocumentType $type) => ['type' => $type->value, 'label' => $type->label()],
                    $bus->missingOrExpiredDocuments(),
                ),
            ],
        ], 'Bus documents retrieved successfully.');
    }

    /**
     * POST /api/v1/buses/{id}/documents
     */
    public function store(StoreBusDocumentRequest $request, string $busId): JsonResponse
    {
        $bus = $this->findBus($busId);

        $this->authorize('manageDocuments', $bus);

        $document = $this->documents->record($bus, $request->validated(), $request->user());

        return $this->created($document, 'Document recorded successfully.');
    }

    /**
     * PUT /api/v1/buses/{busId}/documents/{documentId}
     */
    public function update(UpdateBusDocumentRequest $request, string $busId, string $documentId): JsonResponse
    {
        $bus = $this->findBus($busId);

        $this->authorize('manageDocuments', $bus);

        $document = $this->findDocumentOnBus($bus, $documentId);

        $document = $this->documents->update($document, $request->validated(), $request->user());

        return $this->success($document, 'Document updated successfully.');
    }

    /**
     * DELETE /api/v1/buses/{busId}/documents/{documentId}
     */
    public function destroy(Request $request, string $busId, string $documentId): JsonResponse
    {
        $bus = $this->findBus($busId);

        $this->authorize('manageDocuments', $bus);

        $document = $this->findDocumentOnBus($bus, $documentId);

        $this->documents->delete($document, $request->user());

        return $this->success(null, 'Document removed successfully.');
    }

    /**
     * GET /api/v1/fleet/documents/expiring
     *
     * The compliance board (AD-34).
     */
    public function expiring(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bus::class);

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        return $this->success(
            $this->documents->expiringWithin($validated['days'] ?? 30),
            'Expiring documents retrieved successfully.',
        );
    }

    private function findBus(string $id): Bus
    {
        $bus = Bus::find($id);

        if (! $bus) {
            throw new ResourceNotFoundException('Bus not found.');
        }

        return $bus;
    }

    /**
     * Pairing another bus's document id with this bus must not reach it.
     */
    private function findDocumentOnBus(Bus $bus, string $documentId): BusDocument
    {
        $document = BusDocument::where('id', $documentId)
            ->where('bus_id', $bus->getKey())
            ->first();

        if (! $document) {
            throw new ResourceNotFoundException('Document not found for this bus.');
        }

        return $document;
    }
}
