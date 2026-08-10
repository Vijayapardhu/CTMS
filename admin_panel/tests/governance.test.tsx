import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { server } from '@/mocks/server'
import { setMockLevel } from '@/mocks/handlers'
import { errorResponse, pageResponse, userJson } from '@/mocks/fixtures'
import { configureClient } from '@/api/client'
import { AccessLevel } from '@/auth/accessLevel'
import { SessionProvider } from '@/auth/SessionProvider'
import { GovernanceScreen } from '@/features/governance/GovernanceScreen'
import { AccountsScreen } from '@/features/governance/AccountsScreen'
import { redact } from '@/features/governance/api'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

const auditEntry = {
  id: 'al-1',
  action: 'TRIP_CANCELLED',
  table_name: 'trips',
  record_id: 't-1',
  old_values: null,
  new_values: null,
  ip_address: '10.0.0.4',
  user_agent: 'Mozilla/5.0',
  created_at: '2026-08-10T09:00:00.000000Z',
  user: { id: 'u-1', full_name: 'Priya Rao', email: 'priya@ctms.example' },
}

const accessEntry = {
  id: 'da-1',
  subject_id: 'u-9',
  subject_type: 'user',
  purpose: 'SUBJECT_ACCESS_REQUEST',
  data_class: 'SUBJECT_ACCESS_REQUEST',
  is_bulk: true,
  record_count: 42,
  reason: 'Student requested a copy of their record.',
  ip_address: '10.0.0.4',
  created_at: '2026-08-10T10:00:00.000000Z',
  user: { id: 'u-1', full_name: 'Priya Rao' },
}

const account = (overrides: Record<string, unknown> = {}) => ({
  id: 'u-2',
  email: 'ravi@ctms.example',
  phone_number: '+919876500001',
  first_name: 'Ravi',
  last_name: 'Kumar',
  full_name: 'Ravi Kumar',
  role: 'ADMIN',
  is_active: true,
  last_login_at: '2026-08-10T06:00:00.000000Z',
  created_at: '2025-06-01T00:00:00.000000Z',
  profile: { access_level: 'SUPPORT', designation: 'Supervisor', department: 'Transport' },
  ...overrides,
})

function renderWith(ui: ReactNode, path: string) {
  configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}>
        <SessionProvider>
          <Routes>
            <Route path="/admin/audit" element={ui} />
            <Route path="/admin/access-log" element={ui} />
            <Route path="/admin/accounts" element={ui} />
          </Routes>
        </SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const governanceHandlers = [
  http.get(`${API}/audit-logs`, () => HttpResponse.json(pageResponse([auditEntry], 1))),
  http.get(`${API}/data-access-logs`, () => HttpResponse.json(pageResponse([accessEntry], 1))),
  http.get(`${API}/retention-runs`, () => HttpResponse.json(pageResponse([], 0))),
]

async function renderGovernanceAs(level: AccessLevel, path = '/admin/audit') {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  server.use(...governanceHandlers)
  renderWith(<GovernanceScreen />, path)
  await screen.findByRole('heading', { name: 'Governance' })
}

// ── A16 ────────────────────────────────────────────────────────────────────

describe('the governance screen', () => {
  it('keeps change, reading and deletion in three separate records', async () => {
    await renderGovernanceAs(AccessLevel.SUPER_ADMIN)

    expect(screen.getByRole('tab', { name: 'Audit trail' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Data access' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Retention' })).toBeInTheDocument()
  })

  it('says plainly that none of it can be written to', async () => {
    await renderGovernanceAs(AccessLevel.SUPER_ADMIN)

    // The backend has no write endpoint for audit_logs at all, which is what
    // makes the trail evidence.
    expect(screen.getByText(/read-only/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^edit$/i })).not.toBeInTheDocument()
  })

  it('lands on the access tab when reached by its own URL', async () => {
    await renderGovernanceAs(AccessLevel.SUPER_ADMIN, '/admin/access-log')

    expect(await screen.findByText('Student requested a copy of their record.')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Data access' })).toHaveAttribute('aria-selected', 'true')
  })

  it('fetches an entry’s before-and-after only when asked', async () => {
    let detailCalls = 0
    await renderGovernanceAs(AccessLevel.SUPER_ADMIN)
    server.use(
      http.get(`${API}/audit-logs/al-1`, () => {
        detailCalls += 1

        return HttpResponse.json({
          success: true,
          message: 'ok',
          code: 200,
          data: { ...auditEntry, old_values: { status: 'SCHEDULED' }, new_values: { status: 'CANCELLED' } },
        })
      }),
    )
    await screen.findByText('TRIP_CANCELLED')

    expect(detailCalls).toBe(0)
    await userEvent.click(screen.getByRole('button', { name: 'What changed' }))

    expect(await screen.findByText('CANCELLED')).toBeInTheDocument()
    expect(detailCalls).toBe(1)
  })

  it('does not present a failed audit load as an empty trail', async () => {
    setMockLevel(AccessLevel.SUPER_ADMIN)
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
    server.use(
      http.get(`${API}/audit-logs`, () =>
        HttpResponse.json(errorResponse('Server error.', 500), { status: 500 }),
      ),
    )
    renderWith(<GovernanceScreen />, '/admin/audit')

    expect(await screen.findByText('Unable to load the audit trail')).toBeInTheDocument()
    expect(screen.queryByText('Nothing matches these filters')).not.toBeInTheDocument()
  })
})

describe('redaction', () => {
  it('never lets a secret reach the screen, whatever a row contains', () => {
    const rows = redact({
      status: 'CANCELLED',
      password: '$2y$12$abcdefg',
      refresh_token: 'eyJhbGciOi',
      api_key: 'sk-live-1234',
      driver_id: 'd-1',
    })

    expect(rows).toContainEqual(['password', '[redacted]'])
    expect(rows).toContainEqual(['refresh_token', '[redacted]'])
    expect(rows).toContainEqual(['api_key', '[redacted]'])
    // Ordinary values are untouched.
    expect(rows).toContainEqual(['status', 'CANCELLED'])
    expect(rows).toContainEqual(['driver_id', 'd-1'])
  })

  it('handles a value stored as JSON text', () => {
    expect(redact('{"status":"RUNNING"}')).toEqual([['status', 'RUNNING']])
  })
})

// ── A18 ────────────────────────────────────────────────────────────────────

async function renderAccountsAs(level: AccessLevel, rows = [account()]) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  server.use(http.get(`${API}/users`, () => HttpResponse.json(pageResponse(rows, rows.length))))
  renderWith(<AccountsScreen />, '/admin/accounts')
  await screen.findByRole('heading', { name: 'Accounts' })
}

describe('accounts', () => {
  it('offers no delete, because there is no endpoint for one', async () => {
    await renderAccountsAs(AccessLevel.SUPER_ADMIN)
    await screen.findByText('Ravi Kumar')

    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument()
    expect(screen.getByText(/deactivated, never deleted/i)).toBeInTheDocument()
  })

  it('says an access level is chosen once, because nothing changes it later', async () => {
    await renderAccountsAs(AccessLevel.SUPER_ADMIN)

    expect(screen.getByText(/no endpoint that changes it afterwards/i)).toBeInTheDocument()
  })

  it('will not let somebody deactivate themselves', async () => {
    // `userJson` is the signed-in admin; the row for the same id is "you".
    await renderAccountsAs(AccessLevel.SUPER_ADMIN, [
      account({ id: userJson(AccessLevel.SUPER_ADMIN).id, full_name: 'Priya Rao' }),
    ])
    await screen.findByText(/priya rao/i)

    const deactivate = screen.getByRole('button', { name: /deactivate/i })
    expect(deactivate).toBeDisabled()
    expect(deactivate).toHaveAttribute('title', 'You cannot change your own account’s status.')
  })

  it('gives a transport head none of it', async () => {
    await renderAccountsAs(AccessLevel.OPERATIONS)
    await screen.findByText('Ravi Kumar')

    expect(screen.queryByRole('button', { name: /add an account/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /deactivate/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /export their data/i })).not.toBeInTheDocument()
  })

  it('requires a written reason for a subject access export, and says it is logged', async () => {
    await renderAccountsAs(AccessLevel.SUPER_ADMIN)
    await screen.findByText('Ravi Kumar')

    await userEvent.click(screen.getByRole('button', { name: /export their data/i }))

    const dialog = await screen.findByRole('dialog')
    // BR-502 — the export is itself an access, and a notable one.
    expect(within(dialog).getByText(/written to the data access log/i)).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: /generate export/i })).toBeDisabled()
  })

  it('sends the reason the operator typed', async () => {
    let sent: unknown = null
    await renderAccountsAs(AccessLevel.SUPER_ADMIN)
    server.use(
      http.post(`${API}/users/u-2/subject-access-export`, async ({ request }) => {
        sent = await request.json()

        return HttpResponse.json({ success: true, message: 'Subject access export generated.', code: 200, data: {} })
      }),
    )
    await screen.findByText('Ravi Kumar')

    await userEvent.click(screen.getByRole('button', { name: /export their data/i }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByRole('textbox'), 'They asked for a copy of their record.')
    await userEvent.click(within(dialog).getByRole('button', { name: /generate export/i }))

    await waitFor(() => expect(sent).toEqual({ reason: 'They asked for a copy of their record.' }))
  })

  it('warns what deactivating actually does', async () => {
    await renderAccountsAs(AccessLevel.SUPER_ADMIN)
    await screen.findByText('Ravi Kumar')

    await userEvent.click(screen.getByRole('button', { name: /deactivate/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/signed out everywhere immediately/i)).toBeInTheDocument()
    expect(within(dialog).getByText(/nothing they did is removed/i)).toBeInTheDocument()
  })
})
