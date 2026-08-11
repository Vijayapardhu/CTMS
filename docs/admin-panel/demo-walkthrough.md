# CTMS Admin Panel — walkthrough

A deterministic run through the operational panel, and then the same panel seen
by each of the four access levels.

It is written to be performed in front of somebody. Every step names what to
click, what should appear, and — where it matters more — **what should not**.

---

## 0. Before you start

```bash
cd backend      && php artisan serve --host=127.0.0.1 --port=8000
cd admin_panel  && npm run dev
```

`admin_panel/docs/development.md` rebuilds the development database from
scratch, seeds today's trips, and lists the accounts. You need four
administrators, one per access level. The verification runs use
`{level}@probe.ctms`.

**Google Maps.** Live Operations needs `VITE_GOOGLE_MAPS_API_KEY` in
`admin_panel/.env.local` — a browser key restricted to your origin, never the
server key. Without it the map renders its unavailable state and everything
else on the screen keeps working. Both paths are worth showing.

### Running the driver app alongside it

Verified on a physical handset (Motorola Edge 50 Fusion, Android 16) on
11 August 2026, against this same demonstration backend.

```bash
# The handset cannot reach the laptop's localhost. Tunnel it over the cable:
adb reverse tcp:8000 tcp:8000

# The Android Maps SDK key comes from the environment when local.properties
# has none, so the same key can drive it without a second copy on disk.
export GOOGLE_MAPS_ANDROID_API_KEY=$(grep '^GOOGLE_MAPS_API_KEY=' backend/.env | cut -d= -f2-)

cd driver_app
flutter run -d <device-id> --dart-define=FLAVOR=demo --dart-define=CTMS_API_BASE_URL=http://127.0.0.1:8000/api/v1
```

Sign in as `driver1@ctms.edu`. That account deliberately holds the trip that is
**out on the road**, so it has something live to show at any hour.

**Turn on Do Not Disturb first.** Personal notifications land on top of the app
and end up in any recording or screenshot.

---

## 1. Signing in — SUPER_ADMIN

Sign in as the super administrator.

**Watch for:** the sidebar appears only once the access level is known. There
is no frame in which Administration flashes and then disappears — an
authorisation flicker tells somebody exactly which door to try.

---

## 2. Dashboard

The landing screen composes six sources. There is no dashboard endpoint (G1-1),
so this is six requests presented as one picture.

**Show:** a metric reading `0` next to one reading `—`. Zero is the backend
saying there are no open incidents, which is the best news of the morning. An
em dash is the panel saying it could not find out. Turning a failed request
into a zero would tell a transport head the fleet is fine at exactly the moment
it might not be.

---

## 3. Live Operations

Running trips on a map, refreshed every 30 seconds, capped at twelve tracked
vehicles.

**Show:** select a trip — the ETA is fetched for that trip only. A stale
position is labelled stale using the server's own `is_stale`, not a timestamp
compared here.

**If the key is absent:** the map area explains that live mapping is
unavailable and the trip list, occupancy and delays continue to work. Nothing
is faked.

---

## 4. Fleet → a bus

Fleet lists every bus in one request. Readiness is deliberately **not** a
column — it is one call per bus, and twenty-eight buses would be twenty-eight
requests to fill it.

Open a bus: readiness, inspection history, documents, and the compliance
verdict. Statutory documents about to lapse are on the list screen, and zero of
them reads as good news rather than an empty red panel.

---

## 5. Trips → a trip

Open a completed trip.

**Show:** the stop history. A scheduled trip has none, and the screen shows the
**planned** route labelled as planned rather than an empty timeline pretending
history exists.

**Show:** "Who boarded" on a stop. As SUPER_ADMIN it opens; the named roster is
`TripPolicy::operate`, not `view`.

**Show:** Record a correction. It is a new record kept beside the original —
the original value is preserved and the response is 201, not 200.

---

## 6. Incidents

The queue defaults to open work, life-safety first. That ordering is the
server's, not a client-side sort over one page.

Open an incident and walk the workflow: **Acknowledge → Resolve → Close**.

**Show a refusal on purpose:** press Close on an incident that is not yet
resolved. The server answers 409 and the panel prints its sentence verbatim —
*"An incident cannot go from ACKNOWLEDGED to CLOSED."* — with "Nothing was
changed. This is a rule, not a fault." Do not paraphrase a safety refusal.

**Show:** Record as false alarm. The dialog says others may already have acted,
and that the original report is retained either way (BR-355).

---

## 7. Maintenance

Two tabs: tickets, and preventive services falling due. Due-ness is the
server's answer — it spans days elapsed and kilometres run.

Walk a ticket: **Assign → Schedule → Start → Complete**.

**Show:** after completing, the bus is still off the road, and the panel says
so and offers **Return to service** as a separate, named act. There is no
endpoint that does both (J10), and pretending otherwise would hide a decision
somebody is accountable for.

**Show a refusal:** try to cancel work that is already under way. 409 — work
under way is completed with what was found, not cancelled.

---

## 8. Recovery

**Replacements.** A breakdown on a running trip whose vehicle cannot continue
produces a recommendation by itself (BR-352). Approve it → the confirmation
says approving does not dispatch it. Then Dispatch, then Mark arrived.

**Consolidations.** Propose from the server's own candidate analysis. Approve
it. Then press **Execute** *before* telling the passengers: the dialog changes
its wording to say they have **not** been told, and that merging now means
somebody waits at a stop for a bus that is not coming. Cancel out, press **Tell
passengers**, then Execute — the wording changes back.

---

## 9. Attendance and corrections

The queue shows both figures: what the driver counted and what the boarding
record holds. Settle one.

**Show:** the dialog says both original figures stay exactly as they are. A
review explains a disagreement (BR-266); it does not amend the evidence of what
happened on a trip.

---

## 10. Announcements

Write a notice. It saves as a **draft** — nobody is told anything yet.

**Show:** press Publish. The dialog names the audience in a sentence — "every
student registered for transport" — not `STUDENTS`. Publishing and telling
every student in the college should not feel like the same click.

Withdraw it: the message says notifications already sent cannot be recalled,
because they cannot.

---

## 11. Alerts

Two panels that are never merged (G1-4).

**Show:** "My alerts" is this administrator's own inbox. "Delivery health" is
whether CTMS is reaching handsets at all. An unconfigured channel reads
"Nothing sent", never 0% — the server returns null there, and null is not zero.

Resend a failed delivery from the log.

---

## 12. Reports

Six reports, one endpoint each. The window is `from` and `to`, which is all
these endpoints accept.

**Show:** the fleet report has no date range at all, and says so.

**Show:** the button says **"Download this table"**, never "Export". There is
no server-side export endpoint (G1-3), and the caption states exactly what the
file contains. Download the occupancy table and open the CSV.

---

## 13. Governance — SUPER_ADMIN only

Three records that are never merged: the audit trail is about **change**, the
data-access log is about **reading**, retention is about **deletion**.

**Show:** the header says all three are read-only, and there is no edit or
delete control anywhere — the backend has no endpoint for one. That is what
makes the trail evidence.

Expand an entry to see before and after. Values are redacted by key on the way
to the screen.

---

## 14. Accounts — SUPER_ADMIN only

**Show:** there is no delete. Accounts are deactivated so the history that
references them still makes sense.

**Show:** your own row's Deactivate button is disabled — *"You cannot change
your own account's status."* The server refuses it outright; the panel explains
it.

**Show:** Export their data. It demands a written reason, and says the export
is itself written to the data access log with your name (BR-502).

---

# The same panel, four ways

This is the part worth showing management. Sign out and back in at each level.

## VIEWER — "Transport Assistant"

**Sees:** Dashboard, Live Operations, Trips, Routes, Fleet, Drivers,
Inspections, Maintenance, Incidents, Recovery, Students, Attendance, Alerts,
Announcements, Reports.

**Cannot see:** Audit, Data Access, Accounts. Type `/admin/audit` into the
address bar — a **Forbidden** screen, not a login form. They are signed in;
bouncing them to a sign-in page invites them to try another account.

**Cannot do:** anything. No acknowledge, no resolve, no note on an incident.
Reading an incident is not permission to write on it — that distinction is
G3-3, and before it was fixed a VIEWER completed a running trip.

**Also cannot:** open "Who boarded" on a stop. Oversight sees the counts, never
the named passengers.

## SUPPORT — "Transport Supervisor"

**Gains:** acknowledge and resolve an incident, add a note, raise a maintenance
ticket, assign it, schedule it, start it, dispatch a replacement, mark it
arrived, settle an attendance disagreement, resend a failed delivery.

**Still cannot:** close an incident, complete or cancel a ticket, approve or
reject a replacement, touch a consolidation, cancel or reassign a trip, correct
a trip, or change a bus's status.

**Show:** on a ticket that is under way, Complete is simply absent. BR-358 —
signing work off is the act that returns a vehicle to the road.

## OPERATIONS — "Transport Head"

**Gains:** everything operational. Close incidents, complete and cancel tickets,
return a bus to service, approve and reject replacements, run the whole
consolidation sequence, cancel and reassign trips, correct a trip, manage
drivers and students, publish announcements, and read a stop's named roster.

**Still cannot:** the audit trail, the data-access log, retention, accounts, or
a subject access export. Operating vehicles does not confer sight of who read
whose personal data.

**Show:** navigate to `/admin/audit` — Forbidden.

## SUPER_ADMIN — "System Administrator"

**Gains:** governance and accounts, and nothing else. It is not a bigger
version of OPERATIONS; it is a different job.

---

# What to say if somebody asks "is the UI the security?"

No — and this is worth being direct about.

Every control you have seen hidden or disabled is **user experience**. The
server decides what happens. The panel's permission model is generated from the
backend's own router (`capability-registry.md`), so the two cannot drift
without the build failing, and every one of the 111 refusals in the
verification run was checked against the database afterwards: 403, and the row
untouched.

When the server refuses anyway, the panel shows the forbidden state and does
not sign anybody out.

---

# Known limitations, said plainly

- **Route editing is not offered.** Placing stops needs a map and coordinates
  inside the service area. The endpoints exist and stay OPERATIONS-gated; a
  route missing half its stops still looks like a route.
- **An existing access level cannot be changed.** It is chosen when an
  administrator is created. `UpdateUserRequest` does not accept `access_level`
  and no other endpoint takes it, so there is no control for it.
- **Inspections is today's problem list, not a history.** There is no
  fleet-wide inspection endpoint (G2-2) and readiness is one request per bus,
  so the screen checks up to eight and says how many it did not check.
- **Reports are summaries, not row exports.** There is no server-side export;
  the CSV is built in the browser from the table on screen.
- **Browser verification is unavailable.** The Chrome extension has been
  disconnected since Slice 2. Everything above is verified at the API and test
  level, and is not reported as visual verification.
