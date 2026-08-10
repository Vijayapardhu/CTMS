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
import { AnnouncementsScreen } from '@/features/comms/AnnouncementsScreen'
import { AlertsScreen } from '@/features/comms/AlertsScreen'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

const announcement = (overrides: Record<string, unknown> = {}) => ({
  id: 'an-1',
  title: 'Depot gate closed on Friday',
  content: 'Buses will use the east entrance for the whole day.',
  target_audience: 'STUDENTS',
  priority: 'MEDIUM',
  published_at: null,
  expires_at: null,
  is_active: true,
  created_at: '2026-08-10T08:00:00.000000Z',
  created_by: { id: 'u-1', full_name: 'Priya Rao' },
  ...overrides,
})

const health = {
  window_hours: 24,
  channels: [
    { channel: 'PUSH', enabled: true, delivered: 180, failed: 2, suppressed: 0, pending: 3, success_rate: 98.9 },
    { channel: 'SMS', enabled: false, delivered: 0, failed: 0, suppressed: 0, pending: 0, success_rate: null },
  ],
}

const delivery = (overrides: Record<string, unknown> = {}) => ({
  id: 'del-1',
  notification_id: 'n-1',
  channel: 'PUSH',
  status: 'PERMANENTLY_FAILED',
  attempts: 3,
  first_attempted_at: '2026-08-10T08:00:00.000000Z',
  last_attempted_at: '2026-08-10T08:10:00.000000Z',
  delivered_at: null,
  reason: 'The device token is no longer registered.',
  created_at: '2026-08-10T08:00:00.000000Z',
  notification: { id: 'n-1', event_key: 'TRIP_DELAYED', title: 'Your bus is running late', user: { id: 'u-2', full_name: 'Asha Menon' } },
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

async function renderAnnouncementsAs(level: AccessLevel, rows = [announcement()]) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  server.use(http.get(`${API}/announcements`, () => HttpResponse.json(pageResponse(rows, rows.length))))
  renderWith(<AnnouncementsScreen />, '/announcements')
  await screen.findByRole('heading', { name: 'Announcements' })
}

async function renderAlertsAs(level: AccessLevel, options: { notifications?: unknown[]; deliveries?: unknown[] } = {}) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  server.use(
    http.get(`${API}/notifications`, () =>
      HttpResponse.json(pageResponse(options.notifications ?? [], (options.notifications ?? []).length)),
    ),
    http.get(`${API}/notifications/unread-count`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: { unread: 2 } }),
    ),
    http.get(`${API}/notification-log/health`, () =>
      HttpResponse.json({ success: true, message: 'ok', code: 200, data: health }),
    ),
    http.get(`${API}/notification-log`, () =>
      HttpResponse.json(pageResponse(options.deliveries ?? [delivery()], (options.deliveries ?? [1]).length)),
    ),
  )
  renderWith(<AlertsScreen />, '/alerts')
  await screen.findByRole('heading', { name: 'Alerts' })
}

// ── A14 ────────────────────────────────────────────────────────────────────

describe('announcements', () => {
  it('names the audience in a sentence, not a code', async () => {
    await renderAnnouncementsAs(AccessLevel.OPERATIONS)

    expect(await screen.findByText(/every student registered for transport/i)).toBeInTheDocument()
  })

  it('gives a viewer the notices and no controls', async () => {
    await renderAnnouncementsAs(AccessLevel.VIEWER)
    await screen.findByText('Depot gate closed on Friday')

    for (const label of [/write a notice/i, /^edit$/i, /^publish$/i, /^withdraw$/i]) {
      expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument()
    }
  })

  it('gives a supervisor nothing either — announcements are operations', async () => {
    await renderAnnouncementsAs(AccessLevel.SUPPORT)
    await screen.findByText('Depot gate closed on Friday')

    expect(screen.queryByRole('button', { name: /write a notice/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /^publish$/i })).not.toBeInTheDocument()
  })

  it('spells out who a publish reaches, and that it cannot be recalled', async () => {
    await renderAnnouncementsAs(AccessLevel.OPERATIONS)
    await screen.findByText('Depot gate closed on Friday')

    await userEvent.click(screen.getByRole('button', { name: /^publish$/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/every student registered for transport/i)).toBeInTheDocument()
    expect(within(dialog).getByText(/cannot be recalled/i)).toBeInTheDocument()
  })

  it('says a new notice is only a draft', async () => {
    await renderAnnouncementsAs(AccessLevel.OPERATIONS)

    await userEvent.click(screen.getByRole('button', { name: /write a notice/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/nobody is told anything until it is published/i)).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: /save draft/i })).toBeInTheDocument()
  })

  it('sends `content` and `target_audience`, the fields the request validates', async () => {
    let sent: Record<string, unknown> | null = null
    await renderAnnouncementsAs(AccessLevel.OPERATIONS)
    server.use(
      http.post(`${API}/announcements`, async ({ request }) => {
        sent = (await request.json()) as Record<string, unknown>

        return HttpResponse.json({ success: true, message: 'Announcement created.', code: 201, data: {} }, { status: 201 })
      }),
    )

    await userEvent.click(screen.getByRole('button', { name: /write a notice/i }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.type(within(dialog).getByRole('textbox', { name: /title/i }), 'Road closure')
    await userEvent.type(within(dialog).getByRole('textbox', { name: /notice/i }), 'The north gate is shut all week.')
    await userEvent.click(within(dialog).getByRole('button', { name: /save draft/i }))

    await waitFor(() => expect(sent).toMatchObject({ content: 'The north gate is shut all week.', target_audience: 'ALL' }))
  })

  it('will not publish something already live', async () => {
    await renderAnnouncementsAs(AccessLevel.OPERATIONS, [
      announcement({ published_at: '2026-08-10T09:00:00Z' }),
    ])
    await screen.findByText('Depot gate closed on Friday')

    expect(screen.getByRole('button', { name: /^publish$/i })).toBeDisabled()
    expect(screen.getByRole('button', { name: /^withdraw$/i })).toBeEnabled()
    expect(screen.getByText('Live')).toBeInTheDocument()
  })
})

// ── A13 ────────────────────────────────────────────────────────────────────

describe('alerts', () => {
  it('keeps my inbox and the delivery pipeline apart', async () => {
    await renderAlertsAs(AccessLevel.OPERATIONS)

    // G1-4: an empty inbox and a dead pipeline mean opposite things.
    expect(await screen.findByText('Nothing from the system')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Delivery health' })).toBeInTheDocument()
    expect(screen.getByText('98.9% delivered')).toBeInTheDocument()
  })

  it('shows an unconfigured channel as "nothing sent", never as nought per cent', async () => {
    await renderAlertsAs(AccessLevel.OPERATIONS)

    expect(await screen.findByText('Nothing sent')).toBeInTheDocument()
    expect(screen.getByText('Not configured')).toBeInTheDocument()
    expect(screen.queryByText('0% delivered')).not.toBeInTheDocument()
  })

  it('does not offer a viewer the resend', async () => {
    await renderAlertsAs(AccessLevel.VIEWER)
    await screen.findByText('Your bus is running late')

    expect(screen.queryByRole('button', { name: /send again/i })).not.toBeInTheDocument()
  })

  it('offers a supervisor the resend, except where it already arrived', async () => {
    await renderAlertsAs(AccessLevel.SUPPORT, {
      deliveries: [delivery(), delivery({ id: 'del-2', status: 'DELIVERED', delivered_at: '2026-08-10T08:11:00Z' })],
    })
    await screen.findAllByText('Your bus is running late')

    const buttons = screen.getAllByRole('button', { name: /send again/i })
    expect(buttons[0]).toBeEnabled()
    expect(buttons[1]).toBeDisabled()
    expect(buttons[1]).toHaveAttribute('title', 'This one arrived.')
  })

  it('does not dress a failed log load as an empty log', async () => {
    setMockLevel(AccessLevel.OPERATIONS)
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
    server.use(
      http.get(`${API}/notifications`, () => HttpResponse.json(pageResponse([], 0))),
      http.get(`${API}/notifications/unread-count`, () =>
        HttpResponse.json({ success: true, message: 'ok', code: 200, data: { unread: 0 } }),
      ),
      http.get(`${API}/notification-log/health`, () =>
        HttpResponse.json({ success: true, message: 'ok', code: 200, data: health }),
      ),
      http.get(`${API}/notification-log`, () =>
        HttpResponse.json(errorResponse('Server error.', 500), { status: 500 }),
      ),
    )
    renderWith(<AlertsScreen />, '/alerts')

    expect(await screen.findByText('Unable to load the delivery log')).toBeInTheDocument()
    expect(screen.queryByText('No deliveries match this filter')).not.toBeInTheDocument()
  })
})
