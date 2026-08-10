import type { ReactNode } from 'react'
import { Forbidden } from '@/components/Forbidden'
import { useSession } from './SessionProvider'
import type { CapabilityId } from './capabilities'

/**
 * A route guard.
 *
 * Hiding a sidebar entry is not access control — somebody typing the URL
 * arrives anyway. This renders the forbidden state instead, and deliberately
 * does not redirect to login: the caller is authenticated, and a sign-in form
 * invites them to try another account.
 */
export function RequireCapability({
  capability,
  children,
}: {
  capability: CapabilityId
  children: ReactNode
}) {
  const { can } = useSession()

  return can(capability) ? <>{children}</> : <Forbidden />
}
