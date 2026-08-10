export type Environment = 'development' | 'staging' | 'production'

/**
 * Build-time configuration.
 *
 * Read from Vite env rather than a bundled file, so a release build cannot
 * ship pointing at a developer's laptop and no secret enters the repository.
 * The two values here are both non-secret by design: an API URL, and a Maps
 * browser key that is restricted by HTTP referrer at Google's end.
 */
export type AppConfig = {
  environment: Environment
  apiBaseUrl: string
  mapsBrowserKey: string
  isProduction: boolean
  /** A production build that was never told where the server is. */
  isMisconfigured: boolean
  apiHost: string
}

/**
 * Relative by default, so the browser talks only to its own origin and the
 * dev server proxies `/api` to the backend. The backend has no CORS layer,
 * which is fine for the native driver app and fatal for a browser on another
 * origin.
 */
const DEVELOPMENT_API = '/api/v1'

function readEnvironment(raw: string | undefined): Environment {
  switch (raw) {
    case 'production':
      return 'production'
    // `demo` is the same build as staging under the name the people asking
    // for it use. One fewer thing to remember on the morning of a demo.
    case 'staging':
    case 'demo':
      return 'staging'
    default:
      return 'development'
  }
}

export function readConfig(env: ImportMetaEnv = import.meta.env): AppConfig {
  const environment = readEnvironment(env.VITE_CTMS_ENV)
  const apiBaseUrl = (env.VITE_CTMS_API_BASE_URL || DEVELOPMENT_API).replace(/\/$/, '')
  const isProduction = environment === 'production'

  let apiHost = apiBaseUrl
  try {
    // A relative base resolves against this origin — the proxied case.
    apiHost = new URL(apiBaseUrl, globalThis.location?.origin ?? 'http://localhost').host
  } catch {
    // Left as the raw string. A malformed URL is worth showing in full.
  }

  return {
    environment,
    apiBaseUrl,
    mapsBrowserKey: env.VITE_GOOGLE_MAPS_BROWSER_KEY || '',
    isProduction,
    isMisconfigured: isProduction && apiBaseUrl === DEVELOPMENT_API,
    apiHost,
  }
}

export const config = readConfig()
