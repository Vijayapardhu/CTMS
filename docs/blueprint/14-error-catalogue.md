# 14 — Error Catalogue

Every error the system can produce, with a stable identifier. Developers, QA, support and
users all refer to the same code.

**The value of this document is shared vocabulary.** A parent says "it said ERR-152", support
looks it up, and the answer is "her pass expired — renew it here" without anyone guessing.

---

## Structure of an entry

| Field | Meaning |
|---|---|
| **ID** | Permanent. Never reused |
| **HTTP** | Status returned by the API |
| **Message** | What the **user** sees. Written for the person who hit it, not the developer |
| **Cause** | What actually happened |
| **Recovery** | What resolves it — for the user, and for support |
| **Rule** | The `BR-nnn` being enforced, where applicable |

### Principles

1. **The user message never exposes internals.** No SQL, no stack traces, no class names, no
   file paths, no table names (BR-511).
2. **The message names the fix, not just the fault.** "Licence expired" is a diagnosis;
   "This driver's licence expired on 3 May — record a renewal before assigning a bus" is
   actionable.
3. **The code is always shown to the user** for 5xx and for any error that may require support.
   Users who can quote a code get helped faster.
4. **A correlation identifier accompanies every error response**, so a support agent can find
   the exact request in the logs.
5. **A conflict is not a validation error.** 422 means the payload is malformed; 409 means the
   payload is fine and the world disagrees. Confusing the two makes clients retry things that
   will never succeed.

### Numbering blocks

| Block | Domain |
|---|---|
| ERR-001–049 | Authentication, authorization, transport |
| ERR-050–099 | Fleet |
| ERR-100–149 | Workforce |
| ERR-150–199 | Students & entitlement |
| ERR-200–249 | Network |
| ERR-250–299 | Trips |
| ERR-300–349 | Tracking & connectivity |
| ERR-350–399 | Safety, incidents, maintenance |
| ERR-400–449 | Notifications |
| ERR-450–499 | Finance |
| ERR-500–549 | Data & governance |
| ERR-900–949 | System & dependency failures |

---

## ERR-001–049 · Authentication & authorization

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-001** | 401 | "Invalid email or password." | Unknown account **or** wrong password — deliberately indistinguishable | Retry; use password reset | BR-007 |
| **ERR-002** | 429 | "Too many attempts. Try again in {n} minutes." | Rate limit reached | Wait; support can clear the limit for a locked-out staff member | BR-016 |
| **ERR-003** | 403 | "You do not have permission to view this." | Role or record-level authorization refused | None for the user. Support: confirm the user's role and relationship to the record | BR-164, BR-500 |
| **ERR-004** | 401 | "This account has been deactivated. Contact the transport office." | Account inactive | Staff reactivate via AD-104 | BR-005 |
| **ERR-005** | 401 | "Your session has expired. Please sign in again." | Token expired, revoked, or refresh token replayed | Sign in again | BR-012 |
| **ERR-006** | 401 | "This sign-in link is not valid for this action." | Token type mismatch (refresh used as access) | Sign in again. Repeated occurrences indicate a client bug — escalate | BR-013 |
| **ERR-007** | 401 | "This sign-in is not valid for this service." | Issuer or audience mismatch | Sign in again. Investigate: a token from another environment | BR-014 |
| **ERR-008** | 401 | "Your sign-in could not be verified." | Malformed or tampered token | Sign in again. Repeated: possible tampering — alert | — |
| **ERR-009** | 401 | "Please sign in to continue." | No credentials presented | Sign in | — |
| **ERR-010** | 422 | "This account already has a role assigned." | Attempt to assign a second role | — | BR-001 |
| **ERR-011** | 403 | "You cannot create an account with this role." | Non-admin requesting DRIVER or ADMIN | Ask an administrator to create it | BR-002, BR-003 |
| **ERR-012** | 403 | "You cannot change your own account status." | Self-deactivation attempt | Another administrator must do it | BR-009 |
| **ERR-013** | 409 | "At least two administrators must remain active." | Would leave fewer than two super admins | Promote another user first | BR-010 |
| **ERR-014** | 403 | "Two-factor authentication is required for staff accounts." | Staff without MFA | Enrol via SH-07 | BR-015 |
| **ERR-015** | 403 | "This link has not been verified yet." | Guardian acting on an unverified link | Await verification; contact the office | BR-017 |
| **ERR-016** | 403 | "This action is not available while impersonating." | Write attempted during read-only impersonation | Exit impersonation | BR-019 |
| **ERR-017** | 422 | "That verification code is not valid or has expired." | Bad or expired code | Resend | — |
| **ERR-018** | 422 | "This password does not meet the security policy." | Weak password | See the stated policy | — |
| **ERR-019** | 422 | "The current password is incorrect." | Wrong current password on change | Retry; use reset if forgotten | — |
| **ERR-020** | 422 | "The new password must be different from your current one." | Reuse attempt | Choose another | — |

---

## ERR-050–099 · Fleet

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-050** | 409 | "A bus cannot go from {from} to {to}. It must be serviced first." | Illegal status transition | Route via MAINTENANCE | BR-050, BR-051 |
| **ERR-051** | 409 | "This bus is on an active trip. Reassign or complete the trip first." | Retire/offline with unfinished trip | Reassign via AD-68 | BR-053 |
| **ERR-052** | 409 | "Capacity cannot drop below {n}, the passengers booked on an active trip." | Capacity reduction below booked count | Wait for the trip, or reassign passengers | BR-054 |
| **ERR-053** | 409 | "This bus cannot be used — its {document} expired on {date}." | Expired statutory document | Renew and record in AD-17. **No override exists** | BR-055 |
| **ERR-054** | 409 | "This bus is already assigned to another driver." | Duplicate assignment | Release the existing assignment first | BR-057 |
| **ERR-055** | 422 | "A bus with this registration number already exists." | Duplicate registration | Check for an existing record; it may be retired | BR-059 |
| **ERR-056** | 409 | "This bus is {status} and cannot be assigned to a driver." | Non-operational bus | Choose an available bus | BR-060 |
| **ERR-057** | 422 | "The odometer reading must be at least {n}." | Odometer went backwards | Re-read the odometer; escalate if genuinely lower | BR-061 |
| **ERR-058** | 422 | "Seating capacity must be between 1 and 120." | Implausible capacity | Correct the value | — |
| **ERR-059** | 404 | "Bus not found." | Unknown or retired identifier | Check the register | — |

---

## ERR-100–149 · Workforce

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-100** | 409 | "This driver's licence expired on {date}. Record a renewal before assigning." | Expired licence | Record renewal in AD-31 | BR-100, BR-101 |
| **ERR-101** | 403 | "Licence details can only be changed by the transport office." | Driver self-editing compliance data | Request correction via DR-24 | BR-102 |
| **ERR-102** | 409 | "A driver on a trip cannot change to {status} until the trip ends." | Illegal duty transition | End or reassign the trip | BR-103, BR-104 |
| **ERR-103** | 409 | "This driver is already on an active trip." | Double assignment | Complete or reassign | BR-105 |
| **ERR-104** | 409 | "This assignment would exceed the driver's duty-hour limit ({n}h remaining)." | Duty ceiling | Assign another driver | BR-106 |
| **ERR-105** | 409 | "Complete the vehicle inspection before starting this trip." | Missing or failed inspection | Complete DR-03. A failed safety item blocks entirely | BR-107, BR-108 |
| **ERR-106** | 422 | "This licence number is already registered to another driver." | Duplicate licence | Check for an existing record | BR-110 |
| **ERR-107** | 409 | "A driver profile can only be attached to a driver account." | Wrong role | Change the account's role first | BR-111 |
| **ERR-108** | 409 | "This account already has a driver profile." | Duplicate profile | Edit the existing one | BR-112 |
| **ERR-109** | 403 | "You can only change your own duty status." | Cross-driver action | — | BR-115, BR-116 |
| **ERR-110** | 409 | "This driver is not on duty." | Assignment to a driver on leave or off duty | Change duty status first | — |
| **ERR-111** | 409 | "This driver is on an active trip and cannot be removed." | Retire with active trip | Reassign first | BR-114 |

---

## ERR-150–199 · Students & entitlement

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-150** | 409 | "This student already has transport assigned on {route}." | Second active assignment | Clear the existing assignment first | BR-150 |
| **ERR-151** | 409 | "This student's record is {status} and cannot be assigned transport." | Inactive or suspended student | Reactivate first | BR-151 |
| **ERR-152** | 409 | "This student does not hold a valid transport pass." | Missing or expired pass | Issue or renew via AD-86 | BR-152 |
| **ERR-153** | 422 | "That stop is not on the selected route." | Stop/route mismatch | Choose a stop on the route | BR-153 |
| **ERR-154** | 422 | "That stop is not a {pickup\|drop-off} point." | Wrong stop type | Choose an appropriate stop | BR-154 |
| **ERR-155** | 422 | "Pickup and drop-off stops must be different." | Same stop for both | — | BR-155 |
| **ERR-156** | 403 | "Transport passes can only be issued by the transport office." | Self-service entitlement attempt | Request renewal via ST-17 | BR-157 |
| **ERR-157** | 409 | "This route is at capacity ({used}/{total})." | Capacity exceeded | Choose another route, or override with a reason | BR-159 |
| **ERR-158** | 409 | "A student profile can only be attached to a student account." | Wrong role | — | BR-161 |
| **ERR-159** | 409 | "This account already has a student profile." | Duplicate profile | — | BR-162 |
| **ERR-160** | 422 | "This registration number is already in use." | Duplicate registration | Check for an existing record; may be a re-enrolment | BR-163 |
| **ERR-161** | 404 | "Student not found." | Unknown identifier | — | — |

---

## ERR-200–249 · Network

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-200** | 409 | "Another stop already occupies position {n} on this route." | Sequence collision | Retry; the sequence is managed automatically | BR-200 |
| **ERR-201** | 409 | "This stop is assigned to {n} student(s). Reassign them before removing it." | Stop in use | Reassign via AD-40 | BR-202 |
| **ERR-202** | 409 | "This route has no stops and cannot be scheduled." | Empty route | Add stops via AD-53 | BR-203 |
| **ERR-203** | 409 | "This route is {status} and cannot take passengers." | Inactive route | Activate it, or choose another | BR-204 |
| **ERR-204** | 409 | "This route still has {n} student(s) and {m} active schedule(s)." | Retire blocked | Clear both first | BR-205 |
| **ERR-205** | 409 | "This bus is already scheduled on {route} at that time." | Bus double-booking | Choose another time or bus. The conflicting schedule is linked | BR-206 |
| **ERR-206** | 409 | "This driver is already scheduled on {route} at that time." | Driver double-booking | As above | BR-207 |
| **ERR-207** | 422 | "The arrival time must be later than the departure time." | Inverted window | Correct the times. Overnight runs need two schedules | BR-210, BR-211 |
| **ERR-208** | 422 | "These coordinates are outside the service area." | Bad coordinates | Check for transposed latitude and longitude | BR-214 |
| **ERR-209** | 422 | "A route with this {name\|code} already exists." | Duplicate | — | BR-215 |
| **ERR-210** | 404 | "Stop not found on this route." | Stop/route mismatch or unknown id | — | BR-216 |

---

## ERR-250–299 · Trips

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-250** | 409 | "This trip is {status} and can no longer be changed." | Terminal state | Corrections are attributed adjustments | BR-250 |
| **ERR-251** | 409 | "This trip cannot start: {specific reason}." | Composite start gate failed | The message names the failing condition | BR-251 |
| **ERR-252** | 409 | "This trip cannot start until {time}." | Outside start window | Wait, or ask operations to start it | BR-252 |
| **ERR-253** | 403 | "Only the assigned driver or the transport office can do this." | Wrong actor | — | BR-253 |
| **ERR-254** | 409 | **"The bus is full ({n}/{n}). This student cannot board."** | Capacity reached | Record as left behind; operations dispatches an alternative | BR-254, BR-255 |
| **ERR-255** | 422 | "The passenger count cannot go below zero." | Over-decrement | Recount | BR-256 |
| **ERR-256** | 409 | "This trip has closed. Attendance can no longer be changed here." | Frozen attendance | Operations record an attributed correction via AD-69 | BR-257 |
| **ERR-257** | 422 | "A reason is required to cancel this trip." | Missing reason | Provide one | BR-262 |
| **ERR-258** | 409 | "{Bus\|Driver} is no longer available. Choose another." | Eligibility lapsed between page load and commit | Re-select from the refreshed list | BR-267 |
| **ERR-259** | 409 | "A trip already exists for this schedule on this date." | Duplicate generation | None — generation is idempotent | BR-263 |
| **ERR-260** | 409 | "{date} is not an operating day ({reason})." | Non-operating day | Override with a reason if genuinely required | BR-264, BR-265 |
| **ERR-261** | 404 | "Trip not found." | — | — | — |

---

## ERR-300–349 · Tracking & connectivity

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-300** | 403 | "Position updates are only accepted from the assigned driver." | Spoofing or stale session | Sign in again on the correct device | BR-300 |
| **ERR-301** | 422 | *(not surfaced to the driver)* | Implausible position rejected | Silently dropped and logged; the driver is not interrupted | BR-301, BR-302 |
| **ERR-302** | — | "Location is unavailable. The trip will continue — mark stops manually." | GPS unavailable on device | Continue; students see estimated position | BR-306 |
| **ERR-303** | — | "Location permission is off. Your bus will not appear to students." | Permission missing or restricted | Fix in DR-27. **The most common cause of a bus vanishing from the map** | — |
| **ERR-304** | — | "Last seen {n} minutes ago." | Stale position | Informational. Operations investigates past the threshold | BR-305 |
| **ERR-305** | — | "You are offline. {n} actions will send when you reconnect." | No connectivity, driver app | **Normal operation.** Keep working | — |
| **ERR-306** | — | "You are offline. This action needs a connection." | No connectivity, student/parent app | Retry when connected | — |
| **ERR-307** | 409 | "This action was already recorded." | Duplicate sync by idempotency key | None — absorbed silently | — |
| **ERR-308** | 409 | "This trip was closed by the transport office while you were offline." | Sync conflict | Driver resolves explicitly; queued data is preserved, not discarded | — |
| **ERR-309** | 403 | "This session is no longer the active one for your account." | Second device signed in | Sign in again on this device to take over | — |

---

## ERR-350–399 · Safety, incidents, maintenance

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-350** | 409 | "A submitted report cannot be edited. Add a follow-up note instead." | Immutable incident | Append a note | BR-357 |
| **ERR-351** | 403 | "Only the maintenance team can return a bus to service." | Unauthorised certification | Maintenance closes the ticket | BR-358 |
| **ERR-352** | 403 | "This replacement needs manager approval (cost above {threshold})." | Approval threshold | Await approval | BR-359 |
| **ERR-353** | 409 | "This bus has {n} seats but {m} passengers need transferring." | Insufficient replacement capacity | Choose a larger bus or dispatch two | BR-360 |
| **ERR-354** | 409 | "Combined passengers ({n}) exceed the target bus capacity ({m})." | Consolidation over capacity | Not permitted | BR-362 |
| **ERR-355** | 409 | "This trip has passed the point where it can be merged." | Consolidation too late | Let both run | BR-364 |
| **ERR-356** | 409 | "This bus is overdue for preventive service ({n} days)." | Overdue maintenance past grace | Complete the service | BR-366 |
| **ERR-357** | 422 | "A photograph is required for a {severity} incident." | Missing evidence | Attach one | — |
| **ERR-358** | 422 | "A resolution note is required to close this." | Missing resolution | Provide one | — |
| **ERR-359** | 409 | "Critical alerts cannot be snoozed." | Snooze on critical | Acknowledge and act | — |

---

## ERR-400–449 · Notifications

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-400** | 422 | "This message has no recipients." | Empty audience | Widen the targeting | — |
| **ERR-401** | 403 | "Safety notifications cannot be turned off." | Mute attempt on a critical class | Explained in SH-14 | BR-404 |
| **ERR-402** | — | *(internal)* "Delivery failed on {channel}." | Channel failure | Retried; critical escalates. Visible in AD-94 | BR-406 |
| **ERR-403** | 429 | "This message is too large an audience to send without confirmation." | Above the broadcast threshold | Confirm explicitly | — |

---

## ERR-450–499 · Finance

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-450** | 409 | "A pass cannot be issued to an inactive student." | Inactive record | Reactivate first | BR-450 |
| **ERR-451** | 409 | "This student already holds an active pass until {date}." | Overlapping pass | Renew from the existing expiry instead | BR-451 |
| **ERR-452** | 409 | "A reconciled payment cannot be edited. Record an adjustment instead." | Immutable payment | Record an adjustment | BR-453 |
| **ERR-453** | 403 | "Suspending transport for non-payment requires manager approval." | Missing approval | Escalate | BR-454 |
| **ERR-454** | 402 | "Payment could not be completed." | Gateway declined | Retry or use another method. Entitlement unchanged | BR-452 |
| **ERR-455** | — | "Your payment is being confirmed. Your pass will update shortly." | Payment pending | Wait; no action needed | BR-452 |

---

## ERR-500–549 · Data & governance

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-500** | 403 | "Bulk export requires elevated permission and a stated reason." | Unauthorised bulk export | Request access | BR-502 |
| **ERR-501** | 409 | "This purge would remove records still referenced by {n} trips." | Referential integrity | Narrow the scope | BR-505 |
| **ERR-502** | 403 | "Audit records cannot be changed." | Audit mutation attempt | None — by design | BR-507 |
| **ERR-503** | 413 | "This export is too large to generate now. It will be sent to you when ready." | Above the synchronous threshold | Wait for the notification | — |

---

## ERR-900–949 · System & dependency failures

| ID | HTTP | Message | Cause | Recovery | Rule |
|---|---|---|---|---|---|
| **ERR-900** | 500 | "Something went wrong. Quote reference {correlation-id} if you contact support." | Unhandled server error | Retry. Support uses the reference to find the request | BR-511 |
| **ERR-901** | 503 | "The system is temporarily unavailable. Buses are still running." | Platform outage | Drivers continue offline; staff use the printed duty sheet | — |
| **ERR-902** | — | "Estimated arrival times are approximate — live routing is unavailable." | Maps provider down | ETA falls back to schedule-based | — |
| **ERR-903** | — | "Push notifications are delayed. Urgent messages are being sent by SMS." | Push provider down | Automatic fallback | BR-406 |
| **ERR-904** | — | "SMS delivery is delayed." | SMS gateway down | Push and in-app continue | — |
| **ERR-905** | 503 | "Payments are temporarily unavailable." | Gateway down | Existing passes unaffected | — |
| **ERR-906** | 504 | "This is taking longer than expected." | Upstream timeout | Retry | — |
| **ERR-907** | 409 | "Someone else changed this while you were editing." | Concurrent edit | Review the differences and re-apply | — |
| **ERR-908** | 400 | "This request could not be understood." | Malformed request | Client bug — escalate with the correlation id | — |
| **ERR-909** | 405 | "This action is not available on this endpoint." | Wrong method | Client bug | — |
| **ERR-910** | 404 | "This page or record no longer exists." | Unknown route or deleted record | Return to the list | — |

---

## Error handling rules for implementers

1. **Every error response carries `code`, `message`, `errors` and a correlation identifier.**
2. **Never invent a status code.** The set is 200, 201, 204, 400, 401, 402, 403, 404, 405, 409,
   413, 422, 429, 500, 503, 504.
3. **Never catch broadly and return success.** A swallowed exception is a silent data loss.
4. **Log at the right level:** 4xx at info (expected), 5xx at error (unexpected), security
   refusals (ERR-003, ERR-300) at warning with actor and target.
5. **Never log a secret.** Redaction happens before the log call, not after (BR-510).
6. **Client-visible messages are localised**; codes and log entries are not.
7. **A new error requires a new entry here first**, then the code — enforced by the review
   checklist in [15 §12](15-developer-handbook.md).

---

## Quick reference — the ten support will see most

| Code | Meaning | First question to ask |
|---|---|---|
| ERR-001 | Sign-in failed | "Are you using your college email?" |
| ERR-004 | Account deactivated | "When did you last ride?" |
| ERR-005 | Session expired | "Sign in again — did it work?" |
| ERR-152 | No valid pass | "When did you last renew?" |
| ERR-254 | Bus full | "Which stop were you at?" → escalate to operations |
| ERR-303 | Driver location permission off | "Open Settings → Location → Always" |
| ERR-305 | Driver offline | "Keep working — it will sync" |
| ERR-100 | Driver licence expired | "Has the renewal been recorded?" |
| ERR-053 | Bus document expired | Route to the maintenance coordinator |
| ERR-900 | Server error | "Read me the reference number" |
