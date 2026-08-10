import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { configureClient, request } from '@/api/client'
import { ApiFailure } from '@/api/failure'
import { logger } from '@/app/logger'
import { AccessLevel, meets } from './accessLevel'
import { can as evaluate, type CapabilityId, type ResourceScope } from './capabilities'
import { parseTokens, parseUser, refreshStore, type AdminUser, type Tokens } from './session'

/** M-SESSION, from 04-state-machines.md. */
export type SessionStatus =
  | 'initialising'
  | 'authenticated'
  | 'unauthenticated'
  | 'expired'
  /** Signed in, but not with an ADMIN account. */
  | 'wrongAudience'

type SessionValue = {
  status: SessionStatus
  user: AdminUser | null
  level: AccessLevel | null
  signIn: (email: string, password: string) => Promise<void>
  signOut: () => Promise<void>
  acknowledgeExpiry: () => void
  /** Re-read the access level from the server. */
  revalidate: () => Promise<void>
  can: (capability: CapabilityId, resource?: ResourceScope) => boolean
  hasAccess: (level: AccessLevel) => boolean
  signInFailure: string | null
  signingIn: boolean
}

const SessionContext = createContext<SessionValue | null>(null)

export function useSession(): SessionValue {
  const value = useContext(SessionContext)
  if (!value) throw new Error('useSession used outside SessionProvider')

  return value
}

/**
 * The session, and the only place the access level is read.
 *
 * Two rules this encodes that a screen must never re-decide:
 *
 * 1. A token that is valid but not an ADMIN's is *rejected*. A driver's token
 *    works perfectly against this API; a driver has no business in the
 *    transport office's console.
 * 2. There is no offline session. The driver app keeps a cached identity
 *    because a bus in a valley still has a trip to run. A laptop that cannot
 *    reach CTMS has nothing it can usefully do.
 */
export function SessionProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<SessionStatus>('initialising')
  const [user, setUser] = useState<AdminUser | null>(null)
  const [signInFailure, setSignInFailure] = useState<string | null>(null)
  const [signingIn, setSigningIn] = useState(false)

  // Held in a ref, not state: the API client reads it synchronously on every
  // request and a re-render must not be in that path.
  const tokens = useRef<Tokens | null>(null)
  const refreshing = useRef<Promise<boolean> | null>(null)

  const clear = useCallback((next: SessionStatus) => {
    tokens.current = null
    refreshStore.clear()
    setUser(null)
    setStatus(next)
  }, [])

  const adopt = useCallback(
    (nextUser: AdminUser, nextTokens: Tokens | null): boolean => {
      if (nextUser.role !== 'ADMIN') {
        logger.warn('Non-admin token presented to the admin panel')
        clear('wrongAudience')

        return false
      }

      if (nextTokens) {
        tokens.current = nextTokens
        refreshStore.write(nextTokens.refresh)
      }

      setUser(nextUser)
      setStatus('authenticated')

      return true
    },
    [clear],
  )

  /**
   * Single-flight refresh.
   *
   * Five concurrent reads meeting a 401 must produce one refresh, not five:
   * each exchange revokes the presented refresh token server-side, so racing
   * refreshes invalidate one another.
   */
  const reauthenticate = useCallback(async (): Promise<boolean> => {
    if (refreshing.current) return refreshing.current

    const attempt = (async () => {
      const stored = tokens.current?.refresh ?? refreshStore.read()
      if (!stored) return false

      try {
        const envelope = await request<Record<string, unknown>>('/auth/refresh', {
          method: 'POST',
          body: { refresh_token: stored },
          // This call must never ask for a refresh of its own.
          skipReauth: true,
        })

        const nextTokens = parseTokens(envelope.data)
        const nextUser = parseUser(envelope.data.user)

        return adopt(nextUser, nextTokens)
      } catch {
        clear('expired')

        return false
      } finally {
        refreshing.current = null
      }
    })()

    refreshing.current = attempt

    return attempt
  }, [adopt, clear])

  // Wired before the first request goes out.
  useEffect(() => {
    configureClient({
      accessToken: () => tokens.current?.access ?? null,
      reauthenticate,
    })
  }, [reauthenticate])

  // Restore on boot. A stored refresh token is exchanged, then confirmed
  // against /auth/me — which is what carries the access level.
  useEffect(() => {
    let cancelled = false

    void (async () => {
      const stored = refreshStore.read()

      if (!stored) {
        if (!cancelled) setStatus('unauthenticated')

        return
      }

      const recovered = await reauthenticate()

      if (cancelled) return

      if (!recovered) {
        clear('expired')

        return
      }

      try {
        const me = await request<Record<string, unknown>>('/auth/me')
        if (!cancelled) adopt(parseUser(me.data), null)
      } catch {
        if (!cancelled) clear('expired')
      }
    })()

    return () => {
      cancelled = true
    }
  }, [adopt, clear, reauthenticate])

  /**
   * Ask the server what this account may do now.
   *
   * The access level is not a fact about the session, it is a fact about the
   * account, and somebody else can change it. Re-read on focus and after every
   * token refresh; the server's answer replaces the local one rather than
   * merging with it. If the demotion makes the current screen forbidden, the
   * route guard renders that in place — no redirect, no reload.
   */
  const revalidate = useCallback(async () => {
    if (!tokens.current) return

    try {
      const me = await request<Record<string, unknown>>('/auth/me')
      const fresh = parseUser(me.data)

      // adopt() also rejects a token that is no longer an ADMIN's.
      adopt(fresh, null)
    } catch {
      // A failed revalidation is not a demotion. The existing level stands
      // until the server actually answers.
    }
  }, [adopt])

  useEffect(() => {
    if (status !== 'authenticated') return

    const onFocus = () => void revalidate()
    window.addEventListener('focus', onFocus)

    return () => window.removeEventListener('focus', onFocus)
  }, [status, revalidate])

  const signIn = useCallback(
    async (email: string, password: string) => {
      setSigningIn(true)
      setSignInFailure(null)

      try {
        const envelope = await request<Record<string, unknown>>('/auth/login', {
          method: 'POST',
          body: { email, password },
        })

        const nextUser = parseUser(envelope.data.user)

        if (!adopt(nextUser, parseTokens(envelope.data))) {
          setSignInFailure('This panel is for transport office staff.')
        }
      } catch (error) {
        const failure = error as ApiFailure
        // The backend's wording, which deliberately never says whether the
        // address exists.
        setSignInFailure(failure.displayMessage ?? 'Sign-in failed.')
      } finally {
        setSigningIn(false)
      }
    },
    [adopt],
  )

  const signOut = useCallback(async () => {
    try {
      await request('/auth/logout', { method: 'POST' })
    } catch {
      // The token is being discarded either way. A failed logout call is not
      // a reason to keep somebody signed in on a shared machine.
    }
    clear('unauthenticated')
  }, [clear])

  const value = useMemo<SessionValue>(() => {
    const level = status === 'authenticated' ? (user?.accessLevel ?? null) : null

    return {
      status,
      user: status === 'authenticated' ? user : null,
      level,
      revalidate,
      signIn,
      signOut,
      acknowledgeExpiry: () => setStatus('unauthenticated'),
      // `can` reports what the panel will OFFER, from the generated
      // registry. The server decides what happens; a 403 arriving anyway
      // means the registry is wrong, which is why it is generated.
      can: (capability, resource) =>
        evaluate({ userId: user?.id ?? null, role: user?.role ?? null, level }, capability, resource),
      hasAccess: (required) => meets(level, required),
      signInFailure,
      signingIn,
    }
  }, [status, user, signIn, signOut, signInFailure, signingIn, revalidate])

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}
