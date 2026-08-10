import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { server } from '@/mocks/server'
import { errorResponse, pageResponse } from '@/mocks/fixtures'
import { configureClient } from '@/api/client'
import { FleetScreen } from '@/features/fleet/FleetScreen'
import { BusDetailScreen } from '@/features/fleet/BusDetailScreen'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => server.resetHandlers())
afterAll(() => server.close())

/** Captured from a running backend. */
const bus = (n: number, status = 'AVAILABLE') => ({
  id: `b-${n}`,
  registration_number: `KA-80-IB-${1760 + n}`,
  vehicle_name: `Campus Bus ${n}`,
  model: 'Tata Starbus',
  seating_capacity: 40,
  status,
  current_odometer: 45200,
  mileage: 5,
  fuel_type: 'DIESEL',
  year_of_manufacture: 2019,
  last_maintenance_date: '2026-06-01T00:00:00.000000Z',
  next_maintenance_due: '2026-12-01T00:00:00.000000Z',
  remarks: null,
})

const inspection = {
  id: 'i-1',
  outcome: 'FAILED',
  odometer_reading: 45200,
  inspected_on: '2026-08-10T00:00:00.000000Z',
  inspected_at: '2026-08-10T05:12:00.000000Z',
  notes: null,
  maintenance_ticket_id: null,
  driver: { user: { full_name: 'Ravi Kumar' } },
  items: [
    { id: 'it-1', item: 'BRAKES', passed: false, notes: 'Pedal travel excessive' },
    { id: 'it-2', item: 'LIGHTS', passed: true, notes: null },
  ],
}

const documentsBody = {
  documents: [
    {
      id: 'd-1',
      bus_id: 'b-1',
      document_type: 'INSURANCE',
      document_number: 'HX582283',
      issuing_authority: 'Regional Transport Office',
      issued_on: '2025-08-10T00:00:00.000000Z',
      expires_on: '2027-08-10T00:00:00.000000Z',
      file_path: '/private/documents/hx582283.pdf',
      notes: null,
    },
  ],
  compliance: { is_compliant: true, missing_or_expired: [] },
}

function detailHandlers(readiness: unknown) {
  return [
    http.get(`${API}/buses/b-1`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: bus(1) }),
    ),
    http.get(`${API}/buses/b-1/service-readiness`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: readiness }),
    ),
    http.get(`${API}/buses/b-1/inspections`, () => HttpResponse.json(pageResponse([inspection], 1))),
    http.get(`${API}/buses/b-1/documents`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: documentsBody }),
    ),
  ]
}

function renderWith(ui: React.ReactNode, path = '/buses') {
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

const noExpiring = http.get(`${API}/fleet/documents/expiring`, () =>
  HttpResponse.json({ success: true, message: 'ok', code: 200, data: [] }),
)

// ── A6 ─────────────────────────────────────────────────────────────────────

describe('the fleet list', () => {
  it('renders real-shaped rows', async () => {
    server.use(noExpiring, http.get(`${API}/buses`, () => HttpResponse.json(pageResponse([bus(1)], 28))))
    renderWith(<FleetScreen />)

    expect(await screen.findByText('KA-80-IB-1761')).toBeInTheDocument()
    expect(screen.getByText('Tata Starbus')).toBeInTheDocument()
    expect(screen.getByText('40 seats')).toBeInTheDocument()
    expect(screen.getByText('45,200 km')).toBeInTheDocument()
    // "Available" is both a filter option and the row's status chip.
    expect(screen.getAllByText('Available').length).toBeGreaterThanOrEqual(1)
  })

  it('never fetches readiness per row', async () => {
    let readinessCalls = 0
    server.use(
      noExpiring,
      http.get(`${API}/buses/:id/service-readiness`, () => {
        readinessCalls += 1

        return HttpResponse.json({ success: true, message: 'ok', code: 200, data: { cleared: true, reasons: [], inspection: null } })
      }),
      http.get(`${API}/buses`, () =>
        HttpResponse.json(pageResponse(Array.from({ length: 20 }, (_, index) => bus(index + 1)), 28)),
      ),
    )
    renderWith(<FleetScreen />)
    await screen.findByText('KA-80-IB-1761')

    // Twenty buses on screen, zero readiness calls. A column filled by one
    // request per row is how a fleet list becomes twenty-eight requests.
    expect(readinessCalls).toBe(0)
  })

  it('pages on the server', async () => {
    const pages: string[] = []
    server.use(
      noExpiring,
      http.get(`${API}/buses`, ({ request }) => {
        pages.push(new URL(request.url).searchParams.get('page') ?? '1')

        return HttpResponse.json(pageResponse([bus(1)], 28))
      }),
    )
    renderWith(<FleetScreen />)
    await screen.findByText('1–20 of 28')

    await userEvent.click(screen.getByRole('button', { name: 'Next' }))

    await waitFor(() => expect(pages).toContain('2'))
  })

  it('sends the search term the endpoint actually supports', async () => {
    let query: URLSearchParams | undefined
    server.use(
      noExpiring,
      http.get(`${API}/buses`, ({ request }) => {
        query = new URL(request.url).searchParams

        return HttpResponse.json(pageResponse([bus(1)], 1))
      }),
    )
    renderWith(<FleetScreen />)
    await screen.findByText('KA-80-IB-1761')

    await userEvent.type(screen.getByLabelText('Search'), 'KA-80')

    await waitFor(() => expect(query?.get('search')).toBe('KA-80'))
  })

  it('says nothing needs attention rather than alarming about zero', async () => {
    server.use(noExpiring, http.get(`${API}/buses`, () => HttpResponse.json(pageResponse([bus(1)], 1))))
    renderWith(<FleetScreen />)

    expect(await screen.findByText('No documents need attention.')).toBeInTheDocument()
  })

  it('flags documents that are about to lapse', async () => {
    const soon = new Date(Date.now() + 6 * 86_400_000).toISOString()
    server.use(
      http.get(`${API}/fleet/documents/expiring`, () =>
        HttpResponse.json({
          success: true,
          message: 'ok',
          code: 200,
          data: [
            {
              id: 'd-9',
              bus_id: 'b-1',
              document_type: 'INSURANCE',
              document_number: 'X',
              issuing_authority: null,
              issued_on: null,
              expires_on: soon,
              file_path: null,
              notes: null,
              bus: { registration_number: 'KA-80-IB-1761' },
            },
          ],
        }),
      ),
      http.get(`${API}/buses`, () => HttpResponse.json(pageResponse([bus(1)], 1))),
    )
    renderWith(<FleetScreen />)

    expect(await screen.findByText('1 document needs attention')).toBeInTheDocument()
    expect(screen.getByText('Insurance')).toBeInTheDocument()
  })

  it('offers a retry when the fleet cannot be read', async () => {
    server.use(
      noExpiring,
      http.get(`${API}/buses`, () => HttpResponse.json(errorResponse('Server error.', 500), { status: 500 })),
    )
    renderWith(<FleetScreen />)

    expect(await screen.findByText('Unable to load the fleet')).toBeInTheDocument()
  })

  it('says plainly when nothing matches', async () => {
    server.use(noExpiring, http.get(`${API}/buses`, () => HttpResponse.json(pageResponse([], 0))))
    renderWith(<FleetScreen />)

    expect(await screen.findByText('No buses match these filters')).toBeInTheDocument()
  })
})

// ── A7 ─────────────────────────────────────────────────────────────────────

function renderDetail(readiness: unknown) {
  server.use(...detailHandlers(readiness))

  renderWith(
    <Routes>
      <Route path="/buses/:id" element={<BusDetailScreen />} />
    </Routes>,
    '/buses/b-1',
  )
}

describe('bus details', () => {
  it('shows the vehicle record', async () => {
    renderDetail({ cleared: true, reasons: [], inspection: null })

    expect(await screen.findByText('Tata Starbus')).toBeInTheDocument()
    expect(screen.getByText('40 seats')).toBeInTheDocument()
    // The odometer appears on the vehicle record and again on the inspection
    // that recorded it.
    expect(screen.getAllByText(/45,200 km/)).toHaveLength(2)
  })

  it('says a cleared bus is ready', async () => {
    renderDetail({ cleared: true, reasons: [], inspection: null })

    expect(await screen.findByText('Ready for service')).toBeInTheDocument()
    expect(screen.queryByText('Not ready')).not.toBeInTheDocument()
  })

  it('renders every reason a bus is not ready, in the server wording', async () => {
    renderDetail({
      cleared: false,
      reasons: [
        'No pre-trip inspection has been completed today.',
        'Insurance is missing or expired.',
      ],
      inspection: null,
    })

    expect(await screen.findByText('Not ready')).toBeInTheDocument()
    expect(screen.getByText('Why this bus is not ready')).toBeInTheDocument()
    // Both reasons, unrewritten. The driver reads these exact sentences.
    expect(screen.getByText('No pre-trip inspection has been completed today.')).toBeInTheDocument()
    expect(screen.getByText('Insurance is missing or expired.')).toBeInTheDocument()
  })

  it('does not infer readiness from the bus status', async () => {
    // AVAILABLE, and still not cleared. The two are different questions.
    renderDetail({ cleared: false, reasons: ['Insurance is missing or expired.'], inspection: null })

    expect(await screen.findByText('Not ready')).toBeInTheDocument()
    expect(screen.getByText('Available')).toBeInTheDocument()
  })

  it('shows the inspection history and what failed', async () => {
    renderDetail({ cleared: true, reasons: [], inspection: null })

    expect(await screen.findByText('Ravi Kumar')).toBeInTheDocument()
    expect(screen.getByText('failed')).toBeInTheDocument()
    expect(screen.getByText(/brakes/)).toBeInTheDocument()
    // A passed item is not listed as a failure.
    expect(screen.queryByText(/^lights/)).not.toBeInTheDocument()
  })

  it('lists documents without turning a private path into a link', async () => {
    renderDetail({ cleared: true, reasons: [], inspection: null })

    expect(await screen.findByText('Insurance')).toBeInTheDocument()
    expect(screen.getByText('HX582283')).toBeInTheDocument()
    expect(screen.getByText('Compliant')).toBeInTheDocument()
    // The document store is private; no anchor is built from `file_path`.
    expect(screen.queryByText(/private\/documents/)).not.toBeInTheDocument()
  })

  it('offers no fleet mutations in this slice', async () => {
    renderDetail({ cleared: false, reasons: ['Insurance is missing or expired.'], inspection: null })
    await screen.findByText('Not ready')

    for (const label of [/send to maintenance/i, /ground/i, /assign/i, /edit/i, /delete/i]) {
      expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    }
  })

  it('keeps the page usable when readiness alone fails', async () => {
    server.use(
      http.get(`${API}/buses/b-1/service-readiness`, () =>
        HttpResponse.json(errorResponse('Server error.', 500), { status: 500 }),
      ),
      ...detailHandlers({ cleared: true, reasons: [], inspection: null }),
    )
    renderWith(
      <Routes>
        <Route path="/buses/:id" element={<BusDetailScreen />} />
      </Routes>,
      '/buses/b-1',
    )

    expect(await screen.findByText('Readiness could not be checked.')).toBeInTheDocument()
    expect(screen.getByText('Tata Starbus')).toBeInTheDocument()
  })
})
