<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Maps\GeocodingProvider;
use App\Contracts\Maps\PlacesProvider;
use App\Contracts\Maps\RoadsProvider;
use App\Contracts\Maps\RoutingProvider;
use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Services\Maps\Support\LatLng;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Geocoding, place search and provider health (FR-05, FR-09).
 *
 * These endpoints exist so the operator building a route does not have to type
 * coordinates. Every one of them is allowed to return nothing: with no map
 * provider configured the operator falls back to entering a latitude and
 * longitude by hand, which is exactly what they did before.
 */
class GeoController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GeocodingProvider $geocoding,
        private readonly PlacesProvider $places,
        private readonly RoutingProvider $routing,
        private readonly RoadsProvider $roads,
    ) {}

    /**
     * GET /api/v1/geo/geocode?address=...
     */
    public function geocode(Request $request): JsonResponse
    {
        $this->authorize('create', Route::class);

        $validated = $request->validate([
            'address' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $point = $this->geocoding->geocode($validated['address']);

        if ($point === null) {
            // Not a 404: the request was fine, the answer is "no match". A
            // guessed coordinate would put a stop where the bus does not go.
            return $this->success([
                'found' => false,
                'point' => null,
                'provider_available' => $this->geocoding->isAvailable(),
            ], 'No match for that address. Enter the coordinates directly.');
        }

        return $this->success([
            'found' => true,
            'point' => $point->toArray(),
            'provider_available' => true,
        ], 'Address located.');
    }

    /**
     * GET /api/v1/geo/reverse?latitude=&longitude=
     */
    public function reverse(Request $request): JsonResponse
    {
        $this->authorize('create', Route::class);

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $address = $this->geocoding->reverseGeocode(
            LatLng::make((float) $validated['latitude'], (float) $validated['longitude']),
        );

        return $this->success([
            'found' => $address !== null,
            'address' => $address,
        ], $address !== null ? 'Address resolved.' : 'No address for that point.');
    }

    /**
     * GET /api/v1/geo/places?query=...
     */
    public function places(Request $request): JsonResponse
    {
        $this->authorize('create', Route::class);

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $near = isset($validated['latitude'], $validated['longitude'])
            ? LatLng::make((float) $validated['latitude'], (float) $validated['longitude'])
            : null;

        $results = array_map(fn (array $place) => [
            'name' => $place['name'],
            'address' => $place['address'],
            'place_id' => $place['place_id'],
        ] + $place['point']->toArray(), $this->places->search($validated['query'], $near));

        return $this->success($results, 'Place search completed.');
    }

    /**
     * GET /api/v1/geo/status
     *
     * Which map services are actually answering. Operations needs this to
     * know whether the ETAs on screen are live or estimated.
     */
    public function status(): JsonResponse
    {
        $this->authorize('create', Route::class);

        return $this->success([
            'routing' => $this->providerStatus($this->routing->isAvailable()),
            'geocoding' => $this->providerStatus($this->geocoding->isAvailable()),
            'places' => $this->providerStatus($this->places->isAvailable()),
            'roads' => $this->providerStatus($this->roads->isAvailable()),
            // Even with every provider down the system keeps working; this
            // says how well, not whether.
            'degraded' => ! $this->routing->isAvailable(),
        ], 'Map provider status retrieved.');
    }

    /**
     * @return array{available: bool, mode: string}
     */
    private function providerStatus(bool $available): array
    {
        return [
            'available' => $available,
            'mode' => $available ? 'live' : 'offline_estimate',
        ];
    }
}
