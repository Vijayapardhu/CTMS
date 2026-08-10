import { HttpResponse, http } from 'msw'
import { AccessLevel } from '@/auth/accessLevel'
import { errorResponse, meResponse, pageResponse, tokenResponse } from './fixtures'

const API = 'http://localhost:8000/api/v1'

/**
 * The development and test harness. **Not a second implementation of CTMS.**
 *
 * These handlers answer with the backend's real envelope and nothing more.
 * There is deliberately no business logic here: a mock that decides whether a
 * bus is cleared for service is a mock that will one day disagree with the
 * server, and the test suite will believe the mock.
 *
 * Tests override any of these per case with `server.use(...)`.
 */

/** The level the default session holds. A test changes it before rendering. */
export let mockLevel: AccessLevel = AccessLevel.OPERATIONS

export function setMockLevel(level: AccessLevel) {
  mockLevel = level
}

export const handlers = [
  http.post(`${API}/auth/login`, async ({ request }) => {
    const body = (await request.json()) as { email?: string; password?: string }

    if (body?.password === 'wrong') {
      // The backend's own wording, which never reveals whether the address
      // exists.
      return HttpResponse.json(errorResponse('Invalid email or password.', 401), { status: 401 })
    }

    return HttpResponse.json(tokenResponse(mockLevel))
  }),

  http.post(`${API}/auth/refresh`, () => HttpResponse.json(tokenResponse(mockLevel, 'access-2'))),

  http.get(`${API}/auth/me`, ({ request }) => {
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json(errorResponse('Authentication is required.', 401), { status: 401 })
    }

    return HttpResponse.json(meResponse(mockLevel))
  }),

  http.post(`${API}/auth/logout`, () => HttpResponse.json({ success: true, message: 'ok', data: null, code: 200 })),

  // Enough of a read for slice 0 to prove the client parses an envelope and
  // reads `pagination.total` rather than counting rows.
  http.get(`${API}/trips`, () => HttpResponse.json(pageResponse([], 18))),
  http.get(`${API}/buses`, () => HttpResponse.json(pageResponse([], 12))),
]
