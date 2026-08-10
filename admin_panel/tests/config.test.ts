import { describe, expect, it } from 'vitest'
import { readConfig } from '@/config/env'

const base = { VITE_CTMS_API_BASE_URL: '', VITE_CTMS_ENV: '', VITE_GOOGLE_MAPS_API_KEY: '' }

describe('build configuration', () => {
  it('defaults to development against the local API', () => {
    const config = readConfig({ ...base } as unknown as ImportMetaEnv)

    expect(config.environment).toBe('development')
    expect(config.isProduction).toBe(false)
    expect(config.isMisconfigured).toBe(false)
  })

  it('treats demo as staging, under the name people ask for it by', () => {
    const config = readConfig({ ...base, VITE_CTMS_ENV: 'demo' } as unknown as ImportMetaEnv)

    expect(config.environment).toBe('staging')
  })

  it('flags a production build still pointing at a developer laptop', () => {
    const config = readConfig({ ...base, VITE_CTMS_ENV: 'production' } as unknown as ImportMetaEnv)

    expect(config.isMisconfigured).toBe(true)
  })

  it('is not misconfigured once given a real server', () => {
    const config = readConfig({
      ...base,
      VITE_CTMS_ENV: 'production',
      VITE_CTMS_API_BASE_URL: 'https://ctms.example.edu/api/v1/',
    } as unknown as ImportMetaEnv)

    expect(config.isMisconfigured).toBe(false)
    expect(config.apiBaseUrl).toBe('https://ctms.example.edu/api/v1')
    expect(config.apiHost).toBe('ctms.example.edu')
  })
})
