import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { CAPABILITIES } from '@/auth/capabilities'
import { navItems } from '@/app/navigation'
import { screens } from '@/routes'
import { CORRECTABLE_FIELDS } from '@/features/trips/api'

/**
 * The integration pass, as assertions rather than a checklist.
 *
 * These read the source itself, because the things they protect cannot be
 * caught by rendering one screen: a typo in one `capability="…"` on a screen
 * nobody wrote a test for, a second permission system growing quietly in a
 * feature folder, a route left with no guard.
 */

const SRC = join(process.cwd(), 'src')

function walk(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry)

    return statSync(path).isDirectory() ? walk(path) : [path]
  })
}

const sources = walk(SRC).filter((path) => /\.tsx?$/.test(path) && !path.endsWith('.d.ts'))
const read = (path: string) => readFileSync(path, 'utf-8')

/**
 * The file with its comments removed.
 *
 * Several of these checks look for a pattern that is *described* in a comment
 * explaining why it must never appear in code — the evidence one especially.
 * Matching the explanation would fail the file that documents the rule best.
 */
const code = (path: string) =>
  read(path)
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:])\/\/.*$/gm, '$1')
const relative = (path: string) => path.slice(SRC.length + 1).replace(/\\/g, '/')

describe('every capability the panel names', () => {
  it('exists in the generated registry', () => {
    const unknown: string[] = []

    for (const path of sources) {
      const body = read(path)
      for (const match of body.matchAll(/capability="([^"]+)"|\bcan\('([^']+)'/g)) {
        const id = match[1] ?? match[2]
        if (!(id in CAPABILITIES)) unknown.push(`${relative(path)} → ${id}`)
      }
    }

    // A typo here hides a control forever, silently, for everybody.
    expect(unknown).toEqual([])
  })
})

describe('there is one permission system', () => {
  it('has no raw access-level comparison in a feature, shell or component', () => {
    const offenders: string[] = []

    for (const path of sources) {
      const where = relative(path)
      // `src/auth` *is* the permission system; `accessLevel.ts` defines the
      // ladder and `SessionProvider` reads it. Everywhere else must ask.
      if (where.startsWith('auth/') || where.startsWith('mocks/')) continue

      const body = read(path)
      const raw = [
        /\bhasAccess\s*\(/,
        /level\s*===\s*['"]?(VIEWER|SUPPORT|OPERATIONS|SUPER_ADMIN)/,
        /accessLevel\s*===/,
        /\bmeets\s*\(/,
      ].filter((pattern) => pattern.test(body))

      if (raw.length > 0) offenders.push(where)
    }

    expect(offenders).toEqual([])
  })

  it('imports the guards rather than reimplementing them', () => {
    const reimplemented = sources
      .filter((path) => !relative(path).startsWith('auth/'))
      .filter((path) => /function\s+(Can|RequireCapability|RequireLevel)\b/.test(read(path)))
      .map(relative)

    expect(reimplemented).toEqual([])
  })
})

describe('every screen', () => {
  it('is guarded by a capability', () => {
    expect(screens.filter((screen) => !screen.capability).map((screen) => screen.path)).toEqual([])
  })

  it('is built — no placeholder remains', () => {
    const unbuilt = screens.filter((screen) => !screen.element).map((screen) => screen.path)

    expect(unbuilt).toEqual([])
  })

  it('is reachable from navigation, or is a detail of something that is', () => {
    const navigable = new Set(navItems.map((item) => item.path))
    const orphans = screens
      .filter((screen) => !navigable.has(screen.path))
      // A detail screen is reached from its list, not from the sidebar.
      .filter((screen) => !screen.path.includes(':'))
      .map((screen) => screen.path)

    expect(orphans).toEqual([])
  })
})

describe('the correction dialog', () => {
  it('offers only fields the backend will accept', () => {
    // The panel and `TripRecoveryService::CORRECTABLE` have to agree. When
    // they did not, choosing "Notes" or either odometer field produced a 500
    // from the database instead of a correction. The backend list is the
    // authority; this is the tripwire on this side.
    const backend = readFileSync(
      join(process.cwd(), '..', 'backend', 'app', 'Services', 'Trips', 'TripRecoveryService.php'),
      'utf-8',
    )
    const block = backend.slice(backend.indexOf('const CORRECTABLE'))
    const allowed = [...block.slice(0, block.indexOf(']')).matchAll(/'([a-z_]+)'/g)].map((m) => m[1])

    expect(allowed.length).toBeGreaterThan(0)
    expect(CORRECTABLE_FIELDS.map((field) => field.value).sort()).toEqual([...allowed].sort())
  })
})

describe('the API surface', () => {
  it('builds every request through the one client', () => {
    const direct = sources
      .filter((path) => !relative(path).startsWith('api/'))
      .filter((path) => !relative(path).startsWith('mocks/'))
      // `fetch` anywhere else would miss the token, the refresh and the
      // failure vocabulary.
      .filter((path) => /\bfetch\s*\(/.test(read(path)))
      .map(relative)

    expect(direct).toEqual([])
  })

  it('never constructs a URL for private evidence', () => {
    const constructed = sources
      .filter((path) => /(src|href)\s*=\s*[{"'`][^}"'`]*\/evidence\//.test(code(path)))
      .map(relative)

    // Evidence is fetched as bytes with a token and held in an object URL.
    // A constructed link would either 401 or, worse, work for whoever it was
    // pasted to.
    expect(constructed).toEqual([])
  })

  it('hard-codes no credential', () => {
    const suspicious = sources
      .filter((path) => !relative(path).startsWith('mocks/'))
      .filter((path) => {
        const body = read(path)

        return (
          /AIza[0-9A-Za-z_-]{20,}/.test(body) || // a Google API key
          /eyJ[A-Za-z0-9_-]{20,}\./.test(body) || // a JWT
          /(secret|password|api[_-]?key)\s*[:=]\s*['"][^'"]{8,}['"]/i.test(body)
        )
      })
      .map(relative)

    expect(suspicious).toEqual([])
  })
})
