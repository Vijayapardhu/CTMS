import { AccessLevel } from '@/auth/accessLevel'

/**
 * MOCK DATA — never reaches a production build.
 *
 * Shapes copied from `AuthService::tokenResponse()` and `profileFor()`, which
 * is where the real payload is built. If these drift from the backend the
 * tests still pass and the panel still breaks, so they are written from the
 * server's own code rather than from what the UI would find convenient.
 */

export function userJson(level: AccessLevel, overrides: Record<string, unknown> = {}) {
  return {
    id: 'user-admin-1',
    email: 'priya@ctms.example',
    phone_number: '+919876500000',
    first_name: 'Priya',
    last_name: 'Rao',
    full_name: 'Priya Rao',
    role: 'ADMIN',
    is_active: true,
    last_login_at: '2026-03-12T02:25:00+00:00',
    created_at: '2025-06-01T00:00:00+00:00',
    profile: {
      id: 'admin-1',
      designation: 'Transport Officer',
      department: 'Transport',
      access_level: level,
    },
    ...overrides,
  }
}

export function tokenResponse(level: AccessLevel, accessToken = 'access-1') {
  const issued = new Date()
  const hour = new Date(issued.getTime() + 3_600_000)
  const fortnight = new Date(issued.getTime() + 14 * 86_400_000)

  return {
    success: true,
    message: 'Logged in successfully.',
    code: 200,
    data: {
      access_token: {
        token: accessToken,
        token_type: 'Bearer',
        expires_in: 3600,
        expires_at: hour.toISOString(),
      },
      refresh_token: {
        token: 'refresh-1',
        token_type: 'Bearer',
        expires_in: 1_209_600,
        expires_at: fortnight.toISOString(),
      },
      user: userJson(level),
    },
  }
}

export function meResponse(level: AccessLevel) {
  return {
    success: true,
    message: 'Profile retrieved successfully.',
    code: 200,
    data: userJson(level),
  }
}

export function errorResponse(message: string, code: number, errors: Record<string, string[]> | null = null) {
  return { success: false, message, data: null, errors, code }
}

/** A paginated envelope, used only to prove the client reads `total`. */
export function pageResponse<T>(rows: T[], total = rows.length) {
  return {
    success: true,
    message: 'Retrieved successfully.',
    code: 200,
    data: rows,
    pagination: { total, per_page: 20, current_page: 1, last_page: Math.max(1, Math.ceil(total / 20)) },
  }
}
