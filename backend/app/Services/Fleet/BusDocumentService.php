<?php

namespace App\Services\Fleet;

use App\Enums\DocumentType;
use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\BusDocument;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statutory vehicle documents (BR-055).
 */
class BusDocumentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record a document, superseding any current one of the same type.
     *
     * Renewal never overwrites: an investigation months later must be able to
     * establish what cover was in force on a given day, so the previous
     * certificate is retained and linked.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(Bus $bus, array $data, User $actor): BusDocument
    {
        return DB::transaction(function () use ($bus, $data, $actor) {
            $type = DocumentType::from(strtoupper((string) $data['document_type']));

            $document = new BusDocument($data);
            $document->bus_id = $bus->getKey();
            $document->recorded_by_id = $actor->getKey();
            $document->save();

            // Point every previously-current document of this type at the new
            // one. Normally there is exactly one; the loop tolerates a history
            // that predates this rule.
            $superseded = BusDocument::where('bus_id', $bus->getKey())
                ->where('document_type', $type->value)
                ->whereKeyNot($document->getKey())
                ->whereNull('superseded_by_id')
                ->get();

            foreach ($superseded as $previous) {
                $previous->forceFill(['superseded_by_id' => $document->getKey()])->save();
            }

            $this->audit->log(
                action: 'DOCUMENT_RECORDED',
                table: $document->getTable(),
                recordId: (string) $document->getKey(),
                new: [
                    'bus_id' => (string) $bus->getKey(),
                    'document_type' => $type->value,
                    'expires_on' => $document->expires_on->toDateString(),
                    'superseded_count' => $superseded->count(),
                ],
                actor: $actor,
            );

            return $document;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BusDocument $document, array $data, User $actor): BusDocument
    {
        return DB::transaction(function () use ($document, $data, $actor) {
            $before = $document->getAttributes();

            $document->fill($data);
            $document->save();

            $this->audit->updated($document, $before, $actor);

            return $document;
        });
    }

    /**
     * Remove a document record.
     *
     * @throws BusinessRuleException
     */
    public function delete(BusDocument $document, User $actor): void
    {
        DB::transaction(function () use ($document, $actor) {
            // Deleting a superseded record would break the history the
            // supersession chain exists to preserve.
            if ($document->superseded_by_id !== null) {
                throw new BusinessRuleException(
                    'A superseded document is part of the vehicle history and cannot be removed.',
                );
            }

            $document->delete();

            $this->audit->deleted($document, $actor);
        });
    }

    /**
     * Documents across the fleet lapsing within the given window, for the
     * compliance board (AD-34) and the expiry scan (BG-14).
     *
     * @return Collection<int, BusDocument>
     */
    public function expiringWithin(int $days)
    {
        return BusDocument::with('bus')
            ->current()
            ->expiringWithin($days)
            ->orderBy('expires_on')
            ->get();
    }
}
