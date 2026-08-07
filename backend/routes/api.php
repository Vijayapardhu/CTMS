<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusController;
use App\Http\Controllers\Api\BusDocumentController;
use App\Http\Controllers\Api\ConsolidationController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationDeviceController;
use App\Http\Controllers\Api\NotificationLogController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PreventiveMaintenanceController;
use App\Http\Controllers\Api\ReplacementController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ServiceCalendarController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TrackingController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\TripRecoveryController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleInspectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CTMS API v1
|--------------------------------------------------------------------------
|
| Every route below is authenticated unless it sits in the explicitly public
| block. Role gates (`role:ADMIN`) are a coarse first filter — record-level
| ownership is decided by policies inside the controllers.
|
*/

Route::prefix('v1')->group(function () {

    // ---------------------------------------------------------------------
    // Public — the only unauthenticated surface in the API.
    // ---------------------------------------------------------------------
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/auth/login', [AuthController::class, 'login']);
        // Self-service registration creates STUDENT accounts only; requesting
        // any other role here is rejected by RegisterRequest::authorize().
        Route::post('/auth/register', [AuthController::class, 'register']);
    });

    // Refresh carries no email, so it must not share the `auth` limiter —
    // every caller would land in the same "email:" bucket and starve each other.
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])
        ->middleware('throttle:api');

    // ---------------------------------------------------------------------
    // Authenticated
    // ---------------------------------------------------------------------
    Route::middleware(['auth.jwt', 'throttle:api'])->group(function () {

        // ---- Session ----------------------------------------------------
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/change-password', [PasswordController::class, 'change'])
            ->middleware('throttle:password');

        // ---- Notifications (FR-10) --------------------------------------
        // Every route here is scoped to the caller's own notifications by the
        // controller; there is no path by which one user reads another's.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('notifications/{id}', [NotificationController::class, 'show']);
        Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::patch('notifications/{id}/unread', [NotificationController::class, 'markUnread']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        Route::get('notification-preferences', [NotificationPreferenceController::class, 'index']);
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);

        Route::get('notification-devices', [NotificationDeviceController::class, 'index']);
        Route::post('notification-devices', [NotificationDeviceController::class, 'store']);
        Route::post('notification-devices/revoke-all', [NotificationDeviceController::class, 'revokeAll']);
        Route::delete('notification-devices/{id}', [NotificationDeviceController::class, 'destroy']);

        // The delivery log is operational data about other people's messages.
        Route::middleware('role:ADMIN')->group(function () {
            Route::get('notification-log', [NotificationLogController::class, 'index']);
            Route::get('notification-log/health', [NotificationLogController::class, 'health']);
            Route::post('notification-log/{id}/resend', [NotificationLogController::class, 'resend'])
                ->middleware('throttle:writes');
        });

        // ---- Users (FR-01) ----------------------------------------------
        // `index` is admin-only; `show`/`update` allow self-access and are
        // authorized per-record by UserPolicy.
        Route::get('users', [UserController::class, 'index'])->middleware('role:ADMIN');

        // Administrative account creation. Shares the controller action with
        // the public `/auth/register`, but because this route sits behind
        // auth.jwt the request carries an identity, which is what lets
        // RegisterRequest permit a DRIVER or ADMIN role here and nowhere else.
        Route::post('users', [AuthController::class, 'register'])
            ->middleware(['role:ADMIN', 'access:SUPER_ADMIN', 'throttle:writes']);

        Route::get('users/{id}', [UserController::class, 'show']);
        Route::put('users/{id}', [UserController::class, 'update']);
        Route::patch('users/{id}/status', [UserController::class, 'setActiveState'])
            ->middleware(['role:ADMIN', 'access:SUPER_ADMIN']);

        // ---- Fleet: buses (FR-02) ---------------------------------------
        Route::get('buses', [BusController::class, 'index']);
        Route::get('buses/{id}', [BusController::class, 'show']);
        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::post('buses', [BusController::class, 'store']);
            Route::put('buses/{id}', [BusController::class, 'update']);
            Route::patch('buses/{id}/status', [BusController::class, 'updateStatus']);
            Route::delete('buses/{id}', [BusController::class, 'destroy']);
        });

        // ---- Fleet: statutory documents (BR-055) ------------------------
        Route::get('fleet/documents/expiring', [BusDocumentController::class, 'expiring']);
        Route::get('buses/{id}/documents', [BusDocumentController::class, 'index']);
        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::post('buses/{id}/documents', [BusDocumentController::class, 'store']);
            Route::put('buses/{busId}/documents/{documentId}', [BusDocumentController::class, 'update']);
            Route::delete('buses/{busId}/documents/{documentId}', [BusDocumentController::class, 'destroy']);
        });

        // ---- Fleet: pre-trip inspections (BR-107, BR-108) ---------------
        Route::get('inspections/checklist', [VehicleInspectionController::class, 'checklist']);
        Route::get('inspections/{id}', [VehicleInspectionController::class, 'show']);
        Route::get('buses/{id}/service-readiness', [VehicleInspectionController::class, 'readiness']);
        Route::get('buses/{id}/inspections', [VehicleInspectionController::class, 'index']);
        // Drivers submit their own; the controller resolves which driver, and
        // an administrator must name one explicitly.
        Route::post('buses/{id}/inspections', [VehicleInspectionController::class, 'store'])
            ->middleware(['role:DRIVER,ADMIN', 'throttle:writes']);

        // ---- Fleet: drivers (FR-03) -------------------------------------
        Route::get('drivers', [DriverController::class, 'index'])->middleware('role:ADMIN');
        // A driver may read and set the duty status on their own record;
        // DriverPolicy decides which record that is.
        Route::get('drivers/{id}', [DriverController::class, 'show']);
        Route::patch('drivers/{id}/status', [DriverController::class, 'updateStatus'])
            ->middleware('throttle:writes');
        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::post('drivers', [DriverController::class, 'store']);
            Route::put('drivers/{id}', [DriverController::class, 'update']);
            Route::post('drivers/{id}/assign-bus', [DriverController::class, 'assignBus']);
            Route::delete('drivers/{id}/assign-bus', [DriverController::class, 'unassignBus']);
            Route::delete('drivers/{id}', [DriverController::class, 'destroy']);
        });

        // ---- Students (FR-04) -------------------------------------------
        Route::get('students', [StudentController::class, 'index'])->middleware('role:ADMIN');
        // Self-access is allowed and decided per-record by StudentPolicy.
        Route::get('students/{id}', [StudentController::class, 'show']);
        Route::put('students/{id}', [StudentController::class, 'update'])->middleware('throttle:writes');
        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::post('students', [StudentController::class, 'store']);
            Route::patch('students/{id}/status', [StudentController::class, 'updateStatus']);
            Route::post('students/{id}/assign-transport', [StudentController::class, 'assignTransport']);
            Route::delete('students/{id}/assign-transport', [StudentController::class, 'clearTransport']);
            Route::delete('students/{id}', [StudentController::class, 'destroy']);
        });

        // ---- Routes & stops (FR-05) -------------------------------------
        Route::get('routes', [RouteController::class, 'index']);
        Route::get('routes/{id}', [RouteController::class, 'show']);
        // Stops are only ever addressed through their parent route. A
        // standalone /route-stops write endpoint would be a second mutation
        // path that skips the route-level authorization check.
        Route::get('routes/{id}/stops', [RouteController::class, 'listStops']);
        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::post('routes', [RouteController::class, 'store']);
            Route::put('routes/{id}', [RouteController::class, 'update']);
            Route::patch('routes/{id}/status', [RouteController::class, 'updateStatus']);
            Route::delete('routes/{id}', [RouteController::class, 'destroy']);
            Route::post('routes/{id}/stops', [RouteController::class, 'addStop']);
            Route::put('routes/{routeId}/stops/{stopId}', [RouteController::class, 'updateStop']);
            Route::delete('routes/{routeId}/stops/{stopId}', [RouteController::class, 'deleteStop']);
        });

        // ---- Trips (FR-06) ----------------------------------------------
        // The list is scoped by role in the controller: a driver sees their
        // own duty, a student the route they ride, staff everything.
        Route::get('trips', [TripController::class, 'index']);
        Route::get('trips/{id}', [TripController::class, 'show']);

        // Starting and completing belong to the assigned driver; TripPolicy
        // decides which trip that is, and operations may act on their behalf.
        Route::middleware('throttle:writes')->group(function () {
            Route::post('trips/{id}/start', [TripController::class, 'start']);
            Route::post('trips/{id}/complete', [TripController::class, 'complete']);
        });

        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::post('trips', [TripController::class, 'store']);
            Route::post('trips/generate', [TripController::class, 'generate']);
            Route::post('trips/{id}/cancel', [TripController::class, 'cancel']);
            Route::post('trips/{id}/reassign', [TripController::class, 'reassign']);
        });

        // ---- Tracking (FR-07, FR-08, FR-09) ------------------------------
        // Position ingest is the highest-frequency endpoint in the product:
        // every driver, every few seconds, for ninety minutes twice a day.
        Route::post('trips/{id}/positions', [TrackingController::class, 'recordPosition'])
            ->middleware('throttle:gps');

        Route::get('trips/{id}/live', [TrackingController::class, 'live']);
        Route::get('trips/{id}/eta', [TrackingController::class, 'eta']);

        Route::middleware('throttle:writes')->group(function () {
            Route::get('trips/{id}/stops/{stopId}/manifest', [TrackingController::class, 'manifest']);
            Route::post('trips/{id}/stops/{stopId}/arrive', [TrackingController::class, 'markArrived']);
            Route::post('trips/{id}/stops/{stopId}/skip', [TrackingController::class, 'skipStop']);
            Route::post('trips/{id}/board', [TrackingController::class, 'board']);
            Route::post('trips/{id}/alight', [TrackingController::class, 'alight']);
            Route::post('trips/{id}/left-behind', [TrackingController::class, 'leftBehind']);
        });

        // ---- Incidents (FR-11) -------------------------------------------
        // Reporting is deliberately wide and lightly throttled: a system that
        // rate-limits an emergency has failed.
        Route::get('incidents/types', [IncidentController::class, 'types']);
        Route::get('incidents', [IncidentController::class, 'index']);
        Route::get('incidents/{id}', [IncidentController::class, 'show']);
        Route::post('incidents', [IncidentController::class, 'store']);
        Route::post('incidents/{id}/notes', [IncidentController::class, 'addNote'])
            ->middleware('throttle:writes');
        Route::post('incidents/{id}/cancel', [IncidentController::class, 'cancel'])
            ->middleware('throttle:writes');

        // Answering a report is day-to-day work a supervisor must be able
        // to do at six in the morning. Closing one is not: that is the act
        // that lets a bus back on the road (BR-358).
        Route::middleware(['role:ADMIN', 'throttle:writes'])->group(function () {
            Route::post('incidents/{id}/acknowledge', [IncidentController::class, 'acknowledge'])
                ->middleware('access:SUPPORT');
            Route::post('incidents/{id}/resolve', [IncidentController::class, 'resolve'])
                ->middleware('access:SUPPORT');
            Route::post('incidents/{id}/close', [IncidentController::class, 'close'])
                ->middleware('access:OPERATIONS');
        });

        // ---- Replacement vehicles (FR-12) --------------------------------
        Route::middleware('role:ADMIN')->group(function () {
            Route::get('replacements', [ReplacementController::class, 'index']);
            Route::get('replacements/{id}', [ReplacementController::class, 'show']);

            Route::middleware('throttle:writes')->group(function () {
                // Committing the money and the vehicle.
                Route::post('replacements/{id}/approve', [ReplacementController::class, 'approve'])
                    ->middleware('access:OPERATIONS');
                Route::post('replacements/{id}/reject', [ReplacementController::class, 'reject'])
                    ->middleware('access:OPERATIONS');
                // Executing a decision somebody else already took.
                Route::post('replacements/{id}/dispatch', [ReplacementController::class, 'dispatchReplacement'])
                    ->middleware('access:SUPPORT');
                Route::post('replacements/{id}/arrived', [ReplacementController::class, 'markArrived'])
                    ->middleware('access:SUPPORT');
            });
        });

        // ---- Evidence (BR-367) -------------------------------------------
        // One upload pipeline for every attachment in the system. Upload
        // returns an id, never a URL — a URL gets pasted into a chat and then
        // works for whoever receives it. Retrieval is authorised per file.
        Route::get('evidence/categories', [EvidenceController::class, 'categories']);
        Route::get('evidence/{id}', [EvidenceController::class, 'show']);
        Route::post('evidence', [EvidenceController::class, 'store'])
            ->middleware('throttle:writes');

        // ---- Announcements (blueprint §Communication) --------------------
        // Reading is open to everybody — an announcement exists to be read,
        // and the controller scopes each role to its own audience. Writing and
        // publishing are operations: one call reaches every student and driver
        // on the system.
        Route::get('announcements', [AnnouncementController::class, 'index']);
        Route::get('announcements/{id}', [AnnouncementController::class, 'show']);

        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::post('announcements', [AnnouncementController::class, 'store']);
            Route::put('announcements/{id}', [AnnouncementController::class, 'update']);
            Route::post('announcements/{id}/publish', [AnnouncementController::class, 'publish']);
            Route::post('announcements/{id}/withdraw', [AnnouncementController::class, 'withdraw']);
        });

        // ---- Reports (FR-15) ---------------------------------------------
        // Every one of these is an aggregate. None returns a named student or
        // a position, so this surface cannot become a way around BR-500.
        Route::middleware('role:ADMIN')->group(function () {
            Route::get('reports/trips', [ReportController::class, 'trips']);
            Route::get('reports/occupancy', [ReportController::class, 'occupancy']);
            Route::get('reports/fleet', [ReportController::class, 'fleet']);
            Route::get('reports/incidents', [ReportController::class, 'incidents']);
            Route::get('reports/attendance', [ReportController::class, 'attendance']);
            Route::get('reports/maintenance', [ReportController::class, 'maintenance']);
        });

        // ---- Audit and data protection (BR-501, BR-502, BR-506, BR-507) --
        // Read-only. There is deliberately no write endpoint here: the audit
        // trail is evidence only for as long as nobody can reach in and adjust
        // it.
        Route::middleware('role:ADMIN')->group(function () {
            Route::get('audit-logs', [AuditController::class, 'index'])
                ->middleware('access:SUPER_ADMIN');
            Route::get('audit-logs/{id}', [AuditController::class, 'show'])
                ->middleware('access:SUPER_ADMIN');
            Route::get('data-access-logs', [AuditController::class, 'accessLogs'])
                ->middleware('access:SUPER_ADMIN');
            Route::get('retention-runs', [AuditController::class, 'retentionRuns'])
                ->middleware('access:SUPER_ADMIN');

            Route::post('users/{id}/subject-access-export', [AuditController::class, 'subjectAccessExport'])
                ->middleware(['access:SUPER_ADMIN', 'throttle:writes']);
        });

        // ---- Geocoding and places (FR-05, FR-09) -------------------------
        // Helpers for whoever is building a route. Throttled because each one
        // can cost a paid API call, and gated behind the same authority as
        // creating a route in the first place.
        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::get('geo/geocode', [GeoController::class, 'geocode']);
            Route::get('geo/reverse', [GeoController::class, 'reverse']);
            Route::get('geo/places', [GeoController::class, 'places']);
            Route::get('geo/status', [GeoController::class, 'status']);
        });

        // ---- Maintenance (FR-14) -----------------------------------------
        // Reading is open to drivers for their own bus, so they can see why it
        // is off the road. Everything that changes a ticket is operations —
        // BR-358 turns on who may sign work off.
        Route::get('maintenance-tickets', [MaintenanceController::class, 'index']);
        Route::get('maintenance-tickets/{id}', [MaintenanceController::class, 'show']);

        Route::middleware(['role:ADMIN', 'throttle:writes'])->group(function () {
            // Raising and booking work in is scheduling.
            Route::post('maintenance-tickets', [MaintenanceController::class, 'store'])
                ->middleware('access:SUPPORT');
            Route::post('maintenance-tickets/{id}/assign', [MaintenanceController::class, 'assign'])
                ->middleware('access:SUPPORT');
            Route::post('maintenance-tickets/{id}/schedule', [MaintenanceController::class, 'schedule'])
                ->middleware('access:SUPPORT');
            Route::post('maintenance-tickets/{id}/start', [MaintenanceController::class, 'start'])
                ->middleware('access:SUPPORT');
            // Signing work off is what returns a vehicle to service, and
            // cancelling is what stops a fault holding it (BR-358).
            Route::post('maintenance-tickets/{id}/complete', [MaintenanceController::class, 'complete'])
                ->middleware('access:OPERATIONS');
            Route::post('maintenance-tickets/{id}/cancel', [MaintenanceController::class, 'cancel'])
                ->middleware('access:OPERATIONS');
        });

        // ---- Preventive maintenance (BG-16, BR-366) ----------------------
        Route::middleware('role:ADMIN')->group(function () {
            Route::get('preventive-maintenance', [PreventiveMaintenanceController::class, 'index']);

            Route::middleware('throttle:writes')->group(function () {
                Route::post('preventive-maintenance', [PreventiveMaintenanceController::class, 'store']);
                Route::delete('preventive-maintenance/{id}', [PreventiveMaintenanceController::class, 'destroy']);
            });
        });

        // ---- Smart consolidation (FR-13) ---------------------------------
        // Every route here is manager-only (BR-361). The four steps are kept
        // separate on purpose: approving a merge, telling the passengers, and
        // making it happen are three different acts, and BR-363 turns on the
        // order they occur in.
        Route::middleware('role:ADMIN')->group(function () {
            Route::get('consolidations', [ConsolidationController::class, 'index']);
            Route::get('consolidations/candidates', [ConsolidationController::class, 'candidates']);
            Route::get('consolidations/{id}', [ConsolidationController::class, 'show']);

            Route::middleware('throttle:writes')->group(function () {
                Route::post('consolidations', [ConsolidationController::class, 'store']);
                Route::post('consolidations/{id}/approve', [ConsolidationController::class, 'approve']);
                Route::post('consolidations/{id}/reject', [ConsolidationController::class, 'reject']);
                Route::post('consolidations/{id}/notify', [ConsolidationController::class, 'notify']);
                Route::post('consolidations/{id}/execute', [ConsolidationController::class, 'execute']);
            });
        });

        // ---- Trip corrections and attendance disputes (BR-258, BR-266) ---
        Route::get('trips/{id}/corrections', [TripRecoveryController::class, 'corrections']);
        Route::middleware(['role:ADMIN'])->group(function () {
            Route::get('attendance-discrepancies', [TripRecoveryController::class, 'discrepancies']);

            Route::middleware('throttle:writes')->group(function () {
                Route::post('trips/{id}/corrections', [TripRecoveryController::class, 'correct']);
                Route::post('attendance-discrepancies/{id}/review', [TripRecoveryController::class, 'review']);
            });
        });

        // ---- Service calendar (BR-264) -----------------------------------
        Route::get('service-calendar', [ServiceCalendarController::class, 'index']);
        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::post('service-calendar', [ServiceCalendarController::class, 'store']);
            Route::delete('service-calendar/{id}', [ServiceCalendarController::class, 'destroy']);
        });

        // ---- Schedules (FR-05) ------------------------------------------
        Route::get('schedules', [ScheduleController::class, 'index']);
        Route::get('schedules/{id}', [ScheduleController::class, 'show']);
        Route::middleware(['role:ADMIN', 'access:OPERATIONS', 'throttle:writes'])->group(function () {
            Route::post('schedules', [ScheduleController::class, 'store']);
            Route::put('schedules/{id}', [ScheduleController::class, 'update']);
            Route::patch('schedules/{id}/status', [ScheduleController::class, 'setActive']);
            Route::delete('schedules/{id}', [ScheduleController::class, 'destroy']);
        });
    });
});
