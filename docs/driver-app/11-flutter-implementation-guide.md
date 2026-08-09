# Driver App — Phase 11: Flutter Implementation Guide

**Derived from:** Phases 1–10. This is the build order and the architecture that supports it.

---

## Architecture

```
┌──────────────────────────────────────────────┐
│  presentation   screens · widgets · router   │
├──────────────────────────────────────────────┤
│  application    blocs (Phase 4 machines)     │
├──────────────────────────────────────────────┤
│  domain         entities · failures · repos  │  ← pure Dart, no Flutter
├──────────────────────────────────────────────┤
│  data           api client · local store     │
│                 sync queue · generated models│
└──────────────────────────────────────────────┘
```

**The domain layer never imports Flutter.** That is what makes the business rules — capacity, staleness, duty limits — testable without a widget tree, and it is where the app mirrors the backend's own layering.

### Folder structure

```
lib/
├── main.dart
├── app/                    router · theme · di · bootstrap
├── core/
│   ├── api/                dio client · interceptors · envelope · failures
│   ├── storage/            secure tokens · drift database
│   ├── sync/               queue · replay · idempotency
│   ├── location/           gps service · buffer · foreground service
│   └── design/             tokens · AppIcon registry · components
├── features/
│   ├── auth/               data · domain · application · presentation
│   ├── trip/
│   ├── inspection/
│   ├── evidence/
│   ├── tracking/
│   ├── boarding/
│   ├── incidents/
│   ├── notifications/
│   └── profile/
└── generated/              openapi models — NEVER edited by hand
```

Each feature carries its own four layers. A feature is deletable without touching another.

---

## Packages

| Concern | Package | Why this one |
|---|---|---|
| State | `flutter_bloc` | The Phase 4 machines are literally sealed-class states; Bloc models them directly |
| DI | `get_it` + `injectable` | No `BuildContext` needed in the data layer |
| HTTP | `dio` | Interceptors for auth refresh, retry and envelope unwrapping |
| Models | `openapi-generator` → `json_serializable` | Generated from `openapi-driver.json`. Never hand-write a DTO |
| Local DB | `drift` | The sync queue needs real transactions and ordered reads |
| Secure store | `flutter_secure_storage` | Tokens only |
| Location | `geolocator` + `flutter_foreground_task` | Background GPS survives a locked screen |
| Maps | `google_maps_flutter` | Backend already uses Google routing |
| Camera | `image_picker` + `flutter_image_compress` | Compress before upload (Phase 9) |
| Push | `firebase_messaging` | Backend expects FCM tokens |
| Connectivity | `connectivity_plus` + API-reachability detection | OS connectivity is not server reachability |
| Routing | `go_router` | Deep links from notification payloads |
| Freezed | `freezed` | Sealed states without boilerplate |

**Not used:** `provider` (Bloc covers it), `http` (Dio), `shared_preferences` for anything sensitive, any state library beyond Bloc. One state solution.

---

## Generating models

```bash
dart run build_runner build --delete-conflicting-outputs

openapi-generator generate \
  -i ../docs/driver-app/openapi-driver.json \
  -g dart-dio \
  -o lib/generated \
  --additional-properties=nullableFields=true
```

Regenerate whenever the backend regenerates its spec. `OpenApiContractTest` on the backend fails if the committed spec drifts from the router, so a stale client is caught on the server side first.

---

## The API client

Three interceptors, in order.

```dart
// 1 · Auth — attaches the token, refreshes exactly once on 401.
class AuthInterceptor extends QueuedInterceptor {
  // QueuedInterceptor, not Interceptor: a burst of parallel 401s must produce
  // ONE refresh, not five. Five racing refreshes against a rotating token
  // invalidate each other and log the driver out mid-trip.
}

// 2 · Envelope — unwraps { success, message, data, code } into either the
//     payload or a typed Failure carrying the server's message verbatim.
//     Nothing above the data layer ever sees the envelope.

// 3 · Retry — 5xx and connection errors only. NEVER retries 4xx: a 409 is a
//     considered refusal and retrying it is how a bus gets double-boarded.
```

### Failure model

```dart
sealed class Failure {
  final String message;   // the server's own wording — shown verbatim
}

final class NetworkFailure   extends Failure {}  // → queue it
final class AuthFailure      extends Failure {}  // → session machine
final class ForbiddenFailure extends Failure {}  // → never retry
final class ConflictFailure  extends Failure {   // → 409, SHOW THE MESSAGE
  final Map<String, dynamic> context;            //   e.g. {capacity, occupied}
}
final class ValidationFailure extends Failure {
  final Map<String, List<String>> fieldErrors;   // → map onto inputs
}
final class ServerFailure    extends Failure {}  // → generic + retry
```

`ConflictFailure` is the one the UI must never paraphrase. The backend writes those messages for drivers.

---

## The sync queue

The hardest part of this app. Everything else is screens.

```dart
@DataClassName('QueuedAction')
class QueuedActions extends Table {
  TextColumn get id => text()();
  TextColumn get tripId => text().nullable()();
  TextColumn get kind => text()();          // board | alight | arrive | skip …
  TextColumn get payload => text()();       // JSON
  TextColumn get idempotencyKey => text()();
  IntColumn  get sequence => integer()();   // FIFO within a trip
  DateTimeColumn get createdAt => dateTime()();
  IntColumn  get attempts => integer().withDefault(const Constant(0))();
  TextColumn get lastFailure => text().nullable()();
  BoolColumn get isBlocking => boolean().withDefault(const Constant(false))();
}
```

### Rules

1. **Idempotency key generated once, at enqueue** — never per attempt. A key regenerated on retry turns one boarding into five.
2. **FIFO per trip.** A boarding recorded before an arrival must replay in that order.
3. **Compound actions stay together.** An incident with a photo is one queue entry holding both; the report cannot cite an id that does not exist yet.
4. **Never silently drop.** A permanent rejection moves to a `failed` list the driver can see in `M3`.
5. **Throttle replay.** GPS ceiling is 60/min; replay at ≤ 50/min with a 1s minimum gap.

### Replay outcomes

| Response | Action |
|---|---|
| 2xx | delete from queue |
| 409 duplicate (idempotency) | delete — **this is success**, not a conflict |
| 409 business rule | move to failed, keep the message |
| 422 | move to failed, keep the message |
| 401 | pause queue, refresh, resume |
| 5xx / network | keep, backoff |
| 409 trip not running | purge that trip's queue, refetch trip |

The idempotency-duplicate case is the one teams get wrong. It looks like a failure and is actually the mechanism working.

---

## GPS service

Runs in a foreground service — Android kills background location without one, and a trip runs 90 minutes with a locked screen.

```dart
class GpsService {
  // 5s moving, 10s stationary. Adaptive sampling roughly halves battery
  // across a shift without losing fidelity where it matters.
  Duration get _interval => _isMoving ? const Duration(seconds: 5)
                                      : const Duration(seconds: 10);

  Future<void> onFix(Position p) async {
    final action = QueuedAction.position(
      tripId: _tripId,
      lat: p.latitude, lng: p.longitude,
      accuracy: p.accuracy, speed: p.speed * 3.6, heading: p.heading,
      recordedAt: DateTime.now(),                      // device clock, honoured
      idempotencyKey: '$_tripId:${_sequence++}',       // once, per fix
    );

    // Always enqueue first, then try to send. A fix that exists only inside a
    // pending request is a fix that vanishes when the process is killed.
    await _queue.enqueue(action);
    unawaited(_queue.flush());
  }
}
```

**Foreground notification** — "Trip running · Route 7", non-dismissible, tapping opens the app. Android requires it and it is also honest: the driver should know the app is tracking.

---

## SOS implementation

The one flow with its own rules.

```dart
class SosBloc extends Bloc<SosEvent, SosState> {
  Future<void> _onConfirm(...) async {
    // 1 · Persist BEFORE the network. The process can be killed mid-request;
    //     an SOS that exists only in a pending call never happened.
    final record = await _local.persistSos(
      tripId: _tripId,
      reportedAt: DateTime.now(),
      idempotencyKey: const Uuid().v4(),   // one per press, never per attempt
    );

    emit(SosState.persistedLocal(record));

    // 2 · Attempt, but never surface a failure.
    try {
      final incident = await _repo.report(record);
      emit(SosState.active(incident));
    } on Failure {
      emit(SosState.queued(record));   // NOT "failed"
      _scheduleRetry(record);          // backoff, survives restarts, never stops
    }
  }
}
```

### SOS is a service, not a screen

`SosBloc` is registered as a **singleton above the router**, but that understates it. SOS is an **application service** with a screen as one of its entry points — not a feature that happens to be globally available.

The distinction is architectural:

```dart
// Registered in DI alongside the API client and the sync queue, not
// alongside the other feature blocs.
@singleton
class SosService {
  Future<void> raise({String? tripId});   // callable from ANYWHERE
  Stream<SosState> get state;             // observable from anywhere
  Future<void> withdraw(String note);
}
```

What follows from treating it that way:

- **Any code path can raise it**, not only `C1`. A hardware button binding, a voice intent, an accessibility action, or a future wearable all call `SosService.raise()` without touching the UI layer.
- **It is initialised at app start**, before any screen exists — so a queued SOS from a previous session resumes retrying the moment the app opens, whether or not the driver ever navigates to `P17`.
- **`P17` observes it; it does not own it.** Popping `P17` does not stop retrying. Killing the app does not stop retrying.
- **It has no dependency on `TripBloc`.** `tripId` is optional in the API precisely so an SOS can be raised with no trip running — a driver walking to a parked bus can still call for help.

Everything else in the app is a feature. This one is infrastructure.

---

## Testing

| Layer | Approach | Target |
|---|---|---|
| Domain | Pure unit | 100% of rules |
| Bloc | `bloc_test`, every transition in Phase 4 | 100% of states |
| Sync queue | Integration against in-memory Drift | every replay outcome |
| API client | `http_mock_adapter` against the OpenAPI examples | every status code |
| Widget | Golden tests per component × state | all 20 components |
| Integration | `integration_test`, the full critical path | J1→J13 |

**The tests that matter most** — mirroring what the backend's own review taught:

1. **Every Bloc state is reachable.** A state with no test is a state that will render wrong at 06:40.
2. **Offline → online replay produces exactly one server record per action.** The idempotency guarantee, tested end-to-end.
3. **A 409 renders the server's message verbatim.** Assert on the actual string, not on "an error is shown".
4. **SOS never reaches a failed state.** Fuzz the network layer; assert `SosState.failed` is unreachable.
5. **Boarding is not debounced.** Ten taps in one second produce ten queued actions.

---

## Build order

Each step is shippable and demonstrable before the next.

| # | Slice | Delivers | Done when |
|---|---|---|---|
| 0 | **Foundation** | see the explicit scope below | app builds, four empty tabs render in both themes |
| 1 | **Auth** | `SessionBloc`, secure storage, refresh interceptor, P1/P2/P4 | sign in, survive restart, silent refresh, expiry → login |
| 2 | **Connectivity** | `ConnectivityCubit`, offline banner, API-reachability detection | banner appears on three consecutive failures and clears on the next success |
| 3 | **Trip read** | `TripBloc` (M1, eight states), R1 all states, readiness | every state renders from real data |
| 4 | **Inspection** | P9/P10/P11, draft persistence | full checklist submits; drafts survive a kill |

> **Inspection is exception-driven.** An explicit `ALL OK` action represents
> PASS for every checklist item the server currently supplies. It is an
> intentional affirmative action, not a default selection, and a later explicit
> failure overrides it. The checklist API provides no category metadata, so the
> client must not invent or hard-code categories, and must never assume the
> list is fourteen items long.
| 5 | **Evidence** | M1/M2, compression, upload | failing item attaches a real photograph |
| 6 | **Start** | S2, refusal handling | `reasons[]` renders grouped and actionable |
| 7 | **GPS** | service, buffer, queue, pill | 90-minute run with a 20-minute tunnel loses nothing |
| 8 | **Boarding** | counters, manifest, stop details | 100 taps offline reconcile to 100 records |
| 9 | **SOS** | C1, S1, P17, fallbacks | works in airplane mode; offers call and SMS |
| 10 | **Incidents** | S6, P18, P19 | operational incident refuses without a photo |
| 11 | **End** | S3, P7 | trip closes; summary reconciles |
| 12 | **Notifications** | FCM, R3, deep links | tapping a push lands on the right object |
| 13 | **Profile** | R4, duty status, M3 | queue is inspectable; failures explain themselves |
| 14 | **Polish** | a11y audit, goldens, tablet, dark mode | TalkBack completes J1→J13 |

**Do not reorder 6 before 5** — the inspection cannot be submitted for a critical failure without evidence, so step 4 is only half-testable alone. **Do not start 7 before 6** — GPS refuses positions for a trip that is not RUNNING.

### Slice 2 — the exact scope

Connectivity and nothing else.

**In scope** — `ConnectivityCubit` (M7) provided above the router, the
`PersistentBanner` component, C2 wired to it, and the reporting inside the API
client that decides what reachable means.

**Out of scope** — the sync queue and its banner (C3). M7's `restored` edge
triggers M6, but M6 arrives in slice 6; until then, restoring connectivity
clears the banner and nothing more.

**Done when** three consecutive failures raise the banner, the next successful
call clears it, and a 4xx never raises it.

> **There is no polling probe.** See M7 in Phase 4: reachability is derived
> from traffic the app was going to make anyway. CTMS has no health endpoint,
> and turning an authentication endpoint into one would add an undocumented
> request against a frozen contract.

### Slice 0 — the exact scope

Deliberately narrow. The navigation shell sits here rather than after auth, because auth needs somewhere to land and a login screen with nowhere to go cannot be demonstrated.

**In scope**

| | |
|---|---|
| Flutter project, Android target, flavours | Design tokens (Phase 7) as a `ThemeExtension` |
| Folder structure (four layers per feature) | Typography scale, tabular figures configured |
| `get_it` + `injectable` wiring | Light and dark themes, both switchable |
| `go_router` shell with four tabs | `AppIcon` registry (Phase 8), every entry resolving |
| Generated OpenAPI models from `openapi-driver.json` | Structured logging with redaction |
| Dio client with envelope + retry interceptors | Global error boundary |
| Secure token storage (empty, not yet used) | Empty Trip, Map, Alerts, Me screens |

**Explicitly out of scope**

No business logic. No GPS. No inspection. No maps. No API calls. No `SessionBloc` — the token store exists but nothing writes to it yet.

**Done when** the app builds, launches, renders four empty tabs, switches between light and dark correctly, every icon in the registry resolves to a real glyph, and `flutter analyze` is clean.

> **Verify the icon registry in this slice.** It is the cheapest possible moment to discover that a Hugeicons name is wrong — before any screen depends on it. Every `?` in Phase 8 becomes a `✓` or a fallback here.

### Slice 1 — the exact scope

Authentication and nothing else.

**In scope** — login screen, `POST /auth/login`, token persistence, the refresh interceptor, `POST /auth/logout`, `GET /auth/me`, session-expiry handling, P1 splash, P2 login, P4 session expired.

**Out of scope** — trips, the dashboard's content, notifications, anything a signed-in driver would actually do. Landing on an empty Trip tab is the correct end state for this slice.

**Done when** a driver signs in, the session survives an app restart, an expired access token refreshes silently exactly once, and a revoked token lands on P4 with the stack cleared.

---

## Definition of done, per slice

- [ ] Every Phase 4 state renders and is reachable
- [ ] Loading, empty, offline, error each implemented — not "TODO"
- [ ] Every API failure mode from Phase 9 handled by status code
- [ ] Offline behaviour matches Phase 2's division (safety gates online; everything else offline)
- [ ] Touch targets ≥ 48dp; text scales to 1.5×
- [ ] Screen reader completes the flow
- [ ] Golden tests for new components × states
- [ ] No hard-coded strings that came from the API
- [ ] No colour carrying meaning alone

---

## Release checklist

- [ ] `GOOGLE_MAPS_API_KEY` restricted to the Android package + SHA-1
- [ ] Certificate pinning on the API host
- [ ] ProGuard/R8, no logging in release
- [ ] Foreground-service permissions declared and justified for review
- [ ] Crash reporting with **no PII and no coordinates**
- [ ] Force-update mechanism (`D6`) wired before first release, not after
- [ ] Tested on a low-end device — the target is not a flagship
- [ ] Battery: a 90-minute trip should cost ≤ 12% on a mid-range phone

---

## What this app must never do

The rules that outlive every screen:

1. **Never block the driver on a network call during a trip.**
2. **Never show stale data as fresh.** `is_stale` is server-computed; render it.
3. **Never silently drop a driver's action.** Queue it or explain it.
4. **Never say an SOS failed.**
5. **Never paraphrase a 409.**
6. **Never render a control for something the driver cannot do.** A 403 is a design error, not an error state.
7. **Never let colour alone carry meaning.**
8. **Never trust the client for a safety decision.** Start gate, inspection outcome, capacity — all server-side, always.
