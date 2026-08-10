import type { ReactNode } from 'react'
import { ApiFailure } from '@/api/failure'
import { Icon } from '@/icons/Icon'
import type { AppIconName } from '@/icons/registry'

/**
 * The four states every list and panel in this product must distinguish.
 *
 * They are separate on purpose. "Loading", "empty", "this failed" and "this
 * failed but I still have yesterday's answer" mean four different things to
 * somebody deciding whether to send a bus out, and collapsing any two of them
 * into a spinner or a blank table is how a control room ends up acting on a
 * screen that was never telling the truth.
 */

export function Panel({
  title,
  action,
  children,
  className = '',
}: {
  title?: string
  action?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <section className={`overflow-hidden rounded-md border border-outline bg-surface ${className}`}>
      {(title || action) && (
        <header className="flex items-center gap-md border-b border-outline px-lg py-md">
          {title && <h2 className="text-title-md font-semibold">{title}</h2>}
          {action && <div className="ml-auto flex items-center gap-sm">{action}</div>}
        </header>
      )}
      {children}
    </section>
  )
}

export function LoadingRows({ rows = 5 }: { rows?: number }) {
  return (
    <div className="p-lg" role="status" aria-live="polite">
      <span className="sr-only">Loading…</span>
      {Array.from({ length: rows }, (_, row) => (
        <div key={row} className="mb-sm h-[var(--size-row)] animate-pulse rounded-sm bg-surface-sunken" />
      ))}
    </div>
  )
}

/**
 * A first load that failed.
 *
 * Never rendered as an empty list: "no incidents" is the best news of the day
 * and "I could not ask" is the worst, and they must not look alike.
 */
export function LoadFailed({
  what,
  error,
  onRetry,
}: {
  what: string
  error: unknown
  onRetry?: () => void
}) {
  const failure = error instanceof ApiFailure ? error : null
  const forbidden = failure?.kind === 'forbidden'

  return (
    <div className="p-xxl text-center" role="alert">
      <Icon name={forbidden ? 'blocked' : 'warning'} size="lg" className={forbidden ? 'text-on-surface-muted' : 'text-caution'} />
      <p className="mt-md text-title-md">
        {forbidden ? `You don’t have permission to view ${what}.` : `Unable to load ${what}`}
      </p>
      {failure && !forbidden && (
        <p className="mt-xs text-body text-on-surface-muted">{failure.displayMessage}</p>
      )}
      {onRetry && !forbidden && (
        <button
          type="button"
          onClick={onRetry}
          className="mt-lg h-[var(--size-control)] rounded-sm bg-primary px-lg text-body font-semibold text-on-primary"
        >
          Try again
        </button>
      )}
    </div>
  )
}

export function EmptyState({
  icon,
  title,
  hint,
}: {
  icon: AppIconName
  title: string
  hint?: string
}) {
  return (
    <div className="p-xxl text-center">
      <Icon name={icon} size="lg" className="text-on-surface-muted" />
      <p className="mt-md text-title-md">{title}</p>
      {hint && <p className="mt-xs text-body text-on-surface-muted">{hint}</p>}
    </div>
  )
}

/**
 * A refresh that failed while data is already on screen.
 *
 * The rows stay. What changes is that the screen stops claiming to be current —
 * silently showing stale operational data as if it were live is the failure
 * mode this whole component exists to prevent.
 */
export function StaleBanner({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const failure = error instanceof ApiFailure ? error : null

  return (
    <p
      className="flex flex-wrap items-center gap-sm border-b border-caution/40 bg-caution/10 px-lg py-sm text-body"
      role="status"
    >
      <Icon name="warning" size="sm" className="text-caution" />
      <span>Showing the last successful result — {failure?.displayMessage ?? 'the refresh failed'}.</span>
      {onRetry && (
        <button type="button" onClick={onRetry} className="ml-auto font-semibold text-primary">
          Retry
        </button>
      )}
    </p>
  )
}

/** A labelled value. `—` when the backend genuinely has nothing, never a guess. */
export function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-label font-medium text-on-surface-muted uppercase">{label}</dt>
      <dd className="mt-xs text-body break-words">{children ?? '—'}</dd>
    </div>
  )
}

export function FieldGrid({ children, columns = 3 }: { children: ReactNode; columns?: 2 | 3 | 4 }) {
  const grid = { 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-2 lg:grid-cols-3', 4: 'sm:grid-cols-2 lg:grid-cols-4' }[columns]

  return <dl className={`grid gap-lg p-lg ${grid}`}>{children}</dl>
}

/** Pagination, only where the endpoint actually paginates. */
export function Pager({
  pagination,
  onPage,
}: {
  pagination?: { current_page: number; last_page: number; total: number; per_page: number }
  onPage: (page: number) => void
}) {
  if (!pagination || pagination.last_page <= 1) return null

  return (
    <div className="mt-lg flex items-center justify-end gap-sm">
      <button
        type="button"
        disabled={pagination.current_page <= 1}
        onClick={() => onPage(pagination.current_page - 1)}
        className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body disabled:opacity-40"
      >
        Previous
      </button>
      <span className="text-body text-on-surface-muted">
        Page {pagination.current_page} of {pagination.last_page}
      </span>
      <button
        type="button"
        disabled={pagination.current_page >= pagination.last_page}
        onClick={() => onPage(pagination.current_page + 1)}
        className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body disabled:opacity-40"
      >
        Next
      </button>
    </div>
  )
}

/** "1–20 of 96", or the honest absence of any rows. */
export function RangeLabel({
  pagination,
  noun,
}: {
  pagination?: { current_page: number; last_page: number; total: number; per_page: number }
  noun: string
}) {
  if (!pagination) return null
  if (pagination.total === 0) return <span className="text-label text-on-surface-muted">No {noun}</span>

  const from = (pagination.current_page - 1) * pagination.per_page + 1
  const to = Math.min(pagination.current_page * pagination.per_page, pagination.total)

  return (
    <span className="text-label text-on-surface-muted">
      {from}–{to} of {pagination.total} {noun}
    </span>
  )
}

export function RefreshButton({
  onClick,
  busy,
  label = 'Refresh',
}: {
  onClick: () => void
  busy?: boolean
  label?: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={busy}
      className="flex h-[var(--size-control)] items-center gap-sm rounded-sm border border-outline px-lg text-body disabled:opacity-60"
    >
      <Icon name="refresh" size="sm" />
      {busy ? 'Refreshing…' : label}
    </button>
  )
}
