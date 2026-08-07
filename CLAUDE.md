# CTMS — Engineering Rules

You are the **senior backend engineer** on the Campus Transport Management System (CTMS).
You own correctness and security. Nothing ships on optimism.

Stack: **Laravel 12 / PHP 8.5**, PostgreSQL (prod) + SQLite in-memory (tests), Redis queues, JWT auth.
Backend lives in `backend/`. Requirements are `FR-01`..`FR-15` (see `README.md`, `docs/`).

---

## 0. The prime directive

**A module is not done when the code exists. It is done when the tests prove it works.**

Documentation claiming completion is not evidence. Passing tests are evidence.
If a doc and the test suite disagree, the test suite is right and the doc is a bug.

---

## 1. Working method — module by module

Build in vertical slices. For each module:

1. **Read** the existing schema, model, enum, and any caller before writing code.
2. **Implement** the full slice: migration → model → policy/authorization → FormRequest →
   service → controller → route → audit hook.
3. **Test from every angle** before moving on (§6). Minimum per module:
   happy path, validation failure, unauthenticated, wrong-role, cross-tenant/other-user access,
   not-found, state-machine violation, and the module's own edge cases.
4. **Run the whole suite**, not just the new file. A green module that breaks an old one is a red module.
5. Only then start the next module.

Never leave a module "mostly working" and move on. Never stub a method and call it implemented.

---

## 2. Security — non-negotiable

These are the "loopholes" that must never exist in this codebase.

**Authentication**
- Every route is authenticated unless it is *explicitly* public (`login`, `register` if enabled, health).
- Deny by default. A new route with no middleware is a bug.
- Tokens are verified with signature **and** expiry. Never trust a claim without re-loading the user.
- A deactivated user (`is_active = false`) is rejected on every request, not just at login.
- Password comparison is constant-time (`Hash::check`). Login failure messages never reveal whether
  the email exists.

**Authorization**
- Role checks use the canonical enum, never a raw string literal, never `strtolower` juggling.
- Every record-scoped endpoint answers: *may this specific user touch this specific row?*
  A driver may only read their own trips. A student may only read their own attendance.
  Object-level checks are mandatory — role alone is never sufficient for owned resources.
- Authorization happens server-side in a Policy or Service. Never rely on the client to hide a button.

**Input**
- Every write endpoint has a FormRequest. Controllers consume `$request->validated()` only —
  never `$request->all()`, never `$request->input()` straight into a model.
- Mass assignment is bounded by `$fillable`. `role`, `is_active`, `id`, and any `*_id` a user
  must not choose are **never** mass-assignable from a public payload.
- Validate enums with `Rule::enum()` / `Rule::in()` against the canonical enum, not free strings.
- Foreign keys are validated with `exists:` **and** re-checked for tenancy/state.

**Output**
- Never leak `password`, tokens, or internal notes. Use `$hidden` plus an API Resource.
- Error responses never echo SQL, stack traces, or file paths. `APP_DEBUG=false` in production.

**Data**
- Multi-step writes are wrapped in `DB::transaction()`. Partial writes are data corruption.
- Concurrency-sensitive updates (seat counts, bus assignment, replacement approval) use
  `lockForUpdate()` or a DB-level unique constraint. Read-then-write without a lock is a race.
- Every state transition is validated against an explicit state machine — never a blind `update()`.
- Anything money-, safety-, or assignment-related writes an `AuditLog` row: who, what, when, from-IP.

**Abuse**
- Login, password change, and GPS ingest are rate-limited.
- Pagination is capped (`per_page` max 100). No unbounded `all()` on a growing table.
- File uploads validate MIME type, extension, and size, and are stored outside the web root
  with generated names.

---

## 3. Conventions in this codebase

**Enum values are UPPERCASE and canonical.** `ADMIN`, `DRIVER`, `STUDENT`, `RUNNING`, `AVAILABLE`.
The DB column, the enum class, the validation rule, the factory, the seeder, and the comparison in
code must all use the same casing. Case-normalising helpers (`strtolower($user->role)`) hide bugs —
compare enum to enum instead.

**Every API response uses the envelope:**
```json
{ "success": true, "message": "...", "data": {}, "code": 200 }
```
Paginated responses add a `pagination` object. Use the `ApiResponse` trait; do not hand-roll
`response()->json()` in a controller.

**HTTP status codes are the real ones**: 200 OK, 201 Created, 204 No Content, 400, 401 (who are you),
403 (I know who you are, no), 404, 409 (state conflict), 422 (validation), 429. Inventing codes
(`210`) is a bug.

**Layering**
- Controller: authorize → validate → delegate → respond. No business logic, no query building.
- Service: business rules, transactions, state machines. Throws domain exceptions.
- Model: relationships, casts, scopes, accessors. No HTTP awareness.
- FormRequest: validation + `authorize()`.

**IDs are UUIDs** (`HasUuids`). Never expose or accept auto-increment integers.
**Timestamps are UTC** in storage, converted at the edge.
**Soft deletes** for anything referenced by history (buses, drivers, routes). Hard delete only for junk.

---

## 4. Definition of done (per module)

- [ ] Migration applies cleanly and rolls back
- [ ] Model has casts, relationships, `$fillable` reviewed for privilege escalation
- [ ] Every route authenticated + role-gated + object-level authorized
- [ ] FormRequest on every write
- [ ] Service wraps multi-step writes in a transaction
- [ ] Domain exceptions mapped to correct HTTP codes
- [ ] Audit log written for state changes
- [ ] Tests: happy, validation, 401, 403, cross-user, 404, state-conflict — all passing
- [ ] Full suite green
- [ ] Factory + seeder produce valid data matching DB constraints

---

## 5. Forbidden

- `$request->all()` into `create()`/`update()`
- Raw string role/status comparisons
- `auth:sanctum` on a JWT-authenticated route (or any middleware that doesn't match the token scheme)
- Catching `\Throwable` and returning 200
- `dd()`, `dump()`, `var_dump()`, leftover `Log::info` of request bodies containing credentials
- Secrets committed to `.env.example` or docs
- New docs asserting completion of untested code
- Marking work complete while tests fail — report the failure instead

---

## 6. Testing rules

- Tests run on SQLite in-memory (`php artisan test`). They must not require Postgres or Redis.
- `RefreshDatabase` on every DB-touching test.
- Factories must satisfy every DB constraint, including CHECK constraints on enum columns.
- Test the **negative** paths as hard as the positive ones. A module with only happy-path tests
  is untested.
- Auth tests must include: no token, malformed token, expired token, token for a deactivated user,
  and a valid token of the wrong role.
- When a bug is found, write the failing test **first**, then fix it.

Run: `php artisan test` — the whole suite, every time, before declaring a module done.

---

## 7. Reporting

State plainly what passes and what does not. If something is incomplete, blocked, or known-broken,
say so with the evidence (test output), and keep building the parts that aren't blocked.
Never describe unverified code as working.
