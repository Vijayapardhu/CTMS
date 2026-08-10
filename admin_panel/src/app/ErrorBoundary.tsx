import { Component, type ErrorInfo, type ReactNode } from 'react'
import { logger } from './logger'

type Props = { children: ReactNode }
type State = { failed: boolean }

/**
 * Last line of defence.
 *
 * A render that throws must not leave a blank white page — that is
 * indistinguishable from a network failure, and an operator will refresh for a
 * minute before telling anyone.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { failed: false }

  static getDerivedStateFromError(): State {
    return { failed: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    logger.error('Unhandled render error', { error, componentStack: info.componentStack })
  }

  render() {
    if (!this.state.failed) return this.props.children

    return (
      <div className="grid h-full place-items-center p-xxl text-center">
        <div>
          <h1 className="text-title-lg font-semibold">Something in the panel stopped working</h1>
          <p className="mt-sm text-body text-on-surface-muted">
            Nothing you were looking at was changed. Reloading usually clears it.
          </p>
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="mt-lg h-[var(--size-control)] rounded-sm bg-primary px-lg text-body font-semibold text-on-primary"
          >
            Reload
          </button>
        </div>
      </div>
    )
  }
}
