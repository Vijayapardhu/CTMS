<?php

namespace App\Enums;

/**
 * What happened. Each type belongs to exactly one {@see IncidentClass}, which
 * is what determines how the system responds.
 */
enum IncidentType: string
{
    // ---- Class A — life safety ------------------------------------------
    case SOS = 'SOS';
    case ACCIDENT = 'ACCIDENT';
    case MEDICAL = 'MEDICAL';
    case SECURITY = 'SECURITY';

    // ---- Class B — operational ------------------------------------------
    case BREAKDOWN = 'BREAKDOWN';
    case FLAT_TYRE = 'FLAT_TYRE';
    case ENGINE_FAULT = 'ENGINE_FAULT';
    case BRAKE_FAULT = 'BRAKE_FAULT';
    case FUEL = 'FUEL';

    // ---- Class C — service ----------------------------------------------
    case DIVERSION = 'DIVERSION';
    case CONGESTION = 'CONGESTION';
    case WEATHER = 'WEATHER';
    case PASSENGER_CONDUCT = 'PASSENGER_CONDUCT';

    /**
     * Raised by the system, not by a person (BR-259). It has its own type so
     * that a bus which has gone silent never hides behind a driver's report of
     * traffic, and so operations reads what actually happened.
     */
    case TRACKING_LOST = 'TRACKING_LOST';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function class(): IncidentClass
    {
        return match ($this) {
            self::SOS, self::ACCIDENT, self::MEDICAL, self::SECURITY => IncidentClass::LIFE_SAFETY,
            self::BREAKDOWN, self::FLAT_TYRE, self::ENGINE_FAULT,
            self::BRAKE_FAULT, self::FUEL => IncidentClass::OPERATIONAL,
            self::DIVERSION, self::CONGESTION, self::WEATHER,
            self::PASSENGER_CONDUCT, self::TRACKING_LOST => IncidentClass::SERVICE,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SOS => 'Emergency (SOS)',
            self::ACCIDENT => 'Accident',
            self::MEDICAL => 'Medical emergency',
            self::SECURITY => 'Security threat',
            self::BREAKDOWN => 'Breakdown',
            self::FLAT_TYRE => 'Flat tyre',
            self::ENGINE_FAULT => 'Engine fault',
            self::BRAKE_FAULT => 'Brake fault',
            self::FUEL => 'Fuel problem',
            self::DIVERSION => 'Route diversion',
            self::CONGESTION => 'Traffic congestion',
            self::WEATHER => 'Weather',
            self::PASSENGER_CONDUCT => 'Passenger conduct',
            self::TRACKING_LOST => 'Tracking lost',
        };
    }

    /**
     * Whether a person may raise this type. Tracking loss is inferred from the
     * absence of data, so nobody can report it and nobody can fake it.
     */
    public function isReportableByPerson(): bool
    {
        return $this !== self::TRACKING_LOST;
    }

    /**
     * @return array<int, self>
     */
    public static function reportableCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type) => $type->isReportableByPerson(),
        ));
    }

    /**
     * The severity a report of this type starts at. An operator may raise it;
     * lowering a life-safety incident requires a resolution, not a reclassify.
     */
    public function defaultSeverity(): IncidentSeverity
    {
        return match ($this->class()) {
            IncidentClass::LIFE_SAFETY => IncidentSeverity::CRITICAL,
            IncidentClass::OPERATIONAL => IncidentSeverity::HIGH,
            IncidentClass::SERVICE => IncidentSeverity::LOW,
        };
    }

    /**
     * Whether a photograph is required to submit this report.
     *
     * Never for life-safety: demanding a photograph from someone in an
     * emergency is indefensible.
     */
    public function requiresPhoto(): bool
    {
        return $this->class() === IncidentClass::OPERATIONAL;
    }

    /**
     * Whether this type opens a maintenance ticket (BR-350).
     *
     * Only where the vehicle itself is implicated. A student fainting or a
     * threat on board is an emergency, but it is not a fault — sending the bus
     * to the workshop over it grounds a serviceable vehicle and buries the
     * workshop in tickets it cannot act on.
     */
    public function opensMaintenanceTicket(): bool
    {
        return $this->class() === IncidentClass::OPERATIONAL
            || $this === self::ACCIDENT;
    }
}
