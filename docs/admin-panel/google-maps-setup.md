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
3. Put it in `backend/.env` (never `.env.demo`, never the panel):

   ```dotenv
   GOOGLE_MAPS_SERVER_KEY=AIza...
   ```

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
# The server key: it must NOT be in the panel bundle. This must print 0.
grep -c "$(grep GOOGLE_MAPS_SERVER_KEY backend/.env | cut -d= -f2)" admin_panel/dist/assets/*.js
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
