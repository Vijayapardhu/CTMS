<?php

use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorization (BR-304)
|--------------------------------------------------------------------------
|
| Shared real-time infrastructure, owned by the tracking subsystem.
|
| Two rules govern every channel here:
|
| 1. Authorization is evaluated at subscribe AND on every reconnect. Laravel's
|    broadcasting auth endpoint is called again after a dropped connection, so
|    a subscription that was valid when the trip started does not survive the
|    trip ending or the subscriber's entitlement being revoked.
|
| 2. Live position of a bus carrying minors is not public within the
|    institution. A student reaches the trip serving their own route and
|    nothing else.
|
*/

Broadcast::channel('trips.{tripId}', function (User $user, string $tripId) {
    $trip = Trip::find($tripId);

    if ($trip === null) {
        return false;
    }

    // The window is the trip, not the account. Continuous visibility of a
    // vehicle carrying other people's children is not granted to anyone.
    if (! $trip->isRunning()) {
        return false;
    }

    if ($user->isAdmin()) {
        return true;
    }

    if ($user->isDriver()) {
        return $user->driver !== null
            && (string) $user->driver->getKey() === (string) $trip->driver_id;
    }

    if ($user->isStudent()) {
        $student = $user->student;

        return $student !== null
            && $student->route_id !== null
            && (string) $student->route_id === (string) $trip->route_id
            // A student who has lost their entitlement mid-term stops seeing
            // the bus on their next reconnect.
            && $student->canBoard();
    }

    return false;
});

/*
| The fleet-wide stream. Operations only — this is every bus at once.
*/
Broadcast::channel('fleet', fn (User $user) => $user->isAdmin());

/*
| A user's own notification stream.
*/
Broadcast::channel('users.{userId}', fn (User $user, string $userId) => (string) $user->getKey() === $userId);
