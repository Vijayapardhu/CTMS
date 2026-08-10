# RBAC model

How authorisation works in CTMS, and what the panel is allowed to do about it.

## One sentence

**The server decides what happens. The panel decides what to offer.**

Everything below exists so the panel offers the right things. None of it is a
security control, and no part of it may be trusted to be one.

## The two axes

```text
AuthenticateRequest      a valid, unexpired token for an active user
        ↓
RoleAuthorize:ADMIN      UserRole — ADMIN | DRIVER | STUDENT
        ↓
RequireAccessLevel:X     admins.access_level, compared by atLeast()
        ↓
Policy                   may this user touch this row?
```

`UserRole` says which product you belong to. `AccessLevel` says how much of the
transport office you are. They are independent: every access level holder is an
`ADMIN`, and no driver has an access level at all.

## The ladder

```text
VIEWER  <  SUPPORT  <  OPERATIONS  <  SUPER_ADMIN
```

Compared with `meets(held, required)`, which mirrors PHP's `atLeast()`.
**Never compared as strings** — alphabetically `OPERATIONS` precedes `SUPPORT`,
which would invert the whole model.

`admins.access_level` is `NOT NULL`, so an administrator without a level cannot
exist. A new administrator defaults to `VIEWER`.

| Level | The person | The verb |
|---|---|---|
| `VIEWER` | Oversight | observe |
| `SUPPORT` | Supervisor | supervise and respond |
| `OPERATIONS` | Transport Head | operate and authorise |
| `SUPER_ADMIN` | System administrator | govern |

## Where enforcement actually lives

Three mechanisms, and the panel must know which is in play:

1. **Route middleware** — `RequireAccessLevel`. 72 routes. Visible in the router
   and therefore in the generated capability map.
2. **Policy, subject-scoped** — "the subject themselves, or tier X". Three
   routes today (G3-2). Invisible to the router; the map records them explicitly.
3. **Policy, role-only** — `isAdmin()`. This is where G3-1, G3-2 and G3-3 all
   came from: a check that looks like authorisation and does not consult the
   level.

**A route with no `RequireAccessLevel` is not necessarily open, and not
necessarily closed.** The map states which of the three applies for every
mutation, because guessing is how the panel ends up offering a control that
403s, or hiding one that would have worked.

## What the panel builds on top

```text
capability-map.json          generated from the router. Never edited
        ↓
CAPABILITY[id]               capability id → minimum tier + endpoints
        ↓
can(id) / hasAccess(level)   read once from /auth/me, re-read on focus
        ↓
<Capability id>              action surfaces
RequireLevel / route meta    screen surfaces
```

### Rules

1. **No string comparison of levels.** `meets()` only.
2. **No capability that does not name a real endpoint.** The generator fails the
   build otherwise.
3. **Unknown capability denies.** `can()` falls back to `SUPER_ADMIN`, so a typo
   hides a control rather than exposing one.
4. **Absent, not disabled**, for what a tier can never do. Disabled with a
   reason for what the *state* forbids — a resolved incident cannot be resolved
   again, and that is not about who you are.
5. **A 403 that arrives anyway is a bug in the map**, and is surfaced as one:
   the fixed sentence, never the server's internal wording, never the path.
6. **403 never logs anybody out.** It is not an authentication failure.

## The level can change underneath you

An operator demoted from `OPERATIONS` to `VIEWER` in another browser still holds
a token minted under the old tier.

- `/auth/me` is re-read on window focus and after every token refresh
- The level from the server **replaces** the local one; it is never merged
- If the current route becomes forbidden, the forbidden surface renders in place
  — no redirect, no reload
- In-flight mutations are not cancelled. The server will refuse them, and the
  refusal is shown

The panel never performs an operation on the strength of a tier it held a
minute ago.

## What this model deliberately does not do

- **No frontend roles.** No `Manager`, no `Moderator`, no per-user overrides.
- **No permission storage.** Nothing is cached beyond the current session's
  level, and that is re-read.
- **No client-side enforcement claims.** Hiding is not enforcement, and the
  capability matrix marks every case where the two differ.
