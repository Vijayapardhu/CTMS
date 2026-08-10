import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip, type StatusTone } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, Panel, RefreshButton, StaleBanner } from '@/components/Panel'
import { ActionButton, OperationResult, useOperation } from '@/components/operations'
import { Can } from '@/auth/Can'
import { Icon } from '@/icons/Icon'
import {
  commsKeys,
  fetchDeliveries,
  fetchDeliveryHealth,
  fetchNotifications,
  fetchUnreadCount,
  humanise,
  markAllNotificationsRead,
  markNotificationRead,
  personName,
  resendDelivery,
  whenText,
  type DeliveryStatus,
} from './api'

const DELIVERY_TONE: Record<DeliveryStatus, StatusTone> = {
  QUEUED: 'neutral',
  SENT: 'info',
  DELIVERED: 'positive',
  RETRYING: 'caution',
  PERMANENTLY_FAILED: 'critical',
  SUPPRESSED: 'neutral',
}

const STATUSES: DeliveryStatus[] = [
  'QUEUED',
  'SENT',
  'DELIVERED',
  'RETRYING',
  'PERMANENTLY_FAILED',
  'SUPPRESSED',
]

/**
 * A13 Alerts.
 *
 * Two panels that are never merged (G1-4). "My alerts" is this administrator's
 * own inbox. "Delivery health" is whether CTMS is reaching handsets at all.
 * An empty inbox and a dead delivery pipeline look identical on a screen that
 * conflates them, and they mean opposite things.
 */
export function AlertsScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()
  const page = Number(params.get('page') ?? 1)

  const notifications = useQuery({
    queryKey: commsKeys.notifications(page),
    queryFn: () => fetchNotifications(page),
  })
  const unread = useQuery({ queryKey: commsKeys.unread, queryFn: fetchUnreadCount })

  const markAll = useOperation({
    run: () => markAllNotificationsRead(),
    invalidate: [['notifications']],
  })

  const setPage = (next: number) => {
    const merged = new URLSearchParams(params)
    merged.set('page', String(next))
    setParams(merged, { replace: true })
  }

  return (
    <>
      <PageHeader
        title="Alerts"
        subtitle="What CTMS has told you, and whether it is reaching anybody else."
        actions={
          <RefreshButton
            onClick={() => {
              void queryClient.invalidateQueries({ queryKey: ['notifications'] })
              void queryClient.invalidateQueries({ queryKey: ['notification-log'] })
            }}
          />
        }
      />

      <div className="grid gap-lg xl:grid-cols-[1.2fr_1fr]">
        <Panel
          title="My alerts"
          action={
            <div className="flex items-center gap-sm">
              {unread.data !== undefined && unread.data > 0 && (
                <StatusChip label={`${unread.data} unread`} tone="info" />
              )}
              <ActionButton
                label="Mark all read"
                busy={markAll.isPending}
                disabled={!unread.data}
                title={unread.data ? undefined : 'Nothing is unread.'}
                onClick={() => void markAll.run()}
              />
            </div>
          }
        >
          {markAll.failure && (
            <div className="p-lg">
              <OperationResult operation={markAll} />
            </div>
          )}

          {notifications.isError && notifications.data && (
            <StaleBanner error={notifications.error} onRetry={() => void notifications.refetch()} />
          )}
          {notifications.isPending && <LoadingRows rows={4} />}
          {notifications.isError && !notifications.data && (
            <LoadFailed
              what="your alerts"
              error={notifications.error}
              onRetry={() => void notifications.refetch()}
            />
          )}
          {notifications.data && notifications.data.rows.length === 0 && (
            <EmptyState icon="alerts" title="Nothing from the system" />
          )}

          {notifications.data?.rows.map((notification) => (
            <NotificationRow key={notification.id} notification={notification} />
          ))}

          <div className="p-lg">
            <Pager pagination={notifications.data?.pagination} onPage={setPage} />
          </div>
        </Panel>

        <DeliveryHealthPanel />
      </div>

      <DeliveryLog />
    </>
  )
}

function NotificationRow({
  notification,
}: {
  notification: { id: string; title: string; body: string | null; category: string; read_at: string | null; created_at: string }
}) {
  const read = useOperation({
    run: () => markNotificationRead(notification.id),
    invalidate: [['notifications']],
  })

  return (
    <article className="flex items-start gap-md border-b border-outline px-lg py-md last:border-0">
      <Icon
        name={notification.read_at ? 'success' : 'alerts'}
        size="sm"
        className={notification.read_at ? 'mt-xs text-on-surface-muted' : 'mt-xs text-primary'}
      />
      <div className="min-w-0 flex-1">
        <p className={notification.read_at ? 'text-body' : 'text-body font-semibold'}>{notification.title}</p>
        {notification.body && <p className="mt-xs text-body text-on-surface-muted">{notification.body}</p>}
        <p className="mt-xs text-label text-on-surface-muted">
          {humanise(notification.category)} · {whenText(notification.created_at)}
        </p>
      </div>
      {!notification.read_at && (
        <button
          type="button"
          onClick={() => void read.run()}
          disabled={read.isPending}
          className="text-label font-semibold text-primary disabled:opacity-50"
        >
          Mark read
        </button>
      )}
    </article>
  )
}

/**
 * Whether the system is reaching handsets.
 *
 * The window and the arithmetic are the server's — `GET /notification-log/health`
 * over 24 hours. A null success rate means nothing was attempted, and is shown
 * as "nothing sent" rather than as 0%, which would read as total failure.
 */
function DeliveryHealthPanel() {
  const health = useQuery({ queryKey: commsKeys.health, queryFn: fetchDeliveryHealth })

  return (
    <Panel title="Delivery health">
      {health.isPending && <LoadingRows rows={3} />}
      {health.isError && (
        <LoadFailed what="delivery health" error={health.error} onRetry={() => void health.refetch()} />
      )}

      {health.data && (
        <>
          <p className="px-lg pt-md text-label text-on-surface-muted">
            The last {health.data.window_hours} hours.
          </p>
          <ul className="p-lg">
            {health.data.channels.map((channel) => (
              <li key={channel.channel} className="border-b border-outline py-md last:border-0">
                <div className="flex flex-wrap items-center gap-sm">
                  <span className="text-body font-semibold">{humanise(channel.channel)}</span>
                  {!channel.enabled && <StatusChip label="Not configured" tone="neutral" />}
                  <span className="ml-auto text-body">
                    {channel.success_rate === null ? (
                      <span className="text-on-surface-muted">Nothing sent</span>
                    ) : (
                      <span
                        className={
                          channel.success_rate >= 95
                            ? 'font-semibold text-positive'
                            : channel.success_rate >= 80
                              ? 'font-semibold text-caution'
                              : 'font-semibold text-critical'
                        }
                      >
                        {channel.success_rate}% delivered
                      </span>
                    )}
                  </span>
                </div>
                <p className="mt-xs text-label text-on-surface-muted">
                  {channel.delivered} delivered · {channel.failed} failed · {channel.pending} pending ·{' '}
                  {channel.suppressed} suppressed
                </p>
              </li>
            ))}
          </ul>
        </>
      )}
    </Panel>
  )
}

/** The delivery log, with the one action it supports: send it again. */
function DeliveryLog() {
  const [params, setParams] = useSearchParams()
  const filters = {
    status: params.get('delivery_status') ?? '',
    channel: params.get('channel') ?? '',
    page: Number(params.get('log_page') ?? 1),
  }

  const deliveries = useQuery({
    queryKey: commsKeys.deliveries(filters),
    queryFn: () => fetchDeliveries(filters),
  })

  const update = (next: Record<string, string | number | undefined>) => {
    const merged = new URLSearchParams(params)
    for (const [key, value] of Object.entries(next)) {
      if (value === undefined || value === '') merged.delete(key)
      else merged.set(key, String(value))
    }
    setParams(merged, { replace: true })
  }

  return (
    <div className="mt-lg">
      <Panel
        title="Delivery log"
        action={
          <label className="flex items-center gap-sm text-label">
            <span className="text-on-surface-muted uppercase">Status</span>
            <select
              value={filters.status}
              onChange={(event) => update({ delivery_status: event.target.value, log_page: undefined })}
              className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
            >
              <option value="">Every status</option>
              {STATUSES.map((value) => (
                <option key={value} value={value}>
                  {humanise(value)}
                </option>
              ))}
            </select>
          </label>
        }
      >
        {deliveries.isError && deliveries.data && (
          <StaleBanner error={deliveries.error} onRetry={() => void deliveries.refetch()} />
        )}
        {deliveries.isPending && <LoadingRows rows={3} />}
        {deliveries.isError && !deliveries.data && (
          <LoadFailed what="the delivery log" error={deliveries.error} onRetry={() => void deliveries.refetch()} />
        )}
        {deliveries.data && deliveries.data.rows.length === 0 && (
          <EmptyState icon="send" title="No deliveries match this filter" />
        )}

        {deliveries.data && deliveries.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Notification</th>
                  <th scope="col" className="px-lg font-medium">To</th>
                  <th scope="col" className="px-lg font-medium">Channel</th>
                  <th scope="col" className="px-lg font-medium">Status</th>
                  <th scope="col" className="px-lg font-medium">Attempts</th>
                  <th scope="col" className="px-lg font-medium">Last tried</th>
                  <th scope="col" className="px-lg font-medium" />
                </tr>
              </thead>
              <tbody>
                {deliveries.data.rows.map((delivery) => (
                  <DeliveryRow key={delivery.id} delivery={delivery} />
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="p-lg">
          <Pager pagination={deliveries.data?.pagination} onPage={(next) => update({ log_page: next })} />
        </div>
      </Panel>
    </div>
  )
}

function DeliveryRow({
  delivery,
}: {
  delivery: import('./api').Delivery
}) {
  const resend = useOperation({ run: () => resendDelivery(delivery.id), invalidate: [['notification-log']] })

  return (
    <tr className="border-b border-outline last:border-0 align-top">
      <td className="max-w-[20rem] truncate px-lg py-md">{delivery.notification?.title ?? delivery.notification?.event_key ?? '—'}</td>
      <td className="truncate px-lg py-md">{personName(delivery.notification?.user)}</td>
      <td className="px-lg py-md">{humanise(delivery.channel)}</td>
      <td className="px-lg py-md">
        <StatusChip label={humanise(delivery.status)} tone={DELIVERY_TONE[delivery.status] ?? 'neutral'} />
        {delivery.reason && <p className="mt-xs text-label text-on-surface-muted">{delivery.reason}</p>}
        {(resend.failure || resend.success) && (
          <div className="mt-xs max-w-sm">
            <OperationResult operation={resend} />
          </div>
        )}
      </td>
      <td className="px-lg py-md font-mono">{delivery.attempts}</td>
      <td className="px-lg py-md whitespace-nowrap">{whenText(delivery.last_attempted_at)}</td>
      <td className="px-lg py-md text-right">
        <Can capability="notification.resend">
          <ActionButton
            label="Send again"
            busy={resend.isPending}
            disabled={delivery.status === 'DELIVERED'}
            title={delivery.status === 'DELIVERED' ? 'This one arrived.' : undefined}
            onClick={() => void resend.run()}
          />
        </Can>
      </td>
    </tr>
  )
}
