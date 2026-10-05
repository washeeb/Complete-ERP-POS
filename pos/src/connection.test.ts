import { describe, expect, it } from 'vitest'
import { connectionState, pingServer } from './connection'

describe('connectionState', () => {
  it('reports offline when the device has no network', () => {
    expect(connectionState(false, true)).toBe('offline')
  })
  it('distinguishes a dead server from a dead network', () => {
    expect(connectionState(true, false)).toBe('server-down')
    expect(connectionState(true, true)).toBe('online')
  })
})

describe('pingServer', () => {
  it('treats a thrown fetch as unreachable', async () => {
    const failing = (() => Promise.reject(new Error('net'))) as unknown as typeof fetch
    expect(await pingServer(failing)).toBe(false)
  })
  it('treats a 200 as reachable', async () => {
    const ok = (() => Promise.resolve(new Response('', { status: 200 }))) as unknown as typeof fetch
    expect(await pingServer(ok)).toBe(true)
  })
})
