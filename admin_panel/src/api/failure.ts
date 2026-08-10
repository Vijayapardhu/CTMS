/**
 * Every way a request can fail, as one type.
 *
 * The status codes are the backend's real ones and each means something
 * different to a person — 01-api-contract.md. The two that matter most:
 *
 *   403  the panel's own gating was wrong. A fixed sentence, never the
 *        server's internal wording and never the path.
 *   409  a business rule refused. The server's message is written for a human
 *        and is shown VERBATIM. Paraphrasing a safety refusal is how somebody
 *        talks themselves past it.
 */
export type FailureKind =
  | 'network' // never reached the server
  | 'unauthenticated' // 401
  | 'forbidden' // 403
  | 'notFound' // 404
  | 'conflict' // 409 — business rule
  | 'validation' // 422
  | 'rateLimited' // 429
  | 'server' // 5xx
  | 'unknown'

export class ApiFailure extends Error {
  readonly kind: FailureKind
  readonly status: number
  readonly errors: Record<string, string[]>

  constructor(kind: FailureKind, message: string, status = 0, errors: Record<string, string[]> = {}) {
    super(message)
    this.name = 'ApiFailure'
    this.kind = kind
    this.status = status
    this.errors = errors
  }

  /** The first field error, for a refusal a form has nowhere to put. */
  get firstFieldError(): string | undefined {
    for (const messages of Object.values(this.errors)) {
      if (messages.length > 0) return messages[0]
    }
    return undefined
  }

  /**
   * What a person should read.
   *
   * A 409 is the server's own words. A 403 never is: it would leak how the
   * backend describes its own internals to somebody who was not permitted to
   * ask in the first place.
   */
  get displayMessage(): string {
    switch (this.kind) {
      case 'forbidden':
        return "You don't have permission to perform this action."
      case 'notFound':
        return 'That record is no longer available.'
      case 'network':
        return 'The CTMS server could not be reached.'
      case 'rateLimited':
        return 'Too many requests — retrying shortly.'
      case 'server':
        return 'The server could not complete that.'
      case 'conflict':
      case 'validation':
        return this.message || this.firstFieldError || 'That could not be completed.'
      default:
        return this.message || 'Request failed.'
    }
  }

  /** Whether retrying the identical request could plausibly succeed. */
  get isRetryable(): boolean {
    return this.kind === 'network' || this.kind === 'server' || this.kind === 'rateLimited'
  }
}

function kindFor(status: number): FailureKind {
  if (status === 401) return 'unauthenticated'
  if (status === 403) return 'forbidden'
  if (status === 404) return 'notFound'
  if (status === 409) return 'conflict'
  if (status === 422) return 'validation'
  if (status === 429) return 'rateLimited'
  if (status >= 500) return 'server'
  return 'unknown'
}

type Envelope = {
  success?: boolean
  message?: string
  errors?: Record<string, string[]> | null
}

/** Turns a failed response into an ApiFailure, keeping the server's wording. */
export function failureFrom(status: number, body: unknown): ApiFailure {
  const envelope = (body ?? {}) as Envelope

  return new ApiFailure(
    kindFor(status),
    envelope.message ?? 'Request failed.',
    status,
    envelope.errors ?? {},
  )
}
