<?php

namespace App\Services\Evidence;

use App\Enums\EvidenceCategory;
use App\Exceptions\BusinessRuleException;
use App\Models\EvidenceFile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The one upload pipeline (BR-367, CLAUDE.md §2).
 *
 * Every attachment in the system comes through here — inspection photographs,
 * incident photographs, workshop attachments, licences, vehicle certificates.
 * One pipeline rather than one per feature, because the second one built later
 * is always the one that forgets a check.
 *
 * Three things it will not do: trust the client's filename, trust the client's
 * declared MIME type, or hand back a path.
 */
class EvidenceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Validate and store an uploaded file.
     *
     * @throws BusinessRuleException
     */
    public function store(UploadedFile $file, EvidenceCategory $category, User $actor): EvidenceFile
    {
        $this->assertAcceptable($file, $category);

        // Read from the file itself, not from what the request declared.
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $checksum = hash_file('sha256', $file->getRealPath());

        // The name is generated. A client-supplied name reaches the filesystem
        // as a path, and "../../.env" is a filename.
        $extension = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid7()->toString().'.'.preg_replace('/[^a-z0-9]/', '', $extension);

        $disk = (string) config('ctms.evidence.disk', 'evidence');
        $path = $category->directory().'/'.now()->format('Y/m').'/'.$storedName;

        Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        return DB::transaction(function () use (
            $category, $disk, $path, $file, $mimeType, $checksum, $actor
        ) {
            $evidence = new EvidenceFile;

            $evidence->forceFill([
                'category' => $category,
                'disk' => $disk,
                'path' => $path,
                // Kept for display only. Never used to build a path.
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime_type' => $mimeType,
                'size_bytes' => $file->getSize(),
                'checksum' => $checksum,
                'uploaded_by_id' => $actor->getKey(),
            ])->save();

            $this->audit->log(
                action: 'EVIDENCE_UPLOADED',
                table: $evidence->getTable(),
                recordId: (string) $evidence->getKey(),
                new: [
                    'category' => $category->value,
                    'mime_type' => $mimeType,
                    'size_bytes' => $file->getSize(),
                    'checksum' => $checksum,
                ],
                actor: $actor,
            );

            return $evidence;
        });
    }

    /**
     * Bind a stored file to the record it is evidence for.
     *
     * @throws BusinessRuleException
     */
    public function attach(EvidenceFile $evidence, Model $subject, ?User $actor = null): EvidenceFile
    {
        return DB::transaction(function () use ($evidence, $subject, $actor) {
            $evidence = EvidenceFile::whereKey($evidence->getKey())->lockForUpdate()->firstOrFail();

            // Re-attaching would let one photograph stand as evidence for two
            // different incidents, which is how a single cracked windscreen
            // closes two reports.
            if ($evidence->isAttached()) {
                throw new BusinessRuleException(
                    'This file is already attached to another record.',
                );
            }

            $evidence->forceFill([
                'attachable_type' => $subject->getMorphClass(),
                'attachable_id' => $subject->getKey(),
                'attached_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'EVIDENCE_ATTACHED',
                table: $evidence->getTable(),
                recordId: (string) $evidence->getKey(),
                new: [
                    'attachable_type' => $subject->getMorphClass(),
                    'attachable_id' => (string) $subject->getKey(),
                ],
                actor: $actor,
            );

            return $evidence;
        });
    }

    /**
     * Resolve an id the client supplied, checking they may use it.
     *
     * @throws BusinessRuleException
     */
    public function claim(?string $evidenceId, EvidenceCategory $expected, User $actor): ?EvidenceFile
    {
        if ($evidenceId === null) {
            return null;
        }

        $evidence = EvidenceFile::find($evidenceId);

        if ($evidence === null) {
            throw new BusinessRuleException('The attached file could not be found.');
        }

        // Without this, one driver could cite another driver's photograph —
        // or an already-filed one — as evidence for their own report.
        if (! $actor->isAdmin() && (string) $evidence->uploaded_by_id !== (string) $actor->getKey()) {
            throw new BusinessRuleException('That file was uploaded by somebody else.');
        }

        if ($evidence->category !== $expected) {
            throw new BusinessRuleException(
                "That file was uploaded as a {$evidence->category->label()}, not a {$expected->label()}.",
            );
        }

        if ($evidence->isAttached()) {
            throw new BusinessRuleException('That file is already attached to another record.');
        }

        return $evidence;
    }

    /**
     * The bytes, for an authorised download.
     */
    public function contents(EvidenceFile $evidence): string
    {
        return (string) Storage::disk($evidence->disk)->get($evidence->path);
    }

    public function exists(EvidenceFile $evidence): bool
    {
        return Storage::disk($evidence->disk)->exists($evidence->path);
    }

    /**
     * Sweep files uploaded and never attached to anything.
     *
     * An attached file is evidence and is never touched. This only collects
     * the ones left behind by a report somebody started and abandoned.
     */
    public function purgeOrphans(): int
    {
        $cutoff = now()->subHours((int) config('ctms.retention.orphaned_evidence_hours', 48));
        $purged = 0;

        EvidenceFile::orphaned()
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($files) use (&$purged) {
                foreach ($files as $file) {
                    Storage::disk($file->disk)->delete($file->path);

                    // Hard delete, not soft. The bytes are gone, so a
                    // surviving row would point at a file that no longer
                    // exists — and nothing was ever attached to it, so there
                    // is no history to preserve. Attached evidence never
                    // reaches this method at all.
                    $file->forceDelete();
                    $purged++;
                }
            });

        return $purged;
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * @throws BusinessRuleException
     */
    private function assertAcceptable(UploadedFile $file, EvidenceCategory $category): void
    {
        if (! $file->isValid()) {
            throw new BusinessRuleException('The upload did not complete. Try again.');
        }

        $mimeType = $file->getMimeType();

        // The real type, sniffed from the bytes — not the Content-Type the
        // client sent, which it chooses freely.
        if (! in_array($mimeType, $category->allowedMimeTypes(), true)) {
            throw new BusinessRuleException(
                "A {$category->label()} must be one of: "
                .implode(', ', $category->allowedMimeTypes()).". This file is {$mimeType}.",
                ['mime_type' => $mimeType],
            );
        }

        $extension = strtolower($file->getClientOriginalExtension());

        // Checked as well, not instead. The type and the name are controlled
        // independently, so a file that lies in only one of them still fails.
        if (! in_array($extension, $category->allowedExtensions(), true)) {
            throw new BusinessRuleException(
                "A {$category->label()} must have one of these extensions: "
                .implode(', ', $category->allowedExtensions()).'.',
                ['extension' => $extension],
            );
        }

        $maxBytes = $category->maxKilobytes() * 1024;

        if ($file->getSize() > $maxBytes) {
            throw new BusinessRuleException(
                'That file is larger than the '.$category->maxKilobytes().' KB limit.',
                ['size_bytes' => $file->getSize(), 'max_bytes' => $maxBytes],
            );
        }
    }
}
