import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { ErrorBoundary } from './app/ErrorBoundary'
import { AppShell } from './app/shell/AppShell'
import { screenElement, screens } from './routes'
import { ACCESS_LEVEL_LABEL } from './auth/accessLevel'
import { LoginScreen } from './auth/LoginScreen'
import { RequireCapability } from './auth/RequireCapability'
import { SessionProvider, useSession } from './auth/SessionProvider'
import { Icon } from './icons/Icon'

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false, staleTime: 30_000 },
  },
})

/** While storage is being read. Never privileged navigation. */
function Initialising() {
  return (
    <div className="grid h-full place-items-center bg-surface-sunken" role="status" aria-live="polite">
      <div className="text-center">
        <Icon name="buses" size="lg" className="text-primary" />
        <p className="mt-md text-body text-on-surface-muted">Signing you in…</p>
      </div>
    </div>
  )
}

/** A valid token, for the wrong product. */
function WrongAudience() {
  const { acknowledgeExpiry } = useSession()

  return (
    <div className="grid h-full place-items-center bg-surface-sunken p-xl">
      <div className="max-w-prose rounded-md border border-outline bg-surface p-xl text-center">
        <Icon name="blocked" size="lg" className="text-on-surface-muted" />
        <h1 className="mt-md text-title-lg font-semibold">This panel is for transport office staff.</h1>
        <p className="mt-sm text-body text-on-surface-muted">
          Your account signs in to the CTMS driver app. Nothing here would be usable with it.
        </p>
        <button
          type="button"
          onClick={acknowledgeExpiry}
          className="mt-lg h-[var(--size-control)] rounded-sm bg-primary px-lg text-body font-semibold text-on-primary"
        >
          Back to sign in
        </button>
      </div>
    </div>
  )
}

/**
 * The shell only ever renders once the access level is known.
 *
 * `initialising` renders neither navigation nor content — a sidebar that shows
 * Administration for one frame and then removes it tells somebody exactly
 * which door to try.
 */
function Panel() {
  const { status, user, level, signOut } = useSession()

  if (status === 'initialising') return <Initialising />
  if (status === 'wrongAudience') return <WrongAudience />
  if (status !== 'authenticated') return <LoginScreen />

  return (
    <AppShell
      user={
        user
          ? {
              name: user.fullName,
              levelLabel: level ? ACCESS_LEVEL_LABEL[level] : 'No access level',
            }
          : null
      }
      onSignOut={() => void signOut()}
    >
      <Routes>
        {screens.map((screen) => (
          <Route
            key={screen.path}
            path={screen.path}
            element={<RequireCapability capability={screen.capability}>{screenElement(screen)}</RequireCapability>}
          />
        ))}
      </Routes>
    </AppShell>
  )
}

export function App() {
  return (
    <ErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <BrowserRouter>
          <SessionProvider>
            <Panel />
          </SessionProvider>
        </BrowserRouter>
      </QueryClientProvider>
    </ErrorBoundary>
  )
}
