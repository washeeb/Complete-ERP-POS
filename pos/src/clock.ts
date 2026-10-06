import { v7 as uuidv7 } from 'uuid'
import { api, ApiError } from './api'
import { enqueue, offlineSignIn } from './offline'

export type Clocked = { name: string; kind: 'in' | 'out'; at: string; offline: boolean }

/**
 * Clock in or out with username and PIN, without signing in (owner decision 2026-10-14). Online, the server checks
 * the PIN. Offline, the PIN is checked against the person's offline sign-in on this till and the event is queued with
 * it, to be sent when the server is back — so it only works for people who have signed in here before.
 */
export async function clock(username: string, pin: string, kind: 'in' | 'out'): Promise<Clocked> {
  const id = uuidv7()
  const at = new Date().toISOString()
  try {
    const r = await api.clock(id, username.trim(), pin, kind)
    return { name: r.name, kind, at: r.at, offline: false }
  } catch (err) {
    if (!(err instanceof ApiError && err.code === 'network')) throw err
    const g = await offlineSignIn(username, pin)
    await enqueue({ id, type: 'clock', grant: g, payload: { kind, occurred_at: at } })
    return { name: g.user_name, kind, at, offline: true }
  }
}
