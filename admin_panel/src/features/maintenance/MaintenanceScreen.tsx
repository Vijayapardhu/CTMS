import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip, type StatusTone } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, RangeLabel, RefreshButton, StaleBanner } from '@/components/Panel'
import { ActionButton, OperationResult, useOperation } from '@/components/operations'
import { Can } from '@/auth/Can'
import type { AppIconName } from '@/icons/registry'
import { fetchBuses } from '@/features/fleet/api'
import {
  amount,
  daysUntil,
  fetchPreventive,
  fetchTickets,
  humanise,
  maintenanceKeys,
  openTicket,
  personName,
  shortDate,
  type MaintenanceFilters,
  type MaintenancePriority,
  type MaintenanceStatus,
} from './api'

const STATUSES: MaintenanceStatus[] = ['OPEN', 'SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED']
const PRIORITIES: MaintenancePriority[] = ['LOW', 'MEDIUM', 'HIGH', 'URGENT']

const STATUS_TONE: Record<MaintenanceStatus, StatusTone> = {
  OPEN: 'caution',
  SCHEDULED: 'info',
  IN_PROGRESS: 'info',
  COMPLETED: 'positive',
  CANCELLED: 'neutral',
}

const STATUS_ICON: Record<MaintenanceStatus, AppIconName> = {
  OPEN: 'warning',
  SCHEDULED: 'schedule',
  IN_PROGRESS: 'maintenance',
  COMPLETED: 'success',
  CANCELLED: 'blocked',
}

export function TicketStatusChip({ status }: { status: MaintenanceStatus }) {
  return <StatusChip label={humanise(status)} tone={STATUS_TONE[status] ?? 'neutral'} icon={STATUS_ICON[status]} />
}

const PRIORITY_TONE: Record<MaintenancePriority, StatusTone> = {
  LOW: 'neutral',
  MEDIUM: 'info',
  HIGH: 'caution',
  URGENT: 'critical',
}

export function PriorityChip({ priority }: { priority: MaintenancePriority }) {
  return <StatusChip label={humanise(priority)} tone={PRIORITY_TONE[priority] ?? 'neutral'} />
}

/**
 * A10 Maintenance.
 *
 * Two tabs, because they answer two different questions: what is broken now,
 * and what is due before it breaks. The second is `GET /preventive-maintenance`,
 * whose `due=1` filter is applied by the **server** — due-ness spans days and
 * kilometres and is not something to recompute here from partial data.
 */
export function MaintenanceScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()
  const tab = params.get('tab') === 'preventive' ? 'preventive' : 'tickets'

  const update = (next: Record<string, string | number | undefined>) => {
    const merged = new URLSearchParams(params)
    for (const [key, value] of Object.entries(next)) {
      if (value === undefined || value === '') merged.delete(key)
      else merged.set(key, String(value))
    }
    if (!('page' in next)) merged.delete('page')
    setParams(merged, { replace: true })
  }

  return (
    <>
      <PageHeader
        title="Maintenance"
        subtitle="Work raised against the fleet, and the services falling due."
        actions={
          <div className="flex items-center gap-sm">
            <Can capability="maintenance.open">
              <OpenTicketButton />
            </Can>
            <RefreshButton
              onClick={() => void queryClient.invalidateQueries({ queryKey: ['maintenance'] })}
            />
          </div>
        }
      />

      <div className="mb-lg flex gap-xs border-b border-outline" role="tablist">
        <TabButton active={tab === 'tickets'} onClick={() => update({ tab: undefined })}>
          Tickets
        </TabButton>
        <TabButton active={tab === 'preventive'} onClick={() => update({ tab: 'preventive' })}>
          Preventive
        </TabButton>
      </div>

      {tab === 'tickets' ? <TicketQueue params={params} update={update} /> : <PreventiveTab />}
    </>
  )
}

function TabButton({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: string
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={`h-[var(--size-toolbar)] px-lg text-body ${
        active ? 'border-b-2 border-primary font-semibold text-primary' : 'text-on-surface-muted'
      }`}
    >
      {children}
    </button>
  )
}

function TicketQueue({
  params,
  update,
}: {
  params: URLSearchParams
  update: (next: Record<string, string | number | undefined>) => void
}) {
  const status = (params.get('status') as MaintenanceStatus | null) ?? ''
  const filters: MaintenanceFilters = {
    status,
    priority: (params.get('priority') as MaintenancePriority | null) ?? '',
    open: !status && params.get('all') !== '1',
    page: Number(params.get('page') ?? 1),
    per_page: 20,
  }

  const tickets = useQuery({ queryKey: maintenanceKeys.list(filters), queryFn: () => fetchTickets(filters) })
  const pagination = tickets.data?.pagination

  return (
    <>
      <div className="mb-lg flex flex-wrap items-end gap-md rounded-md border border-outline bg-surface p-md">
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Status</span>
          <select
            value={status}
            onChange={(event) => update({ status: event.target.value, all: undefined })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Open work</option>
            {STATUSES.map((value) => (
              <option key={value} value={value}>
                {humanise(value)}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Priority</span>
          <select
            value={filters.priority}
            onChange={(event) => update({ priority: event.target.value })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Every priority</option>
            {PRIORITIES.map((value) => (
              <option key={value} value={value}>
                {humanise(value)}
              </option>
            ))}
          </select>
        </label>

        {filters.open && (
          <button
            type="button"
            onClick={() => update({ all: '1' })}
            className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body"
          >
            Include completed and cancelled
          </button>
        )}

        <span className="ml-auto">
          <RangeLabel pagination={pagination} noun="tickets" />
        </span>
      </div>

      <div className="overflow-hidden rounded-md border border-outline bg-surface">
        {tickets.isError && tickets.data && (
          <StaleBanner error={tickets.error} onRetry={() => void tickets.refetch()} />
        )}
        {tickets.isPending && <LoadingRows />}
        {tickets.isError && !tickets.data && (
          <LoadFailed what="maintenance tickets" error={tickets.error} onRetry={() => void tickets.refetch()} />
        )}
        {tickets.data && tickets.data.rows.length === 0 && (
          <EmptyState
            icon="success"
            title={filters.open ? 'No open work' : 'No tickets match these filters'}
            hint={filters.open ? 'Nothing in the fleet is waiting on the workshop.' : undefined}
          />
        )}

        {tickets.data && tickets.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Bus</th>
                  <th scope="col" className="px-lg font-medium">Issue</th>
                  <th scope="col" className="px-lg font-medium">Priority</th>
                  <th scope="col" className="px-lg font-medium">Status</th>
                  <th scope="col" className="px-lg font-medium">Assigned</th>
                  <th scope="col" className="px-lg font-medium">Opened</th>
                  <th scope="col" className="px-lg font-medium" />
                </tr>
              </thead>
              <tbody>
                {tickets.data.rows.map((ticket) => (
                  <tr
                    key={ticket.id}
                    className="h-[var(--size-row)] border-b border-outline last:border-0 hover:bg-surface-sunken"
                  >
                    <td className="px-lg font-mono">
                      {ticket.bus ? (
                        <Link to={`/buses/${ticket.bus.id}`} className="hover:text-primary">
                          {ticket.bus.registration_number}
                        </Link>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="max-w-[24rem] truncate px-lg">
                      <Link to={`/maintenance/${ticket.id}`} className="font-semibold hover:text-primary">
                        {ticket.issue_description}
                      </Link>
                    </td>
                    <td className="px-lg">
                      <PriorityChip priority={ticket.priority} />
                    </td>
                    <td className="px-lg">
                      <TicketStatusChip status={ticket.status} />
                    </td>
                    <td className="truncate px-lg">{personName(ticket.assigned_to)}</td>
                    <td className="px-lg whitespace-nowrap">{shortDate(ticket.created_at)}</td>
                    <td className="px-lg text-right">
                      <Link to={`/maintenance/${ticket.id}`} className="text-primary">
                        Open
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <Pager pagination={pagination} onPage={(page) => update({ page })} />
    </>
  )
}

/**
 * Preventive maintenance.
 *
 * `due_on` and `due_at_odometer` are stored columns and are shown as stored.
 * Whether a schedule is *due* is the server's answer via `due=1`, because it
 * depends on both axes and on the bus's current odometer.
 */
function PreventiveTab() {
  const [dueOnly, setDueOnly] = useState(true)
  const schedules = useQuery({
    queryKey: maintenanceKeys.preventive(dueOnly),
    queryFn: () => fetchPreventive(dueOnly),
  })

  return (
    <>
      <div className="mb-lg flex flex-wrap items-center gap-md rounded-md border border-outline bg-surface p-md">
        <label className="flex items-center gap-sm text-body">
          <input type="checkbox" checked={dueOnly} onChange={(event) => setDueOnly(event.target.checked)} />
          Only services that are due
        </label>
        <span className="text-label text-on-surface-muted">
          Due-ness is the server's answer — it depends on days elapsed and kilometres run.
        </span>
      </div>

      <div className="overflow-hidden rounded-md border border-outline bg-surface">
        {schedules.isPending && <LoadingRows rows={3} />}
        {schedules.isError && !schedules.data && (
          <LoadFailed
            what="preventive maintenance"
            error={schedules.error}
            onRetry={() => void schedules.refetch()}
          />
        )}
        {schedules.data && schedules.data.rows.length === 0 && (
          <EmptyState
            icon="success"
            title={dueOnly ? 'Nothing is due' : 'No preventive schedules'}
            hint={dueOnly ? 'Every scheduled service is still within its interval.' : undefined}
          />
        )}

        {schedules.data && schedules.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Bus</th>
                  <th scope="col" className="px-lg font-medium">Service</th>
                  <th scope="col" className="px-lg font-medium">Interval</th>
                  <th scope="col" className="px-lg font-medium">Due on</th>
                  <th scope="col" className="px-lg font-medium">Due at</th>
                  <th scope="col" className="px-lg font-medium">Open ticket</th>
                </tr>
              </thead>
              <tbody>
                {schedules.data.rows.map((schedule) => {
                  const days = daysUntil(schedule.due_on)

                  return (
                    <tr key={schedule.id} className="h-[var(--size-row)] border-b border-outline last:border-0">
                      <td className="px-lg font-mono">
                        {schedule.bus ? (
                          <Link to={`/buses/${schedule.bus.id}`} className="hover:text-primary">
                            {schedule.bus.registration_number}
                          </Link>
                        ) : (
                          '—'
                        )}
                      </td>
                      <td className="px-lg">{schedule.service_name}</td>
                      <td className="px-lg text-on-surface-muted">
                        {[
                          schedule.interval_days ? `${schedule.interval_days} days` : null,
                          schedule.interval_km ? `${schedule.interval_km.toLocaleString()} km` : null,
                        ]
                          .filter(Boolean)
                          .join(' · ') || '—'}
                      </td>
                      <td className="px-lg whitespace-nowrap">
                        {shortDate(schedule.due_on)}
                        {days !== null && (
                          <span className={`ml-sm text-label ${days < 0 ? 'text-critical' : 'text-on-surface-muted'}`}>
                            {days < 0 ? `${Math.abs(days)} days overdue` : days === 0 ? 'today' : `in ${days} days`}
                          </span>
                        )}
                      </td>
                      <td className="px-lg">
                        {schedule.due_at_odometer ? `${schedule.due_at_odometer.toLocaleString()} km` : '—'}
                      </td>
                      <td className="px-lg">
                        {schedule.open_ticket ? (
                          <Link to={`/maintenance/${schedule.open_ticket.id}`} className="text-primary">
                            {humanise(schedule.open_ticket.status)}
                          </Link>
                        ) : (
                          <span className="text-on-surface-muted">None</span>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </>
  )
}

/**
 * Raising work. SUPPORT and above — `RequireAccessLevel:SUPPORT` on the route.
 *
 * A11 links here with `?bus_id=…&compose=1` when somebody raises a ticket from
 * a failed readiness check. Two screens, one intent, and no invented endpoint
 * that does both.
 */
function OpenTicketButton() {
  const [params] = useSearchParams()
  const prefilledBus = params.get('bus_id') ?? ''
  const [open, setOpen] = useState(params.get('compose') === '1')
  const [busId, setBusId] = useState(prefilledBus)
  const [issue, setIssue] = useState('')
  const [priority, setPriority] = useState<MaintenancePriority>('MEDIUM')

  const buses = useQuery({
    queryKey: ['fleet', 'picker'],
    queryFn: () => fetchBuses({ page: 1, per_page: 100 }),
    enabled: open,
  })

  const create = useOperation<void>({
    run: () => openTicket({ bus_id: busId, issue_description: issue.trim(), priority }),
    invalidate: [['maintenance']],
    onSuccess: () => {
      setIssue('')
      setBusId('')
    },
  })

  return (
    <>
      <ActionButton label="Open a ticket" tone="primary" icon="maintenance" onClick={() => setOpen(true)} />

      {open && (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg">
          <div role="dialog" aria-modal="true" aria-label="Open a maintenance ticket" className="w-full max-w-lg rounded-md border border-outline bg-surface p-xl">
            <h2 className="text-title-lg font-semibold">Open a maintenance ticket</h2>

            <label className="mt-lg block">
              <span className="text-label font-medium text-on-surface-muted uppercase">Bus</span>
              <select
                value={busId}
                onChange={(event) => setBusId(event.target.value)}
                className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
              >
                <option value="">Choose a bus</option>
                {buses.data?.rows.map((bus) => (
                  <option key={bus.id} value={bus.id}>
                    {bus.registration_number} · {bus.model ?? 'Unknown model'}
                  </option>
                ))}
              </select>
              {create.fieldError('bus_id') && (
                <span className="mt-xs block text-label text-critical">{create.fieldError('bus_id')}</span>
              )}
            </label>

            <label className="mt-lg block">
              <span className="text-label font-medium text-on-surface-muted uppercase">What is wrong</span>
              <textarea
                value={issue}
                onChange={(event) => setIssue(event.target.value)}
                rows={3}
                className="mt-xs w-full rounded-sm border border-outline bg-surface p-md text-body"
              />
              {create.fieldError('issue_description') && (
                <span className="mt-xs block text-label text-critical">
                  {create.fieldError('issue_description')}
                </span>
              )}
            </label>

            <label className="mt-lg block">
              <span className="text-label font-medium text-on-surface-muted uppercase">Priority</span>
              <select
                value={priority}
                onChange={(event) => setPriority(event.target.value as MaintenancePriority)}
                className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
              >
                {PRIORITIES.map((value) => (
                  <option key={value} value={value}>
                    {humanise(value)}
                  </option>
                ))}
              </select>
            </label>

            <div className="mt-lg">
              <OperationResult operation={create} />
            </div>

            <div className="mt-lg flex justify-end gap-sm">
              <ActionButton
                label="Cancel"
                onClick={() => {
                  setOpen(false)
                  create.reset()
                }}
              />
              <ActionButton
                label="Open ticket"
                tone="primary"
                busy={create.isPending}
                disabled={!busId || issue.trim().length < 5}
                title={!busId ? 'Choose a bus.' : issue.trim().length < 5 ? 'Describe the fault in at least 5 characters.' : undefined}
                onClick={() => void create.run().then((ok) => ok && setOpen(false))}
              />
            </div>
          </div>
        </div>
      )}
    </>
  )
}

export { amount }
