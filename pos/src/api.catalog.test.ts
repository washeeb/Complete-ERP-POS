import { afterEach, describe, expect, it, vi } from 'vitest'

// The till's own storage is not what is tested here: the session it reads is stubbed.
vi.mock('./db', () => ({ db: { kv: { delete: vi.fn() } }, getDevice: vi.fn(async () => null), getSession: vi.fn(async () => ({ token: 't' })) }))
vi.mock('./clientInfo', () => ({ clientHeader: vi.fn(async () => null) }))

import { api, ApiError, CATALOG_WAIT_MS } from './api'

/** A server that never finishes answering: the request waits until it is called off. */
function stalledFetch() {
  return vi.fn((_url: string, init?: RequestInit) => new Promise<Response>((_, reject) => {
    init?.signal?.addEventListener('abort', () => reject(new DOMException('The operation was aborted.', 'AbortError')))
  }))
}

describe('catalog download (owner 2026-11-19: a supermarket till stayed on "Starting the till…")', () => {
  const original = AbortSignal.timeout
  afterEach(() => { AbortSignal.timeout = original; vi.useRealTimers(); vi.unstubAllGlobals() })

  it('gives up after its time limit instead of waiting for good, so the till can open from its saved copy', async () => {
    vi.useFakeTimers()
    ;(AbortSignal as { timeout?: unknown }).timeout = undefined // the timer path, as on an old WebView2
    vi.stubGlobal('fetch', stalledFetch())
    const download = api.catalog('v1')
    const outcome = download.then(() => null, (e: unknown) => e)
    await vi.advanceTimersByTimeAsync(CATALOG_WAIT_MS)
    const e = await outcome
    expect(e).toBeInstanceOf(ApiError)
    expect((e as ApiError).code).toBe('network') // the code the till falls back to its cached catalog on
    expect((e as ApiError).detail).toBe('catalog_timeout')
  })

  it('still returns null when nothing changed, and a refusal from the server as its own error', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 304 })))
    expect(await api.catalog('v1')).toBeNull()
    vi.stubGlobal('fetch', vi.fn(async () => new Response('not json', { status: 500 })))
    const e = await api.catalog('v1').then(() => null, (x: unknown) => x)
    expect(e).toBeInstanceOf(ApiError)
    expect((e as ApiError).code).toBe('server')
    expect((e as ApiError).status).toBe(500)
  })
})
