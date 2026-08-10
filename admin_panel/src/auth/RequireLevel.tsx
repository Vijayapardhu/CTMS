import type { ReactNode } from 'react'
import { Forbidden } from '@/components/Forbidden'
import type { AccessLevel } from './accessLevel'
import { useSession } from './SessionProvider'

/**
 * A route guard.
 *
 * Hiding a sidebar entry is not access control — somebody typing the URL
 * reaches the screen anyway. This renders the forbidden state instead, and
 * deliberately does **not** redirect to login: the caller is authenticated,
 * and bouncing them to a sign-in form invites them to try another account.
 */
export function RequireLevel({ level, children }: { level: AccessLevel; children: ReactNode }) {
  const { hasAccess } = useSession()

  return hasAccess(level) ? <>{children}</> : <Forbidden />
}
