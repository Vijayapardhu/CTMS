/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_CTMS_API_BASE_URL?: string
  readonly VITE_CTMS_ENV?: string
  readonly VITE_GOOGLE_MAPS_API_KEY?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
