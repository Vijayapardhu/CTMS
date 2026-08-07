<?php

namespace App\Services\Maintenance;

use App\Enums\BusStatus;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Events\Maintenance\MaintenanceTicketOpened;
use App\Events\Maintenance\VehicleReturnedToService;
use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\MaintenanceTicket;
use App\Models\PreventiveMaintenanceSchedule;
use App\Models\User;
use App\Models\VehicleIncident;
use App\Models\VehicleInspection;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance (FR-14, BR-350, BR-358, BR-366).
 *
 * Every ticket in the system is opened through here. Previously the incident
 * service and the inspection service each built their own, which meant two
 * places deciding what "urgent" means and two places that could forget to
 * write an audit row.
 */
class MaintenanceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * BR-350 — open a ticket against a bus.
     *
     * @param  array<string, mixed>  $data
     */
    public function open(Bus $bus, array $data, ?User $actor = null): MaintenanceTicket
    {
        return DB::transaction(function () use ($bus, $data, $actor) {
            $priority = $data['priority'] ?? MaintenancePriority::MEDIUM;

            if (is_string($priority)) {
                $priority = MaintenancePriority::from(strtoupper($priority));
            }

            $ticket = new MaintenanceTicket;

            $ticket->forceFill([
                'bus_id' => $bus->getKey(),
                'vehicle_incident_id' => $data['vehicle_incident_id'] ?? null,
                'vehicle_inspection_id' => $data['vehicle_inspection_id'] ?? null,
                'issue_description' => $data['issue_description'],
                'status' => MaintenanceStatus::OPEN,
                'priority' => $priority,
                'estimated_cost' => $data['estimated_cost'] ?? null,
                'scheduled_date' => $data['scheduled_date'] ?? null,
                'opened_by_id' => $actor?->getKey(),
            ])->save();

            $this->audit->log(
                action: 'MAINTENANCE_TICKET_OPENED',
                table: $ticket->getTable(),
                recordId: (string) $ticket->getKey(),
                new: [
                    'bus_id' => (string) $bus->getKey(),
                    'priority' => $priority->value,
                    'issue' => $data['issue_description'],
                ],
                actor: $actor,
            );

            MaintenanceTicketOpened::dispatch($ticket->fresh(['bus']));

            return $ticket;
        });
    }

    /**
     * Convenience for the incident path, so the wording and the priority
     * mapping live in one place.
     */
    public function openForIncident(Bus $bus, VehicleIncident $incident, ?User $actor = null): MaintenanceTicket
    {
        return $this->open($bus, [
            'vehicle_incident_id' => $incident->getKey(),
            'issue_description' => sprintf(
                '%s reported %s: %s',
                $incident->reported_at->toDateTimeString(),
                $incident->incident_type->label(),
                $incident->description,
            ),
            'priority' => $incident->severity->requiresImmediateReplacement()
                ? MaintenancePriority::URGENT
                : MaintenancePriority::MEDIUM,
        ], $actor);
    }

    /**
     * Convenience for the pre-trip inspection path.
     */
    public function openForInspection(Bus $bus, VehicleInspection $inspection, ?User $actor = null): MaintenanceTicket
    {
        $failed = $inspection->failedItems();

        $description = 'Pre-trip inspection failure on '.now()->toDateString().': '
            .$failed->map(function ($item) {
                $note = $item->notes ? " ({$item->notes})" : '';

                return $item->item->label().$note;
            })->implode('; ');

        return $this->open($bus, [
            'vehicle_inspection_id' => $inspection->getKey(),
            'issue_description' => $description,
            'priority' => $inspection->failedSafetyCriticalItems()->isNotEmpty()
                ? MaintenancePriority::URGENT
                : MaintenancePriority::MEDIUM,
        ], $actor);
    }

    /**
     * @throws BusinessRuleException
     */
    public function assign(MaintenanceTicket $ticket, User $assignee, User $actor): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $assignee, $actor) {
            $ticket = $this->lock($ticket);

            if (! $ticket->isOpen()) {
                throw new BusinessRuleException(
                    "This ticket is {$ticket->status->value} and can no longer be assigned.",
                );
            }

            $ticket->forceFill(['assigned_to_id' => $assignee->getKey()])->save();

            $this->audit->log(
                action: 'MAINTENANCE_TICKET_ASSIGNED',
                table: $ticket->getTable(),
                recordId: (string) $ticket->getKey(),
                new: ['assigned_to_id' => (string) $assignee->getKey()],
                actor: $actor,
            );

            return $ticket;
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function schedule(MaintenanceTicket $ticket, \DateTimeInterface $when, User $actor): MaintenanceTicket
    {
        return $this->transition($ticket, MaintenanceStatus::SCHEDULED, $actor, [
            'scheduled_date' => $when,
        ]);
    }

    /**
     * @throws BusinessRuleException
     */
    public function start(MaintenanceTicket $ticket, User $actor): MaintenanceTicket
    {
        return $this->transition($ticket, MaintenanceStatus::IN_PROGRESS, $actor, [
            'started_at' => now(),
        ]);
    }

    /**
     * BR-358 — completing the last grounding ticket is what lets a bus back
     * onto the road, and only an authorised role may do it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws BusinessRuleException
     */
    public function complete(MaintenanceTicket $ticket, array $data, User $actor): MaintenanceTicket
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $ticket = $this->lock($ticket);

            $this->assertCanTransition($ticket, MaintenanceStatus::COMPLETED);

            // BR-061 — the odometer only ever goes up. Delegated to the model
            // so the workshop, the pre-trip check and the trip close share one
            // definition of "backwards" — and, just as importantly, so a
            // workshop reading actually moves the fleet's running total. This
            // service used to validate with its own copy of the rule and then
            // never write the result, so a service recorded at 62,000km left
            // the bus still showing whatever the last inspection said.
            if (isset($data['odometer_reading']) && $ticket->bus !== null) {
                $ticket->bus->recordOdometer((int) $data['odometer_reading']);
            }

            $ticket->forceFill([
                'status' => MaintenanceStatus::COMPLETED,
                'completion_date' => now(),
                'completed_by_id' => $actor->getKey(),
                'resolution_notes' => $data['resolution_notes'],
                'actual_cost' => $data['actual_cost'] ?? null,
                'parts_used' => $data['parts_used'] ?? null,
                'odometer_reading' => $data['odometer_reading'] ?? null,
            ])->save();

            $this->audit->log(
                action: 'MAINTENANCE_TICKET_COMPLETED',
                table: $ticket->getTable(),
                recordId: (string) $ticket->getKey(),
                new: [
                    'completed_by' => (string) $actor->getKey(),
                    'resolution' => $data['resolution_notes'],
                    'actual_cost' => $data['actual_cost'] ?? null,
                ],
                actor: $actor,
            );

            $this->rollForwardSchedule($ticket, $data['odometer_reading'] ?? null);
            $this->returnToServiceIfClear($ticket->bus, $actor);

            return $ticket->fresh(['bus']);
        });
    }

    /**
     * @throws BusinessRuleException
     */
    public function cancel(MaintenanceTicket $ticket, string $reason, User $actor): MaintenanceTicket
    {
        $ticket = $this->transition($ticket, MaintenanceStatus::CANCELLED, $actor, [
            'cancellation_reason' => $reason,
        ]);

        // A cancelled ticket no longer grounds anything, so the bus may now be
        // clear — but only if nothing else is holding it.
        $this->returnToServiceIfClear($ticket->bus, $actor);

        return $ticket;
    }

    // ========================================================================
    // BR-358 — RETURN TO SERVICE
    // ========================================================================

    /**
     * A bus comes back only when nothing grounding is left against it.
     *
     * Deliberately not "when this ticket closes": a vehicle with a fixed
     * gearbox and outstanding failed brakes is not roadworthy, and closing the
     * gearbox job must not quietly clear it.
     */
    public function returnToServiceIfClear(?Bus $bus, ?User $actor = null): bool
    {
        if ($bus === null || $bus->status !== BusStatus::BREAKDOWN) {
            return false;
        }

        $outstanding = MaintenanceTicket::where('bus_id', $bus->getKey())->grounding()->count();

        if ($outstanding > 0) {
            return false;
        }

        $previous = $bus->status;

        $bus->status = BusStatus::AVAILABLE;
        $bus->save();

        $this->audit->log(
            action: 'BUS_RETURNED_TO_SERVICE',
            table: $bus->getTable(),
            recordId: (string) $bus->getKey(),
            old: ['status' => $previous->value],
            new: ['status' => BusStatus::AVAILABLE->value],
            actor: $actor,
        );

        VehicleReturnedToService::dispatch($bus->fresh());

        return true;
    }

    /**
     * BR-366 — schedules that are past their grace period on this bus.
     *
     * @return Collection<int, PreventiveMaintenanceSchedule>
     */
    public function overdueSchedulesFor(Bus $bus): Collection
    {
        return PreventiveMaintenanceSchedule::with('bus')
            ->where('bus_id', $bus->getKey())
            ->active()
            ->get()
            ->filter(fn (PreventiveMaintenanceSchedule $schedule) => $schedule->isPastGracePeriod());
    }

    /**
     * BR-366 — refuse to put a bus on the road when a service is past grace.
     *
     * @throws BusinessRuleException
     */
    public function assertNotBlockedByPreventiveMaintenance(Bus $bus): void
    {
        $overdue = $this->overdueSchedulesFor($bus);

        if ($overdue->isEmpty()) {
            return;
        }

        $names = $overdue->pluck('service_name')->implode(', ');

        throw new BusinessRuleException(
            "This bus is past the grace period for scheduled maintenance ({$names}) "
            .'and cannot be assigned until it has been serviced.',
            ['overdue_services' => $overdue->pluck('service_name')->all()],
        );
    }

    /**
     * BG-16 — open tickets for services that have fallen due.
     *
     * @return int how many were raised
     */
    public function raiseDuePreventiveTickets(?User $actor = null): int
    {
        $raised = 0;

        PreventiveMaintenanceSchedule::with('bus')
            ->active()
            ->whereNull('open_ticket_id')
            ->chunkById(200, function ($schedules) use (&$raised, $actor) {
                foreach ($schedules as $schedule) {
                    if ($schedule->bus === null || ! $schedule->isDue()) {
                        continue;
                    }

                    $ticket = $this->open($schedule->bus, [
                        'issue_description' => "Scheduled service due: {$schedule->service_name}."
                            .($schedule->description ? " {$schedule->description}" : ''),
                        // Preventive work is planned, not an emergency — it
                        // must not ground a bus the moment it falls due, or
                        // every service date would cancel a route.
                        'priority' => MaintenancePriority::MEDIUM,
                    ], $actor);

                    $schedule->forceFill(['open_ticket_id' => $ticket->getKey()])->save();

                    $raised++;
                }
            });

        return $raised;
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws BusinessRuleException
     */
    private function transition(
        MaintenanceTicket $ticket,
        MaintenanceStatus $target,
        User $actor,
        array $extra = [],
    ): MaintenanceTicket {
        return DB::transaction(function () use ($ticket, $target, $actor, $extra) {
            $ticket = $this->lock($ticket);

            $this->assertCanTransition($ticket, $target);

            $previous = $ticket->status;

            $ticket->forceFill(array_merge(['status' => $target], $extra))->save();

            $this->audit->log(
                action: 'MAINTENANCE_TICKET_'.$target->value,
                table: $ticket->getTable(),
                recordId: (string) $ticket->getKey(),
                old: ['status' => $previous->value],
                new: ['status' => $target->value] + $extra,
                actor: $actor,
            );

            return $ticket->fresh(['bus']);
        });
    }

    private function lock(MaintenanceTicket $ticket): MaintenanceTicket
    {
        return MaintenanceTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertCanTransition(MaintenanceTicket $ticket, MaintenanceStatus $target): void
    {
        if (! $ticket->status->canTransitionTo($target)) {
            throw new BusinessRuleException(
                "A maintenance ticket cannot go from {$ticket->status->value} to {$target->value}.",
                ['from' => $ticket->status->value, 'to' => $target->value],
            );
        }
    }

    /**
     * Move the preventive schedule on to its next due point.
     */
    private function rollForwardSchedule(MaintenanceTicket $ticket, ?int $odometer): void
    {
        $schedule = PreventiveMaintenanceSchedule::where('open_ticket_id', $ticket->getKey())->first();

        if ($schedule === null) {
            return;
        }

        $servicedOdometer = $odometer ?? $schedule->bus?->current_odometer;

        $schedule->forceFill([
            'last_serviced_on' => today(),
            'last_serviced_odometer' => $servicedOdometer,
            'due_on' => $schedule->interval_days !== null
                ? today()->copy()->addDays($schedule->interval_days)
                : null,
            'due_at_odometer' => $schedule->interval_km !== null && $servicedOdometer !== null
                ? $servicedOdometer + $schedule->interval_km
                : null,
            'open_ticket_id' => null,
        ])->save();
    }
}
