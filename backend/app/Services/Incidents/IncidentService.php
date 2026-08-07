<?php

namespace App\Services\Incidents;

use App\Enums\BusStatus;
use App\Enums\DriverStatus;
use App\Enums\EvidenceCategory;
use App\Enums\IncidentClass;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Events\Incidents\IncidentEscalated;
use App\Events\Incidents\IncidentReported;
use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\IncidentNote;
use App\Models\MaintenanceTicket;
use App\Models\Trip;
use App\Models\User;
use App\Models\VehicleIncident;
use App\Services\AuditLogger;
use App\Services\Evidence\EvidenceService;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Incident reporting and triage (FR-11).
 *
 * The response to a report is determined by its class, not by whoever wrote
 * the handler: a life-safety incident escalates in two minutes and reaches a
 * human on every channel; an operational one opens a ticket and takes the bus
 * off the road; a service one informs and updates estimates. Encoding that in
 * {@see IncidentClass} rather than in branches here means a new
 * incident type inherits the right behaviour by construction.
 */
class IncidentService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReplacementService $replacements,
        private readonly MaintenanceService $maintenance,
        private readonly EvidenceService $evidence,
    ) {}

    /**
     * Report an incident.
     *
     * @param  array<string, mixed>  $data
     */
    public function report(array $data, User $actor, ?Trip $trip = null): VehicleIncident
    {
        $type = IncidentType::from(strtoupper((string) $data['incident_type']));
        $class = $type->class();

        $existing = $this->findByIdempotencyKey($data['idempotency_key'] ?? null);

        if ($existing !== null) {
            return $existing; // Offline replay from a driver's device.
        }

        $incident = DB::transaction(function () use ($data, $actor, $trip, $type, $class) {
            $bus = $trip?->bus ?? ($actor->driver?->assignedBus);

            $severity = isset($data['severity'])
                ? IncidentSeverity::from(strtoupper((string) $data['severity']))
                : $type->defaultSeverity();

            $incident = new VehicleIncident;

            $incident->forceFill([
                'trip_id' => $trip?->getKey(),
                'bus_id' => $bus?->getKey(),
                'driver_id' => $actor->driver?->getKey(),
                'incident_class' => $class,
                'incident_type' => $type,
                'severity' => $severity,
                'status' => IncidentStatus::REPORTED,
                'description' => $data['description'] ?? $type->label(),
                'latitude' => $data['latitude'] ?? $trip?->current_latitude,
                'longitude' => $data['longitude'] ?? $trip?->current_longitude,
                // Evidence is attached below, once the incident has an id.
                'passengers_aboard' => $trip?->occupied_seat_count ?? 0,
                'vehicle_can_continue' => (bool) ($data['vehicle_can_continue'] ?? false),
                'reported_by_id' => $actor->getKey(),
                // The driver's own timestamp is honoured when the report was
                // queued offline — otherwise every delayed SOS looks like it
                // happened at the moment the signal came back.
                'reported_at' => isset($data['reported_at'])
                    ? Carbon::parse($data['reported_at'])
                    : now(),
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            try {
                $incident->save();
            } catch (UniqueConstraintViolationException) {
                return $this->findByIdempotencyKey($data['idempotency_key']);
            }

            // BR-367 — bind the photograph to the report now that it has an
            // id. `claim()` refuses a file uploaded by somebody else, one of
            // the wrong category, or one already cited on another report — so
            // a single picture of a cracked windscreen cannot close two
            // incidents.
            $evidence = $this->evidence->claim(
                $data['evidence_id'] ?? null,
                EvidenceCategory::INCIDENT_PHOTO,
                $actor,
            );

            if ($evidence !== null) {
                $this->evidence->attach($evidence, $incident, $actor);
            }

            // BR-350 — every qualifying incident opens a ticket, without
            // anyone having to remember to raise one.
            if ($type->opensMaintenanceTicket() && $bus !== null) {
                $ticket = $this->openMaintenanceTicket($bus, $incident, $actor);
                $incident->forceFill(['maintenance_ticket_id' => $ticket->getKey()])->save();
            }

            // BR-351 — a life-safety or operational incident takes the vehicle
            // off the road immediately, unless the driver has said it can
            // continue and the class permits that judgement.
            if ($bus !== null && $class->takesBusOutOfService() && ! $incident->vehicle_can_continue) {
                $this->removeBusFromService($bus, $incident, $actor);
            }

            // BR-109 — a driver who has just been through a critical incident
            // is stood down pending review. Not a judgement about them: the
            // person who has just had an accident or watched a passenger
            // collapse is not in a state to take another bus out, and the
            // system should not let a short-staffed morning make that call.
            if ($severity === IncidentSeverity::CRITICAL) {
                $this->standDownReportingDriver($incident, $actor);
            }

            $this->audit->log(
                action: 'INCIDENT_REPORTED',
                table: $incident->getTable(),
                recordId: (string) $incident->getKey(),
                new: [
                    'class' => $class->value,
                    'type' => $type->value,
                    'severity' => $severity->value,
                    'trip_id' => (string) $trip?->getKey(),
                    'passengers_aboard' => $incident->passengers_aboard,
                ],
                actor: $actor,
            );

            return $incident;
        });

        $incident = $incident->fresh(['bus', 'trip.route', 'driver.user']);

        // BR-352 — a vehicle that cannot continue needs a replacement now,
        // not after someone reads the report.
        if ($class->triggersReplacementSearch() && $trip !== null && ! $incident->vehicle_can_continue) {
            $this->replacements->recommendFor($incident, $actor);
        }

        IncidentReported::dispatch($incident);

        return $incident->fresh(['bus', 'trip.route', 'replacement']);
    }

    /**
     * Acknowledge — "a human has seen this". Distinct from resolving it.
     *
     * @throws BusinessRuleException
     */
    public function acknowledge(VehicleIncident $incident, User $actor): VehicleIncident
    {
        return DB::transaction(function () use ($incident, $actor) {
            $incident = VehicleIncident::whereKey($incident->getKey())->lockForUpdate()->firstOrFail();

            if ($incident->isAcknowledged()) {
                return $incident; // Idempotent — two controllers may both click.
            }

            $this->assertCanTransition($incident, IncidentStatus::ACKNOWLEDGED);

            $incident->forceFill([
                'status' => IncidentStatus::ACKNOWLEDGED,
                'acknowledged_by_id' => $actor->getKey(),
                'acknowledged_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'INCIDENT_ACKNOWLEDGED',
                table: $incident->getTable(),
                recordId: (string) $incident->getKey(),
                new: [
                    'acknowledged_by' => (string) $actor->getKey(),
                    'seconds_to_acknowledge' => (int) $incident->reported_at->diffInSeconds(now()),
                ],
                actor: $actor,
            );

            return $incident;
        });
    }

    /**
     * BR-356 — nobody acknowledged in time.
     */
    public function escalate(VehicleIncident $incident): VehicleIncident
    {
        return DB::transaction(function () use ($incident) {
            $incident = VehicleIncident::whereKey($incident->getKey())->lockForUpdate()->firstOrFail();

            if (! $incident->status->canTransitionTo(IncidentStatus::ESCALATED)) {
                return $incident;
            }

            $incident->forceFill([
                'status' => IncidentStatus::ESCALATED,
                'escalated_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'INCIDENT_ESCALATED',
                table: $incident->getTable(),
                recordId: (string) $incident->getKey(),
                new: ['reason' => 'Not acknowledged within the tolerance for its class.'],
                actor: null,
            );

            IncidentEscalated::dispatch($incident->fresh(['bus', 'trip.route', 'driver.user']));

            return $incident;
        });
    }

    /**
     * Resolve, with an account of what happened.
     *
     * @throws BusinessRuleException
     */
    public function resolve(VehicleIncident $incident, string $notes, User $actor): VehicleIncident
    {
        return DB::transaction(function () use ($incident, $notes, $actor) {
            $incident = VehicleIncident::whereKey($incident->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($incident, IncidentStatus::RESOLVED);

            $incident->forceFill([
                'status' => IncidentStatus::RESOLVED,
                'resolved_by_id' => $actor->getKey(),
                'resolved_at' => now(),
                'resolution_notes' => $notes,
            ])->save();

            $this->audit->log(
                action: 'INCIDENT_RESOLVED',
                table: $incident->getTable(),
                recordId: (string) $incident->getKey(),
                new: ['resolution' => $notes],
                actor: $actor,
            );

            return $incident;
        });
    }

    /**
     * Close a resolved incident.
     *
     * @throws BusinessRuleException
     */
    public function close(VehicleIncident $incident, User $actor): VehicleIncident
    {
        return DB::transaction(function () use ($incident, $actor) {
            $incident = VehicleIncident::whereKey($incident->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCanTransition($incident, IncidentStatus::CLOSED);

            // BR-358's companion: a vehicle fault is not closed while its
            // maintenance ticket is still open, or a bus returns to service on
            // the strength of an administrative tidy-up.
            $ticket = $incident->maintenanceTicket;

            // Asked of the enum, not of a list of string literals. The literal
            // form silently stopped matching the moment `status` became a
            // cast enum, which made every vehicle incident permanently
            // uncloseable — and it listed a 'CLOSED' status that never existed.
            if ($ticket !== null && $ticket->isOpen()) {
                throw new BusinessRuleException(
                    'The maintenance ticket for this incident is still open.',
                    ['maintenance_ticket_id' => (string) $ticket->getKey()],
                );
            }

            $incident->forceFill(['status' => IncidentStatus::CLOSED])->save();

            $this->audit->log(
                action: 'INCIDENT_CLOSED',
                table: $incident->getTable(),
                recordId: (string) $incident->getKey(),
                actor: $actor,
            );

            return $incident;
        });
    }

    /**
     * BR-355 — a cancelled SOS is recorded, never erased.
     *
     * A false alarm is still a fact about what happened, and about how the
     * system behaved when it did.
     */
    public function cancel(VehicleIncident $incident, string $note, User $actor): VehicleIncident
    {
        return DB::transaction(function () use ($incident, $note, $actor) {
            $incident = VehicleIncident::whereKey($incident->getKey())->lockForUpdate()->firstOrFail();

            if ($incident->status === IncidentStatus::CLOSED) {
                throw new BusinessRuleException('This incident is already closed.');
            }

            $incident->forceFill([
                'was_cancelled' => true,
                'cancellation_note' => $note,
                'status' => IncidentStatus::RESOLVED,
                'resolved_by_id' => $actor->getKey(),
                'resolved_at' => now(),
                'resolution_notes' => 'Cancelled by the reporter: '.$note,
            ])->save();

            $this->audit->log(
                action: 'INCIDENT_CANCELLED',
                table: $incident->getTable(),
                recordId: (string) $incident->getKey(),
                new: ['note' => $note],
                actor: $actor,
            );

            return $incident;
        });
    }

    /**
     * BR-357 — follow-up is appended; the report itself never changes.
     */
    public function addNote(VehicleIncident $incident, string $note, User $actor): IncidentNote
    {
        $entry = new IncidentNote(['note' => $note]);

        $entry->forceFill([
            'vehicle_incident_id' => $incident->getKey(),
            'author_id' => $actor->getKey(),
        ])->save();

        return $entry;
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    private function findByIdempotencyKey(?string $key): ?VehicleIncident
    {
        return $key === null
            ? null
            : VehicleIncident::where('idempotency_key', $key)->first();
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCanTransition(VehicleIncident $incident, IncidentStatus $target): void
    {
        if (! $incident->status->canTransitionTo($target)) {
            throw new BusinessRuleException(
                "An incident cannot go from {$incident->status->value} to {$target->value}.",
                ['from' => $incident->status->value, 'to' => $target->value],
            );
        }
    }

    private function openMaintenanceTicket(Bus $bus, VehicleIncident $incident, User $actor): MaintenanceTicket
    {
        // One place decides what a ticket looks like and what "urgent" means.
        // This used to be duplicated here and in the inspection service, which
        // meant two definitions of the same rule drifting apart.
        return $this->maintenance->openForIncident($bus, $incident, $actor);
    }

    /**
     * BR-109 — take the reporting driver off the roster pending review.
     *
     * Their current trip is left alone deliberately. A driver mid-route with
     * passengers aboard has to finish getting them somewhere safe, or hand
     * over to a replacement; abruptly marking them unavailable would strand
     * the trip they are still driving. What this prevents is the *next*
     * assignment.
     */
    private function standDownReportingDriver(VehicleIncident $incident, User $actor): void
    {
        $driver = $incident->driver;

        if ($driver === null || $driver->status === DriverStatus::OFF_DUTY) {
            return;
        }

        $previous = $driver->status;

        $driver->forceFill(['status' => DriverStatus::OFF_DUTY])->save();

        $this->audit->log(
            action: 'DRIVER_STOOD_DOWN_PENDING_REVIEW',
            table: $driver->getTable(),
            recordId: (string) $driver->getKey(),
            old: ['status' => $previous->value],
            new: [
                'status' => DriverStatus::OFF_DUTY->value,
                'vehicle_incident_id' => (string) $incident->getKey(),
            ],
            actor: $actor,
        );
    }

    private function removeBusFromService(Bus $bus, VehicleIncident $incident, User $actor): void
    {
        $previous = $bus->status;
        // Every class that reaches this point takes the bus off the road for
        // the same reason and in the same way.
        $target = BusStatus::BREAKDOWN;

        if ($previous === $target) {
            return;
        }

        $bus->status = $target;
        $bus->save();

        $this->audit->log(
            action: 'BUS_STATUS_CHANGED',
            table: $bus->getTable(),
            recordId: (string) $bus->getKey(),
            old: ['status' => $previous->value],
            new: [
                'status' => $target->value,
                'reason' => "Incident reported: {$incident->incident_type->label()}",
            ],
            actor: $actor,
        );
    }
}
