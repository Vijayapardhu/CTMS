import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './design/tokens.css'
import { App } from './App'
import { config } from './config/env'
import { logger } from './app/logger'

logger.info('Starting', { environment: config.environment, api: config.apiBaseUrl })

if (config.isMisconfigured) {
  // Not fatal — the panel still runs, and refusing to start would be a worse
  // failure than one that says exactly what is wrong.
  logger.error(
    'Production build is pointing at the development API. Set VITE_CTMS_API_BASE_URL when building for release.',
  )
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
