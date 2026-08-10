import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip, type StatusTone } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, RangeLabel, RefreshButton, StaleBanner } from '@/components/Panel'
import { Icon } from '@/icons/Icon'
import type { AppIconName } from '@/icons/registry'
import {
  fetchIncidents,
  humanise,
  incidentKeys,
  personName,
  whenText,
  type IncidentClass,
  type IncidentFilters,
  type IncidentSeverity,
  type IncidentStatus,
} from './api'

const STATUSES: IncidentStatus[] = ['REPORTED', 'ACKNOWLEDGED', 'IN_PROGRESS', 'ESCALATED', 'RESOLVED', 'CLOSED']
const CLASSES: IncidentClass[] = ['LIFE_SAFETY', 'OPERATIONAL', 'SERVICE']

const STATUS_TONE: Record<IncidentStatus, StatusTone> = {
  REPORTED: 'critical',
  ACKNOWLEDGED: 'caution',
  IN_PROGRESS: 'info',
  ESCALATED: 'critical',
  RESOLVED: 'positive',
  CLOSED: 'neutral',
}

const STATUS_ICON: Record<IncidentStatus, AppIconName> = {
  REPORTED: 'error',
  ACKNOWLEDGED: 'eta',
  IN_PROGRESS: 'maintenance',
  ESCALATED: 'sos',
  RESOLVED: 'success',
  CLOSED: 'success',
}

export function IncidentStatusChip({ status }: { status: IncidentStatus }) {
  return <StatusChip label={humanise(status)} tone={STATUS_TONE[status] ?? 'neutral'} icon={STATUS_ICON[status]} />
}

const SEVERITY_TONE: Record<IncidentSeverity, StatusTone> = {
  LOW: 'neutral',
  MEDIUM: 'info',
  HIGH: 'caution',
  CRITICAL: 'critical',
}

export function SeverityChip({ severity }: { severity: IncidentSeverity }) {
  return <StatusChip label={humanise(severity)} tone={SEVERITY_TONE[severity] ?? 'neutral'} />
}

/**
 * A8 Incidents.
 *
 * Defaults to the **open queue**, and says so in the header rather than
 * leaving somebody to infer it from a filter chip. The backend orders
 * life-safety first and newest within that, so the order on screen is the
 * server's judgement, not a client-side sort that only sorts one page.
 *
 * There is no severity filter and no date range, because `GET /incidents` does
 * not accept either (G1-2). A control that filtered twenty rows while claiming
 * to filter the queue is worse than no control.
 */
export function IncidentsScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()

  // `open` is the default, and stays on until somebody picks a status.
  const status = (params.get('status') as IncidentStatus | null) ?? ''
  const filters: IncidentFilters = {
    status,
    class: (params.get('class') as IncidentClass | null) ?? '',
    open: !status && params.get('all') !== '1',
    page: Number(params.get('page') ?? 1),
    per_page: 20,
  }

  const incidents = useQuery({
    queryKey: incidentKeys.list(filters),
    queryFn: () => fetchIncidents(filters),
    // A queue that decides whether a bus stays on the road should not be a
    // day old because somebody left the tab open.
    refetchInterval: 60_000,
  })

  const update = (next: Record<string, string | number | undefined>) => {
    const merged = new URLSearchParams(params)
    for (const [key, value] of Object.entries(next)) {
      if (value === undefined || value === '') merged.delete(key)
      else merged.set(key, String(value))
    }
    if (!('page' in next)) merged.delete('page')
    setParams(merged, { replace: true })
  }

  const pagination = incidents.data?.pagination
  const showingOpenOnly = filters.open
  const staleAfterFailedRefresh = incidents.isError && incidents.data !== undefined

  return (
    <>
      <PageHeader
        title="Incidents"
        subtitle={
          showingOpenOnly
            ? 'The open queue — anything still awaiting a decision. Life-safety first.'
            : 'Every incident matching these filters, life-safety first.'
        }
        actions={
          <RefreshButton
            onClick={() => void queryClient.invalidateQueries({ queryKey: ['incidents'] })}
            busy={incidents.isFetching}
          />
        }
      />

      <div className="mb-lg flex flex-wrap items-end gap-md rounded-md border border-outline bg-surface p-md">
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Status</span>
          <select
            value={status}
            onChange={(event) => update({ status: event.target.value, all: undefined })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Open queue</option>
            {STATUSES.map((value) => (
              <option key={value} value={value}>
                {humanise(value)}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Class</span>
          <select
            value={filters.class}
            onChange={(event) => update({ class: event.target.value })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Every class</option>
            {CLASSES.map((value) => (
              <option key={value} value={value}>
                {humanise(value)}
              </option>
            ))}
          </select>
        </label>

        {showingOpenOnly && (
          <button
            type="button"
            onClick={() => update({ all: '1' })}
            className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body"
          >
            Include resolved and closed
          </button>
        )}

        {(params.get('status') || params.get('class') || params.get('all')) && (
          <button
            type="button"
            onClick={() => setParams(new URLSearchParams(), { replace: true })}
            className="h-[var(--size-control)] rounded-sm px-md text-body text-primary"
          >
            Back to the open queue
          </button>
        )}

        <span className="ml-auto">
          <RangeLabel pagination={pagination} noun="incidents" />
        </span>
      </div>

      <div className="overflow-hidden rounded-md border border-outline bg-surface">
        {staleAfterFailedRefresh && (
          <StaleBanner error={incidents.error} onRetry={() => void incidents.refetch()} />
        )}

        {incidents.isPending && <LoadingRows />}

        {incidents.isError && !incidents.data && (
          <LoadFailed what="incidents" error={incidents.error} onRetry={() => void incidents.refetch()} />
        )}

        {incidents.data && incidents.data.rows.length === 0 && (
          <EmptyState
            icon="success"
            title={showingOpenOnly ? 'No open incidents' : 'No incidents match these filters'}
            hint={showingOpenOnly ? 'Nothing is waiting on the transport office. This is the good day.' : undefined}
          />
        )}

        {incidents.data && incidents.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Severity</th>
                  <th scope="col" className="px-lg font-medium">Type</th>
                  <th scope="col" className="px-lg font-medium">Bus</th>
                  <th scope="col" className="px-lg font-medium">Driver</th>
                  <th scope="col" className="px-lg font-medium">Trip</th>
                  <th scope="col" className="px-lg font-medium">Reported</th>
                  <th scope="col" className="px-lg font-medium">Status</th>
                  <th scope="col" className="px-lg font-medium" />
                </tr>
              </thead>
              <tbody>
                {incidents.data.rows.map((incident) => (
                  <tr
                    key={incident.id}
                    className="h-[var(--size-row)] border-b border-outline last:border-0 hover:bg-surface-sunken"
                  >
                    <td className="px-lg">
                      <SeverityChip severity={incident.severity} />
                    </td>
                    <td className="px-lg">
                      <Link to={`/incidents/${incident.id}`} className="font-semibold hover:text-primary">
                        {humanise(incident.incident_type)}
                      </Link>
                      {incident.incident_class === 'LIFE_SAFETY' && (
                        <span className="ml-sm inline-flex items-center gap-xs text-label font-semibold text-critical">
                          <Icon name="sos" size="xs" />
                          Life safety
                        </span>
                      )}
                    </td>
                    <td className="px-lg font-mono">
                      {incident.bus ? (
                        <Link to={`/buses/${incident.bus.id}`} className="hover:text-primary">
                          {incident.bus.registration_number}
                        </Link>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="truncate px-lg">{personName(incident.driver?.user)}</td>
                    <td className="px-lg">
                      {incident.trip ? (
                        <Link to={`/trips/${incident.trip.id}`} className="hover:text-primary">
                          {incident.trip.route?.route_name ?? 'Trip'}
                        </Link>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="px-lg whitespace-nowrap">{whenText(incident.reported_at)}</td>
                    <td className="px-lg">
                      <IncidentStatusChip status={incident.status} />
                    </td>
                    <td className="px-lg text-right">
                      <Link to={`/incidents/${incident.id}`} className="text-primary">
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
