<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Maps Platform (FR-09)
    |--------------------------------------------------------------------------
    |
    | Off by default, and off in tests. Every provider degrades to an offline
    | estimate when this is disabled, so a deployment with no key is a working
    | deployment with rougher ETAs — not a broken one.
    |
    | The key is read from the environment and must never be committed. In the
    | Cloud console it should be restricted to the five APIs actually used, and
    | the server key kept separate from any key shipped to a browser.
    |
    */

    'google_maps' => [
        'enabled' => (bool) env('GOOGLE_MAPS_ENABLED', false),
        'key' => env('GOOGLE_MAPS_API_KEY'),
        'region' => env('GOOGLE_MAPS_REGION', 'in'),

        // Deliberately short. A slow map call must never hold up GPS ingest;
        // three seconds then fall back is better than ten seconds then fall
        // back, because the bus has moved either way.
        'timeout_seconds' => (int) env('GOOGLE_MAPS_TIMEOUT_SECONDS', 3),
        'retries' => (int) env('GOOGLE_MAPS_RETRIES', 2),
        'retry_delay_ms' => (int) env('GOOGLE_MAPS_RETRY_DELAY_MS', 200),

        // How long the circuit breaker stays open after repeated failures,
        // during which no calls are attempted at all.
        'breaker_minutes' => (int) env('GOOGLE_MAPS_BREAKER_MINUTES', 5),

        // Per-service daily ceilings. 0 means no local ceiling — the billing
        // account is then the only limit, which is how surprise invoices
        // happen. Set these.
        'daily_limits' => [
            'routing' => (int) env('GOOGLE_MAPS_LIMIT_ROUTING', 20000),
            'geocoding' => (int) env('GOOGLE_MAPS_LIMIT_GEOCODING', 2000),
            'places' => (int) env('GOOGLE_MAPS_LIMIT_PLACES', 2000),
            'roads' => (int) env('GOOGLE_MAPS_LIMIT_ROADS', 20000),
        ],

        'cache' => [
            // Short: the point of a traffic-aware route is that it changes.
            'routing_seconds' => (int) env('GOOGLE_MAPS_CACHE_ROUTING_SECONDS', 120),
            // Long: a street does not move. The cheapest cache here and the
            // one that saves the most money.
            'geocode_days' => (int) env('GOOGLE_MAPS_CACHE_GEOCODE_DAYS', 30),
            'places_hours' => (int) env('GOOGLE_MAPS_CACHE_PLACES_HOURS', 24),
        ],

        'places' => [
            'bias_radius_metres' => (int) env('GOOGLE_MAPS_PLACES_BIAS_METRES', 30000),
        ],

        'fallback' => [
            // Assumed average road speed when estimating offline.
            'speed_kmh' => (float) env('GOOGLE_MAPS_FALLBACK_SPEED_KMH', 25),
            // Straight lines understate road distance. Pessimistic on purpose:
            // an ETA that runs slightly late is survivable, one that runs
            // early leaves somebody believing they missed the bus.
            'road_factor' => (float) env('GOOGLE_MAPS_FALLBACK_ROAD_FACTOR', 1.3),
        ],
    ],

];
