import { requestPage, type Page } from '@/api/client'

/** Routes, their stops, and the timetable that runs on them. */

export type TransportRoute = {
  id: string
  route_name: string
  route_code: string
  description: string | null
  total_distance_km: number | string | null
  estimated_duration_minutes: number | null
  start_point: string | null
  end_point: string | null
  status: string
  number_of_stops?: number | null
}

export type RouteStop = {
  id: string
  route_id: string
  stop_name: string
  sequence_number: number
  latitude: number | string | null
  longitude: number | string | null
  address: string | null
  landmark: string | null
  distance_from_start_km: number | null
  estimated_arrival_minutes: number | null
  stop_type: string | null
}

export type Schedule = {
  id: string
  route_id: string
  bus_id: string
  driver_id: string
  departure_time: string
  arrival_time: string
  day_of_week: string
  frequency: string
  status: string
  expected_passenger_count: number | null
  route?: { id: string; route_name: string } | null
  bus?: { id: string; registration_number: string } | null
  driver?: { id: string; user?: { full_name?: string; first_name?: string; last_name?: string } | null } | null
}

export type RouteFilters = { status?: string; search?: string; page?: number; per_page?: number }

export const routeKeys = {
  list: (filters: RouteFilters) => ['routes', 'list', filters] as const,
  stops: (routeId: string) => ['routes', routeId, 'stops'] as const,
  schedules: (routeId: string) => ['schedules', routeId] as const,
}

export const fetchRoutes = (filters: RouteFilters): Promise<Page<TransportRoute>> =>
  requestPage<TransportRoute>('/routes', {
    query: {
      status: filters.status || undefined,
      search: filters.search || undefined,
      page: filters.page,
      per_page: filters.per_page ?? 20,
    },
  })

export const fetchRouteStops = (routeId: string): Promise<Page<RouteStop>> =>
  requestPage<RouteStop>(`/routes/${routeId}/stops`)

export const fetchSchedules = (routeId?: string): Promise<Page<Schedule>> =>
  requestPage<Schedule>('/schedules', { query: { route_id: routeId || undefined, per_page: 50 } })

export function humanise(value: string | null | undefined): string {
  if (!value) return '—'
  const words = value.replace(/_/g, ' ').toLowerCase()

  return words.charAt(0).toUpperCase() + words.slice(1)
}

/** `08:00:00` reads as `08:00`. Seconds are noise on a timetable. */
export function clock(value: string | null | undefined): string {
  return value ? value.slice(0, 5) : '—'
}

export function distance(value: number | string | null): string {
  if (value === null || value === '') return '—'
  const numeric = Number(value)

  return Number.isNaN(numeric) ? String(value) : `${numeric} km`
}
