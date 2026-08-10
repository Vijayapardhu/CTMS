# The capability registry

The panel's permission model is **generated from the backend**, not written by
hand. This document explains what is generated, what is declared, what proves it
correct, and what to do when it drifts.

The short version: the panel and the server should never be able to disagree
about who may do what, and if they do, the build should fail rather than the
operator should find out.

---

## 1. Why it is generated

Three authorisation defects were found in this codebase by probing the running
server (`rbac-audit.md`):

| | What it was | How it was found |
|---|---|---|
| G3-1 | Ten admin-only mutations with no `RequireAccessLevel` at all | Mechanical enumeration of the router |
| G3-2 | Three subject-scoped mutations admitting any administrator | Probing with a second account |
| G3-3 | Fourteen driver-shared endpoints open to read-only staff — a VIEWER **completed a running trip** | Probing with a valid payload |

Every one of them was a place where a hand-maintained frontend model and the
server had quietly diverged. A hand-written matrix cannot catch that, because it
is the thing that is wrong. So the map is derived from the router instead.

---

## 2. The artifacts

```
backend/tools/derive-capability-map.py     the generator — the only thing to edit
docs/admin-panel/capability-map.json       generated, committed, human-readable
admin_panel/src/auth/capabilities.generated.ts   generated, committed, typed
```

Regenerate with either:

```bash
cd backend      && python tools/derive-capability-map.py
cd admin_panel  && npm run capabilities
```

Both write the same bytes — output paths are anchored on the generator's own
location, not the shell's working directory.

**Never edit the two generated files.** They carry a header saying so. Editing
them survives exactly until the next regeneration.

### Determinism

Same backend, byte-identical output. Verified by regenerating from two different
working directories and comparing checksums:

```
ef07f6ce1bd728b3f33181ae2e4544c0  docs/admin-panel/capability-map.json
c5027dd37b3f2b698fb9db22544143c7  admin_panel/src/auth/capabilities.generated.ts
```

This matters because a non-deterministic generator produces a diff on every run,
and a diff that always appears is a diff nobody reads.

---

## 3. What comes from where

Two kinds of fact go into a capability, and they have very different
trustworthiness.

**Read mechanically from `php artisan route:list --json`:**
method, path, `RoleAuthorize` roles, `RequireAccessLevel` tier. These cannot
drift, because they are regenerated from the middleware that enforces them.

**Declared in the generator, because a route cannot express it:**
the policy scope. "The assigned driver, or OPERATIONS" lives in a policy, and no
amount of reading the router will find it. Every declaration names the policy
method and, where one exists, the test that proves it:

```python
('trip.operate.complete', 'POST', '/trips/{id}/complete', OPERATIONS,
 ASSIGNED_DRIVER, 'TripPolicy::operate — G3-3, DriverOperationBoundaryTest'),
```

The generator **fails** — exits non-zero, writes nothing — when:

- two capabilities share an id
- a capability names a route the backend does not have
- a declared tier disagrees with the tier the route's middleware enforces
- an authenticated mutation is neither mapped nor listed in `UNMAPPED_MUTATIONS`

That last check is the important one. A new backend mutation cannot be added
without somebody deciding, in writing, whether the panel offers it.

---

## 4. The scopes

`minimumAccessLevel` is the administrative floor. `scope` is the exception the
policy makes for somebody who is not an administrator at all.

| Scope | Rule |
|---|---|
| `tier` | The access level is the whole rule. |
| `assignedDriver` | The driver assigned to that trip, otherwise the tier. |
| `anyDriver` | Any driver, otherwise the tier. |
| `reporter` | Whoever raised the record, otherwise the tier. |
| `subject` | The person the record is about, otherwise the tier. |
| `own` | Own record only. No administrative path exists, at any tier. |

The scope is what G3-2 and G3-3 were about. `incident.note.create` is
`reporter` + SUPPORT: the driver who raised the incident may annotate it, and so
may a supervisor — but a VIEWER may not, even though a VIEWER may read it.
Reading a record and writing to it are separate capabilities, which is precisely
what the controller got wrong before G3-3.

---

## 5. How the panel consumes it

One registry, three consumers, no second opinion:

```tsx
// Navigation — an unreachable section is absent, not disabled
{ label: 'Audit', path: '/admin/audit', capability: 'audit.read' }

// Routes — typing the URL is not a way in
<RequireCapability capability="audit.read"><AuditScreen /></RequireCapability>

// Actions — the button is not rendered
<Can capability="incident.close"><CloseButton /></Can>
```

All three call `useSession().can(id, resource)`. There is no `hasAccess(level)`
in any feature component and no second permission table anywhere in the panel;
`RequireLevel` was deleted when this landed.

`can()` denies an unknown capability id rather than allowing it, and denies
everything to an account with no access level. Both are the safe direction of a
typo.

**None of this is access control.** It decides what to render. The server
decides what happens, and a 403 renders the forbidden surface without signing
anybody out.

### Staleness

An access level can change while somebody is signed in. The session revalidates
against `/auth/me` on window focus and adopts whatever the server says — up or
down. A *failed* revalidation is not a demotion: dropping privileges on a flaky
network would make the panel unusable on a bad connection. Both directions are
tested (`tests/auth.test.tsx`).

---

## 6. What proves it

**Integrity tests** (`admin_panel/tests/capabilities.test.ts`) — the registry
against the committed artifact: count, ids, no duplicates, every route real,
every access level known, no capability disagreeing with its route's middleware,
and every navigation entry and route guard naming a capability that exists.

**A generated four-tier matrix** — every capability × VIEWER, SUPPORT,
OPERATIONS, SUPER_ADMIN, asserted against the artifact's own declared minimum
rather than a table somebody typed. Plus the ladder itself: strictly more
capability as the tier rises, and no string comparison (alphabetically
`OPERATIONS` precedes `SUPPORT`, which would invert it).

**Guard tests** (`admin_panel/tests/guards.test.tsx`) — both guards through a
real session: an action shown to the tier that has it, *absent* (not disabled)
for the tier that does not, a resource scope beating the tier, and a forbidden
route rendering the forbidden surface without signing anybody out.

**Live probes against the running backend** — the layer MSW can never replace,
and the layer that caught G3-3.

---

## 7. Decisions the panel records rather than hides

Three places where the panel deliberately offers less than the backend allows.
Each is a decision, written down here so it is not mistaken for an oversight.

| What | Why |
|---|---|
| **Route and stop editing** (`route.manage`, `schedule.manage`) | Placing stops needs a map and coordinates inside the service area. A route with three of its seven stops still looks like a route, and a half-built editor is worse than none. The endpoints stay OPERATIONS-gated on the server. |
| **Changing an existing access level** | There is no endpoint. `UpdateUserRequest` does not accept `access_level`, and nothing else takes it. It is chosen at creation. A control here would be a button that silently did nothing. |
| **Deleting anything** | The backend has no `DELETE /users/{id}` and no delete ability to guard one. Accounts are deactivated so the history referencing them still makes sense. |

## 8. Live verification, 2026-08-10

Four probe administrators, one per tier, confirmed by `/auth/me` before a single
denial was trusted. Every mutating capability except the five `own` ones was
called for real, at every tier below its declared minimum:

```
probed 111 denial cases
  refused with 403 : 111
  inconclusive 422 : 0
  NOT REFUSED      : 0
```

Plus `evidence.create`, probed separately because it is a multipart upload:
VIEWER → 403, SUPPORT → 201. Both directions.

**Reads were probed the same way**, and this is where the registry was found
wrong. All 40 read capabilities, at all four tiers:

```
checked 40 read capabilities at four tiers each
  agreeing with the registry : 40
  DISAGREEING               : 0
```

The first run of that sweep reported **one** disagreement. `manifest.read` was
declared VIEWER because the route carries no tier — but
`TrackingController::manifest` authorizes `operate`, not `view`, so the server
admits it only from OPERATIONS or the trip's assigned driver. Middleware cannot
express that and the generator cannot read it; only asking the server found it.
The declaration was corrected to `OPERATIONS` + `assignedDriver` and the sweep
now agrees throughout.

The server being *stricter* than the registry is not a hole — but it is a link
that 403s, which is its own kind of defect.

One read remains unprobed: `inspection.read`, because the development database
holds no inspection record to name.

**Every probe sent a valid payload.** This is not a detail. FormRequest
validation runs *before* the controller's policy check, so an unauthorised
caller with a malformed body sees 422, not 403 — and a 422 counted as a pass
would have hidden every one of the three defects above. The probe reports 422 as
*inconclusive* and fails the run if any appear. There were none.

Seven cases had no such record in the development database (four replacement
transitions, attendance review). Those were probed with a well-formed but absent
id: the tier is enforced in middleware, which runs before route-model binding, so
a 403 still proves the denial. A 404 there would have proved nothing and is
reported as unprobed.

The probe deliberately does **not** run the granted direction — executing sixty
real mutations in state-machine order would rewrite the development database.
That direction is covered by the backend's own suite (1116 tests) and by
`DriverOperationBoundaryTest`, which asserts database state rather than status
codes.

### Reproducing it

The probe script lives outside the repository (it seeds accounts and mutates
data). `admin_panel/docs/development.md` rebuilds the environment; the probe
needs four admins at `{level}@probe.ctms` and a running server.

---

## 9. When it drifts

A backend change lands and the panel's tests go red. That is the system working.

1. Regenerate: `npm run capabilities`
2. Read the diff in `capability-map.json` — it is written to be readable
3. If a *new* mutation appeared, the generator will have refused to write
   anything until you either map it or record it as deliberately unoffered
4. If a *tier* moved, the panel now hides or shows something new. Check the
   screen still makes sense, not just that the test passes
5. Commit both generated files with the change that caused them

What not to do: edit the generated file, add a second check in a component, or
delete the failing assertion.
