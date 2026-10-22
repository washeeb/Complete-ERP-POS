import { EscPos } from './escpos'
import { prettyQty, toMilli } from './money'
import type { Bootstrap, PostedSale } from './types'

/**
 * Dosage labels (owner request 2026-10-22): one small label per medicine with directions, stuck on the pack — the
 * pharmacy's name and phone, the patient, the medicine and quantity, the directions, the date and the receipt number.
 * The same rows make the ESC/POS print (desktop till, each label cut on its own) and the print window (browser).
 */
export type DosageLabel = { name: string; qty: string; dosage: string }

/** The lines that carry directions, in the order sold. */
export function dosageLabels(sale: PostedSale): DosageLabel[] {
  return sale.lines.filter((l) => (l.dosage ?? '').trim() !== '')
    .map((l) => ({ name: l.name, qty: `${prettyQty(toMilli(l.qty))} ${l.uom}`, dosage: l.dosage!.trim() }))
}

export function labelDate(sale: PostedSale, boot: Bootstrap): string {
  return new Date(sale.occurred_at).toLocaleDateString('en-GB', { timeZone: boot.company.timezone, day: 'numeric', month: 'short', year: 'numeric' })
}

export function dosageEscPos(sale: PostedSale, boot: Bootstrap): EscPos {
  const p = new EscPos()
  for (const label of dosageLabels(sale)) {
    p.align('center').bold(true).line(boot.company.name).bold(false).line(boot.branch.name + (boot.branch.phone ? ` · Tel ${boot.branch.phone}` : ''))
    p.align('left').rule()
    p.line(`Patient: ${sale.walk_in?.name ?? '______________________'}`)
    p.bold(true).line(label.name).bold(false).line(`Quantity: ${label.qty}`)
    p.feed().bold(true).double(true).line(label.dosage).double(false).bold(false).feed()
    p.columns(labelDate(sale, boot), sale.receipt_no)
    p.align('center').line('KEEP OUT OF REACH OF CHILDREN')
    p.cut()
  }
  return p
}
