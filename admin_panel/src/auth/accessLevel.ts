/**
 * The backend's second axis of administrator authorization.
 *
 * `admins.access_level`, mirrored exactly — no new roles, no frontend-only
 * permissions. `AccessLevel::atLeast()` in PHP makes these a ladder rather
 * than a set of equals, and `meets()` below is the same comparison.
 *
 * This decides what the panel *offers*. The server decides what happens.
 */
export enum AccessLevel {
  VIEWER = 'VIEWER',
  SUPPORT = 'SUPPORT',
  OPERATIONS = 'OPERATIONS',
  SUPER_ADMIN = 'SUPER_ADMIN',
}

const RANK: Record<AccessLevel, number> = {
  [AccessLevel.VIEWER]: 0,
  [AccessLevel.SUPPORT]: 1,
  [AccessLevel.OPERATIONS]: 2,
  [AccessLevel.SUPER_ADMIN]: 3,
}

export function parseAccessLevel(raw: unknown): AccessLevel | null {
  if (typeof raw !== 'string') return null
  const upper = raw.toUpperCase()

  return upper in RANK ? (upper as AccessLevel) : null
}

/** Whether `held` meets or exceeds `required` — PHP's `atLeast()`. */
export function meets(held: AccessLevel | null, required: AccessLevel): boolean {
  if (held === null) return false

  return RANK[held] >= RANK[required]
}

/**
 * Product terminology, for the one place a person reads their own level.
 *
 * The backend's words are the canonical ones; these are what a transport
 * office calls the same thing.
 */
export const ACCESS_LEVEL_LABEL: Record<AccessLevel, string> = {
  [AccessLevel.VIEWER]: 'Oversight',
  [AccessLevel.SUPPORT]: 'Supervisor',
  [AccessLevel.OPERATIONS]: 'Transport Head',
  [AccessLevel.SUPER_ADMIN]: 'System Administrator',
}
