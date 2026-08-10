import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import type { ReactNode } from 'react'
import { server } from '@/mocks/server'
import { setMockLevel } from '@/mocks/handlers'
import { AccessLevel } from '@/auth/accessLevel'
import { SessionProvider } from '@/auth/SessionProvider'
import { Can } from '@/auth/Can'
import { RequireCapability } from '@/auth/RequireCapability'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => {
  server.resetHandlers()
  window.sessionStorage.clear()
})
afterAll(() => server.close())

/** A real session, because both guards ask it rather than taking a prop. */
async function signedInAs(level: AccessLevel, children: ReactNode) {
  setMockLevel(level)
  window.sessionStorage.setItem('ctms.admin.refresh', 'refresh-1')

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <SessionProvider>{children}</SessionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  await screen.findByTestId('settled')
}

/** Renders once the session has resolved, so nothing is asserted mid-flight. */
function Settled({ children }: { children: ReactNode }) {
  return (
    <>
      {children}
      <span data-testid="settled" />
    </>
  )
}

describe('the action guard', () => {
  it('shows an action to a tier that has it', async () => {
    await signedInAs(
      AccessLevel.OPERATIONS,
      <Settled>
        <Can capability="incident.close">
          <button type="button">Close incident</button>
        </Can>
      </Settled>,
    )

    expect(screen.getByRole('button', { name: 'Close incident' })).toBeInTheDocument()
  })

  it('removes it entirely from a tier that does not — never disables it', async () => {
    await signedInAs(
      AccessLevel.SUPPORT,
      <Settled>
        <Can capability="incident.close">
          <button type="button">Close incident</button>
        </Can>
      </Settled>,
    )

    // A permanently greyed-out control teaches somebody the product is broken
    // for them. Absent is the honest state.
    expect(screen.queryByRole('button', { name: 'Close incident' })).not.toBeInTheDocument()
    expect(screen.queryByText(/close incident/i)).not.toBeInTheDocument()
  })

  it('renders a fallback when one is given', async () => {
    await signedInAs(
      AccessLevel.VIEWER,
      <Settled>
        <Can capability="incident.close" fallback={<p>Ask a transport head to close this.</p>}>
          <button type="button">Close incident</button>
        </Can>
      </Settled>,
    )

    expect(screen.getByText('Ask a transport head to close this.')).toBeInTheDocument()
  })

  it('honours a resource scope over the tier', async () => {
    // A support supervisor may not edit anybody's account — except their own,
    // which is the subject exception the policy makes.
    await signedInAs(
      AccessLevel.SUPPORT,
      <Settled>
        <Can capability="account.update">
          <button type="button">Edit anyone</button>
        </Can>
        <Can capability="account.update" resource={{ subjectUserId: 'user-admin-1' }}>
          <button type="button">Edit myself</button>
        </Can>
      </Settled>,
    )

    expect(screen.queryByRole('button', { name: 'Edit anyone' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Edit myself' })).toBeInTheDocument()
  })
})

describe('the route guard', () => {
  it('renders the screen for a tier that may reach it', async () => {
    await signedInAs(
      AccessLevel.SUPER_ADMIN,
      <Settled>
        <RequireCapability capability="audit.read">
          <h1>Audit</h1>
        </RequireCapability>
      </Settled>,
    )

    expect(screen.getByRole('heading', { name: 'Audit' })).toBeInTheDocument()
  })

  it('forbids without signing anybody out', async () => {
    await signedInAs(
      AccessLevel.OPERATIONS,
      <Settled>
        <RequireCapability capability="audit.read">
          <h1>Audit</h1>
        </RequireCapability>
      </Settled>,
    )

    expect(screen.queryByRole('heading', { name: 'Audit' })).not.toBeInTheDocument()
    expect(screen.getByText(/don’t have permission/i)).toBeInTheDocument()
    // Emphatically not a redirect to login: they are signed in, and a sign-in
    // form here invites them to try another account.
    expect(screen.queryByRole('button', { name: 'Sign in' })).not.toBeInTheDocument()
  })
})
