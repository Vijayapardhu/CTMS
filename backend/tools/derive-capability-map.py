"""Derive the frontend capability map from the router, mechanically.

Nothing here is typed by hand. Every entry is what `php artisan route:list`
reports, so the map cannot drift from the middleware that enforces it.
"""
import io
import json
import re

BASE = 'C:/Users/PARDHU/AppData/Local/Temp/claude/'
routes = json.load(io.open(BASE + 'routes2.json', encoding='utf-8'))
api = [r for r in routes if r['uri'].startswith('api/')]

MUTATING = {'POST', 'PUT', 'PATCH', 'DELETE'}

entries = []
for route in sorted(api, key=lambda r: (r['uri'], r['method'])):
    methods = [m for m in route['method'].split('|') if m != 'HEAD']
    method = methods[0]
    path = '/' + route['uri'].replace('api/v1/', '')
    action = route['action'].replace('App\\Http\\Controllers\\Api\\', '')

    roles, level, authed = None, None, False
    for w in route['middleware']:
        if 'RoleAuthorize:' in w:
            roles = w.split(':')[1].split(',')
        if 'RequireAccessLevel:' in w:
            level = w.split(':')[1]
        if 'AuthenticateRequest' in w:
            authed = True

    entries.append({
        'method': method,
        'path': path,
        'action': action,
        'roles': roles,
        'minimumAccess': level,
        'authenticated': authed,
        'mutates': method in MUTATING,
    })

io.open(BASE + 'capmap.json', 'w', encoding='utf-8').write(json.dumps(entries, indent=1))

# ── What the panel needs to know ──────────────────────────────────────────
admin_only = [e for e in entries if e['roles'] == ['ADMIN']]
gated = [e for e in entries if e['minimumAccess']]
mut = [e for e in entries if e['mutates'] and e['authenticated']]

print('total api routes        :', len(entries))
print('explicitly ADMIN-gated  :', len(admin_only))
print('access-level gated      :', len(gated))
print('authenticated mutations :', len(mut))
print()

print('=== MUTATIONS BY REQUIRED TIER ===')
for tier in ['SUPER_ADMIN', 'OPERATIONS', 'SUPPORT']:
    rows = [e for e in mut if e['minimumAccess'] == tier]
    print('\n%s (%d)' % (tier, len(rows)))
    for e in rows:
        print('  %-7s %s' % (e['method'], e['path']))

ungated = [e for e in mut if not e['minimumAccess']]
print('\n=== MUTATIONS WITH NO ACCESS-LEVEL GATE (%d) ===' % len(ungated))
for e in ungated:
    print('  %-7s %-46s roles=%s' % (e['method'], e['path'], e['roles'] or '-'))
