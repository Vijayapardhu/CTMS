"""Derive the Admin Panel's capability registry from the backend.

    php artisan route:list --json | python tools/derive-capability-map.py

Emits two artifacts, both generated and never hand-edited:

    docs/admin-panel/capability-map.json
    admin_panel/src/auth/capabilities.generated.ts

Two kinds of fact go in, and they come from different places:

1. **Route and middleware** — read mechanically from the router. Method, path,
   role gate and `RequireAccessLevel` tier. These cannot drift, because they are
   regenerated from the thing that enforces them.

2. **Policy scope** — declared below, because it is not expressible in a route.
   "The assigned driver, or OPERATIONS" lives in a policy, and no amount of
   reading `route:list` will find it. Every declaration names the backend test
   that proves it, and the generator fails if the route it names does not exist.

That second list is the one that caused G3-1, G3-2 and G3-3: three separate
occasions where a policy said `isAdmin()` and everybody assumed it said
something stricter. It is written down here so the panel and the server can be
compared instead of trusted.

Deterministic: same backend, byte-identical output.
"""
import io
import json
import os
import subprocess
import sys

# Anchored on this file, not on the shell's working directory: the generator is
# run from backend/ by hand and from admin_panel/ by `npm run capabilities`.
BACKEND = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO = os.path.dirname(BACKEND)

VIEWER, SUPPORT, OPERATIONS, SUPER_ADMIN = 'VIEWER', 'SUPPORT', 'OPERATIONS', 'SUPER_ADMIN'

# Scopes. `tier` is the plain case: the access level is the whole rule.
TIER = 'tier'                      # access level only
ASSIGNED_DRIVER = 'assignedDriver'  # the driver of that trip, else the tier
ANY_DRIVER = 'anyDriver'            # any driver, else the tier
REPORTER = 'reporter'               # the person who raised it, else the tier
SUBJECT = 'subject'                 # the record's subject, else the tier
OWN = 'own'                         # own record only; no administrative path

# ── Capabilities ──────────────────────────────────────────────────────────
#
# (id, method, path, minimumAccessLevel, scope, provenance)
#
# `provenance` names where the rule is enforced and what proves it.

CAPABILITIES = [
    # Reads. Every tier, so the capability is really "can reach the screen".
    ('dashboard.read', 'GET', '/trips', VIEWER, TIER, 'composed; no dashboard endpoint (G1-1)'),
    ('trips.read', 'GET', '/trips', VIEWER, TIER, 'TripPolicy::viewAny'),
    ('trip.read', 'GET', '/trips/{id}', VIEWER, TIER, 'TripPolicy::view'),
    ('live.read', 'GET', '/trips/{id}/live', VIEWER, TIER, 'TripPolicy::view'),
    ('eta.read', 'GET', '/trips/{id}/eta', VIEWER, TIER, 'TripPolicy::view'),
    # Not `view`. TrackingController::manifest authorizes **operate**, so the
    # list of named students at a stop is the assigned driver's or OPERATIONS' —
    # read-only oversight does not get a passenger roster. Declared VIEWER until
    # a live four-tier read probe caught it (VIEWER 403, SUPPORT 403,
    # OPERATIONS 200); the server was stricter than the panel claimed.
    ('manifest.read', 'GET', '/trips/{id}/stops/{stopId}/manifest', OPERATIONS, ASSIGNED_DRIVER,
     'TripPolicy::operate — verified by probe, not by reading the route'),
    ('trip.corrections.read', 'GET', '/trips/{id}/corrections', VIEWER, TIER, 'TripPolicy::view'),
    ('fleet.read', 'GET', '/buses', VIEWER, TIER, 'BusPolicy::viewAny'),
    ('bus.read', 'GET', '/buses/{id}', VIEWER, TIER, 'BusPolicy::view'),
    ('bus.readiness.read', 'GET', '/buses/{id}/service-readiness', VIEWER, TIER, 'BusPolicy::view'),
    ('bus.inspections.read', 'GET', '/buses/{id}/inspections', VIEWER, TIER, 'BusPolicy::view'),
    ('bus.documents.read', 'GET', '/buses/{id}/documents', VIEWER, TIER, 'BusPolicy::view'),
    ('fleet.documents.expiring.read', 'GET', '/fleet/documents/expiring', VIEWER, TIER, 'authenticated'),
    ('inspection.read', 'GET', '/inspections/{id}', VIEWER, TIER, 'BusPolicy::view'),
    ('drivers.read', 'GET', '/drivers', VIEWER, TIER, 'RoleAuthorize:ADMIN'),
    ('driver.read', 'GET', '/drivers/{id}', VIEWER, TIER, 'DriverPolicy::view'),
    ('students.read', 'GET', '/students', VIEWER, TIER, 'RoleAuthorize:ADMIN'),
    ('student.read', 'GET', '/students/{id}', VIEWER, TIER, 'StudentPolicy::view'),
    ('incidents.read', 'GET', '/incidents', VIEWER, TIER, 'VehicleIncidentPolicy::viewAny'),
    ('incident.read', 'GET', '/incidents/{id}', VIEWER, TIER, 'VehicleIncidentPolicy::view'),
    ('incident.types.read', 'GET', '/incidents/types', VIEWER, TIER, 'authenticated'),
    ('evidence.read', 'GET', '/evidence/{id}', VIEWER, TIER, 'EvidenceFilePolicy::view'),
    ('maintenance.read', 'GET', '/maintenance-tickets', VIEWER, TIER, 'MaintenanceTicketPolicy::viewAny'),
    ('maintenance.ticket.read', 'GET', '/maintenance-tickets/{id}', VIEWER, TIER, 'MaintenanceTicketPolicy::view'),
    ('preventive.read', 'GET', '/preventive-maintenance', VIEWER, TIER, 'RoleAuthorize:ADMIN'),
    ('replacements.read', 'GET', '/replacements', VIEWER, TIER, 'RoleAuthorize:ADMIN'),
    ('replacement.read', 'GET', '/replacements/{id}', VIEWER, TIER, 'RoleAuthorize:ADMIN'),
    ('consolidations.read', 'GET', '/consolidations', VIEWER, TIER, 'RoleAuthorize:ADMIN'),
    ('attendance.read', 'GET', '/attendance-discrepancies', VIEWER, TIER, 'RoleAuthorize:ADMIN'),
    ('routes.read', 'GET', '/routes', VIEWER, TIER, 'RoutePolicy::viewAny'),
    ('route.stops.read', 'GET', '/routes/{id}/stops', VIEWER, TIER, 'RoutePolicy::view'),
    ('schedules.read', 'GET', '/schedules', VIEWER, TIER, 'SchedulePolicy::viewAny'),
    ('announcements.read', 'GET', '/announcements', VIEWER, TIER, 'AnnouncementPolicy::viewAny'),
    ('notifications.read', 'GET', '/notifications', VIEWER, TIER, 'own inbox'),
    ('notification.log.read', 'GET', '/notification-log', VIEWER, TIER, 'RoleAuthorize:ADMIN'),
    ('reports.read', 'GET', '/reports/trips', VIEWER, TIER, 'RoleAuthorize:ADMIN'),
    ('accounts.read', 'GET', '/users', VIEWER, TIER, 'RoleAuthorize:ADMIN — panel restricts to SUPER_ADMIN by choice'),

    # SUPPORT — supervise and respond.
    ('incident.acknowledge', 'POST', '/incidents/{id}/acknowledge', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),
    ('incident.resolve', 'POST', '/incidents/{id}/resolve', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),
    ('incident.create', 'POST', '/incidents', SUPPORT, ANY_DRIVER, 'VehicleIncidentPolicy::create — G3-3, DriverOperationBoundaryTest'),
    ('incident.note.create', 'POST', '/incidents/{id}/notes', SUPPORT, REPORTER, 'VehicleIncidentPolicy::addNote — G3-3'),
    ('incident.cancel', 'POST', '/incidents/{id}/cancel', SUPPORT, REPORTER, 'VehicleIncidentPolicy::cancel — G3-3'),
    ('evidence.create', 'POST', '/evidence', SUPPORT, ANY_DRIVER, 'EvidenceFilePolicy::create — G3-3'),
    ('maintenance.open', 'POST', '/maintenance-tickets', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),
    ('maintenance.assign', 'POST', '/maintenance-tickets/{id}/assign', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),
    ('maintenance.schedule', 'POST', '/maintenance-tickets/{id}/schedule', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),
    ('maintenance.start', 'POST', '/maintenance-tickets/{id}/start', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),
    ('replacement.dispatch', 'POST', '/replacements/{id}/dispatch', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),
    ('replacement.arrived', 'POST', '/replacements/{id}/arrived', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),
    ('attendance.review', 'POST', '/attendance-discrepancies/{id}/review', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),
    ('notification.resend', 'POST', '/notification-log/{id}/resend', SUPPORT, TIER, 'RequireAccessLevel:SUPPORT'),

    # OPERATIONS — operate and authorise.
    ('incident.close', 'POST', '/incidents/{id}/close', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('maintenance.complete', 'POST', '/maintenance-tickets/{id}/complete', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('maintenance.cancel', 'POST', '/maintenance-tickets/{id}/cancel', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('replacement.approve', 'POST', '/replacements/{id}/approve', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('replacement.reject', 'POST', '/replacements/{id}/reject', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('trip.correct', 'POST', '/trips/{id}/corrections', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS — G3-1'),
    ('trip.cancel', 'POST', '/trips/{id}/cancel', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('trip.reassign', 'POST', '/trips/{id}/reassign', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('trip.create', 'POST', '/trips', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('trip.generate', 'POST', '/trips/generate', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('bus.changeStatus', 'PATCH', '/buses/{id}/status', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('bus.create', 'POST', '/buses', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('bus.update', 'PUT', '/buses/{id}', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('bus.delete', 'DELETE', '/buses/{id}', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('bus.document.create', 'POST', '/buses/{id}/documents', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('driver.create', 'POST', '/drivers', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('driver.assignBus', 'POST', '/drivers/{id}/assign-bus', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('driver.setStatus', 'PATCH', '/drivers/{id}/status', OPERATIONS, SUBJECT, 'DriverPolicy::changeStatus — G3-2'),
    ('student.update', 'PUT', '/students/{id}', OPERATIONS, SUBJECT, 'StudentPolicy::update — G3-2'),
    ('student.assignTransport', 'POST', '/students/{id}/assign-transport', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('student.setStatus', 'PATCH', '/students/{id}/status', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('announcement.create', 'POST', '/announcements', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('announcement.update', 'PUT', '/announcements/{id}', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('announcement.publish', 'POST', '/announcements/{id}/publish', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('announcement.withdraw', 'POST', '/announcements/{id}/withdraw', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('consolidation.create', 'POST', '/consolidations', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS — G3-1'),
    ('consolidation.approve', 'POST', '/consolidations/{id}/approve', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS — G3-1'),
    ('consolidation.reject', 'POST', '/consolidations/{id}/reject', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS — G3-1'),
    ('consolidation.notify', 'POST', '/consolidations/{id}/notify', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS — G3-1'),
    ('consolidation.execute', 'POST', '/consolidations/{id}/execute', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS — G3-1'),
    ('preventive.create', 'POST', '/preventive-maintenance', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS — G3-1'),
    ('preventive.delete', 'DELETE', '/preventive-maintenance/{id}', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS — G3-1'),
    ('route.manage', 'POST', '/routes', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),
    ('schedule.manage', 'POST', '/schedules', OPERATIONS, TIER, 'RequireAccessLevel:OPERATIONS'),

    # OPERATIONS, shared with the driver app — G3-3.
    ('trip.operate.start', 'POST', '/trips/{id}/start', OPERATIONS, ASSIGNED_DRIVER, 'TripPolicy::operate — G3-3'),
    ('trip.operate.complete', 'POST', '/trips/{id}/complete', OPERATIONS, ASSIGNED_DRIVER, 'TripPolicy::operate — G3-3'),
    ('trip.operate.board', 'POST', '/trips/{id}/board', OPERATIONS, ASSIGNED_DRIVER, 'TripPolicy::operate — G3-3'),
    ('trip.operate.alight', 'POST', '/trips/{id}/alight', OPERATIONS, ASSIGNED_DRIVER, 'TripPolicy::operate — G3-3'),
    ('trip.operate.position', 'POST', '/trips/{id}/positions', OPERATIONS, ASSIGNED_DRIVER, 'TripPolicy::operate — G3-3'),
    ('trip.operate.arrive', 'POST', '/trips/{id}/stops/{stopId}/arrive', OPERATIONS, ASSIGNED_DRIVER, 'TripPolicy::operate — G3-3'),
    ('trip.operate.skip', 'POST', '/trips/{id}/stops/{stopId}/skip', OPERATIONS, ASSIGNED_DRIVER, 'TripPolicy::operate — G3-3'),
    ('trip.operate.leftBehind', 'POST', '/trips/{id}/left-behind', OPERATIONS, ASSIGNED_DRIVER, 'TripPolicy::operate — G3-3'),
    ('inspection.record', 'POST', '/buses/{id}/inspections', OPERATIONS, ANY_DRIVER, 'VehicleInspectionController::resolveInspectingDriver — G3-3'),

    # SUPER_ADMIN — govern.
    ('audit.read', 'GET', '/audit-logs', SUPER_ADMIN, TIER, 'RequireAccessLevel:SUPER_ADMIN'),
    ('audit.entry.read', 'GET', '/audit-logs/{id}', SUPER_ADMIN, TIER, 'RequireAccessLevel:SUPER_ADMIN'),
    ('audit.accessLog.read', 'GET', '/data-access-logs', SUPER_ADMIN, TIER, 'RequireAccessLevel:SUPER_ADMIN'),
    ('audit.retention.read', 'GET', '/retention-runs', SUPER_ADMIN, TIER, 'RequireAccessLevel:SUPER_ADMIN'),
    ('account.create', 'POST', '/users', SUPER_ADMIN, TIER, 'RequireAccessLevel:SUPER_ADMIN'),
    ('account.setActive', 'PATCH', '/users/{id}/status', SUPER_ADMIN, TIER, 'RequireAccessLevel:SUPER_ADMIN'),
    ('account.update', 'PUT', '/users/{id}', SUPER_ADMIN, SUBJECT, 'UserPolicy::update — G3-2'),
    ('personalData.export', 'POST', '/users/{id}/subject-access-export', SUPER_ADMIN, TIER, 'RequireAccessLevel:SUPER_ADMIN'),

    # Self-service. No administrative path at all — an admin editing somebody
    # else's notification preferences is not a thing the API offers.
    ('self.changePassword', 'POST', '/auth/change-password', VIEWER, OWN, 'own account'),
    ('self.logout', 'POST', '/auth/logout', VIEWER, OWN, 'own session'),
    ('self.logoutAll', 'POST', '/auth/logout-all', VIEWER, OWN, 'own sessions'),
    ('self.notification.markRead', 'PATCH', '/notifications/{id}/read', VIEWER, OWN, 'own inbox'),
    ('self.notification.markAllRead', 'POST', '/notifications/read-all', VIEWER, OWN, 'own inbox'),
]

# Mutations the panel deliberately never offers, so the integrity check does not
# demand a capability for them.
UNMAPPED_MUTATIONS = {
    ('POST', '/auth/login'), ('POST', '/auth/refresh'), ('POST', '/auth/register'),
    ('POST', '/notification-devices'), ('POST', '/notification-devices/revoke-all'),
    ('DELETE', '/notification-devices/{id}'), ('PUT', '/notification-preferences'),
    ('DELETE', '/notifications/{id}'), ('PATCH', '/notifications/{id}/unread'),
    ('PUT', '/drivers/{id}'), ('DELETE', '/drivers/{id}'), ('DELETE', '/drivers/{id}/assign-bus'),
    ('POST', '/students'), ('DELETE', '/students/{id}'), ('DELETE', '/students/{id}/assign-transport'),
    ('PUT', '/buses/{busId}/documents/{documentId}'), ('DELETE', '/buses/{busId}/documents/{documentId}'),
    ('PUT', '/routes/{id}'), ('DELETE', '/routes/{id}'), ('PATCH', '/routes/{id}/status'),
    ('POST', '/routes/{id}/stops'), ('PUT', '/routes/{routeId}/stops/{stopId}'),
    ('DELETE', '/routes/{routeId}/stops/{stopId}'),
    ('PUT', '/schedules/{id}'), ('DELETE', '/schedules/{id}'), ('PATCH', '/schedules/{id}/status'),
    ('POST', '/service-calendar'), ('DELETE', '/service-calendar/{id}'),
}


def load_routes():
    raw = subprocess.run(
        ['php', 'artisan', 'route:list', '--json'],
        capture_output=True, text=True, cwd=BACKEND,
    ).stdout
    if '[' not in raw:
        sys.exit('route:list produced no JSON — is the backend installed?\n' + raw[:400])
    start = raw.index('[')

    return [r for r in json.loads(raw[start:]) if r['uri'].startswith('api/')]


def main():
    routes = load_routes()

    table = {}
    for route in routes:
        method = [m for m in route['method'].split('|') if m != 'HEAD'][0]
        path = '/' + route['uri'].replace('api/v1/', '')
        roles, level = None, None
        for w in route['middleware']:
            if 'RoleAuthorize:' in w:
                roles = w.split(':')[1].split(',')
            if 'RequireAccessLevel:' in w:
                level = w.split(':')[1]
        table[(method, path)] = {
            'roles': roles,
            'middlewareAccess': level,
            'authenticated': any('AuthenticateRequest' in w for w in route['middleware']),
            'action': route['action'].replace('App\\Http\\Controllers\\Api\\', ''),
        }

    problems, capabilities, seen = [], [], set()

    for cap_id, method, path, level, scope, provenance in CAPABILITIES:
        if cap_id in seen:
            problems.append('duplicate capability id: ' + cap_id)
        seen.add(cap_id)

        route = table.get((method, path))
        if route is None:
            problems.append('capability %s names a route that does not exist: %s %s' % (cap_id, method, path))
            continue

        # Where middleware states a tier, the declaration must agree with it.
        if route['middlewareAccess'] and route['middlewareAccess'] != level and scope == TIER:
            problems.append('capability %s says %s, middleware says %s' % (cap_id, level, route['middlewareAccess']))

        resource, _, action = cap_id.partition('.')
        capabilities.append({
            'id': cap_id,
            'resource': resource,
            'action': action or 'read',
            'method': method,
            'path': path,
            'minimumAccessLevel': level,
            'scope': scope,
            'mutates': method in ('POST', 'PUT', 'PATCH', 'DELETE'),
            'driverAllowed': scope in (ASSIGNED_DRIVER, ANY_DRIVER),
            'middlewareAccess': route['middlewareAccess'],
            'provenance': provenance,
        })

    # Every authenticated mutation must be mapped or explicitly unmapped.
    mapped = {(c['method'], c['path']) for c in capabilities}
    for (method, path), route in sorted(table.items()):
        if method not in ('POST', 'PUT', 'PATCH', 'DELETE') or not route['authenticated']:
            continue
        if (method, path) in mapped or (method, path) in UNMAPPED_MUTATIONS:
            continue
        problems.append('mutation with no capability and no exemption: %s %s' % (method, path))

    if problems:
        for problem in problems:
            print('DRIFT:', problem, file=sys.stderr)
        sys.exit(1)

    capabilities.sort(key=lambda c: c['id'])

    artifact = {
        'generatedFrom': 'php artisan route:list',
        'routeCount': len(table),
        'capabilityCount': len(capabilities),
        'accessLevels': [VIEWER, SUPPORT, OPERATIONS, SUPER_ADMIN],
        'scopes': {
            TIER: 'The access level is the whole rule.',
            ASSIGNED_DRIVER: 'The driver assigned to that trip, otherwise the tier.',
            ANY_DRIVER: 'Any driver, otherwise the tier.',
            REPORTER: 'The person who raised it, otherwise the tier.',
            SUBJECT: 'The record\'s own subject, otherwise the tier.',
            OWN: 'Own record only. There is no administrative path.',
        },
        'capabilities': capabilities,
        'routes': [
            {'method': m, 'path': p, **v} for (m, p), v in sorted(table.items())
        ],
    }

    io.open(os.path.join(REPO, 'docs', 'admin-panel', 'capability-map.json'), 'w', encoding='utf-8', newline='\n').write(
        json.dumps(artifact, indent=2, ensure_ascii=False) + '\n'
    )

    ts = [
        '/* GENERATED by backend/tools/derive-capability-map.py — do not edit.',
        ' *',
        ' * Route and middleware facts are read from the router. Policy scopes are',
        ' * declared in the generator, because "the assigned driver, or OPERATIONS"',
        ' * lives in a policy and no route can express it. Each is backed by a named',
        ' * backend test; see docs/admin-panel/capability-registry.md.',
        ' */',
        '',
        "import { AccessLevel } from './accessLevel'",
        '',
        'export type CapabilityScope =',
        "  | 'tier'",
        "  | 'assignedDriver'",
        "  | 'anyDriver'",
        "  | 'reporter'",
        "  | 'subject'",
        "  | 'own'",
        '',
        'export type CapabilityDefinition = {',
        '  id: CapabilityId',
        '  resource: string',
        '  action: string',
        '  method: string',
        '  path: string',
        '  minimumAccessLevel: AccessLevel',
        '  scope: CapabilityScope',
        '  mutates: boolean',
        '  driverAllowed: boolean',
        '}',
        '',
        'export const CAPABILITY_IDS = [',
    ]
    for cap in capabilities:
        ts.append("  '%s'," % cap['id'])
    ts += [
        '] as const',
        '',
        'export type CapabilityId = (typeof CAPABILITY_IDS)[number]',
        '',
        'export const CAPABILITIES: Record<CapabilityId, CapabilityDefinition> = {',
    ]
    for cap in capabilities:
        ts.append(
            "  '%s': { id: '%s', resource: '%s', action: '%s', method: '%s', path: '%s',"
            " minimumAccessLevel: AccessLevel.%s, scope: '%s', mutates: %s, driverAllowed: %s },"
            % (
                cap['id'], cap['id'], cap['resource'], cap['action'], cap['method'], cap['path'],
                cap['minimumAccessLevel'], cap['scope'],
                'true' if cap['mutates'] else 'false',
                'true' if cap['driverAllowed'] else 'false',
            )
        )
    ts += ['}', '']

    io.open(os.path.join(REPO, 'admin_panel', 'src', 'auth', 'capabilities.generated.ts'), 'w', encoding='utf-8', newline='\n').write(
        '\n'.join(ts)
    )

    print('capabilities: %d   routes: %d' % (len(capabilities), len(table)))


if __name__ == '__main__':
    main()
