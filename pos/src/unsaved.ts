import { useEffect } from 'react'

/**
 * Work not saved yet (owner request 2026-10-24): a sale being rung up, a delivery being scanned, a count being typed.
 * While it is there, reloading or closing the page asks first (the browser's own "Leave site?"), and the till's own
 * buttons that would throw it away (Sign out, Menu, Back) ask with confirmLeave().
 */
export function useLeaveWarning(active: boolean): void {
  useEffect(() => {
    if (!active) return
    const ask = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = true }
    window.addEventListener('beforeunload', ask)
    return () => window.removeEventListener('beforeunload', ask)
  }, [active])
}

/** True when there is nothing to lose, or the person agrees to lose it. */
export function confirmLeave(active: boolean, what = 'what you entered'): boolean {
  return !active || window.confirm(`You have not saved ${what}. Leave anyway? It will be lost.`)
}
