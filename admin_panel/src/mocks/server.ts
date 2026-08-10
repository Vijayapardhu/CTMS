import { setupServer } from 'msw/node'
import { handlers } from './handlers'

/** The Node harness, used by the test suite. */
export const server = setupServer(...handlers)
