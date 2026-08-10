import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { server } from '@/mocks/server'
import { setMockLevel } from '@/mocks/handlers'
import { errorResponse, pageResponse } from '@/mocks/fixtures'
import { configureClient } from '@/api/client'
import { AccessLevel } from '@/auth/accessLevel'
import { SessionProvider } from '@/auth/SessionProvider'
import { DriversScreen } from '@/features/people/DriversScreen'
import { StudentsScreen } from '@/features/people/StudentsScreen'
import { RoutesScreen } from '@/features/routes/RoutesScreen'
import { InspectionsScreen } from '@/features/fleet/InspectionsScreen'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

const driver = (overrides: Record<string, unknown> = {}) => ({
  id: 'd-1',
  user_id: 'u-9',
  license_number: 'KA0123456',
  license_class: 'HEAVY',
  license_expiry_date: '2026-08-25T00:00:00.000000Z',
  status: 'AVAILABLE',
  assigned_bus_id: 'b-1',
  user: { id: 'u-9', full_name: 'Ravi Kumar', phone_number: '+919876500002' },
  assigned_bus: { id: 'b-1', registration_number: 'KA-80-IB-1761' },
  ...overrides,
})

const student = (overrides: Record<string, unknown> = {}) => ({
  id: 's-1',
  user_id: 'u-11',
  registration_number: '21BCE1043',
  department: 'Computer Science',
  year_of_study: 3,
  status: 'ACTIVE',
  route_id: 'r-1',
  pickup_stop_id: 'rs-1',
  has_valid_ticket: true,
  ticket_expiry_date: '2027-05-31T00:00:00.000000Z',
  user: { id: 'u-11', full_name: 'Asha Menon' },
  route: { id: 'r-1', route_name: 'North Campus Loop' },
  pickup_stop: { id: 'rs-1', stop_name: 'Main Gate' },
  ...overrides,
})

const route = {
  id: 'r-1',
  route_name: 'North Campus Loop',
  route_code: 'NCL',
  description: null,
  total_distance_km: 12.5,
  estimated_duration_minutes: 45,
  start_point: 'Depot',
  end_point: 'Campus',
  status: 'ACTIVE',
  number_of_stops: 2,
}

const bus = (overrides: Record<string, unknown> = {}) => ({
  id: 'b-1',
  registration_number: 'KA-80-IB-1761',
  vehicle_name: 'Campus Bus 1',
  model: 'Tata Starbus',
  seating_capacity: 40,
  status: 'MAINTENANCE',
  current_odometer: 45200,
  mileage: 5,
  fuel_type: 'DIESEL',
  year_of_manufacture: 2019,
  last_maintenance_date: null,
  next_maintenance_due: null,
  remarks: null,
  ...overrides,
})

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

async function renderAs(level: AccessLevel, ui: ReactNode, path: string, heading: string) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  renderWith(ui, path)
  await screen.findByRole('heading', { name: heading })
}

// ── A7 ─────────────────────────────────────────────────────────────────────

describe('drivers', () => {
  const handlers = [http.get(`${API}/drivers`, () => HttpResponse.json(pageResponse([driver()], 1)))]

  it('says how long a licence has left, not just its date', async () => {
    server.use(...handlers)
    await renderAs(AccessLevel.VIEWER, <DriversScreen />, '/drivers', 'Drivers')

    expect(await screen.findByText('Ravi Kumar')).toBeInTheDocument()
    expect(screen.getByText(/in \d+ days/)).toBeInTheDocument()
  })

  it('marks an expired licence as expired', async () => {
    server.use(http.get(`${API}/drivers`, () =>
      HttpResponse.json(pageResponse([driver({ license_expiry_date: '2025-01-01T00:00:00Z' })], 1)),
    ))
    await renderAs(AccessLevel.VIEWER, <DriversScreen />, '/drivers', 'Drivers')

    expect(await screen.findByText('expired')).toBeInTheDocument()
  })

  it('gives a viewer no controls', async () => {
    server.use(...handlers)
    await renderAs(AccessLevel.VIEWER, <DriversScreen />, '/drivers', 'Drivers')
    await screen.findByText('Ravi Kumar')

    expect(screen.queryByRole('button', { name: /assign a bus/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: /set status for/i })).not.toBeInTheDocument()
  })

  it('gives a transport head both', async () => {
    server.use(...handlers)
    await renderAs(AccessLevel.OPERATIONS, <DriversScreen />, '/drivers', 'Drivers')
    await screen.findByText('Ravi Kumar')

    expect(screen.getByRole('button', { name: /assign a bus/i })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: /set status for ravi kumar/i })).toBeInTheDocument()
  })
})

// ── A12 ────────────────────────────────────────────────────────────────────

describe('students', () => {
  const handlers = [
    http.get(`${API}/students`, () => HttpResponse.json(pageResponse([student()], 1))),
    http.get(`${API}/routes`, () => HttpResponse.json(pageResponse([route], 1))),
  ]

  it('shows the transport facts and nothing more', async () => {
    server.use(...handlers)
    await renderAs(AccessLevel.VIEWER, <StudentsScreen />, '/students', 'Students')

    expect(await screen.findByText('Asha Menon')).toBeInTheDocument()
    expect(screen.getByText('21BCE1043')).toBeInTheDocument()
    expect(screen.getByText('from Main Gate')).toBeInTheDocument()
    // Not a student record system: no address, no date of birth.
    expect(screen.queryByText(/date of birth/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/address/i)).not.toBeInTheDocument()
  })

  it('asks for a route before a stop, because a stop belongs to one', async () => {
    let stopCalls = 0
    server.use(
      ...handlers,
      http.get(`${API}/routes/r-1/stops`, () => {
        stopCalls += 1

        return HttpResponse.json(
          pageResponse([{ id: 'rs-1', route_id: 'r-1', stop_name: 'Main Gate', sequence_number: 1, latitude: null, longitude: null, address: null, landmark: null, distance_from_start_km: 0, estimated_arrival_minutes: 0, stop_type: null }], 1),
        )
      }),
    )
    await renderAs(AccessLevel.OPERATIONS, <StudentsScreen />, '/students', 'Students')
    await screen.findByText('Asha Menon')

    await userEvent.click(screen.getByRole('button', { name: /change route/i }))
    const dialog = await screen.findByRole('dialog')

    expect(within(dialog).getByRole('combobox', { name: /pick-up stop/i })).toBeDisabled()
    expect(stopCalls).toBe(0)

    await userEvent.selectOptions(within(dialog).getByRole('combobox', { name: /^route$/i }), 'r-1')

    await waitFor(() => expect(stopCalls).toBe(1))
    expect(within(dialog).getByRole('combobox', { name: /pick-up stop/i })).toBeEnabled()
  })

  it('gives a supervisor no way to move somebody', async () => {
    server.use(...handlers)
    await renderAs(AccessLevel.SUPPORT, <StudentsScreen />, '/students', 'Students')
    await screen.findByText('Asha Menon')

    expect(screen.queryByRole('button', { name: /change route/i })).not.toBeInTheDocument()
  })
})

// ── A17 ────────────────────────────────────────────────────────────────────

describe('routes', () => {
  it('is read-only, and says why', async () => {
    server.use(http.get(`${API}/routes`, () => HttpResponse.json(pageResponse([route], 1))))
    await renderAs(AccessLevel.SUPER_ADMIN, <RoutesScreen />, '/routes', 'Routes')

    expect(await screen.findByText('North Campus Loop')).toBeInTheDocument()
    expect(screen.getByText(/read-only here/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /add a route/i })).not.toBeInTheDocument()
  })

  it('loads stops and the timetable only when a route is opened', async () => {
    let stopCalls = 0
    server.use(
      http.get(`${API}/routes`, () => HttpResponse.json(pageResponse([route], 1))),
      http.get(`${API}/routes/r-1/stops`, () => {
        stopCalls += 1

        return HttpResponse.json(pageResponse([], 0))
      }),
      http.get(`${API}/schedules`, () => HttpResponse.json(pageResponse([], 0))),
    )
    await renderAs(AccessLevel.VIEWER, <RoutesScreen />, '/routes', 'Routes')
    await screen.findByText('North Campus Loop')

    expect(stopCalls).toBe(0)
    await userEvent.click(screen.getByRole('button', { name: /stops and timetable/i }))
    await waitFor(() => expect(stopCalls).toBe(1))
  })
})

// ── A11 ────────────────────────────────────────────────────────────────────

describe('inspections', () => {
  it('asks for readiness only for buses that are off the road', async () => {
    const asked: string[] = []
    server.use(
      http.get(`${API}/buses`, () =>
        HttpResponse.json(pageResponse([bus(), bus({ id: 'b-2', registration_number: 'KA-80-IB-1762', status: 'AVAILABLE' })], 2)),
      ),
      http.get(`${API}/buses/:id/service-readiness`, ({ params }) => {
        asked.push(params.id as string)

        return HttpResponse.json({
          success: true,
          message: 'ok',
          code: 200,
          data: { cleared: false, reasons: ['Brakes failed the last inspection.'], inspection: null },
        })
      }),
    )
    await renderAs(AccessLevel.OPERATIONS, <InspectionsScreen />, '/inspections', 'Inspections')

    expect(await screen.findByText('Brakes failed the last inspection.')).toBeInTheDocument()
    // G2-2: readiness is one call per bus, so an available bus is not asked about.
    expect(asked).toEqual(['b-1'])
  })

  it('says plainly how many buses it did not check', async () => {
    const many = Array.from({ length: 11 }, (_, index) =>
      bus({ id: `b-${index}`, registration_number: `KA-80-IB-17${index}`, status: 'BREAKDOWN' }),
    )
    server.use(
      http.get(`${API}/buses`, () => HttpResponse.json(pageResponse(many, many.length))),
      http.get(`${API}/buses/:id/service-readiness`, () =>
        HttpResponse.json({ success: true, message: 'ok', code: 200, data: { cleared: true, reasons: [], inspection: null } }),
      ),
    )
    await renderAs(AccessLevel.OPERATIONS, <InspectionsScreen />, '/inspections', 'Inspections')

    // Eleven off the road, eight checked. The cap is stated, not silent.
    expect(await screen.findByText(/3 more buses are off the road/i)).toBeInTheDocument()
  })

  it('celebrates a fleet with nothing wrong with it', async () => {
    server.use(http.get(`${API}/buses`, () => HttpResponse.json(pageResponse([bus({ status: 'AVAILABLE' })], 1))))
    await renderAs(AccessLevel.VIEWER, <InspectionsScreen />, '/inspections', 'Inspections')

    expect(await screen.findByText('Every bus is available')).toBeInTheDocument()
  })

  it('does not present a failed fleet load as a clean fleet', async () => {
    server.use(
      http.get(`${API}/buses`, () => HttpResponse.json(errorResponse('Server error.', 500), { status: 500 })),
    )
    await renderAs(AccessLevel.VIEWER, <InspectionsScreen />, '/inspections', 'Inspections')

    expect(await screen.findByText('Unable to load the fleet')).toBeInTheDocument()
    expect(screen.queryByText('Every bus is available')).not.toBeInTheDocument()
  })
})
