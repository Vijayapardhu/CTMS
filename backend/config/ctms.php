<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CTMS Application Settings
    |--------------------------------------------------------------------------
    |
    | Configuration specific to the Campus Transport Management System
    |
    */

    'timezone' => env('CTMS_TIMEZONE', 'UTC'),
    'college_code' => env('CTMS_COLLEGE_CODE', 'COLLEGE001'),
    'college_name' => env('CTMS_COLLEGE_NAME', 'Your College Name'),

    /*
    |--------------------------------------------------------------------------
    | Trip Configuration
    |--------------------------------------------------------------------------
    */

    'trip' => [
        // Window in minutes before trip departure for checkin
        'checkin_window_minutes' => env('TRIP_CHECKIN_WINDOW_MINUTES', 15),

        // Hours before trip departure to allow cancellation
        'cancellation_deadline_hours' => env('TRIP_CANCELLATION_DEADLINE_HOURS', 2),

        // Minutes to buffer after scheduled time for completion
        'completion_buffer_minutes' => env('TRIP_COMPLETION_BUFFER_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | GPS Configuration
    |--------------------------------------------------------------------------
    */

    'gps' => [
        // Interval in seconds for GPS tracking updates
        'tracking_interval_seconds' => env('GPS_TRACKING_INTERVAL_SECONDS', 30),

        // Minimum accuracy threshold in meters
        'accuracy_threshold_meters' => env('GPS_ACCURACY_THRESHOLD_METERS', 50),

        // Geofence radius in meters for stops
        'geofence_radius_meters' => env('GEOFENCE_RADIUS_METERS', 100),

        // Google Maps API Key
        'google_maps_api_key' => env('GOOGLE_MAPS_API_KEY', ''),

        /*
        | Plausibility bounds for an incoming position (BR-302). A reading
        | outside these is a device fault or a spoof, and is rejected rather
        | than stored — one bad point corrupts every ETA downstream.
        */
        'max_speed_kmh' => (int) env('GPS_MAX_SPEED_KMH', 150),
        'max_jump_metres' => (int) env('GPS_MAX_JUMP_METRES', 5000),

        // Consecutive readings inside a geofence before an arrival is real.
        'geofence_confirm_readings' => (int) env('GEOFENCE_CONFIRM_READINGS', 2),

        // A position older than this is presented as stale, not as current.
        'stale_after_seconds' => (int) env('GPS_STALE_AFTER_SECONDS', 120),

        // No position for this long means the trip has stalled (BR-259).
        'stall_after_seconds' => (int) env('GPS_STALL_AFTER_SECONDS', 600),

        // Device clock deviation beyond this marks the timestamp untrusted.
        'clock_skew_tolerance_seconds' => (int) env('GPS_CLOCK_SKEW_SECONDS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delay Detection (N-06)
    |--------------------------------------------------------------------------
    */

    'delay' => [
        // Minutes late before riders are told. Below this it is noise.
        'notify_threshold_minutes' => (int) env('DELAY_NOTIFY_THRESHOLD_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Area (BR-214)
    |--------------------------------------------------------------------------
    |
    | The geographic bounding box the institution operates within. A stop
    | outside it is a data-entry error — most often transposed latitude and
    | longitude, which places a campus stop in the Indian Ocean.
    |
    */

    'service_area' => [
        'min_latitude' => (float) env('SERVICE_AREA_MIN_LAT', 8.0),
        'max_latitude' => (float) env('SERVICE_AREA_MAX_LAT', 37.6),
        'min_longitude' => (float) env('SERVICE_AREA_MIN_LNG', 68.0),
        'max_longitude' => (float) env('SERVICE_AREA_MAX_LNG', 97.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Capacity Planning (BR-159, BR-160)
    |--------------------------------------------------------------------------
    */

    'capacity' => [
        // Seats held back on every route as a buffer for unplanned riders.
        // Assignments may not consume these without an explicit override.
        'safety_margin_seats' => (int) env('CAPACITY_SAFETY_MARGIN_SEATS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Consolidation (FR-13)
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Data retention (BR-504, BR-307)
    |--------------------------------------------------------------------------
    |
    | Per data class, in days. The location trace window is the sensitive one:
    | it is the second-by-second breadcrumb of where a child was. Attendance
    | and trip history are deliberately not purgeable here — losing them would
    | destroy the answer to "was my child on that bus" (BR-505).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Access floor (BR-010)
    |--------------------------------------------------------------------------
    |
    | The system refuses to deactivate its way down to fewer administrators
    | than this. A deployment with none cannot be recovered through the
    | product — there is no endpoint that reactivates an account without an
    | administrator to call it.
    |
    */

    'access' => [
        'minimum_active_admins' => (int) env('MINIMUM_ACTIVE_ADMINS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Duty hours (BR-106)
    |--------------------------------------------------------------------------
    |
    | Fatigue is the failure mode nobody reports. A driver will not say they
    | are too tired on the morning a colleague has called in sick, so the
    | roster refuses for them. Measured from trips actually driven, not from a
    | shift table somebody maintains by hand — that one is always optimistic.
    |
    */

    'duty' => [
        'max_daily_minutes' => (int) env('DUTY_MAX_DAILY_MINUTES', 540),
        'max_continuous_minutes' => (int) env('DUTY_MAX_CONTINUOUS_MINUTES', 270),
        'min_rest_minutes' => (int) env('DUTY_MIN_REST_MINUTES', 600),
        'qualifying_break_minutes' => (int) env('DUTY_QUALIFYING_BREAK_MINUTES', 30),
    ],

    'retention' => [
        'location_trace_days' => (int) env('RETENTION_LOCATION_TRACE_DAYS', 90),

        // Files uploaded and never attached to a report. An attached file is
        // evidence and is never swept.
        'orphaned_evidence_hours' => (int) env('RETENTION_ORPHANED_EVIDENCE_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence uploads (BR-367)
    |--------------------------------------------------------------------------
    |
    | Ceilings are per category; see App\Enums\EvidenceCategory. A phone
    | photograph of a cracked windscreen is a few megabytes, so anything far
    | past that is either a mistake or an attempt to fill the disk.
    |
    */

    'evidence' => [
        'disk' => env('EVIDENCE_DISK', 'evidence'),
        'max_photo_kb' => (int) env('EVIDENCE_MAX_PHOTO_KB', 8192),
        'max_document_kb' => (int) env('EVIDENCE_MAX_DOCUMENT_KB', 16384),
    ],

    'consolidation' => [
        // Below this share of seats filled, a trip is a merge candidate.
        'occupancy_threshold' => (float) env('CONSOLIDATION_OCCUPANCY_THRESHOLD', 0.4),

        // How long a proposal stands before it expires undecided. Short,
        // because it is justified by occupancy figures that keep moving.
        'decision_window_minutes' => (int) env('CONSOLIDATION_DECISION_WINDOW_MINUTES', 30),

        // Used only to rank and explain proposals, never to bill anything.
        'cost_per_km' => (float) env('CONSOLIDATION_COST_PER_KM', 18.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Configuration
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        // Days to retain notifications
        'retention_days' => env('NOTIFICATION_RETENTION_DAYS', 30),

        // Days to retain announcements
        'announcement_retention_days' => env('ANNOUNCEMENT_RETENTION_DAYS', 90),

        /*
        | Channels are independently switchable. An institution with no SMS
        | contract turns it off and the platform routes around it rather than
        | accumulating failed deliveries against a gateway that is not there.
        */
        'channels' => [
            'PUSH' => ['enabled' => env('NOTIFY_PUSH_ENABLED', true)],
            'EMAIL' => ['enabled' => env('NOTIFY_EMAIL_ENABLED', true)],
            'SMS' => ['enabled' => env('NOTIFY_SMS_ENABLED', false)],
            'IN_APP' => ['enabled' => true],
        ],

        /*
        | Retry schedule, in seconds after the previous attempt (BR-406).
        | Attempt 1 is immediate; these are the delays before attempts 2..5.
        | Defined once here so no module invents its own backoff.
        */
        'retry_delays' => [30, 120, 600],

        // Quiet hours are honoured for STANDARD priority only (BR-402).
        'default_quiet_hours' => [
            'start' => env('NOTIFY_QUIET_START'),
            'end' => env('NOTIFY_QUIET_END'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    */

    'firebase' => [
        'api_key' => env('FIREBASE_API_KEY', ''),
        'project_id' => env('FIREBASE_PROJECT_ID', ''),
        'database_url' => env('FIREBASE_DATABASE_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Service Configuration (Twilio)
    |--------------------------------------------------------------------------
    */

    'sms' => [
        'provider' => 'twilio',
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID', ''),
            'auth_token' => env('TWILIO_AUTH_TOKEN', ''),
            'phone_number' => env('TWILIO_PHONE_NUMBER', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration
    |--------------------------------------------------------------------------
    */

    'rate_limit' => [
        'window_minutes' => env('API_RATE_LIMIT_WINDOW_MINUTES', 1),
        'max_requests' => env('API_RATE_LIMIT_REQUESTS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    */

    'api' => [
        'version' => 'v1',
        'prefix' => 'api/v1',
        'pagination' => [
            'per_page' => 15,
            'max_per_page' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */

    'features' => [
        'gps_tracking' => true,
        'real_time_updates' => true,
        'notifications' => true,
        'incident_reporting' => true,
        'maintenance_tracking' => true,
        'analytics' => true,
    ],
];
