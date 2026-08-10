import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip, type StatusTone } from '@/components/StatusChip'
import { Icon } from '@/icons/Icon'
import type { AppIconName } from '@/icons/registry'
import {
  capacity,
  daysUntil,
  fetchBuses,
  fetchExpiringDocuments,
  fleetKeys,
  odometer,
  readableDocumentType,
  shortDate,
  type BusStatus,
  type FleetFilters,
} from './api'

const STATUSES: BusStatus[] = ['AVAILABLE', 'RUNNING', 'MAINTENANCE', 'BREAKDOWN', 'OFFLINE']

/** The backend's five values, and only those. */
const STATUS_TONE: Record<BusStatus, StatusTone> = {
  AVAILABLE: 'positive',
  RUNNING: 'info',
  MAINTENANCE: 'caution',
  BREAKDOWN: 'critical',
  OFFLINE: 'neutral',
}

const STATUS_ICON: Record<BusStatus, AppIconName> = {
  AVAILABLE: 'success',
  RUNNING: 'gps',
  MAINTENANCE: 'maintenance',
  BREAKDOWN: 'error',
  OFFLINE: 'offline',
}

export function BusStatusChip({ status }: { status: BusStatus }) {
  return (
    <StatusChip
      label={status.charAt(0) + status.slice(1).toLowerCase()}
      tone={STATUS_TONE[status] ?? 'neutral'}
      icon={STATUS_ICON[status]}
    />
  )
}

/**
 * A6 Fleet.
 *
 * One request for the table. Readiness is **not** fetched per row: it is a
 * call per bus, and twenty-eight buses would mean twenty-eight requests to
 * fill a column. It lives on the bus's own page, where one call answers the
 * question somebody actually asked.
 */
export function FleetScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()

  const filters: FleetFilters = {
    status: (params.get('status') as BusStatus | null) ?? '',
    search: params.get('search') ?? '',
    page: Number(params.get('page') ?? 1),
    per_page: 20,
  }

  const buses = useQuery({ queryKey: fleetKeys.list(filters), queryFn: () => fetchBuses(filters) })
  const expiring = useQuery({ queryKey: fleetKeys.expiring, queryFn: fetchExpiringDocuments })

  const update = (next: Partial<FleetFilters>) => {
    const merged = new URLSearchParams(params)

    for (const [key, value] of Object.entries(next)) {
      if (value === undefined || value === '' || value === null) merged.delete(key)
      else merged.set(key, String(value))
    }
    if (!('page' in next)) merged.delete('page')

    setParams(merged, { replace: true })
  }

  const pagination = buses.data?.pagination

  return (
    <>
      <PageHeader
        title="Fleet"
        subtitle="Every bus, its condition, and whether it may go out today."
        actions={
          <button
            type="button"
            onClick={() => void queryClient.invalidateQueries({ queryKey: ['fleet'] })}
            disabled={buses.isFetching}
            className="flex h-[var(--size-control)] items-center gap-sm rounded-sm border border-outline px-lg text-body disabled:opacity-60"
          >
            <Icon name="refresh" size="sm" />
            {buses.isFetching ? 'Refreshing…' : 'Refresh'}
          </button>
        }
      />

      <ExpiringDocuments query={expiring} />

      <div className="mb-lg flex flex-wrap items-end gap-md rounded-md border border-outline bg-surface p-md">
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Status</span>
          <select
            value={filters.status}
            onChange={(event) => update({ status: event.target.value as BusStatus | '' })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">All statuses</option>
            {STATUSES.map((status) => (
              <option key={status} value={status}>
                {status.charAt(0) + status.slice(1).toLowerCase()}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Search</span>
          {/* `GET /buses` genuinely supports `search`, across registration,
              vehicle name and model — so this searches the fleet, not the page. */}
          <input
            type="search"
            defaultValue={filters.search}
            placeholder="Registration or model"
            onChange={(event) => update({ search: event.target.value })}
            className="h-[var(--size-control)] w-56 rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>

        {(params.get('status') || params.get('search')) && (
          <button
            type="button"
            onClick={() => setParams(new URLSearchParams(), { replace: true })}
            className="h-[var(--size-control)] rounded-sm px-md text-body text-primary"
          >
            Clear filters
          </button>
        )}

        {pagination && (
          <span className="ml-auto text-label text-on-surface-muted">
            {pagination.total === 0
              ? 'No buses'
              : `${(pagination.current_page - 1) * pagination.per_page + 1}–${Math.min(
                  pagination.current_page * pagination.per_page,
                  pagination.total,
                )} of ${pagination.total}`}
          </span>
        )}
      </div>

      <div className="overflow-hidden rounded-md border border-outline bg-surface">
        {buses.isPending && (
          <div className="p-lg">
            {[0, 1, 2, 3, 4].map((row) => (
              <div key={row} className="mb-sm h-[var(--size-row)] animate-pulse rounded-sm bg-surface-sunken" />
            ))}
          </div>
        )}

        {buses.isError && (
          <div className="p-xxl text-center">
            <Icon name="warning" size="lg" className="text-caution" />
            <p className="mt-md text-title-md">Unable to load the fleet</p>
            <button
              type="button"
              onClick={() => void buses.refetch()}
              className="mt-lg h-[var(--size-control)] rounded-sm bg-primary px-lg text-body font-semibold text-on-primary"
            >
              Try again
            </button>
          </div>
        )}

        {buses.data && buses.data.rows.length === 0 && (
          <div className="p-xxl text-center">
            <Icon name="buses" size="lg" className="text-on-surface-muted" />
            <p className="mt-md text-title-md">No buses match these filters</p>
          </div>
        )}

        {buses.data && buses.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">
                    Registration
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Model
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Capacity
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Status
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Odometer
                  </th>
                  <th scope="col" className="px-lg font-medium" />
                </tr>
              </thead>
              <tbody>
                {buses.data.rows.map((bus) => (
                  <tr
                    key={bus.id}
                    className="h-[var(--size-row)] border-b border-outline last:border-0 hover:bg-surface-sunken"
                  >
                    <td className="px-lg">
                      <Link to={`/buses/${bus.id}`} className="font-mono font-semibold hover:text-primary">
                        {bus.registration_number}
                      </Link>
                      {bus.vehicle_name && (
                        <span className="ml-sm text-label text-on-surface-muted">{bus.vehicle_name}</span>
                      )}
                    </td>
                    <td className="truncate px-lg">{bus.model ?? '—'}</td>
                    <td className="px-lg">{capacity(bus)}</td>
                    <td className="px-lg">
                      <BusStatusChip status={bus.status} />
                    </td>
                    <td className="px-lg font-mono">{odometer(bus)}</td>
                    <td className="px-lg text-right">
                      <Link to={`/buses/${bus.id}`} className="text-body text-primary">
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

      {pagination && pagination.last_page > 1 && (
        <div className="mt-lg flex items-center justify-end gap-sm">
          <button
            type="button"
            disabled={pagination.current_page <= 1}
            onClick={() => update({ page: pagination.current_page - 1 })}
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
            onClick={() => update({ page: pagination.current_page + 1 })}
            className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body disabled:opacity-40"
          >
            Next
          </button>
        </div>
      )}
    </>
  )
}

/**
 * Statutory documents about to lapse.
 *
 * Zero is good news and is rendered as good news — never a red panel saying
 * nothing is wrong.
 */
function ExpiringDocuments({
  query,
}: {
  query: { data?: { rows: import('./api').BusDocument[] }; isPending: boolean; isError: boolean }
}) {
  if (query.isPending || query.isError) return null

  const rows = query.data?.rows ?? []

  if (rows.length === 0) {
    return (
      <p className="mb-lg flex items-center gap-sm rounded-md border border-outline bg-surface p-md text-body">
        <Icon name="success" size="sm" className="text-positive" />
        No documents need attention.
      </p>
    )
  }

  return (
    <section className="mb-lg rounded-md border border-caution/40 bg-caution/10">
      <h2 className="flex items-center gap-sm px-lg py-md text-title-md font-semibold text-caution">
        <Icon name="warning" size="sm" />
        {rows.length} {rows.length === 1 ? 'document needs' : 'documents need'} attention
      </h2>

      <ul className="px-lg pb-md">
        {rows.map((document) => {
          const days = daysUntil(document.expires_on)

          return (
            <li key={document.id} className="flex flex-wrap items-baseline gap-sm py-xs text-body">
              <Link to={`/buses/${document.bus_id}`} className="font-mono font-semibold text-primary">
                {document.bus?.registration_number ?? 'Bus'}
              </Link>
              <span>{readableDocumentType(document.document_type)}</span>
              <span className="ml-auto text-on-surface-muted">
                {shortDate(document.expires_on)}
                {days !== null && (
                  <span className="ml-sm font-semibold">
                    {days < 0 ? 'expired' : days === 0 ? 'expires today' : `in ${days} days`}
                  </span>
                )}
              </span>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
