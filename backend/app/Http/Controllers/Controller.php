<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller.
 *
 * Brings in AuthorizesRequests so every controller can call `$this->authorize()`
 * against a policy. Record-level authorization is mandatory on any endpoint
 * that touches a specific row.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
