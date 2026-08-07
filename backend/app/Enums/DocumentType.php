<?php

namespace App\Enums;

/**
 * Statutory documents a vehicle must hold to carry passengers (BR-055).
 *
 * Mandatory types are a legal bar: a bus whose fitness certificate or
 * insurance has lapsed may not be assigned, and there is no override. Operating
 * uninsured voids cover for every passenger aboard.
 */
enum DocumentType: string
{
    case FITNESS = 'FITNESS';
    case INSURANCE = 'INSURANCE';
    case POLLUTION = 'POLLUTION';
    case PERMIT = 'PERMIT';
    case ROAD_TAX = 'ROAD_TAX';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::FITNESS => 'Fitness certificate',
            self::INSURANCE => 'Insurance',
            self::POLLUTION => 'Pollution certificate',
            self::PERMIT => 'Transport permit',
            self::ROAD_TAX => 'Road tax',
        };
    }

    /**
     * Whether the absence or expiry of this document blocks the vehicle from
     * service. Non-mandatory types are tracked and warned about, but do not
     * stop a bus running.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::FITNESS, self::INSURANCE, self::PERMIT => true,
            self::POLLUTION, self::ROAD_TAX => false,
        };
    }

    /**
     * Types whose expiry takes a bus off the road.
     *
     * @return array<int, self>
     */
    public static function mandatory(): array
    {
        return array_values(array_filter(self::cases(), fn (self $type) => $type->isMandatory()));
    }
}
