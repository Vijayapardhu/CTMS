import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest'
import { HttpResponse, http } from 'msw'
import { server } from '@/mocks/server'
import { configureClient, request, requestPage } from '@/api/client'
import { ApiFailure } from '@/api/failure'
import { errorResponse } from '@/mocks/fixtures'

const API = 'http://localhost:8000/api/v1'

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))
afterEach(() => server.resetHandlers())
afterAll(() => server.close())

describe('the API client', () => {
  it('reads the total from pagination, never from the rows in hand', async () => {
    configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })

    const page = await requestPage('/trips')

    expect(page.rows).toEqual([])
    expect(page.pagination?.total).toBe(18)
  })

  it('shows the server wording for a 409, verbatim', async () => {
    configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })
    server.use(
      http.post(`${API}/trips/t-1/cancel`, () =>
        HttpResponse.json(errorResponse('This trip has already been completed.', 409), { status: 409 }),
      ),
    )

    const failure = (await request('/trips/t-1/cancel', { method: 'POST' }).catch((e) => e)) as ApiFailure

    expect(failure).toBeInstanceOf(ApiFailure)
    expect(failure.kind).toBe('conflict')
    expect(failure.displayMessage).toBe('This trip has already been completed.')
  })

  it('never repeats the server wording for a 403', async () => {
    configureClient({ accessToken: () => 'access-1', reauthenticate: async () => false })
    server.use(
      http.post(`${API}/incidents/i-1/close`, () =>
        HttpResponse.json(errorResponse('Requires administrator tier OPERATIONS.', 403), { status: 403 }),
      ),
    )

    const failure = (await request('/incidents/i-1/close', { method: 'POST' }).catch((e) => e)) as ApiFailure

    expect(failure.kind).toBe('forbidden')
    expect(failure.displayMessage).toBe("You don't have permission to perform this action.")
    expect(failure.displayMessage).not.toContain('OPERATIONS')
  })

  it('buys exactly one refresh on a 401', async () => {
    let attempts = 0
    let refreshes = 0

    server.use(
      http.get(`${API}/trips`, () => {
        attempts += 1
        return HttpResponse.json(errorResponse('Unauthenticated.', 401), { status: 401 })
      }),
    )

    configureClient({
      accessToken: () => 'stale',
      reauthenticate: async () => {
        refreshes += 1
        return true
      },
    })

    await request('/trips').catch(() => undefined)

    expect(attempts).toBe(2)
    expect(refreshes).toBe(1)
  })

  it('reports an unreachable server as a network failure, not a server fault', async () => {
    configureClient({ accessToken: () => null, reauthenticate: async () => false })
    server.use(http.get(`${API}/trips`, () => HttpResponse.error()))

    const failure = (await request('/trips').catch((e) => e)) as ApiFailure

    expect(failure.kind).toBe('network')
    expect(failure.isRetryable).toBe(true)
  })
})
