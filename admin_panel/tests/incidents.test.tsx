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
import { IncidentsScreen } from '@/features/incidents/IncidentsScreen'
import { IncidentDetailScreen } from '@/features/incidents/IncidentDetailScreen'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

/** Shapes captured from a running backend, not from the design document. */
const incident = (overrides: Record<string, unknown> = {}) => ({
  id: 'inc-1',
  incident_class: 'OPERATIONAL',
  incident_type: 'BRAKE_FAULT',
  severity: 'HIGH',
  status: 'REPORTED',
  description: 'Pedal travel is excessive and the bus pulls left under braking.',
  latitude: '12.9716000',
  longitude: '77.5946000',
  passengers_aboard: 34,
  vehicle_can_continue: false,
  reported_at: '2026-08-10T04:05:00.000000Z',
  acknowledged_at: null,
  resolved_at: null,
  escalated_at: null,
  resolution_notes: null,
  was_cancelled: false,
  cancellation_note: null,
  trip_id: 't-1',
  bus_id: 'b-1',
  maintenance_ticket_id: null,
  bus: { id: 'b-1', registration_number: 'KA-80-IB-1761' },
  driver: { id: 'd-1', user: { id: 'u-driver', full_name: 'Ravi Kumar' } },
  trip: { id: 't-1', trip_date: '2026-08-10', route: { route_name: 'North Campus Loop' } },
  reported_by: { id: 'u-driver', full_name: 'Ravi Kumar' },
  notes: [],
  ...overrides,
})

function renderWith(ui: ReactNode, path = '/incidents') {
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

/** The detail screen through a real session, because its actions ask `can`. */
async function renderDetailAs(level: AccessLevel, body = incident()) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  server.use(
    http.get(`${API}/incidents/inc-1`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: body }),
    ),
  )

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/incidents/inc-1']}>
        <SessionProvider>
          <Routes>
            <Route path="/incidents/:id" element={<IncidentDetailScreen />} />
          </Routes>
        </SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  await screen.findByRole('heading', { name: 'Brake fault' })
}

// ── A8 ─────────────────────────────────────────────────────────────────────

describe('the incident queue', () => {
  it('asks for the open queue by default and says so', async () => {
    let asked: string | null = null
    server.use(
      http.get(`${API}/incidents`, ({ request }) => {
        asked = new URL(request.url).search

        return HttpResponse.json(pageResponse([incident()], 1))
      }),
    )
    renderWith(<IncidentsScreen />)

    expect(await screen.findByText('Brake fault')).toBeInTheDocument()
    expect(asked).toContain('open=1')
    expect(screen.getByText(/the open queue/i)).toBeInTheDocument()
  })

  it('renders real columns from the real shape', async () => {
    server.use(http.get(`${API}/incidents`, () => HttpResponse.json(pageResponse([incident()], 1))))
    renderWith(<IncidentsScreen />)

    await screen.findByText('Brake fault')
    expect(screen.getByText('KA-80-IB-1761')).toBeInTheDocument()
    expect(screen.getByText('Ravi Kumar')).toBeInTheDocument()
    expect(screen.getByText('North Campus Loop')).toBeInTheDocument()
    // Severity is a real stored column, so it is shown.
    expect(screen.getByText('High')).toBeInTheDocument()
  })

  it('offers no filter the endpoint cannot honour', async () => {
    server.use(http.get(`${API}/incidents`, () => HttpResponse.json(pageResponse([incident()], 1))))
    renderWith(<IncidentsScreen />)
    await screen.findByText('Brake fault')

    // G1-2: there is no severity or date filter on `GET /incidents`, and one
    // that filtered a single page would under-report the queue.
    expect(screen.getByRole('combobox', { name: /status/i })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: /class/i })).toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: /severity/i })).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/date/i)).not.toBeInTheDocument()
  })

  it('says an empty queue is good news, not an error', async () => {
    server.use(http.get(`${API}/incidents`, () => HttpResponse.json(pageResponse([], 0))))
    renderWith(<IncidentsScreen />)

    expect(await screen.findByText('No open incidents')).toBeInTheDocument()
    expect(screen.getByText(/the good day/i)).toBeInTheDocument()
  })

  it('does not present a failed first load as an empty queue', async () => {
    server.use(
      http.get(`${API}/incidents`, () =>
        HttpResponse.json(errorResponse('Server error.', 500), { status: 500 }),
      ),
    )
    renderWith(<IncidentsScreen />)

    expect(await screen.findByText('Unable to load incidents')).toBeInTheDocument()
    expect(screen.queryByText('No open incidents')).not.toBeInTheDocument()
  })
})

// ── A9 ─────────────────────────────────────────────────────────────────────

describe('the incident record', () => {
  it('shows the driver’s own words and the recorded facts', async () => {
    await renderDetailAs(AccessLevel.VIEWER)

    expect(await screen.findByText(/pedal travel is excessive/i)).toBeInTheDocument()
    expect(await screen.findByText('12.97160, 77.59460')).toBeInTheDocument()
    expect(screen.getByText('34')).toBeInTheDocument()
  })
})

describe('what each tier may do to an incident', () => {
  it('offers a viewer nothing but the record', async () => {
    await renderDetailAs(AccessLevel.VIEWER)

    expect(screen.queryByRole('button', { name: /acknowledge/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^resolve$/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /close/i })).not.toBeInTheDocument()
    // Reading an incident is not permission to write on it — G3-3.
    expect(screen.queryByRole('button', { name: /add note/i })).not.toBeInTheDocument()
  })

  it('lets a supervisor acknowledge and resolve, but not close', async () => {
    await renderDetailAs(AccessLevel.SUPPORT)

    expect(screen.getByRole('button', { name: /acknowledge/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^resolve$/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^close incident$/i })).not.toBeInTheDocument()
  })

  it('lets a transport head close', async () => {
    await renderDetailAs(AccessLevel.OPERATIONS, incident({ status: 'RESOLVED', resolved_at: '2026-08-10T06:00:00Z' }))

    expect(screen.getByRole('button', { name: /^close$/i })).toBeEnabled()
  })

  it('disables an action the state machine forbids, and says why', async () => {
    await renderDetailAs(AccessLevel.OPERATIONS, incident({ status: 'REPORTED' }))

    const close = screen.getByRole('button', { name: /^close$/i })
    expect(close).toBeDisabled()
    expect(close).toHaveAttribute('title', 'Only a resolved incident can be closed.')
  })
})

describe('when the server refuses', () => {
  it('shows a 409 in the server’s own words and does not retry it', async () => {
    let attempts = 0
    await renderDetailAs(AccessLevel.SUPPORT)
    server.use(
      http.post(`${API}/incidents/inc-1/acknowledge`, () => {
        attempts += 1

        return HttpResponse.json(
          errorResponse('This incident was already acknowledged by the transport office.', 409),
          { status: 409 },
        )
      }),
    )

    await userEvent.click(screen.getByRole('button', { name: /acknowledge/i }))

    expect(
      await screen.findByText('This incident was already acknowledged by the transport office.'),
    ).toBeInTheDocument()
    expect(screen.getByText(/nothing was changed/i)).toBeInTheDocument()
    await waitFor(() => expect(attempts).toBe(1))
  })

  it('routes a 422 back to the field that caused it', async () => {
    await renderDetailAs(AccessLevel.SUPPORT)
    server.use(
      http.post(`${API}/incidents/inc-1/notes`, () =>
        HttpResponse.json(
          errorResponse('The given data was invalid.', 422, { note: ['The note must not exceed 2000 characters.'] }),
          { status: 422 },
        ),
      ),
    )

    await userEvent.type(screen.getByRole('textbox', { name: /add a note/i }), 'Workshop called.')
    await userEvent.click(screen.getByRole('button', { name: /add note/i }))

    expect(await screen.findByText('The note must not exceed 2000 characters.')).toBeInTheDocument()
  })

  it('does not sign anybody out when the server says 403', async () => {
    await renderDetailAs(AccessLevel.SUPPORT)
    server.use(
      http.post(`${API}/incidents/inc-1/acknowledge`, () =>
        HttpResponse.json(errorResponse('This action is unauthorized.', 403), { status: 403 }),
      ),
    )

    await userEvent.click(screen.getByRole('button', { name: /acknowledge/i }))

    expect(await screen.findByText(/have permission to perform this action/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sign in' })).not.toBeInTheDocument()
  })
})

describe('withdrawing an incident', () => {
  it('asks for a reason and warns that others may have acted', async () => {
    await renderDetailAs(AccessLevel.SUPPORT)

    await userEvent.click(screen.getByRole('button', { name: /^record as false alarm$/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/others may already have acted/i)).toBeInTheDocument()
    // Retained, never erased — BR-355.
    expect(within(dialog).getByText(/the original report is retained/i)).toBeInTheDocument()
    // Nothing can be withdrawn without a written reason.
    expect(within(dialog).getByRole('button', { name: /record as false alarm/i })).toBeDisabled()
  })

  it('sends the note the controller actually validates', async () => {
    let sent: unknown = null
    await renderDetailAs(AccessLevel.SUPPORT)
    server.use(
      http.post(`${API}/incidents/inc-1/cancel`, async ({ request }) => {
        sent = await request.json()

        return HttpResponse.json({ success: true, message: 'Recorded as a false alarm.', code: 200, data: {} })
      }),
    )

    await userEvent.click(screen.getByRole('button', { name: /^record as false alarm$/i }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByRole('textbox'), 'Driver reported the wrong bus.')
    await userEvent.click(within(dialog).getByRole('button', { name: /record as false alarm/i }))

    // `note`, not `reason` — read from IncidentController::cancel.
    await waitFor(() => expect(sent).toEqual({ note: 'Driver reported the wrong bus.' }))
  })
})
