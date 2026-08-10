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

/**
 * Capabilities the panel gates on, and the level the *server* enforces for
 * each — taken from 12-access-control-matrix.md, which was generated from the
 * router.
 *
 * Adding a capability here is not a decision about what a person may do. It is
 * a record of a decision the backend has already made.
 */
export const CAPABILITY: Record<string, AccessLevel> = {
  // Safety
  'incident.acknowledge': AccessLevel.SUPPORT,
  'incident.resolve': AccessLevel.SUPPORT,
  'incident.close': AccessLevel.OPERATIONS,
  'incident.note': AccessLevel.SUPPORT,
  'incident.cancel': AccessLevel.SUPPORT,

  // Recovery
  'replacement.dispatch': AccessLevel.SUPPORT,
  'replacement.arrived': AccessLevel.SUPPORT,
  'replacement.approve': AccessLevel.OPERATIONS,
  'replacement.reject': AccessLevel.OPERATIONS,

  // Workshop
  'maintenance.open': AccessLevel.SUPPORT,
  'maintenance.assign': AccessLevel.SUPPORT,
  'maintenance.schedule': AccessLevel.SUPPORT,
  'maintenance.start': AccessLevel.SUPPORT,
  'maintenance.complete': AccessLevel.OPERATIONS,
  'maintenance.cancel': AccessLevel.OPERATIONS,

  // Operations
  'trip.cancel': AccessLevel.OPERATIONS,
  'trip.reassign': AccessLevel.OPERATIONS,
  'trip.correct': AccessLevel.OPERATIONS,
  'bus.changeStatus': AccessLevel.OPERATIONS,
  'bus.manage': AccessLevel.OPERATIONS,
  'driver.manage': AccessLevel.OPERATIONS,
  'student.manage': AccessLevel.OPERATIONS,
  'announcement.manage': AccessLevel.OPERATIONS,
  'notification.resend': AccessLevel.SUPPORT,

  // The system itself
  'audit.view': AccessLevel.SUPER_ADMIN,
  'accounts.manage': AccessLevel.SUPER_ADMIN,
  'personalData.export': AccessLevel.SUPER_ADMIN,
}

export type Capability = keyof typeof CAPABILITY
