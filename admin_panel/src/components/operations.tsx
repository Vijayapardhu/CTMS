import { useCallback, useId, useState, type ReactNode } from 'react'
import { useQueryClient, type QueryKey } from '@tanstack/react-query'
import { ApiFailure } from '@/api/failure'
import { Icon } from '@/icons/Icon'

/**
 * Running one operational mutation, and telling the truth about the result.
 *
 * The whole vocabulary lives here so that no screen invents its own. In
 * particular:
 *
 *   409  the server's own sentence, verbatim and never retried. A business
 *        rule refused — "that bus is already on a trip" — and paraphrasing it
 *        is how somebody talks themselves past a safety refusal.
 *   422  field errors, routed back to the field where possible.
 *   403  the panel offered something it should not have. Say so plainly and
 *        never redirect to login: they are signed in.
 */

export type OperationState = {
  isPending: boolean
  failure: ApiFailure | null
  /** The server's own success sentence, which is written for a person. */
  success: string | null
}

export type Operation<TArgs> = OperationState & {
  run: (args: TArgs) => Promise<boolean>
  reset: () => void
  /** The validation message for one field, if the server named it. */
  fieldError: (field: string) => string | undefined
}

export function useOperation<TArgs = void>(options: {
  run: (args: TArgs) => Promise<{ message?: string } | void>
  /** Query keys to invalidate once the server has actually accepted it. */
  invalidate?: QueryKey[]
  onSuccess?: () => void
}): Operation<TArgs> {
  const queryClient = useQueryClient()
  const [isPending, setPending] = useState(false)
  const [failure, setFailure] = useState<ApiFailure | null>(null)
  const [success, setSuccess] = useState<string | null>(null)

  const { run: perform, invalidate, onSuccess } = options

  const reset = useCallback(() => {
    setFailure(null)
    setSuccess(null)
  }, [])

  const run = useCallback(
    async (args: TArgs) => {
      setPending(true)
      setFailure(null)
      setSuccess(null)
      try {
        const envelope = await perform(args)
        // Only after the server has committed it. Invalidating optimistically
        // would repaint the screen from a write that may never have happened.
        for (const key of invalidate ?? []) {
          await queryClient.invalidateQueries({ queryKey: key })
        }
        setSuccess(envelope?.message || 'Done.')
        onSuccess?.()

        return true
      } catch (error) {
        setFailure(
          error instanceof ApiFailure
            ? error
            : new ApiFailure('unknown', 'That could not be completed.'),
        )

        return false
      } finally {
        setPending(false)
      }
    },
    [perform, invalidate, onSuccess, queryClient],
  )

  const fieldError = useCallback(
    (field: string) => failure?.errors?.[field]?.[0],
    [failure],
  )

  return { run, reset, isPending, failure, success, fieldError }
}

/**
 * The outcome of an operation, rendered where the person who ran it is looking.
 *
 * A 409 gets the server's wording untouched; a 403 gets a fixed sentence,
 * because the backend's internal phrasing is not something to hand to somebody
 * who was not allowed to ask.
 */
export function OperationResult({ operation }: { operation: OperationState }) {
  if (operation.failure) {
    const { failure } = operation
    const tone =
      failure.kind === 'conflict'
        ? 'border-caution/40 bg-caution/10'
        : 'border-critical/40 bg-critical/10'

    return (
      <p className={`flex items-start gap-sm rounded-sm border p-md text-body ${tone}`} role="alert">
        <Icon
          name={failure.kind === 'conflict' ? 'warning' : 'error'}
          size="sm"
          className={failure.kind === 'conflict' ? 'mt-xs text-caution' : 'mt-xs text-critical'}
        />
        <span>
          {failure.displayMessage}
          {failure.kind === 'conflict' && (
            <span className="mt-xs block text-label text-on-surface-muted">
              Nothing was changed. This is a rule, not a fault — retrying will refuse again.
            </span>
          )}
        </span>
      </p>
    )
  }

  if (operation.success) {
    return (
      <p
        className="flex items-start gap-sm rounded-sm border border-positive/40 bg-positive/10 p-md text-body"
        role="status"
      >
        <Icon name="success" size="sm" className="mt-xs text-positive" />
        {operation.success}
      </p>
    )
  }

  return null
}

type ButtonTone = 'primary' | 'neutral' | 'destructive'

const BUTTON_TONE: Record<ButtonTone, string> = {
  primary: 'bg-primary text-on-primary font-semibold',
  neutral: 'border border-outline',
  destructive: 'bg-critical text-on-critical font-semibold',
}

export function ActionButton({
  label,
  onClick,
  tone = 'neutral',
  busy,
  disabled,
  icon,
  title,
}: {
  label: string
  onClick: () => void
  tone?: ButtonTone
  busy?: boolean
  disabled?: boolean
  icon?: Parameters<typeof Icon>[0]['name']
  /** Why it is unavailable. A disabled control with no explanation is a bug. */
  title?: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={busy || disabled}
      title={title}
      className={`flex h-[var(--size-control)] items-center gap-sm rounded-sm px-lg text-body disabled:opacity-50 ${BUTTON_TONE[tone]}`}
    >
      {icon && <Icon name={icon} size="sm" />}
      {busy ? 'Working…' : label}
    </button>
  )
}

/**
 * A confirmation, for anything that is hard to take back.
 *
 * `reason` turns it into a form: several backend transitions require a written
 * reason, and asking for it in the same dialog that asks "are you sure" is one
 * decision rather than two.
 */
export function ConfirmDialog({
  open,
  title,
  body,
  confirmLabel,
  tone = 'primary',
  reason,
  operation,
  onConfirm,
  onClose,
}: {
  open: boolean
  title: string
  body: ReactNode
  confirmLabel: string
  tone?: ButtonTone
  /** Present when the backend requires a written reason for this transition. */
  reason?: { label: string; hint?: string; field: string; minLength: number }
  operation: OperationState & { fieldError: (field: string) => string | undefined }
  onConfirm: (reason: string) => void
  onClose: () => void
}) {
  const headingId = useId()
  const reasonId = useId()
  const [text, setText] = useState('')

  if (!open) return null

  const tooShort = reason ? text.trim().length < reason.minLength : false
  const serverFieldError = reason ? operation.fieldError(reason.field) : undefined

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={headingId}
        className="w-full max-w-lg rounded-md border border-outline bg-surface p-xl"
      >
        <h2 id={headingId} className="text-title-lg font-semibold">
          {title}
        </h2>
        <div className="mt-md text-body text-on-surface-muted">{body}</div>

        {reason && (
          <label className="mt-lg block">
            <span className="text-label font-medium text-on-surface-muted uppercase">{reason.label}</span>
            <textarea
              id={reasonId}
              value={text}
              onChange={(event) => setText(event.target.value)}
              rows={3}
              aria-invalid={Boolean(serverFieldError)}
              className="mt-xs w-full rounded-sm border border-outline bg-surface p-md text-body"
            />
            {serverFieldError ? (
              <span className="mt-xs block text-label text-critical">{serverFieldError}</span>
            ) : (
              reason.hint && <span className="mt-xs block text-label text-on-surface-muted">{reason.hint}</span>
            )}
          </label>
        )}

        <div className="mt-lg">
          <OperationResult operation={operation} />
        </div>

        <div className="mt-lg flex justify-end gap-sm">
          <ActionButton label="Cancel" onClick={onClose} disabled={operation.isPending} />
          <ActionButton
            label={confirmLabel}
            tone={tone}
            busy={operation.isPending}
            disabled={tooShort}
            title={tooShort ? `At least ${reason?.minLength} characters are required.` : undefined}
            onClick={() => onConfirm(text.trim())}
          />
        </div>
      </div>
    </div>
  )
}
