import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, Panel, RangeLabel, RefreshButton, StaleBanner } from '@/components/Panel'
import { Icon } from '@/icons/Icon'
import {
  clock,
  distance,
  fetchRouteStops,
  fetchRoutes,
  fetchSchedules,
  humanise,
  routeKeys,
  type RouteFilters,
  type TransportRoute,
} from './api'

/**
 * A17 Routes.
 *
 * Read-only, and deliberately so. Drawing a route means placing stops on a map
 * with coordinates inside the service area, and a half-built route editor is
 * worse than none: a route with three of its seven stops looks like a route.
 * The endpoints for creating and editing exist and are OPERATIONS-gated; the
 * decision not to surface them here is recorded in `capability-registry.md`
 * rather than hidden.
 */
export function RoutesScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()

  const filters: RouteFilters = {
    status: params.get('status') ?? '',
    search: params.get('search') ?? '',
    page: Number(params.get('page') ?? 1),
  }

  const routes = useQuery({ queryKey: routeKeys.list(filters), queryFn: () => fetchRoutes(filters) })
  const [expanded, setExpanded] = useState<string | null>(null)

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
        title="Routes"
        subtitle="The network, its stops, and the timetable that runs on it."
        actions={
          <RefreshButton
            onClick={() => void queryClient.invalidateQueries({ queryKey: ['routes'] })}
            busy={routes.isFetching}
          />
        }
      />

      <p className="mb-lg flex items-start gap-sm rounded-md border border-outline bg-surface p-md text-body">
        <Icon name="routes" size="sm" className="mt-xs text-on-surface-muted" />
        <span>
          Routes are read-only here. Placing stops needs a map and coordinates inside the service area, and a
          route missing half its stops still looks like a route.
        </span>
      </p>

      <div className="mb-lg flex flex-wrap items-end gap-md rounded-md border border-outline bg-surface p-md">
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Search</span>
          <input
            type="search"
            defaultValue={filters.search}
            placeholder="Route name or code"
            onChange={(event) => update({ search: event.target.value })}
            className="h-[var(--size-control)] w-56 rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>
        <span className="ml-auto">
          <RangeLabel pagination={routes.data?.pagination} noun="routes" />
        </span>
      </div>

      <Panel>
        {routes.isError && routes.data && (
          <StaleBanner error={routes.error} onRetry={() => void routes.refetch()} />
        )}
        {routes.isPending && <LoadingRows />}
        {routes.isError && !routes.data && (
          <LoadFailed what="routes" error={routes.error} onRetry={() => void routes.refetch()} />
        )}
        {routes.data && routes.data.rows.length === 0 && (
          <EmptyState icon="routes" title="No routes match these filters" />
        )}

        {routes.data?.rows.map((route) => (
          <RouteRow
            key={route.id}
            route={route}
            expanded={expanded === route.id}
            onToggle={() => setExpanded(expanded === route.id ? null : route.id)}
          />
        ))}
      </Panel>

      <Pager pagination={routes.data?.pagination} onPage={(page) => update({ page })} />
    </>
  )
}

function RouteRow({
  route,
  expanded,
  onToggle,
}: {
  route: TransportRoute
  expanded: boolean
  onToggle: () => void
}) {
  return (
    <article className="border-b border-outline p-lg last:border-0">
      <div className="flex flex-wrap items-baseline gap-sm">
        <span className="font-mono text-label text-on-surface-muted">{route.route_code}</span>
        <h2 className="text-title-md font-semibold">{route.route_name}</h2>
        <StatusChip label={humanise(route.status)} tone={route.status === 'ACTIVE' ? 'positive' : 'neutral'} />
        <span className="ml-auto text-label text-on-surface-muted">
          {route.start_point ?? '—'} → {route.end_point ?? '—'} · {distance(route.total_distance_km)}
          {route.estimated_duration_minutes && ` · ${route.estimated_duration_minutes} min`}
          {route.number_of_stops !== undefined && route.number_of_stops !== null && ` · ${route.number_of_stops} stops`}
        </span>
      </div>

      <button
        type="button"
        aria-expanded={expanded}
        onClick={onToggle}
        className="mt-sm text-label font-semibold text-primary"
      >
        {expanded ? 'Hide stops and timetable' : 'Stops and timetable'}
      </button>

      {expanded && <RouteDetail routeId={route.id} />}
    </article>
  )
}

function RouteDetail({ routeId }: { routeId: string }) {
  const stops = useQuery({ queryKey: routeKeys.stops(routeId), queryFn: () => fetchRouteStops(routeId) })
  const schedules = useQuery({ queryKey: routeKeys.schedules(routeId), queryFn: () => fetchSchedules(routeId) })

  return (
    <div className="mt-md grid gap-lg lg:grid-cols-2">
      <section>
        <h3 className="text-label font-medium text-on-surface-muted uppercase">Stops</h3>
        {stops.isPending && <div className="mt-xs h-24 animate-pulse rounded-sm bg-surface-sunken" />}
        {stops.isError && <p className="mt-xs text-label text-on-surface-muted">Stops could not be loaded.</p>}
        {stops.data && stops.data.rows.length === 0 && (
          <p className="mt-xs text-label text-on-surface-muted">This route has no stops yet.</p>
        )}
        {stops.data && stops.data.rows.length > 0 && (
          <ol className="mt-xs rounded-sm border border-outline">
            {stops.data.rows.map((stop) => (
              <li
                key={stop.id}
                className="flex items-baseline gap-md border-b border-outline px-md py-xs text-label last:border-0"
              >
                <span className="w-6 shrink-0 font-mono text-on-surface-muted">{stop.sequence_number}</span>
                <span className="min-w-0 flex-1 truncate">{stop.stop_name}</span>
                <span className="text-on-surface-muted">
                  {stop.distance_from_start_km !== null && `${stop.distance_from_start_km} km`}
                  {stop.estimated_arrival_minutes !== null && ` · +${stop.estimated_arrival_minutes} min`}
                </span>
              </li>
            ))}
          </ol>
        )}
      </section>

      <section>
        <h3 className="text-label font-medium text-on-surface-muted uppercase">Timetable</h3>
        {schedules.isPending && <div className="mt-xs h-24 animate-pulse rounded-sm bg-surface-sunken" />}
        {schedules.isError && (
          <p className="mt-xs text-label text-on-surface-muted">The timetable could not be loaded.</p>
        )}
        {schedules.data && schedules.data.rows.length === 0 && (
          <p className="mt-xs text-label text-on-surface-muted">Nothing is timetabled on this route.</p>
        )}
        {schedules.data && schedules.data.rows.length > 0 && (
          <ul className="mt-xs rounded-sm border border-outline">
            {schedules.data.rows.map((schedule) => (
              <li
                key={schedule.id}
                className="flex items-baseline gap-md border-b border-outline px-md py-xs text-label last:border-0"
              >
                <span className="font-mono">
                  {clock(schedule.departure_time)}–{clock(schedule.arrival_time)}
                </span>
                <span>{humanise(schedule.day_of_week)}</span>
                <span className="ml-auto font-mono text-on-surface-muted">
                  {schedule.bus?.registration_number ?? '—'}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}
