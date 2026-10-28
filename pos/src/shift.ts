/**
 * True when the shift was opened on an earlier day than `now` (the till's local calendar) and more than 12 hours ago:
 * a shift left open overnight, whose cash would run into the next day. A night shift past midnight is not flagged.
 */
export function staleShiftSince(openedAt: string | undefined | null, now: Date): boolean {
  if (!openedAt) return false
  const opened = new Date(openedAt)
  if (Number.isNaN(opened.getTime())) return false
  const day = (d: Date) => d.toLocaleDateString('en-CA')
  return day(opened) < day(now) && now.getTime() - opened.getTime() > 12 * 3600_000
}

/**
 * The till's own note of its open shift is out of date when the server, answering, has no open shift for it and the
 * till is not still sending a shift it opened offline: the shift was closed (on another till, in the back office, or
 * by mistake). Then the note is dropped, so the till shows Open shift instead of selling into a closed shift.
 */
export function shiftClosedOnServer(serverAnswered: boolean, serverHasShift: boolean, localShiftId: string | undefined, pendingOpenIds: string[]): boolean {
  return serverAnswered && !serverHasShift && !!localShiftId && !pendingOpenIds.includes(localShiftId)
}
