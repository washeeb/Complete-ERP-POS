import { registerSW } from 'virtual:pwa-register'

/**
 * Till updates. The till keeps its own copy of the program so it works offline; a new version arrives with each server
 * update but only takes over when every till window is closed, which people forget. Now the till looks for a new
 * version every 30 minutes and switches to it at the sign-in screen (nobody is in the middle of a sale there).
 */
let apply: ((reload?: boolean) => Promise<void>) | null = null
let waiting = false

const CHECK_EVERY_MS = 30 * 60_000
export const UPDATE_READY = 'till-update-ready'

export function startUpdates(): void {
  if (!('serviceWorker' in navigator)) return
  apply = registerSW({
    immediate: true,
    onNeedRefresh() {
      waiting = true
      window.dispatchEvent(new Event(UPDATE_READY))
    },
    onRegisteredSW(_url, reg) {
      if (reg) setInterval(() => { reg.update().catch(() => undefined) }, CHECK_EVERY_MS)
    },
  })
}

/** Switches to the new version (the page reloads) when one is waiting; false when there is nothing to do. */
export async function applyUpdateIfWaiting(): Promise<boolean> {
  if (!waiting || !apply) return false
  await apply(true)
  return true
}
