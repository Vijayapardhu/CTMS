import { useMemo, useState } from 'react'
import { useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip, TripStatusChip } from '@/components/StatusChip'
import { Icon } from '@/icons/Icon'
import { driverName, occupancy, type LiveTrip } from '@/features/trips/api'
import { LiveMap } from './LiveMap'
import {
  REFRESH_MS,
  TRACKED_LIMIT,
  currentStop,
  fetchEta,
  fetchLive,
  fetchRunningTrips,
  liveKeys,
  nextStop,
  readableDistance,
  readableEta,
  stopsCompleted,
  trackedSet,
  type TrackedTrip,
} from './api'

/**
 * A5 Live Operations.
 *
 * Read-only at every access level: there is nothing to press here, only
 * something to watch. The whole screen is one bounded budget — see `api.ts`.
 */
export function LiveOperationsScreen() {
  const queryClient = useQueryClient()
  const [selectedId, setSelectedId] = useState<string | null>(null)

  const running = useQuery({
    queryKey: liveKeys.running,
    queryFn: fetchRunningTrips,
    refetchInterval: REFRESH_MS,
    // Keep the previous list on screen while the next one is in flight, so
    // the map never blanks mid-refresh.
    placeholderData: (previous) => previous,
  })

  const runningTrips = running.data?.rows ?? []
  const tracked = useMemo(() => trackedSet(runningTrips), [runningTrips])

  const liveResults = useQueries({
    queries: tracked.map((trip) => ({
      queryKey: liveKeys.trip(trip.id),
      queryFn: () => fetchLive(trip.id),
      refetchInterval: REFRESH_MS,
      placeholderData: (previous: LiveTrip | undefined) => previous,
    })),
  })

  const trackedTrips: TrackedTrip[] = tracked.map((trip, index) => ({
    trip,
    live: liveResults[index]?.data ?? null,
  }))

  const selected = trackedTrips.find(({ trip }) => trip.id === selectedId) ?? null
  const selectedNext = nextStop(selected?.live ?? null)

  // The one ETA call on this screen, and only when a trip is selected.
  const eta = useQuery({
    queryKey: liveKeys.eta(selectedId ?? '', selectedNext?.stop_id ?? ''),
    queryFn: () => fetchEta(selectedId!, selectedNext!.stop_id),
    enabled: Boolean(selectedId && selectedNext),
    refetchInterval: REFRESH_MS,
  })

  const refreshing = running.isFetching || liveResults.some((result) => result.isFetching)
  const capped = runningTrips.length > TRACKED_LIMIT
  const everLoaded = Boolean(running.data)

  return (
    <>
      <PageHeader
        title="Live Operations"
        subtitle="Where every running bus is, and how far it has left to go."
        actions={
          <div className="flex items-center gap-md">
            {everLoaded && (
              <span className={`text-label ${capped ? 'font-semibold text-caution' : 'text-on-surface-muted'}`}>
                Tracking {tracked.length} of {runningTrips.length} running{' '}
                {runningTrips.length === 1 ? 'trip' : 'trips'}
              </span>
            )}
            <button
              type="button"
              onClick={() => void queryClient.invalidateQueries({ queryKey: ['live'] })}
              disabled={refreshing}
              className="flex h-[var(--size-control)] items-center gap-sm rounded-sm border border-outline px-lg text-body disabled:opacity-60"
            >
              <Icon name="refresh" size="sm" />
              {refreshing ? 'Updating…' : 'Refresh'}
            </button>
          </div>
        }
      />

      {/* A failed refresh keeps what is already on screen. Only a first load
          that never succeeded takes the page. */}
      {running.isError && everLoaded && (
        <p className="mb-lg flex items-center gap-sm rounded-sm border border-caution/40 bg-caution/10 p-md text-body text-caution">
          <Icon name="warning" size="sm" />
          Unable to update live data. Showing the last positions received.
        </p>
      )}

      {running.isError && !everLoaded && (
        <div className="rounded-md border border-outline bg-surface p-xxl text-center">
          <Icon name="offline" size="lg" className="text-on-surface-muted" />
          <p className="mt-md text-title-md">Live data could not be loaded</p>
          <button
            type="button"
            onClick={() => void running.refetch()}
            className="mt-lg h-[var(--size-control)] rounded-sm bg-primary px-lg text-body font-semibold text-on-primary"
          >
            Try again
          </button>
        </div>
      )}

      {running.isPending && <div className="h-[560px] animate-pulse rounded-md bg-surface-sunken" />}

      {everLoaded && runningTrips.length === 0 && (
        <div className="rounded-md border border-outline bg-surface p-xxl text-center">
          <Icon name="live" size="lg" className="text-on-surface-muted" />
          <p className="mt-md text-title-md">No trip is running</p>
          <p className="mt-xs text-body text-on-surface-muted">
            Buses appear here the moment a driver starts a trip.
          </p>
          <Link to="/trips" className="mt-lg inline-block text-body text-primary">
            See today&rsquo;s timetable →
          </Link>
        </div>
      )}

      {everLoaded && runningTrips.length > 0 && (
        <>
          {capped && (
            <p className="mb-lg flex items-start gap-sm rounded-sm border border-caution/40 bg-caution/10 p-md text-body text-caution">
              <Icon name="warning" size="sm" className="mt-xs" />
              <span>
                {runningTrips.length} buses are running. The map follows {TRACKED_LIMIT} of them to keep the load on
                the server sensible — the rest are listed below and still running.
              </span>
            </p>
          )}

          <div className="grid grid-cols-1 gap-lg xl:grid-cols-[1.6fr_1fr]">
            <div className="h-[560px] overflow-hidden rounded-md border border-outline">
              <LiveMap tracked={trackedTrips} selectedId={selectedId} onSelect={setSelectedId} />
            </div>

            <div className="flex max-h-[560px] flex-col gap-lg overflow-auto">
              {selected ? (
                <SelectedTrip
                  tracked={selected}
                  eta={eta.data ?? null}
                  etaFailed={eta.isError}
                  onClear={() => setSelectedId(null)}
                />
              ) : (
                <EmptySelection />
              )}

              <TripList
                trips={trackedTrips}
                untracked={runningTrips.length - tracked.length}
                selectedId={selectedId}
                onSelect={setSelectedId}
              />
            </div>
          </div>
        </>
      )}
    </>
  )
}

function EmptySelection() {
  return (
    <section className="rounded-md border border-outline bg-surface p-xl text-center">
      <Icon name="buses" size="lg" className="text-on-surface-muted" />
      <p className="mt-md text-title-md">Select a bus</p>
      <p className="mt-xs text-body text-on-surface-muted">
        Choose a running trip to see live details.
      </p>
    </section>
  )
}

function SelectedTrip({
  tracked,
  eta,
  etaFailed,
  onClear,
}: {
  tracked: TrackedTrip
  eta: import('./api').Eta | null
  etaFailed: boolean
  onClear: () => void
}) {
  const { trip, live } = tracked
  const position = live?.position ?? null
  const next = nextStop(live)
  const current = currentStop(live)
  const progress = stopsCompleted(live)
  const distance = readableDistance(eta?.distance_metres ?? null, eta?.distance_is_estimate ?? null)
  const arrival = readableEta(eta)

  return (
    <section className="rounded-md border border-outline bg-surface">
      <div className="flex items-start gap-md border-b border-outline px-lg py-md">
        <div className="min-w-0">
          <h2 className="truncate text-title-md font-semibold">{trip.route?.route_name ?? 'Trip'}</h2>
          <p className="text-label text-on-surface-muted">
            {trip.route?.route_code} · <span className="font-mono">{trip.bus?.registration_number ?? '—'}</span>
          </p>
        </div>
        <button
          type="button"
          onClick={onClear}
          aria-label="Clear selection"
          className="ml-auto grid size-8 shrink-0 place-items-center rounded-sm hover:bg-surface-sunken"
        >
          <Icon name="close" size="sm" />
        </button>
      </div>

      <div className="flex flex-wrap items-center gap-sm px-lg py-md">
        <TripStatusChip status={trip.status} />
        {position ? (
          position.is_stale ? (
            <StatusChip label="Location stale" tone="caution" icon="warning" />
          ) : (
            <StatusChip label="Live" tone="positive" icon="gps" />
          )
        ) : (
          <StatusChip label="Location unavailable" tone="neutral" icon="offline" />
        )}
      </div>

      <dl className="px-lg pb-md">
        <Row label="Driver">{driverName(trip)}</Row>
        <Row label="Occupancy">
          <span className="font-mono">{occupancy(trip)}</span>
        </Row>
        <Row label="Current stop">{current?.stop_name ?? '—'}</Row>
        <Row label="Next stop">{next?.stop_name ?? '—'}</Row>
        <Row label="Arriving in">{arrival ?? '—'}</Row>
        {/* Road distance from the server. No distance is rendered if the
            server did not give one — a plausible number is worse than none. */}
        <Row label="Distance to next stop">{distance ?? '—'}</Row>
        <Row label="Stops completed">
          <span className="font-mono">
            {progress.done} / {progress.total}
          </span>
        </Row>
      </dl>

      {etaFailed && (
        <p className="px-lg pb-md text-label text-on-surface-muted">
          The estimate could not be refreshed. Position and stop progress are unaffected.
        </p>
      )}

      {live && live.stops.length > 0 && <StopProgress stops={live.stops} />}

      <div className="border-t border-outline px-lg py-md">
        <Link to={`/trips/${trip.id}`} className="text-body text-primary">
          Open trip →
        </Link>
      </div>
    </section>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-lg border-b border-outline py-sm last:border-0">
      <dt className="text-body text-on-surface-muted">{label}</dt>
      <dd className="text-right text-body font-semibold">{children}</dd>
    </div>
  )
}

const STOP_MARK: Record<string, string> = {
  ARRIVED: '✓',
  DEPARTED: '✓',
  SKIPPED: '⊘',
  APPROACHING: '●',
  PENDING: '○',
}

function StopProgress({ stops }: { stops: LiveTrip['stops'] }) {
  return (
    <div className="border-t border-outline px-lg py-md">
      <h3 className="mb-sm text-label font-medium text-on-surface-muted uppercase">Stop progress</h3>
      <ol className="flex flex-col gap-xs">
        {stops.map((stop) => (
          <li key={stop.stop_id} className="flex items-center gap-sm text-body">
            <span
              aria-hidden="true"
              className={
                stop.state === 'ARRIVED' || stop.state === 'DEPARTED'
                  ? 'text-positive'
                  : stop.state === 'APPROACHING'
                    ? 'text-primary'
                    : stop.state === 'SKIPPED'
                      ? 'text-caution'
                      : 'text-on-surface-muted'
              }
            >
              {STOP_MARK[stop.state] ?? '○'}
            </span>
            <span className="min-w-0 flex-1 truncate">{stop.stop_name}</span>
            {/* The backend's own state name, never a re-labelling. */}
            <span className="text-label text-on-surface-muted">{stop.state.toLowerCase()}</span>
          </li>
        ))}
      </ol>
    </div>
  )
}

function TripList({
  trips,
  untracked,
  selectedId,
  onSelect,
}: {
  trips: TrackedTrip[]
  untracked: number
  selectedId: string | null
  onSelect: (id: string) => void
}) {
  return (
    <section className="rounded-md border border-outline bg-surface">
      <h2 className="border-b border-outline px-lg py-md text-title-md font-semibold">Running now</h2>

      <ul>
        {trips.map(({ trip, live }) => {
          const position = live?.position
          const selected = trip.id === selectedId

          return (
            <li key={trip.id}>
              <button
                type="button"
                onClick={() => onSelect(trip.id)}
                className={`flex w-full items-center gap-md border-b border-outline px-lg py-sm text-left last:border-0 hover:bg-surface-sunken ${
                  selected ? 'bg-primary-container/40' : ''
                }`}
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-body font-semibold">
                    {trip.route?.route_name ?? 'Trip'}
                  </span>
                  <span className="block truncate font-mono text-label text-on-surface-muted">
                    {trip.bus?.registration_number ?? '—'} · {driverName(trip)}
                  </span>
                </span>

                {!position ? (
                  <span className="shrink-0 text-label text-on-surface-muted">Location unavailable</span>
                ) : position.is_stale ? (
                  <span className="shrink-0 text-label text-caution">Stale</span>
                ) : (
                  <span className="shrink-0 text-label text-positive">Live</span>
                )}
              </button>
            </li>
          )
        })}
      </ul>

      {untracked > 0 && (
        <p className="border-t border-outline px-lg py-md text-label text-on-surface-muted">
          {untracked} more {untracked === 1 ? 'trip is' : 'trips are'} running but not followed on the map.
        </p>
      )}
    </section>
  )
}
