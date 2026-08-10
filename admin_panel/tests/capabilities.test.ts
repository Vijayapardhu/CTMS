import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { AccessLevel } from '@/auth/accessLevel'
import { CAPABILITIES, CAPABILITY_IDS, can, grantedTo, type Actor } from '@/auth/capabilities'
import { navItems } from '@/app/navigation'
import { screens } from '@/routes'

const artifact = JSON.parse(readFileSync('../docs/admin-panel/capability-map.json', 'utf-8')) as {
  routeCount: number
  capabilityCount: number
  capabilities: Array<{
    id: string
    method: string
    path: string
    minimumAccessLevel: string
    scope: string
    mutates: boolean
  }>
  routes: Array<{ method: string; path: string; middlewareAccess: string | null; authenticated: boolean }>
}

const admin = (level: AccessLevel): Actor => ({ userId: 'u-1', role: 'ADMIN', level })
const driver: Actor = { userId: 'd-user', role: 'DRIVER', level: null }

const TIERS = [AccessLevel.VIEWER, AccessLevel.SUPPORT, AccessLevel.OPERATIONS, AccessLevel.SUPER_ADMIN]
const RANK = { VIEWER: 0, SUPPORT: 1, OPERATIONS: 2, SUPER_ADMIN: 3 } as const

// ── Integrity: the registry must not drift from the backend ───────────────

describe('capability registry integrity', () => {
  it('matches the generated artifact exactly', () => {
    expect(CAPABILITY_IDS).toHaveLength(artifact.capabilityCount)
    expect([...CAPABILITY_IDS].sort()).toEqual(artifact.capabilities.map((c) => c.id).sort())
  })

  it('has no duplicate ids', () => {
    expect(new Set(CAPABILITY_IDS).size).toBe(CAPABILITY_IDS.length)
  })

  it('names only routes the backend actually has', () => {
    const routes = new Set(artifact.routes.map((r) => `${r.method} ${r.path}`))
    const missing = CAPABILITY_IDS.filter((id) => {
      const c = CAPABILITIES[id]

      return !routes.has(`${c.method} ${c.path}`)
    })

    expect(missing).toEqual([])
  })

  it('uses only access levels the backend defines', () => {
    const unknown = CAPABILITY_IDS.filter((id) => !(CAPABILITIES[id].minimumAccessLevel in RANK))

    expect(unknown).toEqual([])
  })

  it('agrees with route middleware wherever middleware states a tier', () => {
    const byRoute = new Map(artifact.routes.map((r) => [`${r.method} ${r.path}`, r.middlewareAccess]))

    const disagreements = CAPABILITY_IDS.filter((id) => {
      const c = CAPABILITIES[id]
      const enforced = byRoute.get(`${c.method} ${c.path}`)

      // Scoped capabilities are enforced in a policy, so middleware says
      // nothing about them — that is the G3-2 and G3-3 shape.
      return c.scope === 'tier' && enforced && enforced !== c.minimumAccessLevel
    })

    expect(disagreements).toEqual([])
  })

  it('covers every authenticated mutation, or the generator would have failed', () => {
    // The generator exits non-zero on an unmapped mutation, so reaching this
    // artifact at all is the assertion. This pins the counts so a silently
    // regenerated, smaller map is loud.
    expect(artifact.routeCount).toBe(158)
    expect(artifact.capabilities.filter((c) => c.mutates).length).toBeGreaterThan(50)
  })
})

describe('what the panel asks for', () => {
  it('names only capabilities the backend granted', () => {
    // A typo in a nav entry would hide a section forever, silently. A renamed
    // backend capability would do the same. Both are caught here.
    const unknown = [
      ...navItems.map((item) => ({ where: `nav ${item.label}`, id: item.capability })),
      ...screens.map((screen) => ({ where: `route ${screen.path}`, id: screen.capability })),
    ].filter((entry) => !(entry.id in CAPABILITIES))

    expect(unknown).toEqual([])
  })

  it('guards every screen a nav entry points at with the same capability', () => {
    const byPath = new Map(screens.map((screen) => [screen.path, screen.capability]))

    const mismatched = navItems.filter((item) => byPath.get(item.path) !== item.capability)

    expect(mismatched.map((item) => item.label)).toEqual([])
  })

  it('leaves no screen unguarded', () => {
    expect(screens.filter((screen) => !screen.capability).map((s) => s.path)).toEqual([])
  })
})

// ── The four-tier matrix, generated rather than typed ─────────────────────

describe('the access-level matrix', () => {
  it.each(TIERS)('%s gets exactly the capabilities its tier allows', (level) => {
    const actor = admin(level)

    for (const id of CAPABILITY_IDS) {
      const capability = CAPABILITIES[id]
      const allowed = can(actor, id)

      if (capability.scope === 'own') {
        // Self-service: available to anybody signed in, at every tier.
        expect(allowed, `${id} at ${level}`).toBe(true)
        continue
      }

      const expected = RANK[level] >= RANK[capability.minimumAccessLevel as keyof typeof RANK]
      expect(allowed, `${id} at ${level}`).toBe(expected)
    }
  })

  it('never compares levels as strings', () => {
    // Alphabetically OPERATIONS precedes SUPPORT, which would invert the
    // ladder. A supervisor must not reach an operations capability.
    expect(can(admin(AccessLevel.SUPPORT), 'incident.close')).toBe(false)
    expect(can(admin(AccessLevel.OPERATIONS), 'incident.acknowledge')).toBe(true)
  })

  it('grants strictly more as the tier rises', () => {
    const counts = TIERS.map((level) => grantedTo(admin(level)).length)

    expect(counts[0]).toBeLessThan(counts[1])
    expect(counts[1]).toBeLessThan(counts[2])
    expect(counts[2]).toBeLessThan(counts[3])
  })

  it('denies an unknown capability rather than allowing it', () => {
    expect(can(admin(AccessLevel.SUPER_ADMIN), 'nonsense.invented' as never)).toBe(false)
  })

  it('denies everything to an account with no level', () => {
    const stateless: Actor = { userId: 'u-1', role: 'ADMIN', level: null }

    for (const id of CAPABILITY_IDS) {
      if (CAPABILITIES[id].scope === 'own') continue
      expect(can(stateless, id), id).toBe(false)
    }
  })
})

// ── Resource scopes — the rules G3-2 and G3-3 were about ──────────────────

describe('resource scopes', () => {
  it('lets the assigned driver operate their own trip', () => {
    expect(
      can(driver, 'trip.operate.complete', { actorDriverId: 'd-1', assignedDriverId: 'd-1' }),
    ).toBe(true)
  })

  it('refuses a driver somebody else’s trip', () => {
    expect(
      can(driver, 'trip.operate.complete', { actorDriverId: 'd-1', assignedDriverId: 'd-2' }),
    ).toBe(false)
  })

  it('refuses read-only oversight the same trip', () => {
    // The exact case that was G3-3: a VIEWER completed a running trip.
    expect(can(admin(AccessLevel.VIEWER), 'trip.operate.complete')).toBe(false)
    expect(can(admin(AccessLevel.SUPPORT), 'trip.operate.complete')).toBe(false)
    expect(can(admin(AccessLevel.OPERATIONS), 'trip.operate.complete')).toBe(true)
  })

  it('lets any driver raise an incident, and no viewer', () => {
    expect(can(driver, 'incident.create')).toBe(true)
    expect(can(admin(AccessLevel.VIEWER), 'incident.create')).toBe(false)
    expect(can(admin(AccessLevel.SUPPORT), 'incident.create')).toBe(true)
  })

  it('lets the reporter annotate their own incident', () => {
    const reporter: Actor = { userId: 'u-9', role: 'DRIVER', level: null }

    expect(can(reporter, 'incident.note.create', { reportedById: 'u-9' })).toBe(true)
    expect(can(reporter, 'incident.note.create', { reportedById: 'u-other' })).toBe(false)
  })

  it('separates annotating an incident from reading one', () => {
    // The controller authorised `view` for notes until G3-3, so reading
    // carried the right to write.
    expect(can(admin(AccessLevel.VIEWER), 'incident.read')).toBe(true)
    expect(can(admin(AccessLevel.VIEWER), 'incident.note.create')).toBe(false)
  })

  it('lets a subject edit their own record, and no lesser tier edit theirs', () => {
    const student: Actor = { userId: 'u-5', role: 'STUDENT', level: null }

    expect(can(student, 'student.update', { subjectUserId: 'u-5' })).toBe(true)
    expect(can(student, 'student.update', { subjectUserId: 'u-6' })).toBe(false)
    expect(can(admin(AccessLevel.SUPPORT), 'student.update')).toBe(false)
    expect(can(admin(AccessLevel.OPERATIONS), 'student.update')).toBe(true)
  })

  it('keeps account editing at super admin, or the subject', () => {
    expect(can(admin(AccessLevel.OPERATIONS), 'account.update')).toBe(false)
    expect(can(admin(AccessLevel.OPERATIONS), 'account.update', { subjectUserId: 'u-1' })).toBe(true)
    expect(can(admin(AccessLevel.SUPER_ADMIN), 'account.update')).toBe(true)
  })
})
