<?php

/**
 * Hand-curated mutation testing for the safety-critical predicates.
 *
 * Infection needs a coverage driver (xdebug or pcov). Neither is installed on
 * the Windows dev machine, so this is the substitute: it injects a small set
 * of deliberate, realistic defects into the rules that decide whether a bus
 * carries children, and checks that the suite goes red.
 *
 * It is not a replacement for Infection — it covers eight predicates, not the
 * whole codebase — but it does the thing that matters: it proves the tests are
 * sensitive to the bugs, rather than merely passing. Its first run found three
 * survivors, all of which were real gaps:
 *
 *   · every grounding test used URGENT, so narrowing the rule to URGENT-only
 *     passed the whole suite
 *   · every capacity test used a wide margin, so loosening the check by one
 *     passenger passed
 *   · the odometer rule was implemented twice; the model's copy was never the
 *     one that ran, and the workshop's reading was validated then discarded
 *
 * Run with:  php tools/mutation-check.php
 * Add a mutant whenever a new safety rule lands.
 */
$mutants = [
    [
        'name' => 'A HIGH-priority fault no longer grounds the bus',
        'file' => 'app/Enums/MaintenancePriority.php',
        'from' => 'return $this === self::URGENT || $this === self::HIGH;',
        'to' => 'return $this === self::URGENT;',
        'tests' => 'tests/Feature/Maintenance',
    ],
    [
        'name' => 'Merge allowed AT the divergence point, not just before it',
        'file' => 'app/Services/Trips/ConsolidationService.php',
        'from' => 'if ((int) $furthestReached >= $consolidation->divergence_sequence) {',
        'to' => 'if ((int) $furthestReached > $consolidation->divergence_sequence) {',
        'tests' => 'tests/Feature/Trips/ConsolidationTest.php',
    ],
    [
        'name' => 'Combined passengers may exceed capacity by one',
        'file' => 'app/Models/TripConsolidation.php',
        'from' => 'return $sourcePassengers + $targetPassengers <= $targetCapacity;',
        'to' => 'return $sourcePassengers + $targetPassengers <= $targetCapacity + 1;',
        'tests' => 'tests/Feature/Trips/ConsolidationTest.php',
    ],
    [
        'name' => 'Life-safety escalation delayed from 2 minutes to 20',
        'file' => 'app/Enums/IncidentClass.php',
        'from' => 'self::LIFE_SAFETY => 2,',
        'to' => 'self::LIFE_SAFETY => 20,',
        'tests' => 'tests/Feature/Incidents',
    ],
    [
        'name' => 'Daily duty ceiling becomes unreachable',
        'file' => 'app/Services/Fleet/DutyHoursService.php',
        'from' => 'if ($today >= $dailyCeiling) {',
        'to' => 'if ($today > $dailyCeiling + 600) {',
        'tests' => 'tests/Feature/Hardening/DutyHoursTest.php',
    ],
    [
        'name' => 'Odometer may go backwards',
        'file' => 'app/Models/Bus.php',
        'from' => 'if ($current !== null && $reading < $current) {',
        'to' => 'if ($current !== null && $reading < -1) {',
        'tests' => 'tests/Feature/Maintenance',
    ],
    [
        'name' => 'Audit records become editable',
        'file' => 'app/Models/AuditLog.php',
        'from' => 'static::updating(function () {',
        'to' => 'static::updating(function () { return; ',
        'tests' => 'tests/Feature/Reports',
    ],
    [
        'name' => 'Snap accepted however far it moves the bus',
        'file' => 'app/Services/Maps/Providers/GoogleRoadsProvider.php',
        'from' => 'if ($points[$index]->metresTo($candidate) > self::MAX_SNAP_DRIFT_METRES) {',
        'to' => 'if ($points[$index]->metresTo($candidate) > 100000) {',
        'tests' => 'tests/Feature/Maps',
    ],
];

$killed = 0;
$survived = [];
$skipped = [];

foreach ($mutants as $m) {
    $original = file_get_contents($m['file']);

    // An anchor that no longer matches means the code moved and the mutant is
    // silently testing nothing — which is worse than a failure, so it is
    // reported loudly.
    if (! str_contains($original, $m['from'])) {
        $skipped[] = $m['name'];
        echo "SKIPPED  {$m['name']} — anchor not found, retarget it\n";

        continue;
    }

    file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));

    $out = [];
    exec("php artisan test --without-tty {$m['tests']} 2>&1", $out, $code);

    file_put_contents($m['file'], $original);

    if ($code !== 0) {
        $killed++;
        echo "KILLED   {$m['name']}\n";
    } else {
        $survived[] = $m['name'];
        echo "SURVIVED {$m['name']}\n";
    }
}

$total = $killed + count($survived);

printf(
    "\nMutants: %d   Killed: %d   Survived: %d   Skipped: %d   Score: %s\n",
    $total,
    $killed,
    count($survived),
    count($skipped),
    $total ? round($killed / $total * 100).'%' : 'n/a',
);

foreach ($survived as $s) {
    echo "  SURVIVING: {$s}\n";
}

exit($survived === [] && $skipped === [] ? 0 : 1);
