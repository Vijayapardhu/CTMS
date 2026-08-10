import { AccessLevel, meets } from './accessLevel'
import { CAPABILITIES, CAPABILITY_IDS, type CapabilityDefinition, type CapabilityId } from './capabilities.generated'

export { CAPABILITIES, CAPABILITY_IDS }
export type { CapabilityDefinition, CapabilityId, CapabilityScope } from './capabilities.generated'

/**
 * Who is asking, and about what.
 *
 * `subjectId` and the driver fields exist because several backend policies
 * scope on the *record*, not only the tier — the assigned driver of a trip, the
 * person who raised an incident, the subject of a profile. Flattening those to
 * "an admin can do it" is precisely what caused G3-1, G3-2 and G3-3.
 */
export type Actor = {
  userId: string | null
  role: string | null
  level: AccessLevel | null
}

/** The record an action is aimed at, where the policy scopes on one. */
export type ResourceScope = {
  /** Driver id assigned to this trip. */
  assignedDriverId?: string | null
  /** Driver profile id of the actor, if they are a driver. */
  actorDriverId?: string | null
  /** Who raised the incident. */
  reportedById?: string | null
  /** Whose record this is. */
  subjectUserId?: string | null
}

export function definitionOf(id: CapabilityId): CapabilityDefinition {
  return CAPABILITIES[id]
}

/**
 * May this actor perform this capability?
 *
 * The panel asks this to decide what to **offer**. The server decides what
 * happens; a 403 arriving anyway means this table is wrong, which is why the
 * table is generated from the router rather than typed out.
 */
export function can(actor: Actor, id: CapabilityId, resource: ResourceScope = {}): boolean {
  const capability = CAPABILITIES[id]

  // An unknown id denies. A typo must hide a control, never expose one.
  if (!capability) return false

  switch (capability.scope) {
    case 'own':
      // Self-service. There is no administrative path, so an operator acting
      // on somebody else's record is not merely unauthorised — the API has no
      // such operation.
      return actor.userId !== null

    case 'assignedDriver':
      if (
        actor.role === 'DRIVER' &&
        resource.actorDriverId &&
        resource.assignedDriverId &&
        resource.actorDriverId === resource.assignedDriverId
      ) {
        return true
      }
      break

    case 'anyDriver':
      if (actor.role === 'DRIVER') return true
      break

    case 'reporter':
      if (actor.userId && resource.reportedById && actor.userId === resource.reportedById) {
        return true
      }
      break

    case 'subject':
      if (actor.userId && resource.subjectUserId && actor.userId === resource.subjectUserId) {
        return true
      }
      break

    case 'tier':
      break
  }

  // Every scope falls through to the tier — that is what "or OPERATIONS" means.
  return meets(actor.level, capability.minimumAccessLevel)
}

/** Capabilities this actor holds, for navigation and for tests. */
export function grantedTo(actor: Actor): CapabilityId[] {
  return CAPABILITY_IDS.filter((id) => can(actor, id))
}

export function canRead(actor: Actor, id: CapabilityId, resource: ResourceScope = {}): boolean {
  return !CAPABILITIES[id]?.mutates && can(actor, id, resource)
}

export function canMutate(actor: Actor, id: CapabilityId, resource: ResourceScope = {}): boolean {
  return Boolean(CAPABILITIES[id]?.mutates) && can(actor, id, resource)
}
