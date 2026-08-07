# 13 — Business Rule Catalogue

**This document is the single source of truth for business rules.** Rules stated in
[01 §3](01-system-analysis.md#3-business-rules) are superseded by this catalogue; that
section's short tags (`BR-A1`, `BR-T5`…) map to the canonical `BR-nnn` identifiers here.

A screen specification never restates a rule. It references the identifier.

---

## How to read an entry

| Field | Meaning |
|---|---|
| **ID** | Permanent. Never reused, never renumbered. A retired rule is marked `SUPERSEDED`, not deleted |
| **Rule** | The invariant, stated as something the system enforces |
| **Why** | The consequence of not enforcing it. A rule without a stated consequence gets removed by someone who does not understand it |
| **Enforced at** | The layer that is authoritative. `DB` = database constraint · `SVC` = service layer · `REQ` = request validation · `POL` = policy · `JOB` = background process |
| **Error** | The `ERR-nnn` returned when violated — see [14](14-error-catalogue.md) |
| **Verified by** | The test that proves it. `—` means not yet written |

**Enforcement principle.** Where a rule protects data integrity under concurrency, it is
enforced at `DB` **and** `SVC`. A service check alone loses the race; a database constraint
alone produces an unusable error message. Both are required, and the catalogue says so.

---

## Numbering blocks

| Block | Domain |
|---|---|
| BR-001–049 | Identity & access |
| BR-050–099 | Fleet |
| BR-100–149 | Workforce (drivers) |
| BR-150–199 | Students & entitlement |
| BR-200–249 | Network (routes, stops, schedules) |
| BR-250–299 | Trips |
| BR-300–349 | Tracking |
| BR-350–399 | Safety, incidents, maintenance |
| BR-400–449 | Notifications |
| BR-450–499 | Finance |
| BR-500–549 | Data protection & governance |

Gaps within blocks are deliberate — new rules append to their block without disturbing
existing identifiers.

---

## BR-001–049 · Identity & access

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-001** | Every account holds exactly one role | Multi-role accounts make every authorization check ambiguous | DB, SVC | ERR-010 | `AuthenticationTest` |
| **BR-002** | Self-service registration may create a STUDENT account only | Otherwise anyone posting `"role":"ADMIN"` owns the fleet | REQ, POL | ERR-011 | `RegistrationTest::a_stranger_cannot_register_themselves_as_an_admin` |
| **BR-003** | Creating a DRIVER or ADMIN account requires an authenticated administrator | Same as BR-002, from the other direction | POL | ERR-011 | `RegistrationTest::an_admin_can_register_a_driver` |
| **BR-004** | Role values are compared as enums, never as case-folded strings | A casing mismatch silently grants or denies access, and fails open in some code paths | SVC | — | `RegistrationTest::lowercase_role_values_do_not_bypass_the_role_gate` |
| **BR-005** | A deactivated account is refused on the **next request**, not at token expiry | A revoked user must not keep working for the remaining hour of their token | SVC | ERR-004 | `AuthenticationTest::it_rejects_a_still_valid_token_once_the_account_is_deactivated` |
| **BR-006** | Changing a password revokes every session on every device | If the old password leaked, the attacker's session must die with it | SVC | — | `PasswordTest::changing_the_password_kills_every_existing_session` |
| **BR-007** | Sign-in failure responses are identical for unknown account and wrong password | Otherwise the endpoint is an account-enumeration oracle | SVC | ERR-001 | `AuthenticationTest::it_does_not_reveal_whether_an_email_is_registered` |
| **BR-008** | Password comparison runs even when no account matched | Response timing must not reveal existence either | SVC | — | `AuthenticationTest` (decoy hash) |
| **BR-009** | A user cannot deactivate or delete their own account administratively | Self-lockout is unrecoverable | POL | ERR-012 | `UserManagementTest::an_admin_cannot_deactivate_themselves` |
| **BR-010** | At least two administrators must remain active; the count is taken under a row lock and excludes the system identity | One locked-out administrator is an unrecoverable deployment — no endpoint can reactivate an account without an administrator to call it | SVC | ERR-013 | `the_last_administrators_cannot_be_deactivated`, `an_administrator_can_be_deactivated_while_enough_remain`, `the_system_identity_does_not_count_towards_the_floor`, `deactivating_a_driver_is_never_blocked_by_the_admin_floor` |
| **BR-011** | Deactivating an account immediately revokes its tokens | A deactivation that takes an hour to bite is not a deactivation | SVC | — | `UserManagementTest::deactivation_immediately_kills_the_users_sessions` |
| **BR-012** | Refresh tokens are single-use; presenting one consumes it | Makes a stolen refresh token detectable and time-bounded | SVC | ERR-005 | `AuthenticationTest::it_consumes_the_refresh_token_it_was_given` |
| **BR-013** | A refresh token cannot be presented as an access token, or vice versa | Token confusion escalates a long-lived credential into an API session | SVC | ERR-006 | `AuthenticationTest::it_refuses_a_refresh_token_used_as_an_access_token` |
| **BR-014** | Tokens are validated for issuer and audience, not only signature | A token minted for another service must not be accepted here | SVC | ERR-007 | `AuthenticationTest::it_rejects_a_token_issued_for_another_audience` |
| **BR-015** | Staff roles require multi-factor authentication | Staff accounts reach every child's location | SVC, POL | ERR-014 | — |
| **BR-016** | Credential endpoints are rate-limited per account **and** per origin | Per-account alone lets an attacker lock out a victim; per-origin alone is evaded by rotating the target | SVC | ERR-002 | `AuthenticationTest::it_rate_limits_repeated_login_attempts` |
| **BR-017** | A parent–student link requires institutional or student verification | A parent's assertion alone would let anyone track any child | POL, SVC | ERR-015 | — |
| **BR-018** | Guardian-link requests return an identical response whether or not the student exists | Otherwise the screen discovers which children ride which routes | SVC | — | — |
| **BR-019** | Impersonation is read-only by default and always logged as *acted-by X as Y* | An impersonated write attributed to the victim is an unaccountable action | POL, SVC | ERR-016 | — |
| **BR-020** | Break-glass access is time-boxed, reasoned, and alerts the Super Administrator immediately | Emergency access that is silent and permanent is just a backdoor | SVC, JOB | — | — |

---

## BR-050–099 · Fleet

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-050** | Bus status transitions follow the state machine in [08 §2.1](08-functionality.md) | Arbitrary status assignment puts unrepaired buses back on the road | SVC | ERR-050 | `BusManagementTest::a_broken_bus_cannot_go_straight_back_into_service` |
| **BR-051** | `BREAKDOWN → AVAILABLE` is forbidden; the path runs via `MAINTENANCE` | A bus that broke must be inspected before carrying students. **The single most important fleet guard** | SVC | ERR-050 | `BusManagementTest::a_broken_bus_can_return_to_service_after_maintenance` |
| **BR-052** | Status cannot be changed through a general update endpoint | A `status` field slipped into an edit payload bypasses BR-050 entirely | REQ | — | `BusManagementTest::status_cannot_be_changed_through_the_general_update` |
| **BR-053** | A bus assigned to an unfinished trip cannot be retired, taken offline, or sent to maintenance | The trip would reference a vehicle no longer in service | SVC | ERR-051 | `BusManagementTest::a_running_bus_cannot_be_retired` |
| **BR-054** | Seating capacity cannot be reduced below the booked count of any active trip | Silently strands the difference | SVC | ERR-052 | `a_bus_cannot_be_shrunk_below_its_booked_seats`, `a_bus_can_grow_at_any_time` |
| **BR-055** | A bus with an expired fitness certificate, insurance or permit cannot be assigned to a trip | Legal bar, not a warning. Operating uninsured voids cover for every passenger | SVC | ERR-053 | `BusDocumentTest::a_bus_with_an_expired_document_cannot_be_assigned_to_a_driver` |
| **BR-056** | Buses are retired (soft-deleted), never destroyed | Trips, incidents and attendance reference them for years | DB, SVC | — | `BusManagementTest::an_admin_can_retire_a_bus` |
| **BR-057** | One bus has at most one assigned driver at a time | Two drivers responsible for one vehicle means none is | **DB (unique index)**, SVC | ERR-054 | `DriverManagementTest::a_bus_cannot_be_assigned_to_two_drivers` |
| **BR-058** | A new bus always enters the fleet as `AVAILABLE` | A client-supplied initial status is an unvalidated transition | SVC | — | `BusManagementTest::a_new_bus_always_starts_available` |
| **BR-059** | Registration numbers are unique case-insensitively and stored normalised | `KA-01-AB-1234` and `ka-01-ab-1234` are one vehicle | DB, REQ | ERR-055 | `BusManagementTest::registration_numbers_are_compared_case_insensitively` |
| **BR-060** | A bus under maintenance or breakdown cannot be assigned to a driver | Assignment implies availability | SVC | ERR-056 | `DriverManagementTest::a_bus_under_maintenance_cannot_be_assigned` |
| **BR-061** | Odometer readings are monotonic, enforced on the model so the workshop, the pre-trip check and the trip close share one definition of "backwards" | A decreasing odometer is either a data error or tampering; both need catching | SVC | ERR-057 | `the_odometer_cannot_go_backwards_at_sign_off`, `a_forward_odometer_reading_updates_the_running_total`, `an_inspection_moves_the_running_total_forward` |

---

## BR-100–149 · Workforce

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-100** | A driver with an expired licence cannot be assigned a bus or start a trip | Legal bar; voids insurance | SVC | ERR-100 | `DriverManagementTest::a_driver_with_an_expired_licence_cannot_be_assigned_a_bus` |
| **BR-101** | A licence expiring today is already invalid for tomorrow's trip | Off-by-one here puts an unlicensed driver on the road | SVC | ERR-100 | `DriverManagementTest` |
| **BR-102** | Drivers cannot edit their own licence or compliance records | Self-service renewal defeats the compliance check entirely | POL | ERR-101 | `DriverManagementTest::a_driver_cannot_edit_their_own_licence_details` |
| **BR-103** | Driver status transitions follow [08 §2.2](08-functionality.md) | — | SVC | ERR-102 | `DriverManagementTest::a_driver_on_a_trip_cannot_jump_straight_to_leave` |
| **BR-104** | `ON_TRIP → LEAVE` is forbidden | A driver cannot go on leave while responsible for a bus full of students | SVC | ERR-102 | `DriverManagementTest` |
| **BR-105** | A driver holds at most one active trip | One person cannot drive two buses, and a system that believes they can reports both as staffed | DB, SVC | ERR-103 | `a_driver_cannot_run_two_trips_at_once`, `a_driver_is_free_again_once_the_trip_closes` |
| **BR-106** | Duty-hour ceilings are enforced at the trip-start gate: max daily, max continuous (a qualifying break resets the run), and min rest measured between *shifts* rather than between trips | Fatigue is the leading cause of transport incidents, and the failure mode nobody self-reports. A driver will not say they are too tired on the morning a colleague called in sick, so the roster refuses for them. Measured from trips actually driven — a hand-maintained shift table is always the optimistic one | SVC | ERR-104 | `a_driver_over_the_daily_ceiling_is_refused`, `a_driver_under_the_daily_ceiling_still_runs`, `continuous_driving_without_a_break_is_refused`, `a_qualifying_break_resets_the_continuous_run`, `a_short_rest_since_yesterdays_duty_is_refused`, `a_second_run_of_the_same_morning_is_not_treated_as_broken_rest`, `yesterdays_driving_does_not_count_against_today`, `the_refusal_says_which_ceiling_was_hit` |
| **BR-107** | A driver must pass the pre-trip inspection before a trip can start | The last chance to catch a fault while substitution is still possible | SVC | ERR-105 | `VehicleInspectionTest::a_bus_with_no_inspection_today_is_not_cleared` |
| **BR-108** | A failed safety-critical inspection item blocks the trip and opens a ticket automatically | A recorded fault that does not block is a fault that gets driven | SVC | ERR-105 | `VehicleInspectionTest::a_safety_critical_failure_takes_the_bus_out_of_service` |
| **BR-109** | A driver reporting a `CRITICAL` incident is stood down pending review, and the trip-start gate enforces it | Somebody who has just had an accident is not in a state to take another bus out, and a short-staffed morning must not get to make that call. Their *current* trip is untouched — stranding a bus mid-route is not a safety improvement | SVC | — | `a_driver_who_reports_a_critical_incident_is_stood_down`, `a_stood_down_driver_cannot_start_another_trip`, `a_service_incident_does_not_stand_a_driver_down`, `standing_a_driver_down_is_audited` |
| **BR-110** | Licence numbers are unique across drivers | — | DB, REQ | ERR-106 | `DriverManagementTest::it_rejects_a_duplicate_licence_number` |
| **BR-111** | A driver profile attaches only to an account holding the DRIVER role | A student with a driver profile is an authorization hole | SVC | ERR-107 | `DriverManagementTest::a_driver_profile_cannot_be_attached_to_a_student_account` |
| **BR-112** | An account holds at most one driver profile | — | DB, SVC | ERR-108 | `DriverManagementTest::an_account_cannot_have_two_driver_profiles` |
| **BR-113** | A driver profile cannot be re-pointed at a different user account | Re-pointing transfers an entire history to another person | REQ | — | `DriverManagementTest::a_driver_profile_cannot_be_repointed_at_another_account` |
| **BR-114** | Removing a driver releases their assigned bus | Otherwise the vehicle is stranded and unassignable | SVC | — | `DriverManagementTest::removing_a_driver_frees_their_bus` |
| **BR-115** | Drivers may set their own duty status but only on their own record | — | POL | ERR-109 | `DriverManagementTest::a_driver_cannot_set_another_drivers_status` |
| **BR-116** | Assigning a vehicle is never self-service | Operations decision with cost and safety implications | POL | ERR-109 | `DriverManagementTest::a_driver_cannot_assign_a_bus_to_themselves` |

---

## BR-150–199 · Students & entitlement

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-150** | A student holds at most one active transport assignment | Two assignments means two buses expect them and neither knows | DB, SVC | ERR-150 | `TransportAssignmentTest::reassigning_replaces...` |
| **BR-151** | Transport is assigned only to an `ACTIVE` student | — | SVC | ERR-151 | `TransportAssignmentTest::a_suspended_student...` |
| **BR-152** | Transport is assigned only to a student holding a valid, unexpired pass | Entitlement gate | SVC | ERR-152 | `TransportAssignmentTest::a_student_without_a_valid_pass...` |
| **BR-153** | The pickup stop must belong to the assigned route | Otherwise the student waits where the bus never goes | SVC | ERR-153 | `TransportAssignmentTest::a_stop_from_a_different_route...` |
| **BR-154** | The pickup stop must permit pickup; the drop-off stop must permit drop-off | — | SVC | ERR-154 | `TransportAssignmentTest::a_drop_off_only_stop...` |
| **BR-155** | Pickup and drop-off stops must differ | — | SVC | ERR-155 | `TransportAssignmentTest::the_pickup_and_drop_off_stops_must_differ` |
| **BR-156** | Suspending or deactivating a student clears their transport assignment | Otherwise they remain counted in occupancy planning for trips they may not board | SVC | — | `StudentManagementTest::suspending_a_student_clears...` |
| **BR-157** | Students cannot grant themselves a pass, extend expiry, or alter entitlement | The direct financial-fraud path | POL, REQ | ERR-156 | `StudentManagementTest::a_student_cannot_grant_themselves...` |
| **BR-158** | Students cannot alter their own registration number | It is the institutional identity key | REQ | — | `StudentManagementTest::a_student_cannot_change_their_own_registration_number` |
| **BR-159** | Assignments to a route must not exceed scheduled seat capacity minus the safety margin | Over-subscription strands the surplus, every day, silently | SVC | ERR-157 | `TransportAssignmentTest::assignment_is_refused_when_the_route_is_at_capacity` |
| **BR-160** | Over-subscription requires an explicit override with a recorded reason | Sometimes correct; never accidental | SVC | — | `TransportAssignmentTest::capacity_can_be_exceeded_with_a_stated_reason` |
| **BR-161** | A student profile attaches only to an account holding the STUDENT role | — | SVC | ERR-158 | `StudentManagementTest::a_student_profile_cannot_be_attached_to_a_driver_account` |
| **BR-162** | An account holds at most one student profile | — | DB, SVC | ERR-159 | `StudentManagementTest::an_account_cannot_have_two_student_profiles` |
| **BR-163** | Registration numbers are unique across students | — | DB, REQ | ERR-160 | `StudentManagementTest::it_rejects_a_duplicate_registration_number` |
| **BR-164** | A student may read and edit only their own record | Horizontal privilege escalation is the most common API defect | POL | ERR-003 | `StudentManagementTest::a_student_cannot_read_another_students_record` |
| **BR-165** | Role and account-active state are never accepted on a profile-edit endpoint | Mass-assignment privilege escalation | REQ | — | `UserManagementTest::a_user_cannot_promote_themselves_by_editing_their_profile` |

---

## BR-200–249 · Network

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-200** | A route's stops form a contiguous 1..N sequence, no gaps, no duplicates | Every downstream calculation — ETA, next stop, geofence order — assumes it | **DB (unique route+sequence)**, SVC | ERR-200 | `RouteStopSequencingTest` (all 37) |
| **BR-201** | Inserting a stop shifts subsequent stops; deleting one closes the gap | Maintains BR-200 automatically | SVC | — | `RouteStopSequencingTest::deleting_a_middle_stop_closes_the_gap` |
| **BR-202** | A stop cannot be removed while students are assigned to it | They would silently lose their pickup point | SVC | ERR-201 | `RouteStopSequencingTest::a_stop_with_assigned_students_cannot_be_deleted` |
| **BR-203** | A route with no stops cannot be scheduled | It cannot carry anyone anywhere | SVC | ERR-202 | `ScheduleManagementTest::a_route_with_no_stops_cannot_be_scheduled` |
| **BR-204** | Only an `ACTIVE` route may be scheduled or take passengers | — | SVC | ERR-203 | `ScheduleManagementTest::an_inactive_route_cannot_be_scheduled` |
| **BR-205** | A route cannot be retired while students are assigned or active schedules exist | — | SVC | ERR-204 | `RouteManagementTest::a_route_with_assigned_students_cannot_be_retired` |
| **BR-206** | On a given weekday, a bus cannot appear in two overlapping schedule windows | Physical impossibility; the timetable would promise two routes one vehicle | SVC | ERR-205 | `ScheduleManagementTest::a_bus_cannot_be_double_booked_on_the_same_day` |
| **BR-207** | The same rule applies to drivers | — | SVC | ERR-206 | `ScheduleManagementTest::a_driver_cannot_be_double_booked_on_the_same_day` |
| **BR-208** | Overlap is evaluated on half-open intervals: touching endpoints do not clash | A bus arriving at 09:00 is free to depart at 09:00 | SVC | — | `ScheduleTest::touching_windows_do_not_overlap` |
| **BR-209** | Conflict detection accounts for schedule validity windows | Two schedules in different terms do not clash | SVC | — | `ScheduleManagementTest::schedules_in_non_overlapping_terms_do_not_conflict` |
| **BR-210** | Arrival time must be strictly later than departure time | Overnight runs are modelled as two schedules | REQ | ERR-207 | `ScheduleManagementTest::arrival_must_be_later_than_departure` |
| **BR-211** | A partial schedule update is validated against the merged result, not the payload | Moving only the arrival time can still invert the window | REQ | ERR-207 | `ScheduleManagementTest::a_partial_update_is_validated_against_the_merged_result` |
| **BR-212** | Editing a schedule does not retroactively alter already-generated trips | Silently rewriting today's trip while a bus is en route | SVC | — | — |
| **BR-213** | Changing a route or timetable affecting assigned students requires notification and an effective date | Changing a child's stop without telling them is a safety failure | SVC | — | `changing_a_route_notifies_the_students_riding_it`, `a_route_update_that_changes_nothing_notifies_nobody` |
| **BR-214** | Stop coordinates must fall inside the configured service area | Catches transposed lat/long and unit errors | REQ | ERR-208 | `RouteStopSequencingTest::it_rejects_coordinates_outside_the_service_area` |
| **BR-215** | Route names and codes are unique | — | DB, REQ | ERR-209 | `RouteManagementTest::it_rejects_a_duplicate_route_code` |
| **BR-216** | Stops are addressed only through their parent route | A standalone stop-write endpoint bypasses route-level authorization | Routing, POL | — | `RouteStopSequencingTest::a_stop_cannot_be_edited_through_a_different_route` |

---

## BR-250–299 · Trips

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-250** | Trip status moves forward only; terminal states never reopen | A reopened trip corrupts attendance and reporting | SVC | ERR-250 | `TripLifecycleTest::a_completed_trip_cannot_be_reopened` |
| **BR-251** | A trip starts only with an available bus, an available licensed driver, and a passed inspection | The composite safety gate | SVC | ERR-251 | `TripLifecycleTest::a_trip_cannot_start_without_an_inspection` |
| **BR-252** | A trip cannot start more than the configured window before scheduled departure | Prevents a bus leaving an hour early with nobody aboard | SVC | ERR-252 | `TripLifecycleTest::a_trip_cannot_start_before_its_window_opens` |
| **BR-253** | Only the assigned driver or an operations controller may start or end a trip | — | POL | ERR-253 | `TripLifecycleTest::another_driver_cannot_start_someone_elses_trip` |
| **BR-254** | Occupancy must never exceed seating capacity; boarding is refused at capacity | Overloading is illegal and unsafe | SVC | ERR-254 | `GeofenceAndBoardingTest::boarding_is_refused_at_capacity` |
| **BR-255** | A refused boarding creates a "left behind" record and notifies operations | Silence here is what destroys parental trust | SVC, JOB | — | `GeofenceAndBoardingTest::students_left_behind_are_recorded_and_notified` |
| **BR-256** | Passenger count cannot go below zero | — | SVC | ERR-255 | `GeofenceAndBoardingTest::the_count_cannot_go_below_zero` |
| **BR-257** | Attendance is frozen when a trip closes | — | SVC | ERR-256 | `GeofenceAndBoardingTest::counting_is_refused_once_the_trip_closes` |
| **BR-258** | Corrections after close are new attributed records that preserve the original; the correctable field list excludes `status`, attribution and timestamps | An overwritten attendance record cannot answer "where was my child" — and the excluded fields are precisely the ones somebody would change to hide something | SVC | — | `a_correction_keeps_the_value_it_replaced`, `a_correction_records_who_made_it_and_why`, `a_correction_requires_a_reason`, `the_status_of_a_trip_cannot_be_corrected`, `the_driver_of_a_trip_cannot_be_corrected_away`, `a_running_trip_is_changed_directly_not_corrected`, `a_driver_cannot_correct_a_trip` |
| **BR-259** | A trip with no position update for the configured threshold raises a `TRACKING_LOST` incident — its own type, never shared with a driver-reported one, and never accepted from a client | A bus that vanishes from the map must be noticed by someone; if it shared a type with congestion, a driver's traffic report would suppress the alert for the rest of the run | JOB | — | `a_trip_that_stops_reporting_raises_an_incident`, `a_driver_reporting_traffic_does_not_mask_a_silent_bus`, `a_driver_cannot_claim_their_own_bus_went_silent`, `a_stalled_trip_is_reported_once_not_every_five_minutes`, `a_silent_bus_stays_on_the_road`, `a_finished_trip_is_never_flagged_as_stalled` |
| **BR-260** | A trip left running past scheduled arrival plus buffer is auto-closed and flagged for review | — | JOB | — | `TripLifecycleTest::an_overdue_running_trip_is_closed_automatically` |
| **BR-261** | An auto-closed trip is distinguishable from a normally closed one in every report | Otherwise the punctuality figures are fiction | DB, SVC | — | `TripLifecycleTest::auto_closed_trips_can_be_filtered_for_review` |
| **BR-262** | Cancelling a trip requires a reason and notifies every assigned passenger and guardian | — | REQ, SVC | ERR-257 | `TripLifecycleTest::cancelling_requires_a_reason` |
| **BR-263** | Trip generation is idempotent per (schedule, date) | A re-run must not double the day's trips | JOB, DB | — | `TripGenerationTest::running_generation_twice_creates_nothing_the_second_time` |
| **BR-264** | Trip generation skips non-operating days from the service calendar | — | JOB | — | `TripGenerationTest::nothing_is_generated_on_a_holiday` |
| **BR-265** | A trip on a non-operating day requires an explicit override with a reason | — | SVC | — | `TripGenerationTest::an_ad_hoc_trip_on_a_holiday_needs_an_override_reason` |
| **BR-266** | A headcount that disagrees with boarding events is recorded as a discrepancy, never reconciled away; a review explains it and cannot alter either figure | The discrepancy is the signal. The system cannot tell which number is wrong, and picking one destroys the only evidence that a passenger is unaccounted for | SVC, JOB | — | `a_headcount_exceeding_the_log_is_recorded`, `a_log_exceeding_the_headcount_is_recorded_too`, `both_figures_survive_the_review`, `a_review_cannot_alter_either_count`, `a_review_requires_a_note`, `a_discrepancy_cannot_be_reviewed_twice`, `reconciliation_does_not_duplicate_on_a_second_run` |
| **BR-267** | Reassignment re-checks eligibility at commit time, not at list time | The candidate may have become ineligible while the page was open | SVC | ERR-258 | `TripLifecycleTest::a_bus_with_an_expired_document_cannot_be_assigned` |

---

## BR-300–349 · Tracking

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-300** | Position is accepted only from the assigned driver of a `RUNNING` trip | Otherwise anyone can spoof a bus's location | POL, SVC | ERR-300 | `GpsIngestionTest::another_driver_cannot_report_a_position` |
| **BR-301** | Positions failing plausibility checks are rejected and logged, never stored as truth | One bad point corrupts every ETA downstream | SVC | ERR-301 | `GpsIngestionTest::an_implausible_jump_is_rejected` |
| **BR-302** | Plausibility = impossible speed, impossible jump, outside service region, accuracy beyond threshold | — | SVC | ERR-301 | `GpsIngestionTest::an_implausible_speed_is_rejected` |
| **BR-303** | Position ingest is rate-limited per device | A compromised device must not flood the pipeline | SVC | ERR-002 | `position_reporting_is_rate_limited` |
| **BR-304** | Live position is visible only to entitled viewers, and only while the trip runs | Continuous location of minors is the highest-sensitivity data here | POL | ERR-003 | `BroadcastAuthorizationTest::a_student_on_another_route_cannot_subscribe` |
| **BR-305** | A position older than the staleness threshold is presented as stale, with its age | A stale dot read as live sends a student into the road | UI | — | `GpsIngestionTest::the_live_view_marks_an_old_position_as_stale` |
| **BR-306** | Loss of GPS does not stop the trip | The bus is still running; the system must not pretend otherwise | SVC | — | `GeofenceAndBoardingTest::a_driver_can_mark_arrival_manually_when_gps_fails` |
| **BR-307** | Raw location traces of minors are purged on the retention schedule — the trace only, never the trip or its attendance | The second-by-second breadcrumb of where a child was is the most sensitive thing here. What survives is the answer to "was my child on that bus" (BR-505) | JOB | — | `old_location_traces_are_purged`, `recent_traces_survive`, `the_attendance_record_is_never_purged`, `a_trace_under_an_open_discrepancy_is_not_purged` |
| **BR-308** | Geofence entry fires "approaching" once per stop per trip | Repeated alerts train users to ignore them | SVC, JOB | — | `GeofenceAndBoardingTest::approaching_notifies_once_per_stop_per_trip` |

---

## BR-350–399 · Safety, incidents, maintenance

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-350** | An incident opens a maintenance ticket **only where the vehicle is implicated** — every Class B type, plus `ACCIDENT` | An incident with no ticket is a fault nobody fixes; a ticket with no fault grounds a serviceable bus and buries the workshop | SVC | — | `a_breakdown_opens_a_maintenance_ticket`, `a_medical_emergency_does_not_send_the_bus_to_the_workshop`, `a_collision_does_send_the_bus_to_the_workshop` |
| **BR-351** | A Class A or Class B incident removes the bus from service, unless the driver reports the vehicle can continue | — | SVC | — | `an_sos_takes_the_bus_out_of_service`, `a_driver_reporting_the_vehicle_can_continue_keeps_it_in_service`, `a_service_incident_does_not_take_the_bus_off_the_road` |
| **BR-352** | A Class A or Class B incident triggers a replacement recommendation | — | SVC | — | `a_breakdown_recommends_a_replacement` |
| **BR-353** | An SOS bypasses batching, quiet hours, mute and preference, on every channel at once | — | SVC | — | `an_sos_notifies_operations_critically` |
| **BR-354** | An SOS works without data connectivity — see [the split below](#br-354--who-owns-which-half) | An SOS that needs a connection is not an SOS | SVC + Client | — | `a_replayed_offline_report_is_absorbed`, `an_offline_report_keeps_its_original_timestamp` (backend half) |
| **BR-355** | A cancelled SOS is recorded, never erased | — | SVC | — | `a_cancelled_sos_is_recorded_not_erased` |
| **BR-356** | An unacknowledged Class A incident escalates automatically after 2 minutes; Class B after 15; Class C never | — | JOB | — | `an_unacknowledged_life_safety_incident_escalates`, `an_acknowledged_incident_does_not_escalate`, `escalation_happens_once_per_incident`, `a_service_incident_never_escalates` |
| **BR-357** | Incident reports are immutable once submitted; follow-up is appended | Editable incident records are worthless as evidence | SVC | ERR-350 | `follow_up_is_appended_as_notes` |
| **BR-358** | A bus returns to service only when its ticket is closed by an authorised role | The system must never return an uncertified bus to relieve pressure | POL, SVC | ERR-351 | `an_incident_cannot_be_closed_while_its_ticket_is_open`, `the_broken_bus_stays_out_of_service_after_the_handover` |
| **BR-359** | A replacement is *recommended* by the system and *approved* by a person; the approver is recorded | Dispatching costs money and pulls a vehicle off another duty | POL, SVC | ERR-352 | `a_recommendation_is_not_a_dispatch`, `approval_records_who_committed_the_money`, `a_replacement_cannot_be_dispatched_before_it_is_approved`, `a_driver_cannot_approve_a_replacement` |
| **BR-360** | A replacement must have capacity for the transferred passengers — enforced on the recommendation *and* on an operator override | A bus that cannot carry everyone is a second problem, not a solution | SVC | ERR-353 | `a_replacement_too_small_to_seat_everyone_is_not_offered`, `an_override_is_still_subject_to_capacity`, `an_unfulfillable_request_cannot_be_approved` |
| **BR-361** | Consolidation requires manager approval; the system proposes and expires its own proposals, but never executes one | Merging two services is a decision about people's journeys home, taken to save fuel. Not a dispatcher's call, and certainly not a driver's | POL | ERR-354 | `a_driver_cannot_approve_a_merge`, `a_student_cannot_see_the_proposal_queue`, `approval_records_who_decided`, `rejection_requires_a_reason`, `a_proposal_changes_nothing_on_its_own`, `a_proposal_nobody_decided_expires`, `an_expired_proposal_cannot_be_approved` |
| **BR-362** | Combined passengers must not exceed the target bus's capacity — re-checked at approval, not only at proposal | People board while a proposal sits in the queue, and the figures it was justified by stop holding | SVC | ERR-354 | `a_merge_that_would_not_fit_is_refused_at_proposal`, `a_merge_that_stops_fitting_is_refused_at_approval` |
| **BR-363** | Every affected passenger is notified **before** a consolidation takes effect; notification is a precondition of execution, not a side effect | Being told after your bus was cancelled is not a notification, it is an apology | SVC | — | `a_merge_cannot_execute_before_the_passengers_are_told`, `the_notification_names_the_bus_to_look_for`, `passengers_are_not_told_before_a_manager_has_decided`, `passengers_are_not_told_about_a_mere_proposal`, `notifying_twice_does_not_message_people_twice` |
| **BR-364** | A consolidation cannot execute against a trip already past its divergence point — the last stop the two routes have in common | Past it the target bus is on different roads and cannot collect the people the source was going to | SVC | ERR-355 | `the_divergence_point_is_recorded_at_proposal`, `a_bus_past_the_divergence_point_cannot_be_merged`, `a_bus_short_of_the_divergence_point_can_be_merged`, `routes_that_never_meet_cannot_be_merged` |
| **BR-365** | Passengers aboard and passengers waiting receive **different** incident messages | Their situations and required actions are different — "stay on the bus" and "do not keep waiting" are opposite instructions | SVC | — | `passengers_aboard_and_waiting_get_different_messages`, `a_service_incident_does_not_interrupt_passengers` |
| **BR-366** | Overdue preventive maintenance blocks assignment past the configured grace period. Grace applies to the date axis only — a distance overrun has none | The grace exists so a service falling due does not cancel a route on the day; running indefinitely past it is what the rule stops. A bus that has done the kilometres has done them | JOB, SVC | ERR-356 | `a_bus_within_the_grace_period_still_runs`, `a_bus_past_the_grace_period_does_not_run`, `the_block_names_the_service_that_is_overdue`, `a_distance_overrun_has_no_grace`, `servicing_the_bus_clears_the_block`, `the_scan_opens_a_ticket_for_a_service_that_has_fallen_due` |
| **BR-367** | Photographs attached to incidents are stored outside the web root and served through an authorising check; the stored path never appears in an API response | A path in a response is a URL somebody will eventually fetch without a check | SVC | — | `the_photograph_path_is_never_returned` |

### BR-354 — who owns which half

BR-354 is the one rule in this catalogue that **cannot be satisfied by the backend alone**, and
writing it as a single line hides that. An SOS raised in a dead spot has to work when the
server is unreachable, so the rule is split, and each half is verifiable on its own.

**Backend responsibilities** — testable today, and tested:

| # | Responsibility | How it is met |
|---|---|---|
| B1 | Accept a report that arrives late without treating it as a new one | `idempotency_key` on `POST /incidents`; a replayed key returns the original record rather than creating a second |
| B2 | Preserve when it *happened*, not when it *arrived* | Client-supplied `reported_at` is honoured; without it every delayed SOS looks like it happened when signal returned, and the response-time record becomes fiction |
| B3 | Accept a life-safety report with the type alone | No description, no photograph, no position required — validation gets *lighter* as severity rises |
| B4 | Dispatch on receipt, at `CRITICAL`, bypassing every suppression path | BR-353 |
| B5 | Start the escalation clock from receipt, and record both timestamps | An incident queued for 20 minutes offline must not be treated as 20 minutes ignored |
| B6 | Never discard a report it cannot fully process | Failure to notify is logged and retried; the incident row is already committed (BR-408) |

**Client responsibilities** — specified here, verified at L3 on the driver app:

| # | Responsibility | Why the backend cannot do it |
|---|---|---|
| C1 | Persist the SOS to local storage **before** attempting the network | The process may be killed mid-request; an SOS that exists only in a pending HTTP call is an SOS that never happened |
| C2 | Show the driver that it is queued, not that it is sent | Telling someone help is coming when it is not is worse than telling them nothing |
| C3 | Retry upload with backoff until acknowledged, across app restarts | The server has no way to ask for something it was never told about |
| C4 | Offer a device-native phone call to the emergency contact as an immediate fallback | The cellular voice network survives conditions the data network does not |
| C5 | Offer a device-native SMS with the last known coordinates | SMS is store-and-forward; it delivers when signal returns without the app running |
| C6 | Stamp `reported_at` from the device clock at the moment the button was pressed | Only the device knows this |
| C7 | Generate and reuse one `idempotency_key` per press, never per attempt | A key regenerated on retry turns one emergency into five |

**The consequence for completion:** BR-354 can reach L1 on its backend half and **cannot reach
L3 until the driver app exists**. It is recorded as partially verified rather than verified,
and 4C is not permitted to claim it as complete.

---

## BR-400–449 · Notifications

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-400** | A notification reaches only users with a legitimate relationship to the event | — | SVC | — | `NotificationApiTest::a_user_never_sees_another_users_notifications` |
| **BR-401** | Entitlement is evaluated at **dispatch** time, not at event time | A link revoked between the two must suppress delivery | SVC | — | `NotificationDispatchTest::a_deactivated_recipient_is_not_notified` |
| **BR-402** | Safety-critical classes ignore quiet hours, mute and channel preference | — | SVC | — | `PreferenceResolverTest::a_critical_notification_ignores_quiet_hours` |
| **BR-403** | Non-critical classes honour quiet hours, mute, preference, dedup and batching | — | SVC | — | `PreferenceResolverTest::a_muted_category_suppresses_its_channels` |
| **BR-404** | Safety-critical classes are not mutable by the user, and the UI shows them as locked with an explanation | Hiding the fact that they cannot be muted breeds distrust | UI, SVC | — | `PreferenceResolverTest::a_non_mutable_category_ignores_a_stale_mute` |
| **BR-405** | One event never produces duplicate notifications to one recipient | — | SVC | — | `NotificationDispatchTest::the_same_event_does_not_notify_twice` |
| **BR-406** | Delivery failure retries with backoff; critical classes escalate to an alternate channel | — | JOB | — | `NotificationDeliveryTest::a_transient_failure_schedules_a_retry` |
| **BR-407** | Every dispatch is recorded with recipient, channel, template and outcome | "Did they get told?" must be answerable | SVC | — | `NotificationDispatchTest::suppressed_channels_are_recorded_with_a_reason` |
| **BR-408** | Notification failure never blocks the operation that published the event | A push outage must not stop buses | SVC | — | `EventIntegrationTest::a_notification_failure_never_breaks_the_operation` |

---

## BR-450–499 · Finance

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-450** | A pass cannot be issued to an inactive student | — | SVC | ERR-450 | — |
| **BR-451** | Overlapping active passes for one student are refused | — | DB, SVC | ERR-451 | — |
| **BR-452** | Payment confirmation, not payment initiation, grants entitlement | — | SVC | — | — |
| **BR-453** | A reconciled payment cannot be edited, only adjusted by an attributed correction | — | SVC | ERR-452 | — |
| **BR-454** | Suspending transport for non-payment requires manager approval and a notice period | — | POL, SVC | ERR-453 | — |
| **BR-455** | A student is never removed from a bus mid-journey for a billing reason | Financial enforcement never strands a child | SVC | — | — |
| **BR-456** | A pass expiring mid-journey does not interrupt that journey | Enforcement applies at the next boarding | SVC | — | — |
| **BR-457** | Finance roles have no access to live location or continuous attendance traces | No billing purpose justifies tracking a child | POL | ERR-003 | — |

---

## BR-500–549 · Data protection & governance

| ID | Rule | Why | Enforced at | Error | Verified by |
|---|---|---|---|---|---|
| **BR-500** | Minors' location and attendance are restricted to: the student, verified guardians, on-duty operations, auditors | The core privacy commitment of the product | POL | ERR-003 | — |
| **BR-501** | Every staff access to a student's personal data is logged with the accessing identity, in an append-only `data_access_logs` table separate from the audit trail. A student reading their own record is not staff access and is not logged | The audit trail records *writes*. "Who looked at this child's location history" is the question asked when something has gone wrong, and a write-only trail cannot answer it | SVC | — | `a_member_of_staff_opening_a_students_record_is_logged`, `a_student_reading_their_own_record_is_not_logged_as_staff_access`, `a_subject_access_export_is_recorded_against_the_person_who_ran_it`, `an_access_record_cannot_be_deleted` |
| **BR-502** | Bulk personal-data export requires elevated privilege, a stated reason, and a high-visibility audit entry | Bulk export is how data leaves in quantity | POL, SVC | ERR-500 | `a_subject_access_export_requires_a_stated_reason`, `a_driver_cannot_export_a_students_record`, `a_student_cannot_export_another_students_record` |
| **BR-503** | Exports respect field-level permissions — an export cannot reach data the role cannot see on screen | The most common permission bypass in reporting features | SVC | — | `a_report_never_names_a_student` (reports); export path is role-gated but **not yet field-filtered — see technical debt** |
| **BR-504** | Retention periods are per data class and enforced by automated purge, with a dry pass recorded before every live one | The dry pass is the only record of what a purge was about to do, and the only thing that makes a wrong window visible before it has deleted a term of traces | JOB | — | `old_location_traces_are_purged`, `recent_traces_survive`, `a_dry_run_deletes_nothing`, `a_purge_records_what_it_did`, `old_notifications_are_purged` |
| **BR-505** | A purge refuses to run where it would break referential history — attendance, trips and incidents are never purgeable, and a trace under an open discrepancy is left alone | No retention policy is worth losing the answer to "was my child on that bus" | JOB | ERR-501 | `the_attendance_record_is_never_purged`, `a_trace_under_an_open_discrepancy_is_not_purged` |
| **BR-506** | All data about one person can be located and exported for a subject-access request | Legal obligation | SVC | — | `the_export_returns_the_persons_own_record` |
| **BR-507** | Audit records are append-only; no role may edit or delete them. Enforced on the model, not only in a policy, and there is no write route at all | A policy guards the HTTP surface; the realistic way an audit row gets rewritten is a service doing it directly | DB, POL | ERR-502 | `an_audit_record_cannot_be_edited`, `an_audit_record_cannot_be_deleted`, `there_is_no_write_endpoint_on_the_audit_trail`, `a_driver_cannot_read_the_audit_trail` |
| **BR-508** | Every mutation writes an audit record with actor, action, entity, before, after, address and correlation id | — | SVC | — | `BusManagementTest::a_status_change_is_audited_with_both_values` |
| **BR-509** | Audit writes never fail the operation being audited | A failed log must not roll back a completed trip | SVC | — | `a_failing_audit_write_does_not_fail_the_trip` |
| **BR-510** | Secrets are never written to the audit trail, logs, or error responses | — | SVC | — | `PasswordTest::it_does_not_write_the_password_into_the_audit_trail` |
| **BR-511** | Error responses never expose SQL, stack traces, file paths or class names | Each is a map of the system for an attacker | SVC | — | `an_unexpected_failure_reveals_nothing_about_the_internals`, `a_missing_endpoint_does_not_describe_the_router` |
| **BR-512** | The system acts as an attributable actor in the audit trail, like any human. The identity is a real user row that can never authenticate (`is_active = false`), is unmanageable through the API, and is excluded from staff lists | "Who cancelled this trip?" must never answer "nobody" — and the answer must not be an account somebody could log into | SVC | — | `the_system_proposes_under_its_own_identity`, `the_system_identity_can_never_log_in`, `the_system_identity_is_not_a_manageable_account`, `the_system_identity_is_absent_from_the_staff_list` |

---

## Coverage summary

| Block | Rules | Verified | Unverified |
|---|---|---|---|
| Identity & access | 20 | 12 | 8 |
| Fleet | 12 | 9 | 3 |
| Workforce | 17 | 12 | 5 |
| Students & entitlement | 16 | 16 | 0 |
| Network | 17 | 16 | 1 |
| Trips | 18 | 15 | 3 |
| Tracking | 9 | 7 | 2 |
| Safety & maintenance | 18 | 0 | 18 |
| Notifications | 9 | 9 | 0 |
| Finance | 8 | 0 | 8 |
| Data protection | 13 | 3 | 10 |
| **Total** | **157** | **99** | **58** |

**Read this honestly.** 99 of 157 rules have a test proving they hold. The unverified 58 are
not "probably fine" — they are unproven, and several of them (BR-254, BR-300) are
already implemented in code with no test behind them. Closing this table is the definition of
done for the remaining modules; see [16](16-module-completion-criteria.md).

---

## Governance

- A rule is added here **before** the code that enforces it
- A rule change requires the same review as a schema change
- A superseded rule is marked `SUPERSEDED by BR-nnn` and kept, never deleted — old incident
  reports were decided under it
- Every `SVC`-enforced rule needs a test asserting **both** directions: permitted succeeds,
  forbidden fails with the right error
- The pull-request checklist in [15 §12](15-developer-handbook.md) requires new business logic
  to name the BR identifier it implements
