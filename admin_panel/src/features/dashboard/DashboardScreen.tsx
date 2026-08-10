import { useQueries, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { MetricCard } from '@/components/MetricCard'
import { Icon } from '@/icons/Icon'
import { useSession } from '@/auth/SessionProvider'
import {
  countByStatus,
  dashboardKeys,
  driverName,
  fetchExpiringDocuments,
  fetchFleet,
  fetchOpenIncidents,
  fetchOpenTickets,
  fetchRunningTrips,
  fetchTripsToday,
  readableIncidentType,
  type BusRow,
  type ExpiringDocument,
  type IncidentRow,
  type TicketRow,
  type TripRow,
} from './api'
import type { Page } from '@/api/client'

const TRIP_TONE: Record<string, string> = {
  RUNNING: 'text-positive',
  SCHEDULED: 'text-info',
  COMPLETED: 'text-on-surface-muted',
  CANCELLED: 'text-critical',
}

/**
 * A1 Dashboard.
 *
 * Six independent requests, one page skeleton. Each tile owns its own state so
 * a single failure costs one card rather than the page — a transport head with
 * five true numbers and one that says "unable to load" is far better served
 * than one staring at a full-page error because the document endpoint blinked.
 *
 * Refresh is manual. The frozen specification allows 60 s auto-refresh; this
 * slice does not poll, because an operations console that refetches while
 * somebody is reading it has to justify itself, and Live Operations is where
 * that justification actually lives.
 */
export function DashboardScreen() {
  const { user } = useSession()
  const queryClient = useQueryClient()

  const results = useQueries({
    queries: [
      { queryKey: dashboardKeys.tripsToday, queryFn: fetchTripsToday },
      { queryKey: dashboardKeys.runningTrips, queryFn: fetchRunningTrips },
      { queryKey: dashboardKeys.fleet, queryFn: fetchFleet },
      { queryKey: dashboardKeys.openIncidents, queryFn: fetchOpenIncidents },
      { queryKey: dashboardKeys.openTickets, queryFn: fetchOpenTickets },
      { queryKey: dashboardKeys.expiringDocuments, queryFn: fetchExpiringDocuments },
    ],
  })

  const [trips, running, fleet, incidents, tickets, documents] = results

  const refreshAll = () => void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  const refreshing = results.some((result) => result.isFetching)

  // Every source failed. Only then is the page itself unusable.
  if (results.every((result) => result.isError)) {
    return (
      <>
        <PageHeader title="Dashboard" />
        <div className="grid place-items-center rounded-md border border-outline bg-surface p-xxl text-center">
          <Icon name="offline" size="lg" className="text-on-surface-muted" />
          <h2 className="mt-md text-title-md">The CTMS server could not be reached</h2>
          <p className="mt-xs text-body text-on-surface-muted">Nothing on this page could be loaded.</p>
          <button
            type="button"
            onClick={refreshAll}
            className="mt-lg h-[var(--size-control)] rounded-sm bg-primary px-lg text-body font-semibold text-on-primary"
          >
            Try again
          </button>
        </div>
      </>
    )
  }

  const fleetCounts = fleet.data ? countByStatus(fleet.data.rows) : undefined
  const notReady = fleetCounts
    ? (fleetCounts.MAINTENANCE ?? 0) + (fleetCounts.BREAKDOWN ?? 0) + (fleetCounts.OFFLINE ?? 0)
    : undefined

  const greeting = user?.fullName ? `Good morning, ${user.fullName.split(' ')[0]}` : 'Dashboard'
  const dateLabel = new Date().toLocaleDateString(undefined, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  })

  return (
    <>
      <PageHeader
        title={greeting}
        subtitle={dateLabel}
        actions={
          <button
            type="button"
            onClick={refreshAll}
            disabled={refreshing}
            className="flex h-[var(--size-control)] items-center gap-sm rounded-sm border border-outline px-lg text-body disabled:opacity-60"
          >
            <Icon name="refresh" size="sm" />
            {refreshing ? 'Refreshing…' : 'Refresh'}
          </button>
        }
      />

      <div className="mb-xl grid grid-cols-1 gap-lg sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard
          label="Trips today"
          icon="trips"
          value={trips.data?.pagination?.total}
          context={running.data ? `${running.data.pagination?.total ?? running.data.rows.length} running now` : undefined}
          to="/trips"
          loading={trips.isPending}
          failed={trips.isError}
          onRetry={() => void trips.refetch()}
        />
        <MetricCard
          label="Buses available"
          icon="buses"
          value={fleetCounts ? (fleetCounts.AVAILABLE ?? 0) : undefined}
          context={fleet.data ? `of ${fleet.data.pagination?.total ?? fleet.data.rows.length}` : undefined}
          tone="positive"
          to="/buses"
          loading={fleet.isPending}
          failed={fleet.isError}
          onRetry={() => void fleet.refetch()}
        />
        <MetricCard
          label="Open incidents"
          icon="incidents"
          value={incidents.data?.pagination?.total}
          context={(incidents.data?.pagination?.total ?? 0) > 0 ? 'needs attention' : 'nothing open'}
          tone={(incidents.data?.pagination?.total ?? 0) > 0 ? 'critical' : 'neutral'}
          to="/incidents"
          loading={incidents.isPending}
          failed={incidents.isError}
          onRetry={() => void incidents.refetch()}
        />
        <MetricCard
          label="Vehicles not ready"
          icon="maintenance"
          value={notReady}
          context={tickets.data ? `${tickets.data.pagination?.total ?? 0} open tickets` : undefined}
          tone={notReady && notReady > 0 ? 'caution' : 'neutral'}
          to="/maintenance"
          loading={fleet.isPending}
          failed={fleet.isError}
          onRetry={() => void fleet.refetch()}
        />
      </div>

      <div className="grid grid-cols-1 gap-lg xl:grid-cols-[1fr_1.4fr]">
        <AttentionRequired
          incidents={incidents}
          tickets={tickets}
          documents={documents}
          fleet={fleet.data?.rows ?? []}
        />
        <TodaysTrips trips={trips} />
      </div>
    </>
  )
}

type QueryLike<T> = { data?: Page<T>; isPending: boolean; isError: boolean; refetch: () => unknown }

/**
 * Ordered by consequence, not by time: an SOS above a breakdown above a
 * vehicle out of service above a document expiring next week.
 */
function AttentionRequired({
  incidents,
  tickets,
  documents,
  fleet,
}: {
  incidents: QueryLike<IncidentRow>
  tickets: QueryLike<TicketRow>
  documents: QueryLike<ExpiringDocument>
  fleet: BusRow[]
}) {
  const items: Array<{ tone: string; label: string; detail: string; to: string }> = []

  for (const incident of incidents.data?.rows ?? []) {
    items.push({
      tone: incident.severity === 'CRITICAL' ? 'bg-emergency' : 'bg-critical',
      label: readableIncidentType(incident.incident_type),
      detail: incident.bus?.registration_number ?? 'Unassigned bus',
      to: '/incidents',
    })
  }

  for (const bus of fleet.filter((row) => row.status === 'BREAKDOWN' || row.status === 'MAINTENANCE')) {
    items.push({
      tone: bus.status === 'BREAKDOWN' ? 'bg-critical' : 'bg-caution',
      label: bus.status === 'BREAKDOWN' ? 'Out of service' : 'In the workshop',
      detail: bus.registration_number,
      to: '/buses',
    })
  }

  for (const ticket of tickets.data?.rows ?? []) {
    items.push({
      tone: ticket.priority === 'URGENT' ? 'bg-critical' : 'bg-caution',
      label: 'Maintenance open',
      detail: `${ticket.bus?.registration_number ?? 'Bus'} · ${ticket.issue_description}`,
      to: '/maintenance',
    })
  }

  for (const document of documents.data?.rows ?? []) {
    items.push({
      tone: 'bg-caution',
      label: 'Document expiring',
      detail: `${document.bus?.registration_number ?? 'Bus'} · ${document.document_type ?? 'Document'}`,
      to: '/buses',
    })
  }

  const loading = incidents.isPending || tickets.isPending || documents.isPending
  const partiallyFailed = incidents.isError || tickets.isError || documents.isError

  return (
    <section className="rounded-md border border-outline bg-surface" aria-labelledby="attention-heading">
      <h2 id="attention-heading" className="border-b border-outline px-lg py-md text-title-md font-semibold">
        Attention required
      </h2>

      <div className="p-lg">
        {loading && <div className="h-24 animate-pulse rounded-sm bg-surface-sunken" />}

        {!loading && partiallyFailed && (
          <p className="mb-md flex items-center gap-sm text-body text-caution">
            <Icon name="warning" size="sm" />
            Some of this could not be loaded.
          </p>
        )}

        {!loading && items.length === 0 && !partiallyFailed && (
          <p className="flex items-center gap-sm text-body text-on-surface-muted">
            <Icon name="success" size="sm" className="text-positive" />
            Nothing needs attention.
          </p>
        )}

        <ul className="flex flex-col gap-sm">
          {items.slice(0, 8).map((item, index) => (
            <li key={`${item.label}-${item.detail}-${index}`}>
              <Link to={item.to} className="flex items-center gap-md rounded-sm px-sm py-xs hover:bg-surface-sunken">
                <span className={`size-2 shrink-0 rounded-full ${item.tone}`} aria-hidden="true" />
                <span className="text-body font-semibold">{item.label}</span>
                <span className="truncate text-body text-on-surface-muted">{item.detail}</span>
              </Link>
            </li>
          ))}
        </ul>
      </div>
    </section>
  )
}

function TodaysTrips({ trips }: { trips: QueryLike<TripRow> }) {
  const counts = trips.data ? countByStatus(trips.data.rows) : {}

  return (
    <section className="rounded-md border border-outline bg-surface" aria-labelledby="trips-heading">
      <div className="flex items-center gap-lg border-b border-outline px-lg py-md">
        <h2 id="trips-heading" className="text-title-md font-semibold">
          Today&rsquo;s operations
        </h2>
        <Link to="/trips" className="ml-auto text-body text-primary">
          All trips →
        </Link>
      </div>

      {trips.isPending && <div className="m-lg h-40 animate-pulse rounded-sm bg-surface-sunken" />}

      {trips.isError && (
        <div className="p-lg">
          <p className="text-body text-on-surface-muted">Unable to load today&rsquo;s trips.</p>
          <button
            type="button"
            onClick={() => void trips.refetch()}
            className="mt-sm rounded-sm border border-outline px-md py-xs text-body hover:bg-surface-sunken"
          >
            Retry
          </button>
        </div>
      )}

      {trips.data && trips.data.rows.length === 0 && (
        <p className="p-lg text-body text-on-surface-muted">No trips are scheduled today.</p>
      )}

      {trips.data && trips.data.rows.length > 0 && (
        <>
          <div className="flex flex-wrap gap-lg px-lg py-md text-label">
            {(['RUNNING', 'SCHEDULED', 'COMPLETED', 'CANCELLED'] as const).map((status) => (
              <span key={status} className={TRIP_TONE[status]}>
                <span className="font-semibold">{counts[status] ?? 0}</span>{' '}
                <span className="uppercase">{status.toLowerCase()}</span>
              </span>
            ))}
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-y border-outline text-left text-label text-on-surface-muted uppercase">
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
                    Depart
                  </th>
                  <th scope="col" className="px-lg font-medium">
                    Status
                  </th>
                </tr>
              </thead>
              <tbody>
                {trips.data.rows.slice(0, 8).map((trip) => (
                  <tr key={trip.id} className="h-[var(--size-row)] border-b border-outline last:border-0">
                    <td className="truncate px-lg">{trip.route?.route_name ?? '—'}</td>
                    <td className="px-lg font-mono">{trip.bus?.registration_number ?? '—'}</td>
                    <td className="truncate px-lg">{driverName(trip)}</td>
                    <td className="px-lg font-mono">{trip.scheduled_departure_time?.slice(0, 5) ?? '—'}</td>
                    <td className={`px-lg font-semibold ${TRIP_TONE[trip.status] ?? ''}`}>
                      {trip.status.charAt(0) + trip.status.slice(1).toLowerCase()}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  )
}
