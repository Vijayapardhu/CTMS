<?php

namespace App\Enums;

/**
 * The pre-trip vehicle inspection checklist (BR-107, BR-108).
 *
 * Safety-critical items block the trip outright when they fail. Non-critical
 * failures open a maintenance ticket but let the bus run — a dirty cabin is a
 * problem, not a hazard.
 */
enum InspectionItem: string
{
    case BRAKES = 'BRAKES';
    case TYRES = 'TYRES';
    case LIGHTS = 'LIGHTS';
    case STEERING = 'STEERING';
    case DOORS = 'DOORS';
    case EMERGENCY_EXIT = 'EMERGENCY_EXIT';
    case FIRE_EXTINGUISHER = 'FIRE_EXTINGUISHER';
    case FIRST_AID_KIT = 'FIRST_AID_KIT';
    case MIRRORS = 'MIRRORS';
    case HORN = 'HORN';
    case WIPERS = 'WIPERS';
    case FUEL_LEVEL = 'FUEL_LEVEL';
    case FLUID_LEVELS = 'FLUID_LEVELS';
    case CLEANLINESS = 'CLEANLINESS';

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
            self::BRAKES => 'Brakes',
            self::TYRES => 'Tyres and pressure',
            self::LIGHTS => 'Head, tail and indicator lights',
            self::STEERING => 'Steering',
            self::DOORS => 'Passenger doors',
            self::EMERGENCY_EXIT => 'Emergency exit',
            self::FIRE_EXTINGUISHER => 'Fire extinguisher',
            self::FIRST_AID_KIT => 'First-aid kit',
            self::MIRRORS => 'Mirrors',
            self::HORN => 'Horn',
            self::WIPERS => 'Windscreen wipers',
            self::FUEL_LEVEL => 'Fuel level',
            self::FLUID_LEVELS => 'Oil and coolant levels',
            self::CLEANLINESS => 'Cabin cleanliness',
        };
    }

    /**
     * A failure here stops the bus. These are the items that either cause a
     * crash or determine whether passengers survive one.
     */
    public function isSafetyCritical(): bool
    {
        return match ($this) {
            self::BRAKES,
            self::TYRES,
            self::LIGHTS,
            self::STEERING,
            self::DOORS,
            self::EMERGENCY_EXIT,
            self::FIRE_EXTINGUISHER,
            self::FIRST_AID_KIT => true,
            self::MIRRORS,
            self::HORN,
            self::WIPERS,
            self::FUEL_LEVEL,
            self::FLUID_LEVELS,
            self::CLEANLINESS => false,
        };
    }

    /**
     * Items whose failure blocks a trip.
     *
     * @return array<int, self>
     */
    public static function safetyCritical(): array
    {
        return array_values(array_filter(self::cases(), fn (self $item) => $item->isSafetyCritical()));
    }
}
