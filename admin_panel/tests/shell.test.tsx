import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { server } from '@/mocks/server'
import { setMockLevel } from '@/mocks/handlers'
import { AppShell } from '@/app/shell/AppShell'
import { AccessLevel } from '@/auth/accessLevel'
import { SessionProvider } from '@/auth/SessionProvider'
import { Placeholder } from '@/components/Placeholder'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

/**
 * The shell through the real session, because the sidebar now asks the
 * capability registry rather than being handed a level.
 */
async function renderShell(level: AccessLevel | null) {
  if (level) {
    setMockLevel(level)
    window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')
  }

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <SessionProvider>
          <AppShell>
            <Placeholder title="Dashboard" icon="dashboard" slice="slice 2" />
          </AppShell>
        </SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  if (level) await screen.findByRole('link', { name: /dashboard/i })
}

describe('the shell', () => {
  it('renders navigation and content together', async () => {
    await renderShell(AccessLevel.SUPER_ADMIN)

    expect(screen.getByRole('navigation', { name: 'Sections' })).toBeInTheDocument()
    expect(screen.getByRole('main')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  })

  it('shows administration only to a super admin', async () => {
    await renderShell(AccessLevel.SUPER_ADMIN)
    expect(screen.getByRole('link', { name: /audit/i })).toBeInTheDocument()
  })

  it('hides administration from a transport head', async () => {
    await renderShell(AccessLevel.OPERATIONS)

    expect(screen.queryByRole('link', { name: /audit/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /accounts/i })).not.toBeInTheDocument()
  })

  it('offers nothing at all while the session is unknown', async () => {
    await renderShell(null)

    // Authorization flicker: privileged navigation must never appear for the
    // frame before the level arrives.
    expect(screen.queryByRole('link', { name: /dashboard/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /audit/i })).not.toBeInTheDocument()
  })
})
