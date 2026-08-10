# Google Maps — the five minutes only you can do

CTMS uses **two different Google credentials**, and mixing them up is the one
mistake here that actually matters.

| Key | Lives in | Used by | Restricted by |
|---|---|---|---|
| **Browser key** | `admin_panel/.env.local` | Maps JavaScript API, in the operator's browser | HTTP referrer |
| **Server key** | `backend/.env` | Routes API, from the server | IP address |

The browser key is **visible to anybody who opens the page** — that is
unavoidable for the Maps JavaScript API and is why Google's protection for it
is a referrer restriction rather than secrecy. The server key is never sent to
a browser and must never be put in `admin_panel/.env.local`.

Neither key is in this repository, and neither should ever be committed.

---

## 0. What this project's existing key actually is

Checked against Google on 11 August 2026, so that the advice below is about the
real situation rather than a hypothetical.

`backend/.env` holds **one** key, under `GOOGLE_MAPS_API_KEY`. Probing it:

| Test | Result | What it tells us |
|---|---|---|
| Geocoding with **no** referrer | `OK` | Not referrer-restricted — a referrer-restricted key rejects this |
| Geocoding with an arbitrary referrer | `OK` | Confirms the same |
| Static Maps | `This API is not activated` | The key has a curated set of APIs, and Static Maps is not in it |

So: **the key has no application restriction.** Anybody who obtains it can spend
against this project.

Two consequences that decide the shape of the solution:

1. **Putting it in the panel exposes it.** A browser key is readable by anybody
   who opens the page — unavoidable for the Maps JavaScript API, and the reason
   Google protects browser keys with a referrer restriction instead of secrecy.
   An unrestricted key in a bundle has no protection at all.

2. **You cannot simply restrict this key to fix that.** Adding a referrer
   restriction would break the backend's own calls, because a server sends no
   referrer. The two uses need genuinely different restrictions, so they need
   two keys. There is no configuration of one key that is correct for both.

### What the demonstration scripts do about it

`demo.ps1` and `demo.sh` prefer a browser key in `admin_panel/.env.local`. If
there is not one, they pass the backend's key to Vite **for that run only** —
handed to the process, never copied to a second file. That is a deliberate
convenience for demonstrating on one laptop, and it is logged on screen when it
happens.

It is not a deployment strategy. Before the panel is served anywhere but a
laptop, do §1 below and put a referrer-restricted key in `.env.local`, where it
takes precedence automatically.

### If the map is grey during the demonstration

The Maps JavaScript API may not be among the APIs enabled on that key — Static
Maps is not, which suggests a curated set. The panel now detects Google's
`gm_authFailure` and says *"Google refused the map credential"* rather than
showing a grey rectangle. If you see that, enable **Maps JavaScript API** on
the key, or follow §1 and issue a proper browser key.

---

## 1. The browser key — for Live Operations

In the [Google Cloud console](https://console.cloud.google.com/):

1. **APIs & Services → Library** → enable **Maps JavaScript API**. Only that
   one. The panel does not use Places, Directions or Geocoding from the
   browser.
2. **APIs & Services → Credentials → Create credentials → API key.**
3. On the new key, set **Application restrictions → Websites**, and add every
   origin the panel is served from:

   ```text
   http://127.0.0.1:5173/*      the demonstration laptop
   http://localhost:5173/*      the same, by the other name
   https://ctms.yourcollege.edu/*   wherever it is actually deployed
   ```

   An unrestricted browser key is a key anybody can lift off the page and put
   on their own site, on your bill.

4. Set **API restrictions → Restrict key → Maps JavaScript API**.
5. Put it in `admin_panel/.env.local`:

   ```dotenv
   VITE_GOOGLE_MAPS_API_KEY=AIza...
   ```

   That file is git-ignored. Restart `npm run dev` — Vite reads env at startup.

### Without it

Nothing breaks. Live Operations renders its **map-unavailable** state and the
trip list, occupancy, delays and ETAs all keep working, because they come from
the API rather than from Google.

This is worth showing in the demonstration on purpose: it is the honest answer
to "what happens when the map key expires?"

---

## 2. The server key — for road distance

Optional, and CTMS is deliberately usable without it.

1. Enable **Routes API**.
2. Create a second key, restricted by **IP address** to the server, and by API
   to Routes API only.
3. Put it in `backend/.env` (never the panel):

   ```dotenv
   GOOGLE_MAPS_API_KEY=AIza...
   ```

   That is the variable the backend actually reads — `config/ctms.php` binds
   it. There is no `GOOGLE_MAPS_SERVER_KEY`.

### Without it

The backend falls back to its own estimate and marks the answer
`distance_is_estimate: true`. The driver app and the panel both label it as an
estimate rather than presenting a guess as a measurement.

---

## 3. Checking it worked

```bash
# The browser key: it is in the built page, which is expected and fine.
cd admin_panel && npm run build && grep -c "AIza" dist/assets/*.js
```

A non-zero count is correct for the browser key. If it is zero, the env var was
not set when Vite built.

```bash
# If the two keys are different, the server's must NOT be in the panel bundle.
# This must print 0. While one key is used for both, it will print 1 — which is
# the exposure §0 describes, not a passing check.
grep -c "$(grep '^GOOGLE_MAPS_API_KEY=' backend/.env | cut -d= -f2)" admin_panel/dist/assets/*.js
```

For road distance, ask the API for a running trip's ETA and look at the flag:

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  http://127.0.0.1:8000/api/v1/trips/$TRIP/eta | grep -o 'distance_is_estimate":[a-z]*'
```

`false` means Google answered. `true` means the offline estimate did, which is
a working state, not a failure.

---

## 4. Cost, honestly

Maps JavaScript API is billed per map load. Live Operations loads **one** map
per visit to the screen and refreshes data — not the map — every 30 seconds.
For a transport office of a handful of staff this sits inside Google's monthly
free allowance comfortably.

The Routes API is called server-side for a selected trip's ETA, not for every
trip on the screen. That was a deliberate design decision (Slice 4) and it is
the reason this is affordable rather than a surprise invoice.

Set a **budget alert** on the Cloud project anyway. It costs nothing and it is
the difference between noticing a runaway in an hour and noticing at
month-end.
