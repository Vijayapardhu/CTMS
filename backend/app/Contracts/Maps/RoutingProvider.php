<?php

namespace App\Contracts\Maps;

use App\Services\Maps\Support\LatLng;
use App\Services\Maps\Support\TravelEstimate;

/**
 * Routes API and Route Matrix (FR-09).
 *
 * Every method on this contract is required to return an answer. None may
 * throw because the network was slow or a billing account lapsed: buses do not
 * stop running when Google does, and an ETA pipeline that raises on a timeout
 * takes the live map down with it.
 *
 * The obligation is discharged by returning a `TravelEstimate` with
 * `isEstimate: true` — straight-line distance and an assumed speed. The
 * caller must surface that distinction rather than hide it.
 */
interface RoutingProvider
{
    /**
     * Distance and duration from one point to another.
     *
     * @param  array<int, LatLng>  $waypoints  ordered intermediate stops
     */
    public function route(LatLng $origin, LatLng $destination, array $waypoints = []): TravelEstimate;

    /**
     * One origin against many destinations — the shape the ETA recalculation
     * needs, and far cheaper than N separate route calls.
     *
     * @param  array<int, LatLng>  $destinations
     * @return array<int, TravelEstimate> keyed to match $destinations
     */
    public function matrix(LatLng $origin, array $destinations): array;

    /**
     * Whether the provider is currently able to answer for real. Used for
     * reporting and health checks, never as a gate — callers just call.
     */
    public function isAvailable(): bool;
}
