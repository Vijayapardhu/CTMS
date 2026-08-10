import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { server } from '@/mocks/server'
import { errorResponse, pageResponse } from '@/mocks/fixtures'
import { configureClient } from '@/api/client'
import { LiveOperationsScreen } from '@/features/live/LiveOperationsScreen'
import { TRACKED_LIMIT, readableDistance, trackedSet } from '@/features/live/api'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => server.resetHandlers())
afterAll(() => server.close())

const runningTrip = (n: number) => ({
  id: `t-${n}`,
  trip_date: '2026-08-10',
  status: 'RUNNING',
  scheduled_departure_time: `0${(n % 9) + 1}:00:00`,
  scheduled_arrival_time: '09:00:00',
  actual_departure_time: '08:06:00',
  actual_arrival_time: null,
  booked_seat_count: 20,
  occupied_seat_count: 18,
  auto_closed: false,
  cancellation_reason: null,
  route: { id: 'r-1', route_name: `Route ${n}`, route_code: `R-0${n}`, number_of_stops: 2, total_distance_km: 37, estimated_duration_minutes: 60, start_point: 'A', end_point: 'B' },
  bus: { id: `b-${n}`, registration_number: `AP-39-X-${1000 + n}`, model: 'Tata', seating_capacity: 50, status: 'RUNNING', current_odometer: 1 },
  driver: { id: `d-${n}`, license_number: 'AP-1', status: 'ON_TRIP', user: { full_name: `Driver ${n}` } },
})

const live = (options: { position?: 'live' | 'stale' | 'none' } = {}) => ({
  trip_id: 't-1',
  status: 'RUNNING',
  position:
    options.position === 'none'
      ? null
      : {
          latitude: 16.97,
          longitude: 82.09,
          recorded_at: '2026-08-10T08:21:35+00:00',
          age_seconds: 14,
          is_stale: options.position === 'stale',
        },
  occupancy: { occupied: 18, capacity: 50 },
  delay_minutes: 0,
  stops: [
    { stop_id: 's-1', stop_name: 'Velangi', sequence_number: 1, state: 'DEPARTED', eta_at: null, arrived_at: '2026-08-10T08:09:35+00:00' },
    { stop_id: 's-2', stop_name: 'Peddapuram', sequence_number: 2, state: 'PENDING', eta_at: null, arrived_at: null },
  ],
})

const etaBody = {
  eta_at: '2026-08-10T08:45:00+00:00',
  minutes: 12,
  basis: 'live',
  stops_away: 1,
  distance_metres: 36964,
  distance_is_estimate: false,
}

function handlers(count: number, options: { position?: 'live' | 'stale' | 'none' } = {}) {
  const trips = Array.from({ length: count }, (_, index) => runningTrip(index + 1))

  return [
    http.get(`${API}/trips`, () => HttpResponse.json(pageResponse(trips, count))),
    http.get(`${API}/trips/:id/live`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: live(options) }),
    ),
    http.get(`${API}/trips/:id/eta`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: etaBody }),
    ),
  ]
}

function renderLive() {
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <LiveOperationsScreen />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('the tracked set', () => {
  it('caps at twelve and stays stable between refreshes', () => {
    const trips = Array.from({ length: 18 }, (_, index) => runningTrip(index + 1)) as never[]

    const first = trackedSet(trips)
    const second = trackedSet([...trips].reverse())

    expect(first).toHaveLength(TRACKED_LIMIT)
    // The same twelve, whatever order the server returned them in — a map
    // whose buses take turns appearing is worse than one that admits the cap.
    expect(second.map((trip) => trip.id)).toEqual(first.map((trip) => trip.id))
  })
})

describe('road distance', () => {
  it('drops precision as the number grows', () => {
    expect(readableDistance(850, false)).toBe('850 m')
    expect(readableDistance(7400, false)).toBe('7.4 km')
    expect(readableDistance(36964, false)).toBe('37 km')
  })

  it('marks an estimate rather than presenting it as exact', () => {
    expect(readableDistance(32414, true)).toBe('~32 km')
  })

  it('renders nothing when the server gave no distance', () => {
    expect(readableDistance(null, false)).toBeNull()
  })
})

describe('live operations', () => {
  it('lists running trips and says how many are tracked', async () => {
    server.use(...handlers(3))
    renderLive()

    expect(await screen.findByText('Tracking 3 of 3 running trips')).toBeInTheDocument()
    expect(screen.getByText('Route 1')).toBeInTheDocument()
  })

  it('makes the twelve-trip cap obvious rather than silently truncating', async () => {
    server.use(...handlers(18))
    renderLive()

    expect(await screen.findByText('Tracking 12 of 18 running trips')).toBeInTheDocument()
    expect(screen.getByText(/6 more trips are running but not followed on the map/)).toBeInTheDocument()
  })

  it('says so plainly when nothing is running', async () => {
    server.use(http.get(`${API}/trips`, () => HttpResponse.json(pageResponse([], 0))))
    renderLive()

    expect(await screen.findByText('No trip is running')).toBeInTheDocument()
  })

  it('asks for no estimate until a trip is selected', async () => {
    let etaCalls = 0
    server.use(
      http.get(`${API}/trips/:id/eta`, () => {
        etaCalls += 1

        return HttpResponse.json({ success: true, message: 'ok', code: 200, data: etaBody })
      }),
      ...handlers(3),
    )
    renderLive()
    await screen.findByText('Select a bus')

    // Three trips tracked, zero ETA calls. That is the budget.
    expect(etaCalls).toBe(0)

    await userEvent.click(screen.getByRole('button', { name: /Route 1/ }))

    await waitFor(() => expect(etaCalls).toBe(1))
  })

  it('shows the selected trip with its road distance and next stop', async () => {
    server.use(...handlers(2))
    renderLive()
    await screen.findByText('Select a bus')

    await userEvent.click(screen.getByRole('button', { name: /Route 1/ }))

    expect(await screen.findByText('37 km')).toBeInTheDocument()
    expect(screen.getByText('12 min')).toBeInTheDocument()
    // Named twice on purpose: as the next stop, and in the progress list.
    expect(screen.getAllByText('Peddapuram')).toHaveLength(2)
    expect(screen.getByText('1 / 2')).toBeInTheDocument()
    // The routing implementation is never named to management. The
    // map-unavailable notice does name Google Maps, and should — that one is
    // a deployment message about a missing credential, not a distance label.
    expect(screen.queryByText(/Google Routes|Google API|Routes API/)).not.toBeInTheDocument()
  })

  it('never shows a stale position as live', async () => {
    server.use(...handlers(1, { position: 'stale' }))
    renderLive()
    await screen.findByText('Select a bus')

    await userEvent.click(screen.getByRole('button', { name: /Route 1/ }))

    expect(await screen.findByText('Location stale')).toBeInTheDocument()
    expect(screen.queryByText('Live')).not.toBeInTheDocument()
  })

  it('says a trip has no location rather than inventing one', async () => {
    server.use(...handlers(1, { position: 'none' }))
    renderLive()
    await screen.findByText('Select a bus')

    await userEvent.click(screen.getByRole('button', { name: /Route 1/ }))

    // Not 0,0, and not the last place it was seen. Said in the panel chip and
    // again beside the row in the list.
    expect(await screen.findAllByText('Location unavailable')).toHaveLength(2)
  })

  it('keeps the last known data when a refresh fails', async () => {
    let calls = 0
    server.use(
      http.get(`${API}/trips`, () => {
        calls += 1
        if (calls === 1) return HttpResponse.json(pageResponse([runningTrip(1)], 1))

        return HttpResponse.json(errorResponse('Server error.', 500), { status: 500 })
      }),
      ...handlers(1).slice(1),
    )
    renderLive()
    await screen.findByText('Route 1')

    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }))

    await waitFor(() => expect(screen.getByText(/Unable to update live data/)).toBeInTheDocument())
    // The map and the list are still there.
    expect(screen.getByText('Route 1')).toBeInTheDocument()
  })

  it('shows a clear error when nothing ever loaded', async () => {
    server.use(http.get(`${API}/trips`, () => HttpResponse.json(errorResponse('Server error.', 500), { status: 500 })))
    renderLive()

    expect(await screen.findByText('Live data could not be loaded')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  })

  it('keeps the screen usable when only the estimate fails', async () => {
    server.use(
      http.get(`${API}/trips/:id/eta`, () =>
        HttpResponse.json(errorResponse('Server error.', 500), { status: 500 }),
      ),
      ...handlers(1),
    )
    renderLive()
    await screen.findByText('Select a bus')

    await userEvent.click(screen.getByRole('button', { name: /Route 1/ }))

    expect(await screen.findByText(/estimate could not be refreshed/)).toBeInTheDocument()
    // Position and stop progress are unaffected.
    expect(screen.getAllByText('Peddapuram').length).toBeGreaterThan(0)
  })

  it('says the map is not configured without taking the screen down', async () => {
    server.use(...handlers(1))
    renderLive()

    // No VITE_GOOGLE_MAPS_API_KEY in the test environment, which is the
    // deployed state until a browser key exists.
    expect(await screen.findByText('Map unavailable')).toBeInTheDocument()
    expect(screen.getByText(/Google Maps configuration is missing/)).toBeInTheDocument()
    expect(screen.getByText('Route 1')).toBeInTheDocument()
  })
})
