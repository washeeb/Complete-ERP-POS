import { EscPos } from './escpos'
import { formatMoney } from './money'
import type { Bootstrap } from './types'

/**
 * The slip printed after a drawer movement (cash drop, pay-out, pay-in): what moved, how much, who did it, who
 * approved it, and lines for the signatures. One slip for the safe bag or the expense file, another for the drawer.
 * The same lines make the ESC/POS print (desktop till) and the print window (browser).
 */
export type DrawerSlip = {
  id: string; kind: 'payout' | 'payin' | 'drop'; amountMinor: number; at: string; cashier: string; approvedBy: string | null
  category?: string | null; payee?: string | null; reason?: string | null; reference?: string | null; queued: boolean
}

export const SLIP_TITLES: Record<DrawerSlip['kind'], string> = { drop: 'CASH DROP', payout: 'PAY-OUT (EXPENSE)', payin: 'PAY-IN' }

/** A short reference to write on the safe bag or the expense receipt: the end of the movement's id. */
export const slipRef = (id: string): string => id.replace(/-/g, '').slice(-8).toUpperCase()

/** The people who sign: the cashier always; the supervisor for a drop or an approved pay-out; the person paid for a pay-out. */
export function slipSignatures(s: DrawerSlip): string[] {
  const who = ['Cashier']
  if (s.kind === 'drop') who.push('Supervisor (took the cash)')
  else if (s.approvedBy) who.push('Supervisor (approved)')
  if (s.kind === 'payout') who.push('Received by')
  return who
}

/** Label / value rows shown under the amount. */
export function slipRows(s: DrawerSlip, boot: Bootstrap): [string, string][] {
  const when = new Date(s.at).toLocaleString('en-GB', { timeZone: boot.company.timezone, dateStyle: 'medium', timeStyle: 'short' })
  const rows: [string, string][] = [['Ref', slipRef(s.id)], ['Date', when], ['Till', `${boot.branch.code} · ${boot.till?.code ?? ''}`]]
  if (s.category) rows.push(['Spent on', s.category])
  if (s.payee) rows.push(['Paid to', s.payee])
  if (s.reference) rows.push(['Receipt no.', s.reference])
  if (s.reason) rows.push([s.kind === 'payout' ? 'For' : s.kind === 'drop' ? 'Note' : 'Reason', s.reason])
  rows.push(['Cashier', s.cashier])
  if (s.approvedBy) rows.push([s.kind === 'drop' ? 'Taken by' : 'Approved by', s.approvedBy])
  return rows
}

export function slipEscPos(s: DrawerSlip, boot: Bootstrap): EscPos {
  const p = new EscPos().align('center').bold(true).line(boot.company.name).bold(false).line(boot.branch.name)
  p.feed().bold(true).double(true).line(SLIP_TITLES[s.kind]).line(formatMoney(s.amountMinor, boot.company.currency, boot.company.exponent)).double(false).bold(false)
  p.align('left').rule()
  for (const [label, value] of slipRows(s, boot)) {
    if (label.length + value.length + 1 > 48) p.line(`${label}:`).line(`  ${value}`) // long text on its own line
    else p.columns(label, value)
  }
  if (s.queued) p.line('Saved on the till offline; sent when the server is back.')
  p.rule()
  for (const who of slipSignatures(s)) p.feed(2).line(`${who}:`).line('Signature ________________________________')
  return p.cut()
}
