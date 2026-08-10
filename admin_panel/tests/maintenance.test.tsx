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
import { MaintenanceScreen } from '@/features/maintenance/MaintenanceScreen'
import { MaintenanceDetailScreen } from '@/features/maintenance/MaintenanceDetailScreen'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

const ticket = (overrides: Record<string, unknown> = {}) => ({
  id: 'mt-1',
  bus_id: 'b-1',
  issue_description: 'Brake pads worn beyond limit on the front axle.',
  status: 'OPEN',
  priority: 'HIGH',
  assigned_to_id: null,
  scheduled_date: null,
  started_at: null,
  completion_date: null,
  estimated_cost: '4500.00',
  actual_cost: null,
  parts_used: null,
  odometer_reading: null,
  resolution_notes: null,
  cancellation_reason: null,
  created_at: '2026-08-09T09:00:00.000000Z',
  vehicle_incident_id: null,
  vehicle_inspection_id: null,
  bus: { id: 'b-1', registration_number: 'KA-80-IB-1761', status: 'MAINTENANCE' },
  assigned_to: null,
  opened_by: { id: 'u-1', full_name: 'Priya Rao' },
  completed_by: null,
  ...overrides,
})

const schedule = {
  id: 'pm-1',
  bus_id: 'b-1',
  service_name: 'Engine oil and filter',
  description: null,
  interval_days: 180,
  interval_km: 15000,
  last_serviced_on: '2026-02-01T00:00:00.000000Z',
  last_serviced_odometer: 30000,
  due_on: '2026-08-01T00:00:00.000000Z',
  due_at_odometer: 45000,
  grace_days: 7,
  is_active: true,
  open_ticket_id: null,
  bus: { id: 'b-1', registration_number: 'KA-80-IB-1761' },
  open_ticket: null,
}

function renderWith(ui: ReactNode, path = '/maintenance') {
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}>
        <SessionProvider>{ui}</SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function renderQueueAs(level: AccessLevel) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  server.use(http.get(`${API}/maintenance-tickets`, () => HttpResponse.json(pageResponse([ticket()], 1))))
  renderWith(<MaintenanceScreen />)
  await screen.findByText('Brake pads worn beyond limit on the front axle.')
}

async function renderTicketAs(level: AccessLevel, body = ticket()) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  server.use(
    http.get(`${API}/maintenance-tickets/mt-1`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: body }),
    ),
  )
  renderWith(
    <Routes>
      <Route path="/maintenance/:id" element={<MaintenanceDetailScreen />} />
    </Routes>,
    '/maintenance/mt-1',
  )
  await screen.findByRole('heading', { name: /brake pads worn/i })
}

// ── A10 ────────────────────────────────────────────────────────────────────

describe('the maintenance queue', () => {
  it('asks for open work by default', async () => {
    let asked = ''
    setMockLevel(AccessLevel.OPERATIONS)
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
    server.use(
      http.get(`${API}/maintenance-tickets`, ({ request }) => {
        asked = new URL(request.url).search

        return HttpResponse.json(pageResponse([ticket()], 1))
      }),
    )
    renderWith(<MaintenanceScreen />)

    await screen.findByText('Brake pads worn beyond limit on the front axle.')
    expect(asked).toContain('open=1')
  })

  it('leaves due-ness to the server rather than recomputing it', async () => {
    let asked = ''
    setMockLevel(AccessLevel.OPERATIONS)
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
    server.use(
      http.get(`${API}/maintenance-tickets`, () => HttpResponse.json(pageResponse([], 0))),
      http.get(`${API}/preventive-maintenance`, ({ request }) => {
        asked = new URL(request.url).search

        return HttpResponse.json(pageResponse([schedule], 1))
      }),
    )
    renderWith(<MaintenanceScreen />)

    await userEvent.click(await screen.findByRole('tab', { name: 'Preventive' }))

    expect(await screen.findByText('Engine oil and filter')).toBeInTheDocument()
    // `due=1` is the server's judgement across days and kilometres.
    expect(asked).toContain('due=1')
    expect(screen.getByText('45,000 km')).toBeInTheDocument()
  })

  it('offers a viewer no way to raise work', async () => {
    await renderQueueAs(AccessLevel.VIEWER)

    expect(screen.queryByRole('button', { name: /open a ticket/i })).not.toBeInTheDocument()
  })

  it('lets a supervisor raise work', async () => {
    await renderQueueAs(AccessLevel.SUPPORT)

    expect(screen.getByRole('button', { name: /open a ticket/i })).toBeInTheDocument()
  })
})

// ── the ticket ─────────────────────────────────────────────────────────────

describe('what each tier may do to a ticket', () => {
  it('gives a viewer the record and nothing else', async () => {
    await renderTicketAs(AccessLevel.VIEWER)

    for (const label of [/assign/i, /schedule/i, /start work/i, /complete/i, /cancel ticket/i]) {
      expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    }
  })

  it('gives a supervisor assign, schedule and start — but not sign-off', async () => {
    await renderTicketAs(AccessLevel.SUPPORT)

    expect(screen.getByRole('button', { name: /assign/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /schedule/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /start work/i })).toBeInTheDocument()
    // BR-358: signing work off returns a vehicle to the road.
    expect(screen.queryByRole('button', { name: /^complete$/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /cancel ticket/i })).not.toBeInTheDocument()
  })

  it('gives a transport head sign-off', async () => {
    await renderTicketAs(AccessLevel.OPERATIONS, ticket({ status: 'IN_PROGRESS', started_at: '2026-08-10T07:00:00Z' }))

    expect(screen.getByRole('button', { name: /^complete$/i })).toBeEnabled()
  })

  it('will not let work already under way be cancelled, and says why', async () => {
    await renderTicketAs(AccessLevel.OPERATIONS, ticket({ status: 'IN_PROGRESS' }))

    const cancel = screen.getByRole('button', { name: /cancel ticket/i })
    expect(cancel).toBeDisabled()
    expect(cancel).toHaveAttribute(
      'title',
      'Work already under way is completed with what was found, not cancelled.',
    )
  })
})

describe('returning a bus to service', () => {
  it('is offered as a second, separate act after sign-off', async () => {
    await renderTicketAs(
      AccessLevel.OPERATIONS,
      ticket({ status: 'COMPLETED', completion_date: '2026-08-10T12:00:00Z', bus: { id: 'b-1', registration_number: 'KA-80-IB-1761', status: 'MAINTENANCE' } }),
    )

    // J10: there is no endpoint that does both, so the panel does not pretend.
    expect(screen.getByText(/still off the road/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /return to service/i })).toBeInTheDocument()
  })

  it('calls PATCH /buses/{id}/status, naming the bus in the confirmation', async () => {
    let sent: unknown = null
    await renderTicketAs(
      AccessLevel.OPERATIONS,
      ticket({ status: 'COMPLETED', bus: { id: 'b-1', registration_number: 'KA-80-IB-1761', status: 'MAINTENANCE' } }),
    )
    server.use(
      http.patch(`${API}/buses/b-1/status`, async ({ request }) => {
        sent = await request.json()

        return HttpResponse.json({ success: true, message: 'Bus status updated.', code: 200, data: {} })
      }),
    )

    await userEvent.click(screen.getByRole('button', { name: /return to service/i }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/KA-80-IB-1761/)).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: /return to service/i }))

    await waitFor(() => expect(sent).toMatchObject({ status: 'AVAILABLE' }))
  })

  it('is not offered when the bus is already available', async () => {
    await renderTicketAs(
      AccessLevel.OPERATIONS,
      ticket({ status: 'COMPLETED', bus: { id: 'b-1', registration_number: 'KA-80-IB-1761', status: 'AVAILABLE' } }),
    )

    expect(screen.queryByText(/still off the road/i)).not.toBeInTheDocument()
  })
})

describe('when the workshop server refuses', () => {
  it('shows a 409 verbatim and changes nothing on screen', async () => {
    await renderTicketAs(AccessLevel.SUPPORT)
    server.use(
      http.post(`${API}/maintenance-tickets/mt-1/start`, () =>
        HttpResponse.json(errorResponse('A ticket cannot go from OPEN to IN_PROGRESS twice.', 409), {
          status: 409,
        }),
      ),
    )

    await userEvent.click(screen.getByRole('button', { name: /start work/i }))

    expect(await screen.findByText('A ticket cannot go from OPEN to IN_PROGRESS twice.')).toBeInTheDocument()
    expect(screen.getByText(/nothing was changed/i)).toBeInTheDocument()
  })

  it('routes a 422 to the field the server named', async () => {
    await renderTicketAs(AccessLevel.OPERATIONS, ticket({ status: 'IN_PROGRESS' }))
    server.use(
      http.post(`${API}/maintenance-tickets/mt-1/complete`, () =>
        HttpResponse.json(
          errorResponse('The given data was invalid.', 422, {
            odometer_reading: ['The odometer reading cannot be lower than the last recorded value.'],
          }),
          { status: 422 },
        ),
      ),
    )

    await userEvent.click(screen.getByRole('button', { name: /^complete$/i }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getAllByRole('textbox')[0], 'Replaced the front pads.')
    await userEvent.click(within(dialog).getByRole('button', { name: /^complete$/i }))

    expect(
      await screen.findByText('The odometer reading cannot be lower than the last recorded value.'),
    ).toBeInTheDocument()
  })
})
