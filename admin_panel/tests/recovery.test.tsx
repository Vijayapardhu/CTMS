import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { server } from '@/mocks/server'
import { setMockLevel } from '@/mocks/handlers'
import { errorResponse, pageResponse } from '@/mocks/fixtures'
import { configureClient } from '@/api/client'
import { AccessLevel } from '@/auth/accessLevel'
import { SessionProvider } from '@/auth/SessionProvider'
import { RecoveryScreen } from '@/features/recovery/RecoveryScreen'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

const replacement = (overrides: Record<string, unknown> = {}) => ({
  id: 'rep-1',
  trip_id: 't-1',
  vehicle_incident_id: 'inc-1',
  status: 'RECOMMENDED',
  reason: 'Engine fault; the bus cannot continue.',
  distance_metres: 2400,
  passengers_to_transfer: 28,
  rejection_reason: null,
  approved_at: null,
  dispatched_at: null,
  arrived_at: null,
  created_at: '2026-08-10T06:00:00.000000Z',
  trip: { id: 't-1', trip_date: '2026-08-10', route: { route_name: 'North Campus Loop' } },
  original_bus: { id: 'b-1', registration_number: 'KA-80-IB-1761' },
  replacement_bus: { id: 'b-2', registration_number: 'KA-80-IB-1762' },
  incident: { id: 'inc-1', incident_type: 'ENGINE_FAULT', status: 'REPORTED' },
  ...overrides,
})

const consolidation = (overrides: Record<string, unknown> = {}) => ({
  id: 'con-1',
  source_trip_id: 't-1',
  target_trip_id: 't-2',
  status: 'APPROVED',
  reason: 'Both services under a fifth full.',
  source_passengers: 4,
  target_passengers: 6,
  target_capacity: 40,
  estimated_savings: '1200.00',
  divergence_sequence: null,
  rejection_reason: null,
  passengers_notified_at: null,
  executed_at: null,
  expires_at: '2026-08-10T10:00:00.000000Z',
  decided_at: '2026-08-10T07:00:00.000000Z',
  created_at: '2026-08-10T06:30:00.000000Z',
  source_trip: { id: 't-1', trip_date: '2026-08-10', route: { route_name: 'North Campus Loop' } },
  target_trip: { id: 't-2', trip_date: '2026-08-10', route: { route_name: 'South Campus Loop' } },
  decided_by: { id: 'u-1', full_name: 'Priya Rao' },
  ...overrides,
})

async function renderAs(level: AccessLevel, options: { replacements?: unknown[]; consolidations?: unknown[] } = {}) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  server.use(
    http.get(`${API}/replacements`, () =>
      HttpResponse.json(pageResponse(options.replacements ?? [replacement()], (options.replacements ?? [1]).length)),
    ),
    http.get(`${API}/consolidations`, () =>
      HttpResponse.json(pageResponse(options.consolidations ?? [], (options.consolidations ?? []).length)),
    ),
  )

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/replacements']}>
        <SessionProvider>
          <RecoveryScreen />
        </SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  await screen.findByRole('heading', { name: 'Recovery' })
}

// ── replacements ───────────────────────────────────────────────────────────

describe('replacement requests', () => {
  it('shows which bus takes over from which, and how far away it is', async () => {
    await renderAs(AccessLevel.OPERATIONS)

    expect(await screen.findByText('KA-80-IB-1761')).toBeInTheDocument()
    expect(screen.getByText('KA-80-IB-1762')).toBeInTheDocument()
    expect(screen.getByText(/2\.4 km away/)).toBeInTheDocument()
    expect(screen.getByText(/28 passengers to move/)).toBeInTheDocument()
  })

  it('offers a viewer nothing to press', async () => {
    await renderAs(AccessLevel.VIEWER)
    await screen.findByText('KA-80-IB-1761')

    for (const label of [/approve/i, /reject/i, /dispatch/i, /mark arrived/i]) {
      expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    }
  })

  it('lets support dispatch but not decide', async () => {
    await renderAs(AccessLevel.SUPPORT, { replacements: [replacement({ status: 'APPROVED' })] })
    await screen.findByText('KA-80-IB-1761')

    // Executing a decision somebody else took is a tier below taking it.
    expect(screen.getByRole('button', { name: /dispatch/i })).toBeEnabled()
    expect(screen.queryByRole('button', { name: /^approve$/i })).not.toBeInTheDocument()
  })

  it('lets a transport head decide, and explains a step that is out of order', async () => {
    await renderAs(AccessLevel.OPERATIONS)
    await screen.findByText('KA-80-IB-1761')

    expect(screen.getByRole('button', { name: /^approve$/i })).toBeEnabled()
    expect(screen.getByRole('button', { name: /dispatch/i })).toBeDisabled()
    expect(screen.getByRole('button', { name: /dispatch/i })).toHaveAttribute(
      'title',
      'Only an approved request can be dispatched.',
    )
  })

  it('says approving is not dispatching', async () => {
    await renderAs(AccessLevel.OPERATIONS)
    await screen.findByText('KA-80-IB-1761')

    await userEvent.click(screen.getByRole('button', { name: /^approve$/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/approving does not dispatch it/i)).toBeInTheDocument()
  })

  it('fetches the history only when it is asked for', async () => {
    let detailCalls = 0
    await renderAs(AccessLevel.OPERATIONS)
    server.use(
      http.get(`${API}/replacements/rep-1`, () => {
        detailCalls += 1

        return HttpResponse.json({
          success: true,
          message: 'ok',
          code: 200,
          data: replacement({ status: 'ARRIVED', approved_at: '2026-08-10T07:00:00Z', approved_by: { id: 'u-1', full_name: 'Priya Rao' } }),
        })
      }),
    )
    await screen.findByText('KA-80-IB-1761')

    // `GET /replacements` does not load who approved it; the row does not
    // pretend to know, and does not fetch per row either.
    expect(detailCalls).toBe(0)

    await userEvent.click(screen.getByRole('button', { name: 'History' }))
    expect(await screen.findByText(/priya rao/i)).toBeInTheDocument()
    expect(detailCalls).toBe(1)
  })
})

// ── consolidations ─────────────────────────────────────────────────────────

describe('service consolidation', () => {
  it('warns when a merge would run ahead of the passengers being told', async () => {
    await renderAs(AccessLevel.OPERATIONS, { consolidations: [consolidation()] })
    await userEvent.click(screen.getByRole('tab', { name: 'Consolidations' }))
    await screen.findByText('North Campus Loop')

    await userEvent.click(screen.getByRole('button', { name: /execute/i }))

    // BR-363: telling people comes first, and the dialog says what happens if
    // it does not.
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/have NOT been told/i)).toBeInTheDocument()
    expect(within(dialog).getByText(/waits at a stop for a bus that is not coming/i)).toBeInTheDocument()
  })

  it('changes its warning once the passengers have been told', async () => {
    await renderAs(AccessLevel.OPERATIONS, {
      consolidations: [consolidation({ passengers_notified_at: '2026-08-10T08:00:00Z' })],
    })
    await userEvent.click(screen.getByRole('tab', { name: 'Consolidations' }))
    await screen.findByText('North Campus Loop')

    expect(screen.getByText('Passengers told')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /tell passengers/i })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: /execute/i }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/have been told/i)).toBeInTheDocument()
  })

  it('gives support none of the consolidation steps', async () => {
    await renderAs(AccessLevel.SUPPORT, { consolidations: [consolidation()] })
    await userEvent.click(screen.getByRole('tab', { name: 'Consolidations' }))
    await screen.findByText('North Campus Loop')

    for (const label of [/^approve$/i, /^reject$/i, /tell passengers/i, /execute/i, /propose a merge/i]) {
      expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    }
  })

  it('proposes only pairs the server itself put forward', async () => {
    let candidateCalls = 0
    await renderAs(AccessLevel.OPERATIONS, { consolidations: [] })
    server.use(
      http.get(`${API}/consolidations/candidates`, () => {
        candidateCalls += 1

        return HttpResponse.json({
          success: true,
          message: 'ok',
          code: 200,
          data: [
            {
              source_trip_id: 't-1',
              source_route: 'North Campus Loop',
              source_passengers: 4,
              target_trip_id: 't-2',
              target_route: 'South Campus Loop',
              target_passengers: 6,
              target_capacity: 40,
            },
          ],
        })
      }),
    )

    await userEvent.click(screen.getByRole('tab', { name: 'Consolidations' }))
    await userEvent.click(await screen.findByRole('button', { name: /propose a merge/i }))

    // The pairing is the backend's analysis, not something computed here.
    await waitFor(() => expect(candidateCalls).toBe(1))
    expect(await screen.findByText(/the server's own analysis/i)).toBeInTheDocument()
  })

  it('shows a 409 verbatim and does not retry it', async () => {
    let attempts = 0
    // PROPOSED, so approving is a step the state machine allows and the only
    // thing that can refuse it is the server.
    await renderAs(AccessLevel.OPERATIONS, { consolidations: [consolidation({ status: 'PROPOSED' })] })
    server.use(
      http.post(`${API}/consolidations/con-1/approve`, () => {
        attempts += 1

        return HttpResponse.json(
          errorResponse('A consolidation cannot go from EXECUTED to APPROVED.', 409),
          { status: 409 },
        )
      }),
    )

    await userEvent.click(screen.getByRole('tab', { name: 'Consolidations' }))
    await screen.findByText('North Campus Loop')
    await userEvent.click(screen.getByRole('button', { name: /^approve$/i }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: /^approve$/i }))

    expect(await screen.findByText('A consolidation cannot go from EXECUTED to APPROVED.')).toBeInTheDocument()
    await waitFor(() => expect(attempts).toBe(1))
  })
})
