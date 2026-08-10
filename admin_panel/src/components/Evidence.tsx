import { useEffect, useState } from 'react'
import { requestBlob } from '@/api/client'
import { ApiFailure } from '@/api/failure'
import { Icon } from '@/icons/Icon'

/**
 * C25 Evidence.
 *
 * `POST /evidence` returns an **id**, never a URL, and `GET /evidence/{id}`
 * streams the bytes only to somebody the policy admits — with
 * `Content-Disposition: attachment` and `Cache-Control: private, no-store`.
 *
 * So this component does the only correct thing: fetch the bytes through the
 * authenticated client and render them from an object URL that exists in this
 * tab and nowhere else. There is deliberately no `<img src="/api/v1/evidence/…">`
 * — that request would carry no token — and no attempt to construct a public
 * link, because no public link exists.
 */
export function EvidenceImage({ id, alt }: { id: string; alt: string }) {
  const [state, setState] = useState<
    { kind: 'loading' } | { kind: 'ready'; url: string; type: string } | { kind: 'failed'; message: string }
  >({ kind: 'loading' })

  useEffect(() => {
    let objectUrl: string | null = null
    let cancelled = false

    async function load() {
      try {
        const blob = await requestBlob(`/evidence/${id}`)
        if (cancelled) return
        objectUrl = URL.createObjectURL(blob)
        setState({ kind: 'ready', url: objectUrl, type: blob.type })
      } catch (error) {
        if (cancelled) return
        setState({
          kind: 'failed',
          message: error instanceof ApiFailure ? error.displayMessage : 'That file could not be opened.',
        })
      }
    }

    void load()

    return () => {
      cancelled = true
      // Revoked on unmount: an object URL that outlives the screen is a copy
      // of private evidence left lying around in the tab.
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [id])

  if (state.kind === 'loading') {
    return <div className="h-32 w-full animate-pulse rounded-sm bg-surface-sunken" aria-label="Loading evidence" />
  }

  if (state.kind === 'failed') {
    return (
      <div className="grid h-32 place-items-center rounded-sm border border-outline bg-surface-sunken p-md text-center">
        <div>
          <Icon name="blocked" size="sm" className="text-on-surface-muted" />
          <p className="mt-xs text-label text-on-surface-muted">{state.message}</p>
        </div>
      </div>
    )
  }

  if (!state.type.startsWith('image/')) {
    return (
      <a
        href={state.url}
        download
        className="flex items-center gap-sm rounded-sm border border-outline p-md text-body text-primary"
      >
        <Icon name="document" size="sm" />
        {alt}
      </a>
    )
  }

  return (
    <figure className="overflow-hidden rounded-sm border border-outline">
      <img src={state.url} alt={alt} className="h-32 w-full object-cover" />
    </figure>
  )
}
