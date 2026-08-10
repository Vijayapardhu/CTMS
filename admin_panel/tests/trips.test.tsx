import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { server } from '@/mocks/server'
import { errorResponse, pageResponse } from '@/mocks/fixtures'
import { configureClient } from '@/api/client'
import { AccessLevel } from '@/auth/accessLevel'
import { TripsScreen } from '@/features/trips/TripsScreen'
import { TripDetailScreen } from '@/features/trips/TripDetailScreen'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => server.resetHandlers())
afterAll(() => server.close())

/** Shapes captured from a running backend. */
const trip = (status: string, id = 't-1') => ({
  id,
  trip_date: '2026-08-10',
  status,
  scheduled_departure_time: '08:00:00',
  scheduled_arrival_time: '09:00:00',
  actual_departure_time: status === 'SCHEDULED' ? null : '08:06:00',
  actual_arrival_time: status === 'COMPLETED' ? '09:11:00' : null,
  booked_seat_count: 20,
  occupied_seat_count: 18,
  auto_closed: false,
  cancellation_reason: null,
  route: {
    id: 'r-1',
    route_name: 'Velangi → Aditya University',
    route_code: 'R-04',
    number_of_stops: 6,
    total_distance_km: 37,
    estimated_duration_minutes: 60,
    start_point: 'Velangi',
    end_point: 'Aditya University',
  },
  bus: {
    id: 'b-1',
    registration_number: 'AP-39-X-1122',
    model: 'Tata Starbus',
    seating_capacity: 50,
    status: 'RUNNING',
    current_odometer: 84210,
  },
  driver: { id: 'd-1', license_number: 'AP-2019-1', status: 'ON_TRIP', user: { full_name: 'Ravi Kumar' } },
})

const liveWithStops = (status: string, stale = false) => ({
  trip_id: 't-1',
  status,
  position:
    status === 'RUNNING'
      ? { latitude: 16.97, longitude: 82.09, recorded_at: '2026-08-10T08:21:35+00:00', age_seconds: 14, is_stale: stale }
      : null,
  occupancy: { occupied: 18, capacity: 50 },
  delay_minutes: 0,
  stops: [
    { stop_id: 's-1', stop_name: 'Velangi', sequence_number: 1, state: 'DEPARTED', eta_at: null, arrived_at: '2026-08-10T08:09:35+00:00' },
    { stop_id: 's-2', stop_name: 'Peddapuram', sequence_number: 2, state: 'PENDING', eta_at: null, arrived_at: null },
  ],
})

const liveWithoutStops = (status: string) => ({
  trip_id: 't-1',
  status,
  position: null,
  occupancy: { occupied: 0, capacity: 60 },
  delay_minutes: 0,
  stops: [],
})

function renderWith(ui: React.ReactNode, path = '/trips') {
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

// ── A3 ─────────────────────────────────────────────────────────────────────

describe('the trips list', () => {
  it('renders real-shaped rows with scheduled and actual times', async () => {
    server.use(http.get(`${API}/trips`, () => HttpResponse.json(pageResponse([trip('RUNNING')], 18))))
    renderWith(<TripsScreen />)

    expect(await screen.findByText('Velangi → Aditya University')).toBeInTheDocument()
    expect(screen.getByText('AP-39-X-1122')).toBeInTheDocument()
    expect(screen.getByText('Ravi Kumar')).toBeInTheDocument()
    expect(screen.getByText('08:00')).toBeInTheDocument()
    expect(screen.getByText('act 08:06')).toBeInTheDocument()
    // Capacity comes from the bus, not from the trip.
    expect(screen.getByText('18 / 50')).toBeInTheDocument()
  })

  it('counts from the backend pagination, not from the rows in hand', async () => {
    server.use(http.get(`${API}/trips`, () => HttpResponse.json(pageResponse([trip('RUNNING')], 42))))
    renderWith(<TripsScreen />)

    expect(await screen.findByText('1–20 of 42')).toBeInTheDocument()
    expect(screen.getByText(/Page 1 of 3/)).toBeInTheDocument()
  })

  it('asks the server for the next page rather than slicing locally', async () => {
    const pages: string[] = []
    server.use(
      http.get(`${API}/trips`, ({ request }) => {
        pages.push(new URL(request.url).searchParams.get('page') ?? '1')

        return HttpResponse.json(pageResponse([trip('RUNNING')], 42))
      }),
    )
    renderWith(<TripsScreen />)
    await screen.findByText('1–20 of 42')

    await userEvent.click(screen.getByRole('button', { name: 'Next' }))

    await waitFor(() => expect(pages).toContain('2'))
  })

  it('sends only filters the endpoint validates', async () => {
    let query: URLSearchParams | undefined
    server.use(
      http.get(`${API}/trips`, ({ request }) => {
        query = new URL(request.url).searchParams

        return HttpResponse.json(pageResponse([trip('RUNNING')], 1))
      }),
    )
    renderWith(<TripsScreen />)
    await screen.findByText('Velangi → Aditya University')

    await userEvent.selectOptions(screen.getByLabelText('Status'), 'RUNNING')

    await waitFor(() => expect(query?.get('status')).toBe('RUNNING'))
    // No free-text search exists on this endpoint, so none is offered.
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument()
    expect(query?.get('search')).toBeNull()
  })

  it('says plainly when nothing matches', async () => {
    server.use(http.get(`${API}/trips`, () => HttpResponse.json(pageResponse([], 0))))
    renderWith(<TripsScreen />)

    expect(await screen.findByText('No trips match these filters')).toBeInTheDocument()
  })

  it('offers a retry when the read fails', async () => {
    server.use(
      http.get(`${API}/trips`, () => HttpResponse.json(errorResponse('Server error.', 500), { status: 500 })),
    )
    renderWith(<TripsScreen />)

    expect(await screen.findByText('Unable to load trips')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument()
  })
})

// ── A4 ─────────────────────────────────────────────────────────────────────

function detail(level: AccessLevel, status: string, live: unknown) {
  server.use(
    http.get(`${API}/auth/me`, () =>
      HttpResponse.json({
        success: true,
        message: 'ok',
        code: 200,
        data: {
          id: 'u-1',
          email: 'priya@ctms.example',
          full_name: 'Priya Rao',
          role: 'ADMIN',
          is_active: true,
          profile: { id: 'a-1', designation: 'Officer', department: 'Transport', access_level: level },
        },
      }),
    ),
    http.get(`${API}/trips/t-1`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: trip(status) }),
    ),
    http.get(`${API}/trips/t-1/live`, () => HttpResponse.json({ success: true, message: 'ok', code: 200, data: live })),
    http.get(`${API}/trips/t-1/corrections`, () =>
      HttpResponse.json(
        pageResponse(
          [
            {
              id: 'c-1',
              field: 'occupied_seat_count',
              // The real column names — not old_value/new_value.
              original_value: '18',
              corrected_value: '22',
              reason: 'Recount after the depot check',
              created_at: '2026-08-10T08:30:50.000000Z',
              corrected_by: { first_name: 'Priya', last_name: 'Rao' },
            },
          ],
          1,
        ),
      ),
    ),
    http.get(`${API}/routes/r-1/stops`, () =>
      HttpResponse.json(
        pageResponse(
          [
            { id: 's-1', stop_name: 'Velangi', sequence_number: 1, estimated_arrival_minutes: 0, distance_from_start_km: 0, landmark: null },
            { id: 's-2', stop_name: 'Peddapuram', sequence_number: 2, estimated_arrival_minutes: 22, distance_from_start_km: 12, landmark: null },
          ],
          2,
        ),
      ),
    ),
  )
}

/** The detail screen inside a session, so `can()` is the real thing. */
async function renderDetail(level: AccessLevel, status: string, live: unknown) {
  detail(level, status, live)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')

  const { SessionProvider } = await import('@/auth/SessionProvider')

  renderWith(
    <SessionProvider>
      <Routes>
        <Route path="/trips/:id" element={<TripDetailScreen />} />
      </Routes>
    </SessionProvider>,
    '/trips/t-1',
  )
}

describe('the trip detail', () => {
  afterEach(() => window.sessionStorage.clear())

  it('shows a completed trip with its real stop history', async () => {
    await renderDetail(AccessLevel.OPERATIONS, 'COMPLETED', liveWithStops('COMPLETED'))

    expect(await screen.findByText('Peddapuram')).toBeInTheDocument()
    expect(screen.getAllByText('Departed').length).toBeGreaterThan(0)
    // The detail screen states scheduled and actual as separate fields.
    expect(screen.getByText('09:11')).toBeInTheDocument()
  })

  it('shows a running trip as live when the server says the position is fresh', async () => {
    await renderDetail(AccessLevel.OPERATIONS, 'RUNNING', liveWithStops('RUNNING', false))

    expect(await screen.findByText('Live')).toBeInTheDocument()
    expect(screen.queryByText('Not updating')).not.toBeInTheDocument()
  })

  it('never shows a stale position as current', async () => {
    await renderDetail(AccessLevel.OPERATIONS, 'RUNNING', liveWithStops('RUNNING', true))

    // `is_stale` is the server's judgement and is rendered, not recomputed.
    expect(await screen.findByText('Not updating')).toBeInTheDocument()
    expect(screen.queryByText('Live')).not.toBeInTheDocument()
  })

  it('shows the planned route for a scheduled trip, and says it has not run', async () => {
    await renderDetail(AccessLevel.OPERATIONS, 'SCHEDULED', liveWithoutStops('SCHEDULED'))

    expect(await screen.findByText(/has not started, so there is no stop history/)).toBeInTheDocument()
    expect(screen.getByText('Peddapuram')).toBeInTheDocument()
    // No invented history: the planned list carries no arrival states.
    expect(screen.queryByText('Departed')).not.toBeInTheDocument()
  })
})

describe('correction permissions', () => {
  afterEach(() => window.sessionStorage.clear())

  it('offers no correction to a viewer', async () => {
    await renderDetail(AccessLevel.VIEWER, 'COMPLETED', liveWithStops('COMPLETED'))
    await screen.findByText('Corrections')

    expect(screen.queryByRole('button', { name: 'Correct this trip' })).not.toBeInTheDocument()
  })

  it('offers no correction to a supervisor', async () => {
    await renderDetail(AccessLevel.SUPPORT, 'COMPLETED', liveWithStops('COMPLETED'))
    await screen.findByText('Corrections')

    // BR-258 is an operations decision; SUPPORT sees the history, not the pen.
    expect(screen.queryByRole('button', { name: 'Correct this trip' })).not.toBeInTheDocument()
  })

  it('renders the correction history with the real column names', async () => {
    await renderDetail(AccessLevel.VIEWER, 'COMPLETED', liveWithStops('COMPLETED'))

    expect(await screen.findByText('18 → 22')).toBeInTheDocument()
    expect(screen.getByText(/Priya Rao/)).toBeInTheDocument()
    expect(screen.getByText('Recount after the depot check')).toBeInTheDocument()
  })

  it('offers the correction to a transport head', async () => {
    await renderDetail(AccessLevel.OPERATIONS, 'COMPLETED', liveWithStops('COMPLETED'))

    expect(await screen.findByRole('button', { name: 'Correct this trip' })).toBeInTheDocument()
  })

  it('shows a server refusal rather than failing quietly', async () => {
    await renderDetail(AccessLevel.OPERATIONS, 'COMPLETED', liveWithStops('COMPLETED'))
    server.use(
      http.post(`${API}/trips/t-1/corrections`, () =>
        HttpResponse.json(errorResponse('Requires administrator tier OPERATIONS.', 403), { status: 403 }),
      ),
    )

    await userEvent.click(await screen.findByRole('button', { name: 'Correct this trip' }))
    await userEvent.type(screen.getByLabelText(/Reason/), 'Recount after the depot check')
    await userEvent.click(screen.getByRole('button', { name: 'Record correction' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent("You don't have permission to perform this action.")
    // The server's internal wording never reaches the operator.
    expect(alert).not.toHaveTextContent('tier OPERATIONS')
  })
})
