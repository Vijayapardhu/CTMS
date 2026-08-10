import { useState, type FormEvent } from 'react'
import { Icon } from '@/icons/Icon'
import { config } from '@/config/env'
import { useSession } from './SessionProvider'

/** The one screen reachable without a session. */
export function LoginScreen() {
  const { signIn, signInFailure, signingIn } = useSession()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)

  const submit = (event: FormEvent) => {
    event.preventDefault()
    void signIn(email, password)
  }

  return (
    <div className="grid h-full place-items-center bg-surface-sunken p-xl">
      <form
        onSubmit={submit}
        className="w-full max-w-[380px] rounded-md border border-outline bg-surface p-xl"
        aria-labelledby="login-heading"
      >
        <div className="mb-lg flex items-center gap-sm">
          <Icon name="buses" size="lg" className="text-primary" />
          <div>
            <h1 id="login-heading" className="text-title-lg font-semibold">
              CTMS
            </h1>
            <p className="text-label text-on-surface-muted">Transport Operations</p>
          </div>
        </div>

        {signInFailure && (
          <div
            role="alert"
            className="mb-lg flex items-start gap-sm rounded-sm border border-critical/40 bg-critical/10 p-md text-body text-critical"
          >
            <Icon name="error" size="sm" />
            <span>{signInFailure}</span>
          </div>
        )}

        <label className="mb-md block">
          <span className="mb-xs block text-label font-medium">Email</span>
          <input
            type="email"
            required
            autoComplete="username"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            className="h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>

        <label className="mb-lg block">
          <span className="mb-xs block text-label font-medium">Password</span>
          <div className="flex gap-sm">
            <input
              type={showPassword ? 'text' : 'password'}
              required
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              className="h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
            />
            <button
              type="button"
              onClick={() => setShowPassword((value) => !value)}
              // Somebody who cannot see what they typed retries until the
              // throttle locks them out.
              title={showPassword ? 'Hide password' : 'Show password'}
              aria-label={showPassword ? 'Hide password' : 'Show password'}
              className="grid size-9 shrink-0 place-items-center rounded-sm border border-outline"
            >
              <Icon name="accessLog" size="sm" />
            </button>
          </div>
        </label>

        <button
          type="submit"
          disabled={signingIn}
          className="h-[var(--size-control)] w-full rounded-sm bg-primary text-body font-semibold text-on-primary disabled:opacity-60"
        >
          {signingIn ? 'Signing in…' : 'Sign in'}
        </button>

        {!config.isProduction && (
          <p className="mt-lg text-center font-mono text-label text-on-surface-muted">
            {config.environment} · {config.apiHost}
          </p>
        )}
      </form>
    </div>
  )
}
