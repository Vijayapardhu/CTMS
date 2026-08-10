import { Link } from 'react-router-dom'
import { Icon } from '@/icons/Icon'
import type { AppIconName } from '@/icons/registry'

type Tone = 'neutral' | 'positive' | 'caution' | 'critical'

type Props = {
  label: string
  /** Undefined means the value could not be determined. Zero means zero. */
  value: number | undefined
  context?: string
  icon: AppIconName
  tone?: Tone
  to?: string
  loading?: boolean
  failed?: boolean
  onRetry?: () => void
}

const TONE: Record<Tone, string> = {
  neutral: 'text-on-surface',
  positive: 'text-positive',
  caution: 'text-caution',
  critical: 'text-critical',
}

/**
 * C5 MetricCard.
 *
 * The distinction this component exists to protect: **`0` and `—` are
 * different**. Zero is the backend saying there are no open incidents, which
 * is the best news of the morning. An em dash is the panel saying it could not
 * find out. Turning a failed request into a zero would tell a transport head
 * the fleet is fine at exactly the moment it might not be.
 *
 * The card reserves its own height so a tile arriving late cannot shift the
 * row that has already been read.
 */
export function MetricCard({
  label,
  value,
  context,
  icon,
  tone = 'neutral',
  to,
  loading = false,
  failed = false,
  onRetry,
}: Props) {
  const body = (
    <div className="flex h-[104px] flex-col justify-between rounded-md border border-outline bg-surface p-lg">
      <div className="flex items-center gap-sm text-label font-medium text-on-surface-muted uppercase">
        <Icon name={icon} size="xs" />
        <span className="truncate">{label}</span>
      </div>

      {loading ? (
        <div className="h-8 w-16 animate-pulse rounded-sm bg-surface-sunken" aria-label={`${label} loading`} />
      ) : failed ? (
        <div className="flex items-center justify-between gap-sm">
          <span className="text-body text-on-surface-muted">Unable to load</span>
          {onRetry && (
            <button
              type="button"
              onClick={(event) => {
                event.preventDefault()
                onRetry()
              }}
              className="rounded-sm border border-outline px-sm py-xs text-label font-medium hover:bg-surface-sunken"
            >
              Retry
            </button>
          )}
        </div>
      ) : (
        <div className="flex items-baseline gap-sm">
          <span className={`text-display font-semibold ${TONE[tone]}`}>{value ?? '—'}</span>
          {context && <span className="truncate text-label text-on-surface-muted">{context}</span>}
        </div>
      )}
    </div>
  )

  if (!to || loading || failed) return body

  return (
    <Link to={to} className="block rounded-md hover:brightness-[0.98]">
      {body}
    </Link>
  )
}
