import { config } from '@/config/env'

/**
 * Logging, kept deliberately small.
 *
 * Verbose off production only. Nothing here ever logs a request body: the
 * driver app learned that lesson, and an admin request body can carry a
 * student's details.
 */
export const logger = {
  info(message: string, context?: Record<string, unknown>) {
    if (config.isProduction) return
    console.info(`[ctms] ${message}`, context ?? '')
  },
  warn(message: string, context?: Record<string, unknown>) {
    console.warn(`[ctms] ${message}`, context ?? '')
  },
  error(message: string, error?: unknown) {
    console.error(`[ctms] ${message}`, error ?? '')
  },
}
