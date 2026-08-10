# Authorization states

What every screen and every action must be able to be, and what each one says.

## The rule underneath all of it

**401 is "who are you". 403 is "I know who you are, no".** They are different
questions and get different answers. A 403 never sends anybody to a login form —
that invites them to try another account, and it loses the work in front of them.

---

## Screen states

| State | When | Renders |
|---|---|---|
| `initial` | Nothing requested | Nothing. Never a flash of empty |
| `loading` | First request in flight | Skeleton at final dimensions |
| `loaded` | Data on screen | The screen |
| `empty` | Succeeded, genuinely nothing | Calm. Never red, never "error" |
| `refreshing` | Data on screen, newer request in flight | Existing data + progress line. **Never a skeleton** |
| `partialFailure` | Some sources failed | What loaded stays; what failed retries in place |
| `forbidden` | 403 | Fixed sentence + a route back. Session intact |
| `unauthorized` | 401, refresh failed | Session ends, login |
| `unavailable` | Nothing ever loaded | What failed + retry |
| `offline` | 3 consecutive failures | Reads keep their data, marked. Mutations disabled |

### Copy

| State | Words |
|---|---|
| `forbidden` | "You don't have permission to access this page." Never the server's wording, never the endpoint |
| `unavailable` | "The CTMS server could not be reached." |
| `empty` | Domain-specific and positive — "No open incidents", "No documents need attention" |

---

## Action states

Every mutation surface is in exactly one of these.

| State | Meaning | Surface |
|---|---|---|
| `absent` | This tier can never do it | **Not rendered.** Not greyed out |
| `blocked` | This tier may, this record's state may not | Disabled, with the reason on hover |
| `ready` | Offered | Enabled |
| `confirming` | Awaiting confirmation | Dialog naming the consequence |
| `submitting` | In flight | Disabled, spinner, **width unchanged** |
| `succeeded` | Server accepted | Toast, row updated in place |
| `refused` | 409 or 422 — considered and declined | **Persistent** message in the server's words |
| `failed` | 5xx or network — outcome unknown | Retry. Never "not saved" |
| `forbidden` | 403 arrived anyway | Fixed sentence. A bug in the capability map |

### `absent` versus `blocked`

The distinction carries the whole model.

- **Absent** is about *who you are*. A supervisor never sees "Close incident";
  a permanently disabled control teaches people the product is broken for them.
- **Blocked** is about *what this record is*. A resolved incident cannot be
  resolved again, and that is worth saying — disabled, with the reason.

### `refused` versus `failed`

- **Refused** — the server considered it and said no. The message is the
  server's, shown verbatim, and does **not** auto-dismiss. A safety refusal
  paraphrased is a safety refusal talked past.
- **Failed** — nobody knows whether it landed. The panel never says "not saved".
  It says the result is unknown and offers a refresh.

---

## Status codes

| Code | Panel behaviour |
|---|---|
| 200 / 201 | Render. Toast the creation |
| 204 | Success, no body |
| 400 | A bug in the panel. Generic error + retry |
| 401 | One refresh, single-flight. Second failure ends the session |
| 403 | Forbidden surface. **No redirect.** Session preserved |
| 404 | "That record is no longer available." Back to the list |
| 409 | **Verbatim.** Read `errors` for detail |
| 422 | Map `errors` to fields; first field error if the form has nowhere |
| 429 | Back off, pause polling, say so |
| 5xx | "The server could not complete that." + retry |
| network | "The CTMS server could not be reached." |

---

## Forms

1. **Never reset on failure.** A refusal must not cost somebody their typing.
2. **Disable on submit**, so a slow response cannot become three records.
3. **422 lands on the field.** A validation error floating above a form nobody
   can map to an input is a dead end.
4. **Preserve after 403 too** — the map was wrong, not the operator.

---

## Session and level changes

```text
Initialising ─▶ Authenticated ─▶ Refreshing ─▶ Authenticated
      │               │                └────▶ Expired
      │               └─▶ WrongAudience  (a valid non-ADMIN token)
      └─▶ Unauthenticated
```

- The shell renders **nothing** until the level is known. A sidebar that shows
  Administration for one frame tells somebody exactly which door to try.
- `/auth/me` is re-read on window focus and after every refresh. The server's
  level **replaces** the local one.
- If a demotion makes the current route forbidden, the forbidden surface renders
  in place — no redirect, no reload, no lost context.
- In-flight mutations are not cancelled. The server refuses them and the refusal
  is shown.
- A valid token for a non-ADMIN is refused with "This panel is for transport
  office staff." A driver's token works perfectly against this API and has no
  business here.

---

## Offline

Inherited from the driver app, and not re-derived:

```text
3 consecutive API failures  →  offline
any successful response     →  reachable
```

**Not** `navigator.onLine`. A laptop on café Wi-Fi with no route to the college
server is online by the radio and offline by CTMS.

While offline: reads keep what they hold, marked stale; **every mutation control
is disabled**. Nothing queues — this is a management panel, and an office can
wait for the truth.
