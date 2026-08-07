<?php

namespace App\Services\Fleet;

use App\Enums\BusStatus;
use App\Enums\EvidenceCategory;
use App\Enums\InspectionItem;
use App\Enums\InspectionOutcome;
use App\Events\Fleet\InspectionFailed;
use App\Exceptions\BusinessRuleException;
use App\Models\Bus;
use App\Models\Driver;
use App\Models\MaintenanceTicket;
use App\Models\User;
use App\Models\VehicleInspection;
use App\Services\AuditLogger;
use App\Services\Evidence\EvidenceService;
use App\Services\Maintenance\MaintenanceService;
use Illuminate\Support\Facades\DB;

/**
 * Pre-trip vehicle inspections (BR-107, BR-108).
 *
 * This is the last point at which a fault is caught while a substitution is
 * still possible. Everything here is built around that: a failure is recorded
 * immediately, it opens a maintenance ticket without anyone having to remember
 * to, and a safety-critical failure takes the bus off the road rather than
 * merely noting a concern.
 */
class VehicleInspectionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MaintenanceService $maintenance,
        private readonly EvidenceService $evidence,
    ) {}

    /**
     * Record a completed inspection.
     *
     * @param  array<int, array{item: string, passed: bool, notes?: string|null, evidence_id?: string|null}>  $items
     *
     * @throws BusinessRuleException
     */
    public function submit(
        Bus $bus,
        Driver $driver,
        array $items,
        int $odometerReading,
        User $actor,
        ?string $notes = null,
    ): VehicleInspection {
        return DB::transaction(function () use ($bus, $driver, $items, $odometerReading, $actor, $notes) {
            $bus = Bus::whereKey($bus->getKey())->lockForUpdate()->firstOrFail();

            $this->assertChecklistIsComplete($items);
            $this->assertOdometerIsPlausible($bus, $odometerReading);

            $verdicts = $this->normaliseItems($items);
            $outcome = $this->determineOutcome($verdicts);

            $inspection = new VehicleInspection;
            $inspection->forceFill([
                'bus_id' => $bus->getKey(),
                'driver_id' => $driver->getKey(),
                'inspected_on' => now()->toDateString(),
                'inspected_at' => now(),
                'outcome' => $outcome,
                'odometer_reading' => $odometerReading,
                'notes' => $notes,
            ])->save();

            foreach ($verdicts as $verdict) {
                $line = $inspection->items()->create([
                    'item' => $verdict['item']->value,
                    'passed' => $verdict['passed'],
                    'notes' => $verdict['notes'],
                ]);

                // BR-367 — the photograph a failed safety-critical check
                // demands is bound to the line it evidences, and `claim()`
                // refuses one belonging to somebody else or already cited.
                $evidence = $this->evidence->claim(
                    $verdict['evidence_id'],
                    EvidenceCategory::INSPECTION_PHOTO,
                    $actor,
                );

                if ($evidence !== null) {
                    $this->evidence->attach($evidence, $line, $actor);
                }
            }

            $inspection->load('items');

            // BR-108 — any failure opens a ticket, without anyone having to
            // remember to raise one.
            if ($outcome !== InspectionOutcome::PASSED) {
                $ticket = $this->openMaintenanceTicket($bus, $inspection, $actor);

                $inspection->forceFill(['maintenance_ticket_id' => $ticket->getKey()])->save();
            }

            // A safety-critical failure takes the bus out of service at once.
            if ($outcome === InspectionOutcome::FAILED && $bus->status !== BusStatus::MAINTENANCE) {
                $previousStatus = $bus->status;

                $bus->status = BusStatus::MAINTENANCE;
                $bus->save();

                $this->audit->log(
                    action: 'BUS_STATUS_CHANGED',
                    table: $bus->getTable(),
                    recordId: (string) $bus->getKey(),
                    old: ['status' => $previousStatus->value],
                    new: [
                        'status' => BusStatus::MAINTENANCE->value,
                        'reason' => 'Failed pre-trip inspection',
                    ],
                    actor: $actor,
                );
            }

            $this->audit->log(
                action: 'INSPECTION_SUBMITTED',
                table: $inspection->getTable(),
                recordId: (string) $inspection->getKey(),
                new: [
                    'bus_id' => (string) $bus->getKey(),
                    'outcome' => $outcome->value,
                    'failed_items' => $inspection->failedItems()
                        ->map(fn ($item) => $item->item->value)->values()->all(),
                ],
                actor: $actor,
            );

            $inspection = $inspection->fresh(['items', 'maintenanceTicket']);

            // Operations need this while there is still time to substitute a
            // vehicle — a window of minutes (N-20).
            if ($outcome === InspectionOutcome::FAILED) {
                InspectionFailed::dispatch($inspection);
            }

            return $inspection;
        });
    }

    /**
     * Whether a bus may start a trip today, and why not if it may not.
     *
     * Module 4's trip-start gate (BR-251) consumes this. It is also what the
     * driver app shows against a disabled "Start trip" button, because a
     * disabled control with no stated reason is a support call.
     *
     * @return array{cleared: bool, reasons: array<int, string>, inspection: VehicleInspection|null}
     */
    public function serviceReadiness(Bus $bus): array
    {
        $reasons = [];

        if (! $bus->status->isOperational() && $bus->status !== BusStatus::RUNNING) {
            $reasons[] = "The bus is {$bus->status->value}.";
        }

        foreach ($bus->missingOrExpiredDocuments() as $type) {
            $reasons[] = "{$type->label()} is missing or expired.";
        }

        $inspection = $bus->inspectionOn();

        if ($inspection === null) {
            $reasons[] = 'No pre-trip inspection has been completed today.';
        } elseif (! $inspection->clearsForService()) {
            $failed = $inspection->failedSafetyCriticalItems()
                ->map(fn ($item) => $item->item->label())
                ->implode(', ');

            $reasons[] = "Today's inspection failed on: {$failed}.";
        }

        return [
            'cleared' => $reasons === [],
            'reasons' => $reasons,
            'inspection' => $inspection,
        ];
    }

    /**
     * Assert a bus is fit to start a trip (BR-107).
     *
     * @throws BusinessRuleException
     */
    public function assertClearedForService(Bus $bus): void
    {
        $readiness = $this->serviceReadiness($bus);

        if (! $readiness['cleared']) {
            throw new BusinessRuleException(
                'This bus is not cleared for service: '.implode(' ', $readiness['reasons']),
                ['reasons' => $readiness['reasons']],
            );
        }
    }

    // ========================================================================
    // INTERNALS
    // ========================================================================

    /**
     * Every checklist item must be answered. A partially completed inspection
     * is worse than none, because it looks like diligence.
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws BusinessRuleException
     */
    private function assertChecklistIsComplete(array $items): void
    {
        $answered = array_map(fn (array $item) => strtoupper((string) $item['item']), $items);
        $missing = [];

        foreach (InspectionItem::cases() as $case) {
            if (! in_array($case->value, $answered, true)) {
                $missing[] = $case->value;
            }
        }

        if ($missing !== []) {
            throw new BusinessRuleException(
                'The inspection checklist is incomplete.',
                ['missing_items' => $missing],
            );
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertOdometerIsPlausible(Bus $bus, int $reading): void
    {
        // BR-061 lives on the model so that the workshop, the pre-trip check
        // and the trip close all measure "backwards" the same way. The
        // inspection history is still consulted, because a bus may have
        // readings recorded before the running total existed.
        $last = max(
            (int) $bus->inspections()->max('odometer_reading'),
            (int) $bus->current_odometer,
        );

        if ($reading < $last) {
            throw new BusinessRuleException(
                "The odometer reading must be at least {$last}.",
                ['last_reading' => $last],
            );
        }

        $bus->recordOdometer($reading);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{item: InspectionItem, passed: bool, notes: string|null, evidence_id: string|null}>
     */
    private function normaliseItems(array $items): array
    {
        $normalised = [];

        foreach ($items as $item) {
            $normalised[] = [
                'item' => InspectionItem::from(strtoupper((string) $item['item'])),
                'passed' => (bool) $item['passed'],
                'notes' => $item['notes'] ?? null,
                'evidence_id' => $item['evidence_id'] ?? null,
            ];
        }

        return $normalised;
    }

    /**
     * @param  array<int, array{item: InspectionItem, passed: bool}>  $verdicts
     */
    private function determineOutcome(array $verdicts): InspectionOutcome
    {
        $failures = array_filter($verdicts, fn (array $verdict) => ! $verdict['passed']);

        if ($failures === []) {
            return InspectionOutcome::PASSED;
        }

        foreach ($failures as $failure) {
            if ($failure['item']->isSafetyCritical()) {
                return InspectionOutcome::FAILED;
            }
        }

        return InspectionOutcome::PASSED_WITH_DEFECTS;
    }

    private function openMaintenanceTicket(Bus $bus, VehicleInspection $inspection, User $actor): MaintenanceTicket
    {
        return $this->maintenance->openForInspection($bus, $inspection, $actor);
    }
}
