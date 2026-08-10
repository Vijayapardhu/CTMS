import { config } from '@/config/env'
import { ApiFailure, failureFrom } from './failure'

/** The envelope every CTMS response carries. */
export type Envelope<T> = {
  success: boolean
  message: string
  code: number
  data: T
  pagination?: Pagination
}

export type Pagination = {
  total: number
  per_page: number
  current_page: number
  last_page: number
}

export type Page<T> = { rows: T[]; pagination?: Pagination }

type TokenSource = () => string | null
/** Returns true if a fresh token was obtained and the request may be retried. */
type Reauthenticate = () => Promise<boolean>

let accessToken: TokenSource = () => null
let reauthenticate: Reauthenticate = async () => false

export function configureClient(options: { accessToken: TokenSource; reauthenticate: Reauthenticate }) {
  accessToken = options.accessToken
  reauthenticate = options.reauthenticate
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  query?: Record<string, string | number | boolean | undefined | null>
  body?: unknown
  signal?: AbortSignal
  /** Internal: stops a refreshed request from refreshing again. */
  retried?: boolean
  /**
   * Never attempt reauthentication for this request.
   *
   * Required for `/auth/refresh` itself. Without it a rejected refresh token
   * produces a 401 on the refresh call, which asks for a refresh, which is
   * already in flight — and the single-flight promise then awaits itself. The
   * app hangs on "Signing you in…" forever rather than returning to login.
   */
  skipReauth?: boolean
}

function url(path: string, query?: RequestOptions['query']): string {
  // Resolved against this origin when the base is relative, which is how the
  // proxied development setup works.
  const target = new URL(
    config.apiBaseUrl + (path.startsWith('/') ? path : `/${path}`),
    globalThis.location?.origin ?? 'http://localhost',
  )

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') {
      target.searchParams.set(key, String(value))
    }
  }

  return target.toString()
}

/**
 * One request, with the whole failure vocabulary applied.
 *
 * A 401 buys exactly one refresh. The second failure means the session is
 * genuinely over and pretending otherwise loops a driver's afternoon away in
 * a redirect. A 403 is never retried and never redirects to login: the caller
 * is authenticated, they simply may not do this.
 */
export async function request<T>(path: string, options: RequestOptions = {}): Promise<Envelope<T>> {
  const token = accessToken()

  let response: Response
  try {
    response = await fetch(url(path, options.query), {
      method: options.method ?? 'GET',
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
    })
  } catch {
    throw new ApiFailure('network', 'The CTMS server could not be reached.')
  }

  if (response.status === 204) {
    return { success: true, message: '', code: 204, data: undefined as T }
  }

  let body: unknown = null
  try {
    body = await response.json()
  } catch {
    if (!response.ok) throw failureFrom(response.status, null)
  }

  if (response.ok) return body as Envelope<T>

  if (response.status === 401 && !options.retried && !options.skipReauth) {
    if (await reauthenticate()) {
      return request<T>(path, { ...options, retried: true })
    }
  }

  throw failureFrom(response.status, body)
}

/**
 * A read that returns bytes rather than an envelope — evidence, essentially.
 *
 * It goes through this module so it inherits the one token source and the one
 * refresh, rather than a screen reaching for the token itself. The response is
 * private (`Cache-Control: private, no-store`, `Content-Disposition:
 * attachment`) and the caller is expected to hold it in an object URL and
 * revoke it, never to turn it into a shareable link.
 */
export async function requestBlob(path: string, options: RequestOptions = {}): Promise<Blob> {
  const token = accessToken()

  let response: Response
  try {
    response = await fetch(url(path, options.query), {
      headers: {
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      signal: options.signal,
    })
  } catch {
    throw new ApiFailure('network', 'The CTMS server could not be reached.')
  }

  if (response.ok) return response.blob()

  if (response.status === 401 && !options.retried && !options.skipReauth) {
    if (await reauthenticate()) return requestBlob(path, { ...options, retried: true })
  }

  let body: unknown = null
  try {
    body = await response.json()
  } catch {
    /* a binary endpoint refusing need not answer in JSON */
  }

  throw failureFrom(response.status, body)
}

/** A paginated read, with the envelope's own total rather than a row count. */
export async function requestPage<T>(path: string, options: RequestOptions = {}): Promise<Page<T>> {
  const envelope = await request<T[]>(path, options)

  return { rows: envelope.data ?? [], pagination: envelope.pagination }
}
