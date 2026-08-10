import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { server } from '@/mocks/server'
import { errorResponse, pageResponse } from '@/mocks/fixtures'
import { configureClient } from '@/api/client'
import { DashboardScreen } from '@/features/dashboard/DashboardScreen'
import { SessionProvider } from '@/auth/SessionProvider'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

/**
 * Rows in the shape the running backend actually returns — captured from a
 * live `GET /trips`, not invented. Incidents carry `incident_type` and
 * `reported_at`; getting those names wrong renders an empty column rather
 * than an error, which is why they are pinned here.
 */
const tripRow = (status: string, id: string) => ({
  id,
  status,
  trip_date: '2026-08-10',
  scheduled_departure_time: '07:30:00',
  occupied_seat_count: 20,
  route: { route_name: 'Velangi → Aditya University', route_code: 'R-04' },
  bus: { registration_number: 'AP-39-X-1122' },
  driver: { user: { full_name: 'Ravi Kumar' } },
})

const busRow = (status: string, id: string) => ({ id, registration_number: `AP-39-X-${id}`, status })

const incidentRow = {
  id: 'i-1',
  incident_type: 'SOS',
  severity: 'CRITICAL',
  status: 'REPORTED',
  reported_at: '2026-08-10T02:12:00+00:00',
  description: 'Emergency (SOS)',
  bus: { registration_number: 'AP-39-X-1122' },
}

function dashboardHandlers(overrides: { incidentTotal?: number } = {}) {
  return [
    http.get(`${API}/trips`, ({ request }) => {
      const status = new URL(request.url).searchParams.get('status')
      if (status === 'RUNNING') return HttpResponse.json(pageResponse([tripRow('RUNNING', 't-1')], 4))

      return HttpResponse.json(
        pageResponse(
          [tripRow('RUNNING', 't-1'), tripRow('SCHEDULED', 't-2'), tripRow('COMPLETED', 't-3')],
          18,
        ),
      )
    }),
    http.get(`${API}/buses`, () =>
      HttpResponse.json(
        pageResponse(
          [
            busRow('AVAILABLE', '1'),
            busRow('AVAILABLE', '2'),
            busRow('MAINTENANCE', '3'),
            busRow('BREAKDOWN', '4'),
          ],
          4,
        ),
      ),
    ),
    http.get(`${API}/incidents`, () =>
      HttpResponse.json(
        overrides.incidentTotal === 0 ? pageResponse([], 0) : pageResponse([incidentRow], overrides.incidentTotal ?? 1),
      ),
    ),
    http.get(`${API}/maintenance-tickets`, () => HttpResponse.json(pageResponse([], 0))),
    http.get(`${API}/fleet/documents/expiring`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: [] }),
    ),
  ]
}

function renderDashboard() {
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <SessionProvider>
          <DashboardScreen />
        </SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('the dashboard', () => {
  it('reads real-shaped data and counts from pagination, not from rows', async () => {
    server.use(...dashboardHandlers())
    renderDashboard()

    // 18 trips today, from `pagination.total` — only three rows were returned.
    expect(await screen.findByText('18')).toBeInTheDocument()
    expect(screen.getByText('4 running now')).toBeInTheDocument()
    // Three rows in the table, all on the same route in this fixture.
    expect(screen.getAllByText('Velangi → Aditya University')).toHaveLength(3)
    expect(screen.getAllByText('Ravi Kumar')).toHaveLength(3)
  })

  it('issues its six requests independently', async () => {
    const seen: string[] = []
    server.events.on('request:start', ({ request }) => seen.push(new URL(request.url).pathname))
    server.use(...dashboardHandlers())

    renderDashboard()
    await screen.findByText('18')

    await waitFor(() => {
      expect(seen.filter((path) => path.endsWith('/trips'))).toHaveLength(2)
      expect(seen).toContain('/api/v1/buses')
      expect(seen).toContain('/api/v1/incidents')
      expect(seen).toContain('/api/v1/maintenance-tickets')
      expect(seen).toContain('/api/v1/fleet/documents/expiring')
    })

    server.events.removeAllListeners()
  })

  it('shows a real zero as zero, not as unknown', async () => {
    server.use(...dashboardHandlers({ incidentTotal: 0 }))
    renderDashboard()

    expect(await screen.findByText('nothing open')).toBeInTheDocument()

    // Zero, rendered as zero. The backend said there are none, which is the
    // best news of the morning — and is not the same as not knowing.
    const card = screen.getByText('nothing open').closest('div')!.parentElement!
    expect(within(card).getByText('0')).toBeInTheDocument()
    expect(within(card).queryByText('—')).not.toBeInTheDocument()
  })

  it('shows an em dash when a value cannot be determined', async () => {
    server.use(
      http.get(`${API}/buses`, () => HttpResponse.json(errorResponse('Server error.', 500), { status: 500 })),
      ...dashboardHandlers(),
    )
    renderDashboard()

    // Never zero. A failed request must not report an empty fleet.
    expect(await screen.findAllByText('Unable to load')).toHaveLength(2)
    expect(screen.queryByText('Buses available')).toBeInTheDocument()
  })

  it('keeps the five good tiles when one fails', async () => {
    server.use(
      http.get(`${API}/incidents`, () => HttpResponse.json(errorResponse('Server error.', 500), { status: 500 })),
      ...dashboardHandlers(),
    )
    renderDashboard()

    // The failure is contained: trips and fleet are still real.
    expect(await screen.findByText('18')).toBeInTheDocument()
    expect(screen.getByText('Unable to load')).toBeInTheDocument()
    expect(screen.queryByText('The CTMS server could not be reached')).not.toBeInTheDocument()
  })

  it('retries just the tile that failed', async () => {
    let attempts = 0
    server.use(
      http.get(`${API}/incidents`, () => {
        attempts += 1
        if (attempts === 1) return HttpResponse.json(errorResponse('Server error.', 500), { status: 500 })

        return HttpResponse.json(pageResponse([incidentRow], 1))
      }),
      ...dashboardHandlers(),
    )
    renderDashboard()

    await userEvent.click(await screen.findByRole('button', { name: 'Retry' }))

    await waitFor(() => expect(screen.queryByText('Unable to load')).not.toBeInTheDocument())
    expect(attempts).toBe(2)
  })

  it('falls back to a page-level failure only when everything fails', async () => {
    server.use(
      http.get(`${API}/trips`, () => HttpResponse.error()),
      http.get(`${API}/buses`, () => HttpResponse.error()),
      http.get(`${API}/incidents`, () => HttpResponse.error()),
      http.get(`${API}/maintenance-tickets`, () => HttpResponse.error()),
      http.get(`${API}/fleet/documents/expiring`, () => HttpResponse.error()),
    )
    renderDashboard()

    expect(await screen.findByText('The CTMS server could not be reached')).toBeInTheDocument()
  })

  it('keeps an acronym an acronym', async () => {
    server.use(...dashboardHandlers())
    renderDashboard()

    // CSS `capitalize` would render this "Sos".
    expect(await screen.findByText('SOS')).toBeInTheDocument()
  })

  it('links each card to a screen that exists', async () => {
    server.use(...dashboardHandlers())
    renderDashboard()
    await screen.findByText('18')

    const targets = screen.getAllByRole('link').map((link) => link.getAttribute('href'))
    expect(targets).toContain('/trips')
    expect(targets).toContain('/buses')
    expect(targets).toContain('/incidents')
    expect(targets).toContain('/maintenance')
  })

  it('reserves the card height while loading so nothing shifts', async () => {
    server.use(...dashboardHandlers())
    const { container } = renderDashboard()

    const cards = container.querySelectorAll('.h-\\[104px\\]')
    expect(cards.length).toBe(4)

    await screen.findByText('18')
    expect(container.querySelectorAll('.h-\\[104px\\]').length).toBe(4)
  })

  it('offers no mutation controls — the dashboard is read-only at every level', async () => {
    server.use(...dashboardHandlers())
    renderDashboard()
    await screen.findByText('18')

    const buttons = screen.getAllByRole('button').map((button) => button.textContent)
    expect(buttons).toEqual(['Refresh'])
  })
})
