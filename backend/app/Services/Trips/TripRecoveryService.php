<?php

namespace App\Services\Trips;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceDiscrepancy;
use App\Models\PassengerLog;
use App\Models\Trip;
use App\Models\TripCorrection;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Putting a trip's record right after the fact (BR-258, BR-266).
 *
 * Two rules meet here, and both say the same thing in different words: the
 * system does not get to quietly rewrite what it recorded. A correction is a
 * new attributed record placed beside the original. A headcount that disagrees
 * with the boarding log is preserved as a disagreement, not averaged away.
 */
class TripRecoveryService
{
    /**
     * Fields an operator may correct after a trip has closed. Deliberately
     * narrow: `status`, attribution and timestamps are not on it, because
     * those are the fields somebody would want to change to hide something.
     */
    /**
     * The fields a correction may touch.
     *
     * Every one of these must be a real column on `trips`. This list also
     * feeds the request's validation rule and the panel's correction dialog,
     * so a name that is not a column becomes a 500 from the database instead
     * of a refusal — which is what `odometer_start`, `odometer_end` and
     * `notes` did until a demonstration build tripped over them. A trip has no
     * odometer and no notes column; those readings live on the inspection and
     * the maintenance ticket.
     *
     * `TripRecoveryTest::every_correctable_field_is_a_real_column_on_trips`
     * is the mechanical check that stops this recurring.
     */
    private const CORRECTABLE = [
        'occupied_seat_count',
        'booked_seat_count',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<int, string>
     */
    public static function correctableFields(): array
    {
        return self::CORRECTABLE;
    }

    /**
     * BR-258 — record a correction without destroying what it corrects.
     *
     * @throws BusinessRuleException
     */
    public function correct(
        Trip $trip,
        string $field,
        mixed $value,
        string $reason,
        User $actor,
    ): TripCorrection {
        if (! in_array($field, self::CORRECTABLE, true)) {
            throw new BusinessRuleException(
                "'{$field}' is not a correctable field on a closed trip.",
                ['correctable' => self::CORRECTABLE],
            );
        }

        return DB::transaction(function () use ($trip, $field, $value, $reason, $actor) {
            $trip = Trip::whereKey($trip->getKey())->lockForUpdate()->firstOrFail();

            if (! $trip->isTerminal()) {
                throw new BusinessRuleException(
                    'This trip is still active. Change it through the trip itself rather '
                    .'than filing a correction against it.',
                );
            }

            $original = $trip->getAttribute($field);

            $correction = new TripCorrection;

            $correction->forceFill([
                'trip_id' => $trip->getKey(),
                'field' => $field,
                // Captured before the write, which is the only moment it can
                // still be captured.
                'original_value' => $original === null ? null : (string) $original,
                'corrected_value' => $value === null ? null : (string) $value,
                'reason' => $reason,
                'corrected_by_id' => $actor->getKey(),
            ])->save();

            $trip->forceFill([$field => $value])->save();

            $this->audit->log(
                action: 'TRIP_CORRECTED',
                table: $trip->getTable(),
                recordId: (string) $trip->getKey(),
                old: [$field => $original],
                new: [$field => $value, 'reason' => $reason],
                actor: $actor,
            );

            return $correction;
        });
    }

    /**
     * BG-20, BR-266 — compare the driver's headcount to the boarding events.
     *
     * Returns null when they agree. When they do not, the disagreement is
     * recorded and left open for a human: the system cannot tell which number
     * is wrong, and picking one would destroy the evidence that a passenger
     * may be unaccounted for.
     */
    public function reconcileAttendance(Trip $trip): ?AttendanceDiscrepancy
    {
        $headcount = (int) $trip->occupied_seat_count;

        // Net of the log: everyone recorded boarding, less everyone recorded
        // getting off again.
        $boarded = PassengerLog::where('trip_id', $trip->getKey())
            ->where('action', 'BOARDED')->count();
        $alighted = PassengerLog::where('trip_id', $trip->getKey())
            ->where('action', 'ALIGHTED')->count();

        $boardingEvents = max(0, $boarded - $alighted);

        if ($headcount === $boardingEvents) {
            return null;
        }

        $existing = AttendanceDiscrepancy::where('trip_id', $trip->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        $discrepancy = new AttendanceDiscrepancy;

        $discrepancy->forceFill([
            'trip_id' => $trip->getKey(),
            'headcount' => $headcount,
            'boarding_event_count' => $boardingEvents,
            'difference' => $headcount - $boardingEvents,
            'status' => 'OPEN',
        ])->save();

        $this->audit->log(
            action: 'ATTENDANCE_DISCREPANCY_RAISED',
            table: $discrepancy->getTable(),
            recordId: (string) $discrepancy->getKey(),
            new: [
                'trip_id' => (string) $trip->getKey(),
                'headcount' => $headcount,
                'boarding_event_count' => $boardingEvents,
                'difference' => $headcount - $boardingEvents,
            ],
        );

        return $discrepancy;
    }

    /**
     * A human has looked at the disagreement. The numbers stay as they were.
     *
     * @throws BusinessRuleException
     */
    public function reviewDiscrepancy(
        AttendanceDiscrepancy $discrepancy,
        string $note,
        User $actor,
    ): AttendanceDiscrepancy {
        return DB::transaction(function () use ($discrepancy, $note, $actor) {
            $discrepancy = AttendanceDiscrepancy::whereKey($discrepancy->getKey())
                ->lockForUpdate()->firstOrFail();

            if ($discrepancy->status === 'REVIEWED') {
                throw new BusinessRuleException('This discrepancy has already been reviewed.');
            }

            // Note the absence of any write to headcount or boarding_event_count.
            // Reviewing explains a disagreement; it does not resolve it away.
            $discrepancy->forceFill([
                'status' => 'REVIEWED',
                'review_note' => $note,
                'reviewed_by_id' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'ATTENDANCE_DISCREPANCY_REVIEWED',
                table: $discrepancy->getTable(),
                recordId: (string) $discrepancy->getKey(),
                new: ['note' => $note],
                actor: $actor,
            );

            return $discrepancy;
        });
    }
}
