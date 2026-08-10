import { AccessLevel, parseAccessLevel } from './accessLevel'

/** The user shape `AuthService::profileFor()` returns. */
export type AdminUser = {
  id: string
  email: string
  fullName: string
  role: string
  isActive: boolean
  accessLevel: AccessLevel | null
  designation?: string
  department?: string
}

export type Tokens = {
  access: string
  refresh: string
  /** Epoch milliseconds. Used to refresh before a call fails, not after. */
  accessExpiresAt: number
}

export type Session = { user: AdminUser; tokens: Tokens }

export function parseUser(raw: unknown): AdminUser {
  const data = raw as Record<string, unknown>
  const profile = (data.profile ?? {}) as Record<string, unknown>

  return {
    id: String(data.id ?? ''),
    email: String(data.email ?? ''),
    fullName: String(data.full_name ?? ''),
    role: String(data.role ?? ''),
    isActive: data.is_active !== false,
    // The whole panel gates on this one field. It arrives on the admin
    // profile, from `AuthService::profileFor()`.
    accessLevel: parseAccessLevel(profile.access_level),
    designation: profile.designation ? String(profile.designation) : undefined,
    department: profile.department ? String(profile.department) : undefined,
  }
}

export function parseTokens(raw: unknown): Tokens {
  const data = raw as Record<string, Record<string, unknown>>
  const access = data.access_token ?? {}
  const refresh = data.refresh_token ?? {}

  const expiresAt = access.expires_at
    ? Date.parse(String(access.expires_at))
    : Date.now() + Number(access.expires_in ?? 3600) * 1000

  return {
    access: String(access.token ?? ''),
    refresh: String(refresh.token ?? ''),
    accessExpiresAt: Number.isNaN(expiresAt) ? Date.now() + 3_600_000 : expiresAt,
  }
}

/**
 * Where the refresh token lives.
 *
 * `sessionStorage`, not `localStorage`: it dies with the tab, so a shared
 * office machine does not carry a transport head's session into whoever sits
 * down next. The access token is held in memory only and never written down
 * at all.
 */
const REFRESH_KEY = 'ctms.admin.refresh'

export const refreshStore = {
  read(): string | null {
    try {
      return window.sessionStorage.getItem(REFRESH_KEY)
    } catch {
      return null
    }
  },
  write(token: string) {
    try {
      window.sessionStorage.setItem(REFRESH_KEY, token)
    } catch {
      // A browser with storage disabled still works; the session simply does
      // not survive a reload.
    }
  },
  clear() {
    try {
      window.sessionStorage.removeItem(REFRESH_KEY)
    } catch {
      // Nothing to clear.
    }
  },
}
