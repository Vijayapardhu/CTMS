import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { HttpResponse, http } from 'msw'
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { server } from '@/mocks/server'
import { setMockLevel } from '@/mocks/handlers'
import { errorResponse, meResponse, tokenResponse, userJson } from '@/mocks/fixtures'
import { AccessLevel } from '@/auth/accessLevel'
import { SessionProvider, useSession } from '@/auth/SessionProvider'
import { RequireCapability } from '@/auth/RequireCapability'
import { LoginScreen } from '@/auth/LoginScreen'
import { AppShell } from '@/app/shell/AppShell'
import { Placeholder } from '@/components/Placeholder'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

beforeEach(() => setMockLevel(AccessLevel.OPERATIONS))

/** The real provider, the real client, MSW standing in for the socket. */
function Harness({ initialPath = '/' }: { initialPath?: string }) {
  return (
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[initialPath]}>
        <SessionProvider>
          <Screen />
        </SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>
  )
}

function Screen() {
  const { status, level, user, signOut } = useSession()

  if (status === 'initialising') return <p>Signing you in…</p>
  if (status === 'wrongAudience') return <p>This panel is for transport office staff.</p>
  if (status !== 'authenticated') return <LoginScreen />

  return (
    <AppShell
      user={{ name: user!.fullName, levelLabel: level ?? '' }}
      onSignOut={() => void signOut()}
    >
      <Routes>
        <Route path="/" element={<Placeholder title="Dashboard" icon="dashboard" slice="slice 2" />} />
        <Route
          path="/admin/audit"
          element={
            <RequireCapability capability="audit.read">
              <Placeholder title="Audit" icon="audit" slice="slice 8" />
            </RequireCapability>
          }
        />
      </Routes>
    </AppShell>
  )
}

async function signIn(password = 'correct-horse') {
  await userEvent.type(screen.getByLabelText('Email'), 'priya@ctms.example')
  await userEvent.type(screen.getByLabelText('Password'), password)
  await userEvent.click(screen.getByRole('button', { name: 'Sign in' }))
}

describe('signing in', () => {
  it('reaches the panel with a valid administrator', async () => {
    render(<Harness />)
    await screen.findByRole('button', { name: 'Sign in' })

    await signIn()

    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  })

  it('shows the server refusal and never says whether the address exists', async () => {
    render(<Harness />)
    await screen.findByRole('button', { name: 'Sign in' })

    await signIn('wrong')

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Invalid email or password.')
    expect(alert).not.toHaveTextContent(/not found|no account|does not exist/i)
  })

  it('refuses a driver, whose token is perfectly valid', async () => {
    server.use(
      http.post(`${API}/auth/login`, () =>
        HttpResponse.json({
          ...tokenResponse(AccessLevel.VIEWER),
          data: { ...tokenResponse(AccessLevel.VIEWER).data, user: userJson(AccessLevel.VIEWER, { role: 'DRIVER' }) },
        }),
      ),
    )

    render(<Harness />)
    await screen.findByRole('button', { name: 'Sign in' })
    await signIn()

    expect(await screen.findByText('This panel is for transport office staff.')).toBeInTheDocument()
  })
})

describe('session restoration', () => {
  it('restores a stored session without a password', async () => {
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')

    render(<Harness />)

    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  })

  it('falls back to login when the stored token is refused', async () => {
    window.sessionStorage.setItem('ctms.admin.refresh', 'stale')
    server.use(
      http.post(`${API}/auth/refresh`, () =>
        HttpResponse.json(errorResponse('This session has ended.', 401), { status: 401 }),
      ),
    )

    render(<Harness />)

    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeInTheDocument()
    expect(window.sessionStorage.getItem('ctms.admin.refresh')).toBeNull()
  })

  it('confirms the identity against /auth/me, which carries the level', async () => {
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')

    let meCalls = 0
    server.use(
      http.get(`${API}/auth/me`, () => {
        meCalls += 1
        return HttpResponse.json(meResponse(AccessLevel.SUPER_ADMIN))
      }),
    )

    render(<Harness />)
    await screen.findByRole('heading', { name: 'Dashboard' })

    expect(meCalls).toBeGreaterThan(0)
    expect(await screen.findByRole('link', { name: /audit/i })).toBeInTheDocument()
  })
})

describe('signing out', () => {
  it('discards the session even if the server call fails', async () => {
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
    server.use(http.post(`${API}/auth/logout`, () => HttpResponse.error()))

    render(<Harness />)
    await screen.findByRole('heading', { name: 'Dashboard' })

    await userEvent.click(screen.getByRole('button', { name: /sign out/i }))

    // A failed logout is no reason to leave somebody signed in on a shared
    // office machine.
    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeInTheDocument()
    expect(window.sessionStorage.getItem('ctms.admin.refresh')).toBeNull()
  })
})

describe('navigation by access level', () => {
  const cases: Array<[AccessLevel, string[], string[]]> = [
    [AccessLevel.VIEWER, ['Dashboard', 'Trips', 'Incidents'], ['Audit', 'Accounts']],
    [AccessLevel.SUPPORT, ['Dashboard', 'Maintenance'], ['Audit', 'Accounts']],
    [AccessLevel.OPERATIONS, ['Dashboard', 'Buses'], ['Audit', 'Accounts']],
    [AccessLevel.SUPER_ADMIN, ['Dashboard', 'Audit', 'Accounts'], []],
  ]

  it.each(cases)('%s sees the right sections', async (level, visible, hidden) => {
    setMockLevel(level)
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')

    render(<Harness />)
    await screen.findByRole('heading', { name: 'Dashboard' })

    for (const label of visible) {
      expect(screen.getByRole('link', { name: new RegExp(label, 'i') })).toBeInTheDocument()
    }
    for (const label of hidden) {
      expect(screen.queryByRole('link', { name: new RegExp(label, 'i') })).not.toBeInTheDocument()
    }
  })

  it('protects a screen reached by typing the URL, not just by hiding the link', async () => {
    setMockLevel(AccessLevel.OPERATIONS)
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')

    render(<Harness initialPath="/admin/audit" />)

    // Forbidden, and emphatically NOT a redirect to login: this person is
    // signed in, they simply may not be here.
    expect(await screen.findByText(/don’t have permission/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sign in' })).not.toBeInTheDocument()
  })

  it('loses a capability when the server demotes the account mid-session', async () => {
    setMockLevel(AccessLevel.SUPER_ADMIN)
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')

    render(<Harness />)
    expect(await screen.findByRole('link', { name: /audit/i })).toBeInTheDocument()

    // The transport office lowers this account to read-only. The token in the
    // browser is unchanged and still perfectly valid — only the server's answer
    // moved. Revalidation on focus is what makes the panel notice.
    server.use(http.get(`${API}/auth/me`, () => HttpResponse.json(meResponse(AccessLevel.VIEWER))))
    window.dispatchEvent(new Event('focus'))

    await waitFor(() =>
      expect(screen.queryByRole('link', { name: /audit/i })).not.toBeInTheDocument(),
    )
    // Demoted, not signed out: they keep the work they were doing.
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  })

  it('keeps the level it has when revalidation fails', async () => {
    setMockLevel(AccessLevel.SUPER_ADMIN)
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')

    render(<Harness />)
    await screen.findByRole('link', { name: /audit/i })

    // A flaky network is not a demotion. Dropping privileges on a failed
    // request would make the panel unusable on a bad connection.
    server.use(http.get(`${API}/auth/me`, () => HttpResponse.error()))
    window.dispatchEvent(new Event('focus'))

    await waitFor(() => expect(screen.getByRole('link', { name: /audit/i })).toBeInTheDocument())
  })

  it('offers no navigation at all before the level is known', async () => {
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')

    render(<Harness />)

    // The frame before the session resolves must not show privileged links.
    expect(screen.queryByRole('link', { name: /audit/i })).not.toBeInTheDocument()

    await waitFor(() => expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument())
  })
})
