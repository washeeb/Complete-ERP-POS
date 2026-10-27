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
