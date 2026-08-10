import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { ErrorBoundary } from './app/ErrorBoundary'
import { AppShell } from './app/shell/AppShell'
import { screenElement, screens } from './routes'
import { AccessLevel } from './auth/accessLevel'

/** Slice 0: reads have no retry storm and no background refetch surprises. */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: false,
      staleTime: 30_000,
    },
  },
})

/**
 * Slice 0 renders the shell with every section visible. Slice 1 replaces the
 * fixed level with the session's own, and adds the guards.
 */
export function App() {
  return (
    <ErrorBoundary>
      <QueryClientProvider client={queryClient}>
        <BrowserRouter>
          <AppShell level={AccessLevel.SUPER_ADMIN}>
            <Routes>
              {screens.map((screen) => (
                <Route key={screen.path} path={screen.path} element={screenElement(screen)} />
              ))}
            </Routes>
          </AppShell>
        </BrowserRouter>
      </QueryClientProvider>
    </ErrorBoundary>
  )
}
