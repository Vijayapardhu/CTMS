import { Link } from 'react-router-dom'
import { Icon } from '@/icons/Icon'

/**
 * 403, as a screen.
 *
 * Never a redirect to login — the caller is authenticated, they simply may not
 * be here, and bouncing them to a sign-in form invites them to try a different
 * account. Nothing here names the endpoint or repeats the server's internal
 * wording.
 */
export function Forbidden() {
  return (
    <div className="grid place-items-center rounded-md border border-outline bg-surface p-xxl text-center">
      <Icon name="blocked" size="lg" className="text-on-surface-muted" />
      <h1 className="mt-md text-title-lg font-semibold">You don&rsquo;t have permission to access this page.</h1>
      <p className="mt-xs max-w-prose text-body text-on-surface-muted">
        Your account does not include this area. If you believe it should, the transport office can change your access
        level.
      </p>
      <Link
        to="/"
        className="mt-lg flex h-[var(--size-control)] items-center rounded-sm bg-primary px-lg text-body font-semibold text-on-primary"
      >
        Back to the dashboard
      </Link>
    </div>
  )
}
