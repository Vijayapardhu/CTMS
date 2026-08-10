import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { server } from '@/mocks/server'
import { errorResponse } from '@/mocks/fixtures'
import { configureClient } from '@/api/client'
import { ReportsScreen } from '@/features/reports/ReportsScreen'
import { toCsv } from '@/features/reports/api'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => server.resetHandlers())
afterAll(() => server.close())

const envelope = (data: unknown) => ({ success: true, message: 'ok', code: 200, data })

const tripsReport = {
  window: { from: '2026-08-01T00:00:00+00:00', to: '2026-08-10T23:59:59+00:00' },
  trips: { total: 20, completed: 7, cancelled: 2, running: 3, scheduled: 8 },
  completion_rate: 35,
  cancellation_rate: 10,
  departed_late: 0,
  punctuality_rate: null,
  auto_closed: 0,
}

const occupancyReport = {
  window: { from: '2026-08-01T00:00:00+00:00', to: '2026-08-10T23:59:59+00:00' },
  trips_measured: 7,
  passengers_carried: 55,
  seats_offered: 360,
  utilisation_percent: 15.3,
  by_route: [{ route_name: 'North Campus Loop', trips: 2, passengers: 22, utilisation_percent: 24.4 }],
}

const fleetReport = {
  generated_at: '2026-08-10T17:08:20+00:00',
  buses: { total: 30, by_status: { AVAILABLE: 27, BREAKDOWN: 2, MAINTENANCE: 1 } },
  grounded_by_maintenance: 2,
  open_tickets: { total: 3, by_priority: { LOW: 1, URGENT: 2 } },
  overdue_maintenance_buses: 0,
}

function renderAt(path: string) {
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/reports" element={<ReportsScreen />} />
          <Route path="/reports/:kind" element={<ReportsScreen />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('reports', () => {
  it('asks only for the window the endpoint accepts', async () => {
    let asked = ''
    server.use(
      http.get(`${API}/reports/trips`, ({ request }) => {
        asked = new URL(request.url).search

        return HttpResponse.json(envelope(tripsReport))
      }),
    )
    renderAt('/reports/trips?from=2026-08-01&to=2026-08-10')

    await screen.findByText('20')
    // `from` and `to` are the only parameters `ReportController::window`
    // validates. No route filter, no bus filter, no `date`.
    expect(asked).toContain('from=2026-08-01')
    expect(asked).toContain('to=2026-08-10')
    expect(asked).not.toContain('route')
    expect(asked).not.toContain('bus')
  })

  it('shows a null rate as "no data" rather than nought per cent', async () => {
    server.use(http.get(`${API}/reports/trips`, () => HttpResponse.json(envelope(tripsReport))))
    renderAt('/reports/trips')

    // punctuality_rate is null: nothing departed in the window. That is not 0%.
    expect(await screen.findByText('No data')).toBeInTheDocument()
    expect(screen.queryByText('0%')).not.toBeInTheDocument()
  })

  it('says the fleet report has no window, and does not send one', async () => {
    let asked = 'unset'
    server.use(
      http.get(`${API}/reports/fleet`, ({ request }) => {
        asked = new URL(request.url).search

        return HttpResponse.json(envelope(fleetReport))
      }),
    )
    renderAt('/reports/fleet')

    expect(await screen.findByText(/no date range/i)).toBeInTheDocument()
    expect(asked).toBe('')
  })

  it('labels the download honestly and never calls it an export', async () => {
    server.use(http.get(`${API}/reports/occupancy`, () => HttpResponse.json(envelope(occupancyReport))))
    renderAt('/reports/occupancy')

    // G1-3: there is no server-side export endpoint, so nothing here may imply
    // an authoritative extract.
    expect(await screen.findByRole('button', { name: /download this table/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /export/i })).not.toBeInTheDocument()
    expect(screen.getByText(/the whole table, which this endpoint returns unpaginated/i)).toBeInTheDocument()
  })

  it('disables the download when there is no table to download', async () => {
    server.use(
      http.get(`${API}/reports/occupancy`, () => HttpResponse.json(envelope({ ...occupancyReport, by_route: [] }))),
    )
    renderAt('/reports/occupancy')

    await screen.findByText('No activity in this range')
    expect(screen.getByRole('button', { name: /download this table/i })).toBeDisabled()
  })

  it('builds the CSV from the rows on screen', async () => {
    const clicks: string[] = []
    // jsdom has no object-URL support, so it is added here rather than
    // replacing `URL` wholesale — the API client builds request URLs with
    // `new URL(...)`, and an object spread is not callable as a constructor.
    const createObjectURL = vi.fn(() => 'blob:test')
    const revokeObjectURL = vi.fn()
    Object.assign(URL, { createObjectURL, revokeObjectURL })
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(function (this: HTMLAnchorElement) {
        clicks.push(this.download)
      })

    server.use(http.get(`${API}/reports/occupancy`, () => HttpResponse.json(envelope(occupancyReport))))
    renderAt('/reports/occupancy')

    await screen.findByText('North Campus Loop')
    await userEvent.click(screen.getByRole('button', { name: /download this table/i }))

    await waitFor(() => expect(clicks[0]).toMatch(/^ctms-occupancy-2026-08-01\.csv$/))
    expect(createObjectURL).toHaveBeenCalled()

    click.mockRestore()
  })

  it('does not present a failed report as an empty one', async () => {
    server.use(
      http.get(`${API}/reports/trips`, () =>
        HttpResponse.json(errorResponse('Server error.', 500), { status: 500 }),
      ),
    )
    renderAt('/reports/trips')

    expect(await screen.findByText('Unable to load this report')).toBeInTheDocument()
    expect(screen.queryByText(/no activity/i)).not.toBeInTheDocument()
  })

  it('offers all six reports', async () => {
    server.use(http.get(`${API}/reports/trips`, () => HttpResponse.json(envelope(tripsReport))))
    renderAt('/reports')

    for (const label of ['Trips', 'Occupancy', 'Incidents', 'Maintenance', 'Attendance', 'Fleet']) {
      expect(screen.getByRole('link', { name: label })).toBeInTheDocument()
    }
  })
})

describe('the CSV writer', () => {
  it('quotes what would otherwise break the file', () => {
    const csv = toCsv(['route', 'note'], [['North, Campus', 'He said "late"'], ['South', null]])

    expect(csv).toBe('route,note\r\n"North, Campus","He said ""late"""\r\nSouth,')
  })
})
