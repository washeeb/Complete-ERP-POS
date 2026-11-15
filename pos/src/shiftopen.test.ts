import { beforeEach, describe, expect, it, vi } from 'vitest'

// Owner report 2026-11-15 (SUPBOG): Open shift pressed again while the first press was on its way on a slow line. The
// server accepted the first shift; the till queued a second one and sold under it, and the server refused all 146 sales.
// One open at a time: a second call waits for the first.

const kv = new Map<string, Record<string, unknown>>()
const queue: unknown[] = []
vi.mock('./db', () => ({
  db: { kv: { put: async (v: Record<string, unknown>) => { kv.set(v.id as string, v) }, delete: async (k: string) => { kv.delete(k) } },
    queue: { count: async () => queue.length, filter: () => ({ toArray: async () => [] }) } },
  getSession: async () => ({ id: 'session', token: 't', userId: 'u1', userName: 'Bismark' }),
  getLocalShift: async () => kv.get('shift'),
}))
vi.mock('./offline', () => ({ enqueue: async (item: unknown) => { queue.push(item) }, grantFor: async () => ({ id: 'g' }), saveGrant: async () => undefined }))
const opened: string[] = []
vi.mock('./api', () => ({
  api: { openShift: (_f: number, id: string) => new Promise((ok) => setTimeout(() => { opened.push(id); ok({}) }, 50)) },
  ApiError: class extends Error { code = ''; status = 0 },
  SIGNED_OUT: 'signed out',
  van: {},
}))

const { openShift } = await import('./till')

describe('opening a shift', () => {
  beforeEach(() => { kv.clear(); queue.length = 0; opened.length = 0 })

  it('opens one shift when Open shift is pressed twice on a slow line', async () => {
    await Promise.all([openShift(0, true), openShift(0, true), openShift(0, true)])
    expect(opened).toHaveLength(1)
    expect(queue).toHaveLength(0)
    expect(kv.get('shift')?.shiftId).toBe(opened[0])
    expect(kv.get('shift')?.queued).toBe(false)
  })

  it('opens again normally once the first open has finished', async () => {
    await openShift(0, true)
    await openShift(0, true)
    expect(opened).toHaveLength(2)
  })
})
