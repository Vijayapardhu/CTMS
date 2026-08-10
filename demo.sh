#!/usr/bin/env bash
# CTMS — start the demonstration environment.
#
#   ./demo.sh              start everything, rebuilding the demo world
#   ./demo.sh --keep       start everything, keeping yesterday's data
#   ./demo.sh --seed-only  rebuild the data and stop
#
# The macOS and Linux twin of demo.ps1. Same environment, same data, same
# credentials.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
backend="$root/backend"
panel="$root/admin_panel"

keep=0
seed_only=0
for argument in "$@"; do
  case "$argument" in
    --keep) keep=1 ;;
    --seed-only) seed_only=1 ;;
    *) echo "Unknown option: $argument" >&2; exit 2 ;;
  esac
done

step() { printf '\n== %s\n' "$1"; }
note() { printf '   %s\n' "$1"; }

step 'Checking what is installed'
for tool in php node npm; do
  command -v "$tool" >/dev/null || { echo "$tool is not on PATH." >&2; exit 1; }
  note "$tool $("$tool" --version 2>&1 | head -1)"
done

# SQLite and a synchronous queue: no PostgreSQL, no Redis, no worker.
# Production is the other shape — see backend/.env.example.
note 'Database: SQLite · Queue: synchronous · Cache: file (see backend/.env.demo)'

[ -d "$backend/vendor" ] || { step 'Installing backend dependencies'; (cd "$backend" && composer install --no-interaction); }
[ -d "$panel/node_modules" ] || { step 'Installing panel dependencies'; (cd "$panel" && npm ci); }

# The template is committed; the working copy is not, because it carries an
# application key and that is a secret even in a demonstration.
if [ ! -f "$backend/.env.demo" ]; then
  step 'Creating backend/.env.demo from the template'
  cp "$backend/.env.demo.example" "$backend/.env.demo"
fi

if ! grep -q '^APP_KEY=base64:' "$backend/.env.demo"; then
  step 'Generating an application key'
  (cd "$backend" && php artisan --env=demo key:generate --force >/dev/null)
fi

if [ "$keep" -eq 0 ]; then
  step 'Building the demonstration world'
  (cd "$backend" && php artisan --env=demo ctms:demo --fresh)
else
  note 'Keeping the existing demonstration data (--keep).'
fi

if [ "$seed_only" -eq 1 ]; then
  step 'Done'
  note 'Data rebuilt. Start the servers yourself, or run without --seed-only.'
  exit 0
fi

step 'Checking the map credential'

# Order of preference:
#   1. A browser key of the panel's own, in admin_panel/.env.local
#   2. Failing that, the backend's key, handed to Vite for this run only
#
# The second is a convenience for demonstrating on one laptop. It hands the
# process the key rather than writing a copy of it anywhere, so there is still
# exactly one place the credential lives and one place to rotate it.
#
# It is NOT how this should be deployed: the backend's key has no referrer
# restriction, and a key in a browser bundle is readable by anybody who opens
# the page. Restricting that key by referrer would break the backend's own
# server-side calls, which send no referrer — so a real deployment needs a
# second, referrer-restricted key. See docs/admin-panel/google-maps-setup.md.
if [ -f "$panel/.env.local" ] && grep -q '^VITE_GOOGLE_MAPS_API_KEY=.\+' "$panel/.env.local"; then
  note 'Browser key found in admin_panel/.env.local.'
elif [ -f "$backend/.env" ] && grep -q '^GOOGLE_MAPS_API_KEY=.\+' "$backend/.env"; then
  VITE_GOOGLE_MAPS_API_KEY="$(grep '^GOOGLE_MAPS_API_KEY=' "$backend/.env" | head -1 | cut -d= -f2- | tr -d '\"')"
  export VITE_GOOGLE_MAPS_API_KEY
  note 'Using the backend key for this run. Demonstration only — it is unrestricted.'
  note 'Issue a referrer-restricted browser key before serving this anywhere else.'
else
  note 'No map key found in either place.'
  note 'Live Operations will show its map-unavailable state; everything else works.'
  note 'See docs/admin-panel/google-maps-setup.md.'
fi

step 'Starting the backend on http://127.0.0.1:8000'
(cd "$backend" && php artisan serve --env=demo --host=127.0.0.1 --port=8000) &
api=$!

step 'Starting the admin panel on http://127.0.0.1:5173'
(cd "$panel" && npm run dev) &
web=$!

cat <<'BANNER'

  CTMS is running.

    Admin panel   http://127.0.0.1:5173
    API           http://127.0.0.1:8000/api/v1

    viewer@ctms.edu       Transport Assistant     (VIEWER)
    supervisor@ctms.edu   Transport Supervisor    (SUPPORT)
    head@ctms.edu         Transport Head          (OPERATIONS)
    admin@ctms.edu        System Administrator    (SUPER_ADMIN)
    driver1@ctms.edu      Ravi Kumar              (DRIVER)

    password              Ctms@2026

  The walkthrough is docs/admin-panel/demo-walkthrough.md.
  Press Ctrl+C to stop both.

BANNER

trap 'kill "$api" "$web" 2>/dev/null || true' EXIT INT TERM
wait "$api" "$web"
