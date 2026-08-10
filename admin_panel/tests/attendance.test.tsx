import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { server } from '@/mocks/server'
import { setMockLevel } from '@/mocks/handlers'
import { errorResponse, pageResponse } from '@/mocks/fixtures'
import { configureClient } from '@/api/client'
import { AccessLevel } from '@/auth/accessLevel'
import { SessionProvider } from '@/auth/SessionProvider'
import { AttendanceScreen } from '@/features/attendance/AttendanceScreen'
import { TripDetailScreen } from '@/features/trips/TripDetailScreen'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

const discrepancy = (overrides: Record<string, unknown> = {}) => ({
  id: 'ad-1',
  trip_id: 't-1',
  headcount: 34,
  boarding_event_count: 32,
  difference: 2,
  status: 'OPEN',
  review_note: null,
  reviewed_at: null,
  created_at: '2026-08-10T09:00:00.000000Z',
  trip: {
    id: 't-1',
    trip_date: '2026-08-10',
    route: { route_name: 'North Campus Loop' },
    driver: { id: 'd-1', user: { full_name: 'Ravi Kumar' } },
  },
  reviewed_by: null,
  ...overrides,
})

const trip = (overrides: Record<string, unknown> = {}) => ({
  id: 't-1',
  trip_date: '2026-08-10',
  status: 'COMPLETED',
  scheduled_departure_time: '07:30:00',
  scheduled_arrival_time: '08:30:00',
  actual_departure_time: '07:33:00',
  actual_arrival_time: '08:36:00',
  booked_seat_count: 34,
  occupied_seat_count: 32,
  auto_closed: false,
  cancellation_reason: null,
  route: {
    id: 'r-1',
    route_name: 'North Campus Loop',
    route_code: 'NCL',
    number_of_stops: 2,
    total_distance_km: 12,
    estimated_duration_minutes: 45,
    start_point: 'Depot',
    end_point: 'Campus',
  },
  bus: { id: 'b-1', registration_number: 'KA-80-IB-1761', model: 'Tata', seating_capacity: 40, status: 'AVAILABLE', current_odometer: 45200 },
  driver: { id: 'd-1', license_number: 'KA0123', status: 'AVAILABLE', user: { full_name: 'Ravi Kumar' } },
  ...overrides,
})

const liveBody = {
  trip_id: 't-1',
  status: 'COMPLETED',
  position: null,
  occupancy: { occupied: 32, capacity: 40 },
  delay_minutes: 6,
  stops: [
    { stop_id: 's-1', stop_name: 'Main Gate', sequence_number: 1, state: 'DEPARTED', eta_at: null, arrived_at: '2026-08-10T07:40:00Z' },
  ],
}

function renderWith(ui: ReactNode, path: string) {
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}>
        <SessionProvider>{ui}</SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function renderQueueAs(level: AccessLevel, rows = [discrepancy()]) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  server.use(http.get(`${API}/attendance-discrepancies`, () => HttpResponse.json(pageResponse(rows, rows.length))))
  renderWith(<AttendanceScreen />, '/attendance')
  await screen.findByRole('heading', { name: 'Attendance' })
}

async function renderTripAs(level: AccessLevel, body = trip()) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  server.use(
    http.get(`${API}/trips/t-1`, () => HttpResponse.json({ success: true, message: 'ok', code: 200, data: body })),
    http.get(`${API}/trips/t-1/live`, () => HttpResponse.json({ success: true, message: 'ok', code: 200, data: liveBody })),
    http.get(`${API}/trips/t-1/corrections`, () => HttpResponse.json(pageResponse([], 0))),
  )
  renderWith(
    <Routes>
      <Route path="/trips/:id" element={<TripDetailScreen />} />
    </Routes>,
    '/trips/t-1',
  )
  await screen.findByRole('heading', { name: 'North Campus Loop' })
}

// ── discrepancies ──────────────────────────────────────────────────────────

describe('the attendance queue', () => {
  it('shows both figures rather than picking one', async () => {
    await renderQueueAs(AccessLevel.SUPPORT)

    expect(await screen.findByText('34')).toBeInTheDocument()
    expect(screen.getByText('32')).toBeInTheDocument()
    expect(screen.getByText('+2')).toBeInTheDocument()
  })

  it('gives a viewer the queue but no way to settle', async () => {
    await renderQueueAs(AccessLevel.VIEWER)
    await screen.findByText('34')

    expect(screen.queryByRole('button', { name: /^settle$/i })).not.toBeInTheDocument()
  })

  it('says plainly that settling changes neither figure', async () => {
    await renderQueueAs(AccessLevel.SUPPORT)
    await screen.findByText('34')

    await userEvent.click(screen.getByRole('button', { name: /^settle$/i }))

    // BR-266 — the review explains the disagreement, it does not amend it.
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/both original figures stay exactly as they are/i)).toBeInTheDocument()
  })

  it('sends `note`, the field the request actually validates', async () => {
    let sent: unknown = null
    await renderQueueAs(AccessLevel.SUPPORT)
    server.use(
      http.post(`${API}/attendance-discrepancies/ad-1/review`, async ({ request }) => {
        sent = await request.json()

        return HttpResponse.json({ success: true, message: 'Discrepancy reviewed.', code: 200, data: {} })
      }),
    )
    await screen.findByText('34')

    await userEvent.click(screen.getByRole('button', { name: /^settle$/i }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByRole('textbox'), 'Two boarded before the tablet was unlocked.')
    await userEvent.click(within(dialog).getByRole('button', { name: /^settle$/i }))

    await waitFor(() => expect(sent).toEqual({ note: 'Two boarded before the tablet was unlocked.' }))
  })

  it('will not settle one twice', async () => {
    await renderQueueAs(AccessLevel.SUPPORT, [
      discrepancy({ status: 'REVIEWED', review_note: 'Settled at the depot.', reviewed_by: { id: 'u-1', full_name: 'Priya Rao' } }),
    ])
    await screen.findByText('34')

    const settle = screen.getByRole('button', { name: /^settle$/i })
    expect(settle).toBeDisabled()
    expect(screen.getByText('Settled at the depot.')).toBeInTheDocument()
  })
})

// ── trip operations ────────────────────────────────────────────────────────

describe('what can still be done to a trip', () => {
  it('offers a viewer nothing operational', async () => {
    await renderTripAs(AccessLevel.VIEWER)

    expect(screen.queryByRole('button', { name: /reassign/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /cancel trip/i })).not.toBeInTheDocument()
    // BR-258 — amending the evidence of what a driver did is not oversight's.
    expect(screen.queryByRole('button', { name: /record a correction/i })).not.toBeInTheDocument()
  })

  it('offers a supervisor nothing operational either', async () => {
    await renderTripAs(AccessLevel.SUPPORT)

    expect(screen.queryByRole('button', { name: /reassign/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /cancel trip/i })).not.toBeInTheDocument()
  })

  it('gives a transport head both, and disables them on a finished trip', async () => {
    await renderTripAs(AccessLevel.OPERATIONS)

    const cancel = screen.getByRole('button', { name: /cancel trip/i })
    expect(cancel).toBeDisabled()
    expect(cancel).toHaveAttribute('title', 'A trip that is completed cannot be cancelled.')
  })

  it('enables them on a trip that has not finished', async () => {
    await renderTripAs(AccessLevel.OPERATIONS, trip({ status: 'SCHEDULED' }))

    expect(screen.getByRole('button', { name: /cancel trip/i })).toBeEnabled()
    expect(screen.getByRole('button', { name: /reassign/i })).toBeEnabled()
  })

  it('shows a 409 from a cancellation in the server’s own words', async () => {
    await renderTripAs(AccessLevel.OPERATIONS, trip({ status: 'RUNNING' }))
    server.use(
      http.post(`${API}/trips/t-1/cancel`, () =>
        HttpResponse.json(errorResponse('A running trip is completed, not cancelled.', 409), { status: 409 }),
      ),
    )

    await userEvent.click(screen.getByRole('button', { name: /cancel trip/i }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByRole('textbox'), 'The road is closed for a procession.')
    await userEvent.click(within(dialog).getByRole('button', { name: /cancel trip/i }))

    expect(await screen.findByText('A running trip is completed, not cancelled.')).toBeInTheDocument()
  })
})

describe('the stop roster', () => {
  it('is not offered to read-only oversight', async () => {
    await renderTripAs(AccessLevel.VIEWER)

    // `TripPolicy::operate` guards the named list, not `view`. A live probe
    // caught the registry claiming otherwise.
    expect(screen.queryByRole('button', { name: /who boarded/i })).not.toBeInTheDocument()
  })

  it('is fetched only when a transport head asks for a particular stop', async () => {
    let manifestCalls = 0
    await renderTripAs(AccessLevel.OPERATIONS)
    server.use(
      http.get(`${API}/trips/t-1/stops/s-1/manifest`, () => {
        manifestCalls += 1

        return HttpResponse.json({
          success: true,
          message: 'ok',
          code: 200,
          data: {
            expected: [
              { student_id: 'st-1', name: 'Asha Menon', registration_number: '21BCE1043', boarded: true },
              { student_id: 'st-2', name: 'Dev Nair', registration_number: '21BCE1044', boarded: false },
            ],
            expected_count: 2,
            boarded_count: 1,
          },
        })
      }),
    )

    expect(manifestCalls).toBe(0)

    await userEvent.click(screen.getByRole('button', { name: /who boarded at main gate/i }))

    expect(await screen.findByText('Asha Menon')).toBeInTheDocument()
    expect(screen.getByText('did not board')).toBeInTheDocument()
    expect(screen.getByText('1 of 2 expected passengers boarded')).toBeInTheDocument()
    expect(manifestCalls).toBe(1)
  })
})
