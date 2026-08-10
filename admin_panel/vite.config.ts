import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { '@': path.resolve(__dirname, './src') },
  },
  // The CTMS backend has no CORS layer — the driver app is a native client and
  // never needed one. A browser SPA on another origin therefore cannot call it
  // directly, so development and preview both proxy `/api` to the backend and
  // the panel only ever talks to its own origin. See G2-3 in the gap register:
  // deploying the panel on a separate origin needs a decision on the backend
  // side, and this proxy is not that decision.
  server: {
    port: 5174,
    proxy: { '/api': { target: process.env.CTMS_BACKEND ?? 'http://127.0.0.1:8000', changeOrigin: true } },
  },
  preview: {
    port: 5175,
    proxy: { '/api': { target: process.env.CTMS_BACKEND ?? 'http://127.0.0.1:8000', changeOrigin: true } },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/setup.ts'],
    css: false,
    // The client's base is relative and resolves against the page origin, so
    // the test origin IS the API origin. Every MSW handler is written against
    // this host.
    environmentOptions: { jsdom: { url: 'http://localhost:8000/' } },
  },
})
