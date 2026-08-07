<?php

namespace App\Http\Controllers\Api;

use App\Enums\EvidenceCategory;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Evidence\StoreEvidenceRequest;
use App\Models\EvidenceFile;
use App\Services\Evidence\EvidenceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Evidence uploads and authorised retrieval (BR-367).
 *
 * Upload returns an id. It does not return a URL, because a URL is a thing
 * that gets pasted into a chat message and then works for whoever receives it.
 */
class EvidenceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EvidenceService $evidence) {}

    /**
     * POST /api/v1/evidence
     */
    public function store(StoreEvidenceRequest $request): JsonResponse
    {
        $this->authorize('create', EvidenceFile::class);

        $evidence = $this->evidence->store(
            $request->file('file'),
            EvidenceCategory::from(strtoupper($request->validated()['category'])),
            $request->user(),
        );

        return $this->created([
            'id' => (string) $evidence->getKey(),
            'category' => $evidence->category->value,
            'original_name' => $evidence->original_name,
            'mime_type' => $evidence->mime_type,
            'size_bytes' => $evidence->size_bytes,
            'checksum' => $evidence->checksum,
            // Deliberately no URL and no path. The client holds an id and
            // fetches through the endpoint below, which checks who is asking.
            'download_path' => "/api/v1/evidence/{$evidence->getKey()}",
        ], 'File uploaded. Attach it to a report to keep it.');
    }

    /**
     * GET /api/v1/evidence/{id}
     *
     * Streams the bytes to whoever is entitled to them.
     */
    public function show(Request $request, string $id): Response
    {
        $evidence = EvidenceFile::with('attachable')->find($id);

        if (! $evidence) {
            throw new ResourceNotFoundException('File not found.');
        }

        $this->authorize('view', $evidence);

        if (! $this->evidence->exists($evidence)) {
            // The row says there is a photograph and the disk disagrees. That
            // is a data-integrity problem, not a missing page, and it should
            // read as one in the logs.
            throw new ResourceNotFoundException(
                'The stored file is missing. Report this to the transport office.',
            );
        }

        return response($this->evidence->contents($evidence), 200, [
            'Content-Type' => $evidence->mime_type,
            // `attachment`, never `inline`: an image rendered in the browser
            // from a private store is one redirect away from being embedded
            // somewhere it should not be.
            'Content-Disposition' => 'attachment; filename="'.$evidence->downloadName().'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * GET /api/v1/evidence/categories
     *
     * What the client may upload, and the limits — so a driver on a bad
     * connection is told before the upload, not after it fails.
     */
    public function categories(): JsonResponse
    {
        $this->authorize('create', EvidenceFile::class);

        return $this->success(
            array_map(fn (EvidenceCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
                'allowed_mime_types' => $category->allowedMimeTypes(),
                'allowed_extensions' => $category->allowedExtensions(),
                'max_kilobytes' => $category->maxKilobytes(),
            ], EvidenceCategory::cases()),
            'Evidence categories retrieved successfully.',
        );
    }
}
