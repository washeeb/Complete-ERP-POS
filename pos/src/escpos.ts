import { formatMoney, prettyQty, toMilli } from './money'
import type { Raster } from './brand'
import type { Bootstrap, PostedSale } from './types'
import { sdcLines } from './evat'

/**
 * ESC/POS receipts for 80 mm thermal printers (48 characters a line in font A), used by the desktop till for silent
 * printing. Text is sent as code page PC858 (Latin-1 + €): characters outside it become "?", and GH₵ is written "GHS".
 */
const ESC = 0x1b
const GS = 0x1d
export const WIDTH = 48

export class EscPos {
  private bytes: number[] = [ESC, 0x40, ESC, 0x74, 19] // initialise; code page 19 = PC858

  text(s: string): this {
    for (const ch of s.replace(/GH₵/g, 'GHS').replace(/₵/g, 'GHS').normalize('NFC')) {
      const c = ch.codePointAt(0)!
      this.bytes.push(c < 0x80 ? c : (PC858[ch] ?? 0x3f))
    }
    return this
  }

  line(s = ''): this { return this.text(s).feed() }
  feed(n = 1): this { for (let i = 0; i < n; i++) this.bytes.push(0x0a); return this }
  align(a: 'left' | 'center' | 'right'): this { this.bytes.push(ESC, 0x61, { left: 0, center: 1, right: 2 }[a]); return this }
  bold(on: boolean): this { this.bytes.push(ESC, 0x45, on ? 1 : 0); return this }
  double(on: boolean): this { this.bytes.push(GS, 0x21, on ? 0x11 : 0); return this }

  /** Two columns on one line: left text, right text (left is cut if needed). */
  columns(left: string, right: string, width = WIDTH): this {
    const room = Math.max(1, width - right.length - 1)
    const l = left.length > room ? left.slice(0, room) : left
    return this.line(l + ' '.repeat(width - l.length - right.length) + right)
  }

  rule(): this { return this.line('-'.repeat(WIDTH)) }

  /** QR code (model 2, error correction M), drawn by the printer. */
  qr(data: string, size = 6): this {
    const d = Array.from(new TextEncoder().encode(data))
    const len = d.length + 3
    this.bytes.push(GS, 0x28, 0x6b, 4, 0, 0x31, 0x41, 0x32, 0x00) // model 2
    this.bytes.push(GS, 0x28, 0x6b, 3, 0, 0x31, 0x43, size) // module size
    this.bytes.push(GS, 0x28, 0x6b, 3, 0, 0x31, 0x45, 0x31) // error correction M
    this.bytes.push(GS, 0x28, 0x6b, len & 0xff, len >> 8, 0x31, 0x50, 0x30, ...d) // store
    this.bytes.push(GS, 0x28, 0x6b, 3, 0, 0x31, 0x51, 0x30) // print
    return this
  }

  /** A black-and-white image (GS v 0, raster), e.g. the company logo. */
  image(r: { width: number; height: number; bytesPerRow: number; data: Uint8Array }): this {
    this.bytes.push(GS, 0x76, 0x30, 0, r.bytesPerRow & 0xff, r.bytesPerRow >> 8, r.height & 0xff, r.height >> 8)
    for (const b of r.data) this.bytes.push(b)
    return this
  }

  cut(): this { this.feed(4); this.bytes.push(GS, 0x56, 0x01); return this }

  toBytes(): Uint8Array { return Uint8Array.from(this.bytes) }

  toBase64(): string {
    let s = ''
    for (const b of this.bytes) s += String.fromCharCode(b)
    return btoa(s)
  }
}

// The accented letters a receipt may need, in PC858.
const PC858: Record<string, number> = {
  'é': 0x82, 'è': 0x8a, 'ê': 0x88, 'ë': 0x89, 'à': 0x85, 'â': 0x83, 'ä': 0x84, 'ç': 0x87, 'î': 0x8c, 'ï': 0x8b, 'ô': 0x93, 'ö': 0x94, 'ù': 0x97, 'û': 0x96, 'ü': 0x81,
  'É': 0x90, 'Ç': 0x80, 'Ö': 0x99, 'Ü': 0x9a, 'ñ': 0xa4, 'Ñ': 0xa5, '£': 0x9c, '€': 0xd5, '°': 0xf8, '×': 0x9e, '−': 0x2d, '–': 0x2d, '—': 0x2d, '’': 0x27, '‘': 0x27, '“': 0x22, '”': 0x22, '…': 0x2e,
}

const PLAIN_UNITS = new Set(['PC', 'EA', 'PCS'])

/** The receipt as the printed (browser) one, in ESC/POS. */
export function receiptEscPos(sale: PostedSale, boot: Bootstrap, logo: Raster | null = null): EscPos {
  const m = (v: number) => formatMoney(v, sale.currency, boot.company.exponent)
  const when = new Date(sale.occurred_at).toLocaleString('en-GB', { timeZone: boot.company.timezone, dateStyle: 'medium', timeStyle: 'short' })
  const p = new EscPos().align('center')
  if (logo) p.image(logo).feed()
  p.bold(true).double(true).line(boot.company.name).double(false).bold(false)
  p.line(boot.branch.name + (boot.branch.phone ? ` · Tel ${boot.branch.phone}` : ''))
  if (boot.company.tin || boot.company.vat_no) p.line([boot.company.tin && `TIN ${boot.company.tin}`, boot.company.vat_no && `VAT ${boot.company.vat_no}`].filter(Boolean).join(' '))
  p.bold(true).line(`${sale.kind === 'return' ? 'RETURN' : 'Receipt'} ${sale.receipt_no}`).bold(false).line(`${when} · ${sale.cashier}`)
  if (sale.order_number) p.bold(true).line(`Order ${sale.order_number.slice(-3)}`).bold(false)
  if (sale.walk_in?.name) p.line(`Customer: ${sale.walk_in.name}`)
  p.align('left').rule()
  for (const l of sale.lines) {
    const unit = PLAIN_UNITS.has(l.uom) ? '' : ` ${l.uom}`
    p.line(l.name)
    p.columns(`  ${prettyQty(toMilli(l.qty))}${unit} x ${m(l.unit_price_minor)}`, m(l.line_total_minor))
    for (const pr of l.promotions ?? []) p.columns(`  ${pr.name}`, `-${m(pr.saving_minor)}`)
    if (l.note) p.line(`  ${l.note}`)
    if (l.dosage) p.line(`  Dosage: ${l.dosage}`)
    if (l.serials?.length) p.line(`  S/N ${l.serials.join(', ')}`)
  }
  if (sale.comment) p.rule().line(sale.comment)
  p.rule()
  if ((sale.discount_minor ?? 0) > 0) p.columns('You saved', m(sale.discount_minor ?? 0))
  p.bold(true).columns(sale.kind === 'return' ? 'REFUND' : 'TOTAL', m(sale.total_minor)).bold(false)
  if ((sale.tip_minor ?? 0) > 0) p.columns('Tip — thank you', m(sale.tip_minor ?? 0))
  for (const pay of sale.payments) p.columns(pay.method + (pay.reference ? ` ${pay.reference}` : ''), m(pay.tendered_minor))
  if (sale.change_minor > 0) p.columns('Change', m(sale.change_minor))
  p.rule().line(sale.prices_include_tax ? 'Prices include tax.' : 'Prices exclude tax.').columns('Before tax', m(sale.net_minor))
  for (const t of sale.tax_breakdown) p.columns(`${t.name} ${(t.rate_bp / 100).toFixed(2)}%`, m(t.amount_minor))
  p.align('center')
  if (sale.fiscal?.code) {
    p.feed().line(`GRA E-VAT ${sale.fiscal.code}`)
    for (const l of sdcLines(sale.fiscal.sdc)) p.line(l)
    if (sale.fiscal.qr) p.qr(sale.fiscal.qr, 5).feed()
  }
  p.feed().qr(sale.receipt_no).feed().line(boot.receipt_footer)
  if (boot.license?.ref) p.line(`Ref ${boot.license.ref}`)
  if (boot.brand?.powered_by) p.line(boot.brand.powered_by)
  for (const line of boot.brand?.credit ?? []) p.line(line) // the software maker's contact
  return p.cut()
}
