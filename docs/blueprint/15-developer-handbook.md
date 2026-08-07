# 15 — Developer Handbook

How code is written on this project. Every convention here is already in force in
`backend/` — this documents what exists, it does not propose something new.

Read alongside [`CLAUDE.md`](../../CLAUDE.md) at the repository root, which is the short form
of these rules for day-to-day work.

---

## 1. Stack and versions

| Layer | Technology |
|---|---|
| Runtime | PHP 8.5 |
| Framework | Laravel 12 |
| Database | PostgreSQL (production) · SQLite in-memory (tests) |
| Cache / queue | Redis (production) · array / sync (tests) |
| Realtime | Laravel Reverb |
| Auth | JWT (firebase/php-jwt), custom token service |
| Testing | PHPUnit 11 |
| Style | Laravel Pint |

**Tests must never require Postgres or Redis.** `php artisan test` runs against SQLite
in-memory and an array cache. A test that needs a live service is a test that will not run in
CI, and therefore will not run at all.

---

## 2. Folder structure

```
backend/app/
├── Enums/            Native PHP backed enums. Domain vocabulary + state machines
├── Exceptions/       Domain exceptions, each mapping to one HTTP status
├── Http/
│   ├── Controllers/Api/    Thin. Authorize → validate → delegate → respond
│   ├── Middleware/         Cross-cutting request concerns
│   └── Requests/<Domain>/  One FormRequest per write action
├── Models/           Eloquent. Relationships, casts, scopes. No HTTP awareness
├── Policies/         Record-level authorization
├── Providers/        Bootstrapping, rate limiters, model guardrails
├── Services/
│   ├── Auth/         AuthService, TokenService
│   ├── Fleet/        BusService, DriverService
│   ├── Network/      RouteService, ScheduleService
│   └── *.php         Cross-cutting services (AuditLogger, StudentService)
├── Support/          Framework-agnostic helpers (ApiError)
└── Traits/           ApiResponse
```

**Rule.** Services are grouped by **domain**, not by entity. `Fleet/` holds both buses and
drivers because they are one operational concern. A new domain gets a new directory; a new
entity within an existing domain does not.

---

## 3. Layer responsibilities

The single most important convention on the project. Each layer does one thing.

| Layer | Does | Never does |
|---|---|---|
| **Route** | Declares the URL, the middleware stack, the role gate | Contains logic |
| **Middleware** | Identity, correlation id, rate limiting, logging | Business decisions |
| **FormRequest** | Shape, type, range, uniqueness, referential existence, `authorize()` | Queries business state, mutates anything |
| **Controller** | Authorize → validate → delegate → respond | Builds queries, applies business rules, writes to models |
| **Policy** | Answers "may *this* actor act on *this* record?" | Validates payloads, mutates |
| **Service** | Business rules, state machines, transactions, audit | Knows about HTTP, requests or responses |
| **Model** | Relationships, casts, scopes, derived state | Contains workflow, sends notifications |

### 3.1 The canonical controller action

```php
public function updateStatus(UpdateBusStatusRequest $request, string $id): JsonResponse
{
    $bus = $this->findBus($id);            // 404 as a domain exception

    $this->authorize('changeStatus', $bus); // 403 via policy

    $bus = $this->buses->changeStatus(      // all rules live in the service
        $bus,
        $request->status(),
        $request->user(),
        $request->validated('reason'),
    );

    return $this->success($bus, "Bus status updated to {$bus->status->value}.");
}
```

Four steps, no more. A controller action longer than about fifteen lines is doing a service's
job.

### 3.2 The canonical service method

```php
public function changeStatus(Bus $bus, BusStatus $target, User $actor, ?string $reason = null): Bus
{
    return DB::transaction(function () use ($bus, $target, $actor, $reason) {
        // Re-read under a lock — two admins clicking at once must not both
        // see the old status and both consider their transition legal.
        $bus = Bus::whereKey($bus->getKey())->lockForUpdate()->firstOrFail();

        $current = $bus->status;

        if (! $current->canTransitionTo($target)) {          // BR-050
            throw BusinessRuleException::invalidTransition('bus', $current->value, $target->value);
        }

        if ($current === $target) {
            return $bus;                                      // idempotent no-op
        }

        $bus->status = $target;
        $bus->save();

        $this->audit->log(/* ... */);                         // BR-508

        return $bus;
    });
}
```

**The four obligations of a service method that mutates state:**

1. Wrap multi-step writes in `DB::transaction()`
2. Re-read under `lockForUpdate()` before deciding anything concurrency-sensitive
3. Validate the state machine before assigning
4. Write an audit record

---

## 4. Naming conventions

| Thing | Convention | Example |
|---|---|---|
| Class | `StudlyCase`, singular | `BusService`, `RouteStop` |
| Method | `camelCase`, verb-first | `changeStatus()`, `assignBus()` |
| Boolean method | `is`/`has`/`can` prefix | `isAssignable()`, `hasActiveTrip()`, `canBoard()` |
| Enum case | `SCREAMING_SNAKE`, value identical to name | `case ON_TRIP = 'ON_TRIP'` |
| Table | `snake_case`, plural | `route_stops` |
| Column | `snake_case` | `assigned_bus_id` |
| Foreign key | `<singular>_id` | `route_id`, `pickup_stop_id` |
| Boolean column | `is_`/`has_`/`can_` prefix | `is_active`, `has_valid_ticket` |
| Timestamp column | `<verb>_at` | `last_login_at`, `transport_assigned_at` |
| FormRequest | `<Verb><Entity>Request` | `UpdateBusStatusRequest` |
| Policy method | Matches the ability | `changeStatus`, `assignTransport` |
| Test method | `snake_case`, reads as a sentence | `a_broken_bus_cannot_go_straight_back_into_service` |
| Route parameter | `{id}`, or `{routeId}`/`{stopId}` when nested | — |

**Enum values are UPPERCASE and identical across enum, database column, validation rule,
factory, seeder and comparison.** Case-normalising at comparison time (`strtolower($role)`)
is prohibited — it hides the exact class of bug that made every authorization check in this
codebase fail silently before it was fixed. Normalise once, at the edge, in
`prepareForValidation()`.

---

## 5. Enum conventions

Native PHP backed enums. Not the Spatie package, not class constants.

```php
enum BusStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    // ...

    public static function values(): array          // for validation rules
    public function allowedTransitions(): array     // the state machine
    public function canTransitionTo(self $t): bool
    public function isOperational(): bool           // domain question
}
```

**Rules**
- The state machine lives on the enum, not scattered through services
- `values()` exists so validation rules reference the enum, never a hard-coded array — adding a
  case cannot leave a rule behind
- Models cast to the enum, so comparisons are enum-to-enum
- Domain questions (`isOperational()`, `isAssignable()`) live on the enum where they depend
  only on the value

---

## 6. Model conventions

```php
protected $fillable = [ /* only user-supplied, non-privileged fields */ ];
```

**`$fillable` is a security boundary, not a convenience list.** Excluded from every model:

- `id` — generated
- `role`, `is_active` — privilege
- `status` on Bus, Driver, Trip — owned by a service that enforces the state machine
- `assigned_bus_id`, `route_id`, `pickup_stop_id` — assignment, owned by a service
- `sequence_number` — derived, managed by `RouteService`
- `number_of_stops` — derived
- any `*_at` timestamp the system sets

Privileged fields are set explicitly:

```php
$user->role = $role;          // never via fill()
$bus->status = BusStatus::AVAILABLE;
```

`Model::preventSilentlyDiscardingAttributes()` is enabled outside production
(`AppServiceProvider`). Filling a non-fillable attribute throws rather than silently dropping
it. This has already caught real regressions on this project.

**Other model rules**
- UUID primary keys (`HasUuids`), `$incrementing = false`, `$keyType = 'string'`
- `SoftDeletes` on anything history references: buses, drivers, students, routes, stops,
  schedules, trips
- Cast everything: enums, dates, booleans, decimals. An uncast decimal compared as a string is
  a bug waiting for production data
- `$hidden` for `password` and `remember_token`
- Scopes for reusable query fragments (`scopeActive`, `scopeAssignable`)
- No model dispatches notifications or contains workflow

---

## 7. Exception and response conventions

### 7.1 Domain exceptions

| Exception | Status | Use |
|---|---|---|
| `AuthenticationException` | 401 | Identity not established |
| `AuthorizationException` | 403 | Identity established, not permitted |
| `ResourceNotFoundException` | 404 | Record does not exist |
| `BusinessRuleException` | 409 | Payload fine, world disagrees |
| `ValidationException` | 422 | Payload malformed |

**409 vs 422 is not a style choice.** 422 tells a client to fix its payload; 409 tells it the
payload was fine and the state was not. Returning 422 for a state conflict makes clients retry
forever.

Rendering is centralised in `bootstrap/app.php`. A controller never formats an error.

### 7.2 Responses

Every endpoint returns the envelope, via the `ApiResponse` trait:

```json
{ "success": true, "message": "...", "data": {}, "code": 200 }
```

- `success()` / `created()` / `error()` / `paginated()`
- `perPage()` caps page size server-side regardless of what is requested
- Never hand-roll `response()->json()` in a controller
- Never invent a status code

---

## 8. Authorization conventions

Two axes, both mandatory (see [08 §5](08-functionality.md)):

```php
// Axis 1 — route-level role gate, coarse
Route::post('buses', [BusController::class, 'store'])->middleware('role:ADMIN');

// Axis 2 — record-level policy, fine
$this->authorize('update', $bus);
```

**Rules**
- `role:` middleware takes canonical uppercase values and throws on an unknown role — a typo
  in a route definition fails loudly rather than silently denying everyone
- Deny by default: `RoleAuthorize` with no roles refuses, it does not allow
- Every endpoint that touches a specific record calls `authorize()`. There is no exception
- `AuthenticateRequest` calls `Auth::setUser()` so `Gate`, policies and `auth()->user()` all
  resolve the same identity as `$request->user()`
- Policies answer questions about relationships; they never mutate

---

## 9. Audit conventions

```php
$this->audit->created($model, $actor);
$this->audit->updated($model, $before, $actor);   // $before = getAttributes() pre-change
$this->audit->deleted($model, $actor);
$this->audit->log(action: 'BUS_STATUS_CHANGED', /* ... */);
```

- Named actions are `SCREAMING_SNAKE` verbs in the past tense
- `AuditLogger` redacts secrets before writing (BR-510)
- Audit failure logs an error but **never** breaks the operation it is auditing (BR-509)
- `updated()` records only what actually changed; an empty diff writes nothing
- The system is a valid actor — `null` actor plus a named action, never an unattributed row

---

## 10. Queue, event and notification conventions

*(Conventions established now; modules 4–7 implement against them.)*

| Concern | Convention |
|---|---|
| Job naming | `<Verb><Subject>Job` — `GenerateDailyTripsJob` |
| Job idempotency | Every job is safe to run twice. Keyed by natural identity, e.g. (schedule, date) |
| Job failure | `failed()` logs with context and raises an operations alert; never silent |
| Queue selection | `critical` (SOS, incident) · `default` · `bulk` (imports, exports, reports) |
| Event naming | `<Subject><PastTenseVerb>` — `TripStarted`, `PassengerBoarded` |
| Event payload | Identifiers and primitives only. Never a serialised model — it will be stale by the time it is handled |
| Listener | One listener, one side effect. A listener that does three things is three listeners |
| Notification | Never dispatched from a model or a controller. Services publish events; listeners notify |
| Notification failure | Degrades, never blocks the publisher (BR-408) |
| Broadcast channel | Authorized at subscription **and** re-authorized on reconnect (BR-304) |

---

## 11. Testing conventions

### 11.1 Structure

```
tests/
├── TestCase.php              Base: createAdmin/createDriver/createStudent, authHeader()
├── Feature/<Domain>/         HTTP-level tests. The primary suite
└── Unit/                     Pure logic: enums, state machines, calculations
```

### 11.2 Rules

- `RefreshDatabase` on every database-touching test
- **Authenticate the way clients do** — mint a real token via `authHeader($user)` rather than
  `actingAs()`, so the JWT middleware stays under test
- Factories satisfy **every** database constraint, including CHECK constraints on enum columns.
  A factory that produces invalid data is a source of false failures
- Test names read as sentences and state the rule, not the mechanics:
  `a_broken_bus_cannot_go_straight_back_into_service`
- Reference the `BR-nnn` identifier in a comment where the rule is non-obvious

### 11.3 Required coverage per endpoint

Every endpoint needs all seven. Six of seven is an untested endpoint.

| # | Case | Expected |
|---|---|---|
| 1 | Happy path | 200/201 |
| 2 | Validation failure | 422 with field-level errors |
| 3 | No token | 401 |
| 4 | Wrong role | 403 |
| 5 | **Cross-user access** — valid token, someone else's record | 403 |
| 6 | Unknown identifier | 404 |
| 7 | State conflict | 409 |

Case 5 is the one that gets skipped and the one that leaks data. It is mandatory.

### 11.4 Auth-specific coverage

No token · malformed token · expired token · token signed with the wrong key · token for a
deactivated account · token for a deleted account · refresh token used as access token · token
for the wrong audience · revoked token.

### 11.5 Running

```bash
php artisan test                    # everything — the only acceptable pre-merge state
php artisan test --filter=BusTest   # one class
```

Green means green. There is no "known failure".

---

## 12. Git workflow

### 12.1 Branches

| Branch | Purpose |
|---|---|
| `main` | Deployable at all times |
| `develop` | Integration |
| `feature/<module>-<short-description>` | New work |
| `fix/<short-description>` | Defect |
| `hotfix/<short-description>` | Production emergency, branches from `main` |

Never commit directly to `main` or `develop`.

### 12.2 Commits

```
<type>(<scope>): <imperative summary>

<why, not what — the diff already says what>

Refs: BR-050, ERR-050
```

Types: `feat` · `fix` · `refactor` · `test` · `docs` · `chore` · `perf` · `security`.
Scopes: `auth` · `fleet` · `people` · `network` · `trip` · `tracking` · `safety` ·
`finance` · `notify` · `report` · `core`.

Commits are atomic: one logical change. A commit that touches four modules is four commits.

### 12.3 Pull requests

Title matches the commit convention. The description states:

- **What** changed and **why**
- Which `BR-nnn` rules it implements or affects
- Which `ERR-nnn` codes it introduces
- Test evidence — the actual output, not a claim
- Migration and rollback notes where the schema changes
- Anything deliberately left out

---

## 13. Pull request checklist

Copied into every PR. An unticked box needs an explanation, not silence.

**Correctness**
- [ ] Full suite passes locally (`php artisan test`) — output pasted
- [ ] New endpoints have all seven test cases from §11.3
- [ ] Business rules implemented name their `BR-nnn` identifier
- [ ] New errors have an `ERR-nnn` entry in [14](14-error-catalogue.md)

**Security**
- [ ] Every new endpoint is authenticated unless deliberately public, and the deliberation is
      stated
- [ ] Record-level `authorize()` on anything touching a specific record
- [ ] Cross-user access test present and passing
- [ ] No privileged field added to `$fillable`
- [ ] No secret in code, logs, audit, or error responses
- [ ] Multi-step writes are transactional; concurrency-sensitive reads are locked

**Layering**
- [ ] Controller is authorize → validate → delegate → respond, nothing more
- [ ] Business rules are in a service, not a controller or model
- [ ] Validation is in a FormRequest, not inline
- [ ] `$request->validated()` used; never `$request->all()`

**Data**
- [ ] Migration is reversible and tested both directions
- [ ] Enum values match the column definition exactly
- [ ] Factory satisfies every constraint
- [ ] Audit record written for state changes

**Interface** *(front-end changes)*
- [ ] Components come from [11](11-ui-component-library.md); no bespoke element without an
      entry
- [ ] Tokens from [12](12-design-system.md); no raw hex, no raw pixel spacing
- [ ] Loading, empty, error and success states implemented
- [ ] Keyboard reachable; focus visible; contrast verified
- [ ] Disabled actions expose their reason

**Hygiene**
- [ ] No `TODO`, `FIXME`, `dd()`, `dump()`, commented-out code
- [ ] No placeholder or stub method presented as complete
- [ ] Pint clean
- [ ] Documentation updated where behaviour changed

---

## 14. Code review checklist

Reviewers check for what tests cannot.

**Ask of every change**
1. What happens if two people do this simultaneously?
2. What happens if this fails halfway?
3. Can a user reach someone else's record by changing an identifier?
4. Is the error message useful to the person who will see it?
5. What happens when this is offline?
6. Does this silently succeed when it should loudly fail?
7. Would this be obvious to someone reading it in a year?

**Reject on sight**
- `$request->all()` into `create()` or `update()`
- Raw string comparison of a role or status
- `catch (\Throwable) { return response()->json(['ok' => true]); }`
- A new endpoint with no `authorize()` call
- A state assignment that skips the state machine
- A test asserting only the happy path
- A claim of completion with failing tests

**Review etiquette** — comment on the code, not the author. Distinguish blocking concerns from
preferences; say which. Approving means you would be comfortable operating it.

---

## 15. Definition of ready

Work is not started until:

- [ ] The screens exist in [03–07](03-screens-shared-auth.md)
- [ ] The business rules exist in [13](13-business-rule-catalogue.md)
- [ ] The error codes exist in [14](14-error-catalogue.md)
- [ ] The flow exists in [09](09-system-flows.md)
- [ ] Dependencies from [10 §5](10-system-map.md) are built
- [ ] Acceptance criteria are written and testable

Starting without these produces the thing this whole blueprint exists to prevent: code that
works on the happy path and has no defined behaviour anywhere else.
