<?php

namespace App\Enums;

/**
 * What a stored file is evidence *of*.
 *
 * One pipeline for every attachment in the system rather than an upload
 * endpoint per feature: inspection photos, incident photos, workshop
 * attachments, licences and vehicle certificates all need the same MIME
 * checks, the same private storage and the same authorising retrieval, and
 * building the second one later is how two of them end up different.
 */
enum EvidenceCategory: string
{
    case INCIDENT_PHOTO = 'INCIDENT_PHOTO';
    case INSPECTION_PHOTO = 'INSPECTION_PHOTO';
    case MAINTENANCE_ATTACHMENT = 'MAINTENANCE_ATTACHMENT';
    case DRIVER_DOCUMENT = 'DRIVER_DOCUMENT';
    case VEHICLE_CERTIFICATE = 'VEHICLE_CERTIFICATE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * MIME types this category accepts.
     *
     * Photographic evidence is images only. A document category also takes
     * PDFs, because a licence or an insurance certificate arrives as one.
     * Deliberately an allow-list: a deny-list of dangerous types is a list
     * somebody forgets to update.
     *
     * @return array<int, string>
     */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::INCIDENT_PHOTO,
            self::INSPECTION_PHOTO => ['image/jpeg', 'image/png', 'image/heic', 'image/webp'],

            self::MAINTENANCE_ATTACHMENT,
            self::DRIVER_DOCUMENT,
            self::VEHICLE_CERTIFICATE => [
                'image/jpeg', 'image/png', 'image/heic', 'image/webp', 'application/pdf',
            ],
        };
    }

    /**
     * Extensions that match the allowed MIME types.
     *
     * Checked as well as the MIME type, not instead of it. A client controls
     * the declared type and the filename independently, so agreeing with
     * neither on its own is worth much.
     *
     * @return array<int, string>
     */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::INCIDENT_PHOTO,
            self::INSPECTION_PHOTO => ['jpg', 'jpeg', 'png', 'heic', 'webp'],

            self::MAINTENANCE_ATTACHMENT,
            self::DRIVER_DOCUMENT,
            self::VEHICLE_CERTIFICATE => ['jpg', 'jpeg', 'png', 'heic', 'webp', 'pdf'],
        };
    }

    /**
     * Largest accepted size in kilobytes.
     *
     * A phone photograph of a cracked windscreen is a few megabytes; anything
     * far past that is either a mistake or an attempt to fill the disk.
     */
    public function maxKilobytes(): int
    {
        return match ($this) {
            self::INCIDENT_PHOTO, self::INSPECTION_PHOTO => (int) config('ctms.evidence.max_photo_kb', 8192),
            default => (int) config('ctms.evidence.max_document_kb', 16384),
        };
    }

    /**
     * The directory files of this category are written to, inside the private
     * disk. Grouping keeps a retention sweep able to target one class.
     */
    public function directory(): string
    {
        return strtolower($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::INCIDENT_PHOTO => 'Incident photograph',
            self::INSPECTION_PHOTO => 'Inspection photograph',
            self::MAINTENANCE_ATTACHMENT => 'Maintenance attachment',
            self::DRIVER_DOCUMENT => 'Driver document',
            self::VEHICLE_CERTIFICATE => 'Vehicle certificate',
        };
    }
}
