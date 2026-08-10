import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { TripStatusChip } from '@/components/StatusChip'
import { Icon } from '@/icons/Icon'
import { clock, driverName, fetchTrips, occupancy, tripKeys, type TripFilters, type TripStatus } from './api'

const STATUSES: TripStatus[] = ['SCHEDULED', 'RUNNING', 'COMPLETED', 'CANCELLED']

function todayISO(): string {
  const now = new Date()

  return `${now.getFullYear()}-${`${now.getMonth() + 1}`.padStart(2, '0')}-${`${now.getDate()}`.padStart(2, '0')}`
}

/**
 * A3 Trips.
 *
 * Filters live in the URL, so a filtered view is a link somebody can send. All
 * of them are parameters `TripController::index` actually validates — there is
 * no free-text search, because the endpoint has none and a box that searched
 * the twenty rows in hand while looking like it searched the timetable would
 * be worse than no box at all.
 */
export function TripsScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()

  const filters: TripFilters = {
    date: params.get('date') ?? todayISO(),
    status: (params.get('status') as TripStatus | null) ?? '',
    page: Number(params.get('page') ?? 1),
    per_page: 20,
  }

  const trips = useQuery({ queryKey: tripKeys.list(filters), queryFn: () => fetchTrips(filters) })

  const update = (next: Partial<TripFilters>) => {
    const merged = new URLSearchParams(params)

    for (const [key, value] of Object.entries(next)) {
      if (value === undefined || value === '' || value === null) merged.delete(key)
      else merged.set(key, String(value))
    }
    // Any filter change returns to the first page; page 4 of a different
    // filter is somebody else's page 4.
    if (!('page' in next)) merged.delete('page')

    setParams(merged, { replace: true })
  }

  const filtered = params.get('status') !== null || params.get('date') !== null
  const pagination = trips.data?.pagination

  return (
    <>
      <PageHeader
        title="Trips"
        subtitle="Every run on the timetable, and what actually happened to it."
        actions={
          <button
            type="button"
            onClick={() => void queryClient.invalidateQueries({ queryKey: ['trips'] })}
            disabled={trips.isFetching}
            className="flex h-[var(--size-control)] items-center gap-sm rounded-sm border border-outline px-lg text-body disabled:opacity-60"
          >
            <Icon name="refresh" size="sm" />
            {trips.isFetching ? 'Refreshing…' : 'Refresh'}
          </button>
        }
      />

      <div className="mb-lg flex flex-wrap items-end gap-md rounded-md border border-outline bg-surface p-md">
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Date</span>
          <input
            type="date"
            value={filters.date}
            onChange={(event) => update({ date: event.target.value })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>

        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Status</span>
          <select
            value={filters.status}
            onChange={(event) => update({ status: event.target.value as TripStatus | '' })}
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

        {filtered && (
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
              ? 'No trips'
              : `${(pagination.current_page - 1) * pagination.per_page + 1}–${Math.min(
                  pagination.current_page * pagination.per_page,
                  pagination.total,
                )} of ${pagination.total}`}
          </span>
        )}
      </div>

      <div className="overflow-hidden rounded-md border border-outline bg-surface">
        {trips.isPending && (
          <div className="p-lg">
            {[0, 1, 2, 3, 4].map((row) => (
              <div key={row} className="mb-sm h-[var(--size-row)] animate-pulse rounded-sm bg-surface-sunken" />
            ))}
          </div>
        )}

        {trips.isError && (
          <div className="p-xxl text-center">
            <Icon name="warning" size="lg" className="text-caution" />
            <p className="mt-md text-title-md">Unable to load trips</p>
            <p className="mt-xs text-body text-on-surface-muted">
              {(trips.error as { displayMessage?: string })?.displayMessage ?? 'The request failed.'}
            </p>
            <button
              type="button"
              onClick={() => void trips.refetch()}
              className="mt-lg h-[var(--size-control)] rounded-sm bg-primary px-lg text-body font-semibold text-on-primary"
            >
              Try again
            </button>
          </div>
        )}

        {trips.data && trips.data.rows.length === 0 && (
          <div className="p-xxl text-center">
            <Icon name="trips" size="lg" className="text-on-surface-muted" />
            <p className="mt-md text-title-md">No trips match these filters</p>
            <p className="mt-xs text-body text-on-surface-muted">
              Nothing is scheduled for {filters.date}, or the status filter excludes it.
            </p>
          </div>
        )}

        {trips.data && trips.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">
                    Route
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Bus
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Driver
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Departure
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Arrival
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Occupancy
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Status
                  </th>
                  <th scope="col" className="px-lg font-medium" />
                </tr>
              </thead>
              <tbody>
                {trips.data.rows.map((trip) => (
                  <tr
                    key={trip.id}
                    className="h-[var(--size-row)] border-b border-outline last:border-0 hover:bg-surface-sunken"
                  >
                    <td className="px-lg">
                      <Link to={`/trips/${trip.id}`} className="font-semibold hover:text-primary">
                        {trip.route?.route_name ?? '—'}
                      </Link>
                      {trip.route?.route_code && (
                        <span className="ml-sm font-mono text-label text-on-surface-muted">
                          {trip.route.route_code}
                        </span>
                      )}
                    </td>
                    <td className="px-lg font-mono">{trip.bus?.registration_number ?? '—'}</td>
                    <td className="truncate px-lg">{driverName(trip)}</td>
                    <td className="px-lg font-mono">
                      {clock(trip.scheduled_departure_time)}
                      {/* Actual beside scheduled, because the gap is the story. */}
                      {trip.actual_departure_time && (
                        <span className="ml-sm text-label text-on-surface-muted">
                          act {clock(trip.actual_departure_time)}
                        </span>
                      )}
                    </td>
                    <td className="px-lg font-mono">
                      {clock(trip.scheduled_arrival_time)}
                      {trip.actual_arrival_time && (
                        <span className="ml-sm text-label text-on-surface-muted">
                          act {clock(trip.actual_arrival_time)}
                        </span>
                      )}
                    </td>
                    <td className="px-lg font-mono">{occupancy(trip)}</td>
                    <td className="px-lg">
                      <TripStatusChip status={trip.status} />
                    </td>
                    <td className="px-lg text-right">
                      <Link to={`/trips/${trip.id}`} className="text-body text-primary">
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
        <Pagination
          page={pagination.current_page}
          lastPage={pagination.last_page}
          onChange={(page) => update({ page })}
        />
      )}
    </>
  )
}

/** Server-side, always. The panel never holds a whole table to slice it. */
function Pagination({
  page,
  lastPage,
  onChange,
}: {
  page: number
  lastPage: number
  onChange: (page: number) => void
}) {
  return (
    <div className="mt-lg flex items-center justify-end gap-sm">
      <button
        type="button"
        disabled={page <= 1}
        onClick={() => onChange(page - 1)}
        className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body disabled:opacity-40"
      >
        Previous
      </button>
      <span className="text-body text-on-surface-muted">
        Page {page} of {lastPage}
      </span>
      <button
        type="button"
        disabled={page >= lastPage}
        onClick={() => onChange(page + 1)}
        className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body disabled:opacity-40"
      >
        Next
      </button>
    </div>
  )
}
