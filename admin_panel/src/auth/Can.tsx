import type { ReactNode } from 'react'
import { useSession } from './SessionProvider'
import type { CapabilityId, ResourceScope } from './capabilities'

/**
 * An action surface, gated by capability.
 *
 * Absent, not disabled: what a tier can never do should not be on screen at
 * all. A permanently greyed-out control teaches somebody the product is broken
 * for them. `fallback` exists for the rarer case where the *state* forbids it
 * and saying so helps.
 *
 * This is UX. The server is still the boundary.
 */
export function Can({
  capability,
  resource,
  fallback = null,
  children,
}: {
  capability: CapabilityId
  resource?: ResourceScope
  fallback?: ReactNode
  children: ReactNode
}) {
  const { can } = useSession()

  return can(capability, resource) ? <>{children}</> : <>{fallback}</>
}
