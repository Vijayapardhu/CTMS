# Driver app — device walkthrough

The manual pass, to be run on a real handset against a running backend before
showing the app to anyone. Nothing here is verified by the automated suite:
these are the steps that need a phone, a signal and a bus route.

**Nothing below has been performed yet.** Tick as you go, and record the actual
behaviour next to anything that fails rather than repeating the step until it
passes.

## Before you start

| Needs | Where it comes from |
|---|---|
| PostgreSQL, Redis, Laravel | `backend/docker-compose.yml` |
| `adb reverse tcp:8000 tcp:8000` | for a debug build talking to a local server |
| Android Maps key | `android/local.properties`, see `maps-setup.md` |
| `EMERGENCY_PHONE` | `--dart-define=EMERGENCY_PHONE=...` |
| A trip dated **today** | the app asks for the device's date; a seeded trip on another date will not appear |

## Login

1. Launch from the home screen — the launch window shows the CTMS mark, not a
   white flash
2. Sign in
3. Kill and relaunch — the trip tab returns without a password

## Inspection

4. Readiness states why the bus is not cleared
5. Start inspection
6. Answer everything correct
7. Submit
8. Bus becomes ready, and the reason disappears from the trip card

## Trip

9. Start trip — the sheet says what starting causes, then it starts
10. Map renders with the bus on it
11. Road distance to the next stop appears, and matches Google to within a
    rounding step — **not** the straight line
12. The marker moves as the bus moves, and the position stops claiming to be
    live once it is older than two minutes
13. Drive within 100 m of a stop — the Arrived button lights
14. Arrive

## Boarding

15. Board a student
16. The counter moves, and the office sees it
17. Fill the bus — the board button refuses before the server has to

## Incident

18. Open Report a problem
19. Attach a photograph where the type demands one
20. Submit
21. The result is the backend's own words, and the incident exists server-side

## SOS

22. Hold the SOS control for its full duration
23. "Help has been alerted", carrying the server's message
24. One incident exists — not two

## Offline

25. Aeroplane mode
26. The offline banner appears **after** the third failed call, not the instant
    the radio drops
27. Board a student — it is saved locally and says so, never "sent"
28. Raise an SOS — it is queued, says so plainly, and offers the phone and SMS
    fallbacks
29. Restore the network
30. The queue drains, and exactly one of each action reached the server

## Session recovery

31. Force-stop the app mid-trip
32. Relaunch — the running trip returns and tracking resumes on its own
33. Queued actions from step 27 are still queued, and still only sent once

## Both themes

34. Repeat the trip, map, alerts and inspection screens in dark mode — nothing
    clipped, nothing unreadable, no white-on-pastel
