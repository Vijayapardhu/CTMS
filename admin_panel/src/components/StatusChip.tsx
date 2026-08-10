import { Icon } from '@/icons/Icon'
import type { AppIconName } from '@/icons/registry'

export type StatusTone = 'positive' | 'caution' | 'critical' | 'neutral' | 'info'

const TONE: Record<StatusTone, string> = {
  positive: 'bg-positive text-on-positive',
  caution: 'bg-caution text-on-caution',
  critical: 'bg-critical text-on-critical',
  neutral: 'bg-neutral text-on-neutral',
  info: 'bg-info text-on-info',
}

/**
 * C6 StatusChip.
 *
 * **Never colour alone** — every chip carries a word, and an icon where one
 * adds meaning. Roughly eight percent of male staff have a colour vision
 * deficiency, and a coloured dot tells them nothing.
 *
 * Foregrounds come from the design system's paired tokens, so a chip is
 * legible in both themes without anybody choosing a text colour by eye.
 */
export function StatusChip({
  label,
  tone,
  icon,
}: {
  label: string
  tone: StatusTone
  icon?: AppIconName
}) {
  return (
    <span
      className={`inline-flex items-center gap-xs rounded-sm px-sm py-xs text-label font-semibold ${TONE[tone]}`}
    >
      {icon && <Icon name={icon} size="xs" />}
      {label}
    </span>
  )
}

const TRIP_TONE: Record<string, StatusTone> = {
  RUNNING: 'positive',
  SCHEDULED: 'info',
  COMPLETED: 'neutral',
  CANCELLED: 'critical',
}

const TRIP_ICON: Record<string, AppIconName> = {
  RUNNING: 'gps',
  SCHEDULED: 'schedule',
  COMPLETED: 'success',
  CANCELLED: 'blocked',
}

export function TripStatusChip({ status }: { status: string }) {
  return (
    <StatusChip
      label={status.charAt(0) + status.slice(1).toLowerCase()}
      tone={TRIP_TONE[status] ?? 'neutral'}
      icon={TRIP_ICON[status]}
    />
  )
}

const STOP_TONE: Record<string, StatusTone> = {
  PENDING: 'neutral',
  APPROACHING: 'info',
  ARRIVED: 'positive',
  DEPARTED: 'positive',
  SKIPPED: 'caution',
}

export function StopStateChip({ state }: { state: string }) {
  return <StatusChip label={state.charAt(0) + state.slice(1).toLowerCase()} tone={STOP_TONE[state] ?? 'neutral'} />
}
