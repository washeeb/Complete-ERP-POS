/**
 * Unsaved work (owner request 2026-11-26): a page with a save bar tells this module whether it has changes not yet
 * saved. Moving away inside the back office (the menu, a link, Back) then asks first — Save and leave, Leave without
 * saving, or Stay — and closing the tab or reloading gets the browser's own warning.
 *
 * A save is known to have worked when a change sent to the server (not a read) succeeds after the person asked to save
 * (the save bar's buttons, Ctrl+S, or "Save and leave"); the page is then clean again.
 */
export type LeaveGuard = { dirty: () => boolean; save: () => void; saved: () => void }

let guard: LeaveGuard | null = null
let asker: ((leave: () => void) => void) | null = null
let saveAskedAt = 0
let afterSave: (() => void) | null = null

/** The page's guard (one at a time: the page on screen). Returns the function that removes it. */
export function setLeaveGuard(g: LeaveGuard): () => void {
  guard = g
  return () => { if (guard === g) guard = null }
}

/** The box that asks (mounted once by the app). */
export function setLeaveAsker(a: typeof asker) {
  asker = a
}

export function hasUnsaved(): boolean {
  return !!guard?.dirty()
}

/** Before moving away: go on at once when nothing is unsaved, otherwise ask first. */
export function confirmLeave(leave: () => void) {
  if (!hasUnsaved() || !asker) { leave(); return }
  asker(leave)
}

/** The person asked to save (a save bar button, Ctrl+S): the next change the server accepts makes the page clean. */
export function saveAsked() {
  saveAskedAt = Date.now()
}

/** "Save and leave": save, then go on once the server has it (stays on the page, with its message, if it refuses). */
export function saveThenLeave(leave: () => void) {
  if (!guard) { leave(); return }
  afterSave = leave
  saveAsked()
  guard.save()
  setTimeout(() => { if (afterSave === leave) afterSave = null }, 30000) // nothing was sent (e.g. a required field empty)
}

/** Every request the back office sends reports here (api.ts). */
export function requestDone(method: string, ok: boolean) {
  if (method.toUpperCase() === 'GET' || !saveAskedAt || Date.now() - saveAskedAt > 60000) return
  saveAskedAt = 0
  if (!ok) { afterSave = null; return }
  guard?.saved()
  const then = afterSave
  afterSave = null
  if (then) setTimeout(then, 0) // after the page's own reaction to the save (it may open the record first)
}

// Closing the tab, reloading, or leaving for another site: the browser's own question.
if (typeof window !== 'undefined') {
  window.addEventListener('beforeunload', (e) => {
    if (hasUnsaved()) { e.preventDefault(); e.returnValue = '' }
  })
}
