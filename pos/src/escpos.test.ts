import { describe, expect, it } from 'vitest'
import { EscPos, WIDTH, receiptEscPos } from './escpos'
import type { Bootstrap, PostedSale } from './types'

const ascii = (b: Uint8Array) => Array.from(b).map((c) => (c >= 0x20 && c < 0x7f ? String.fromCharCode(c) : c === 0x0a ? '\n' : '·')).join('')

describe('ESC/POS receipts', () => {
  it('starts with initialise and the PC858 code page, ends with a cut', () => {
    const b = new EscPos().line('Hi').cut().toBytes()
    expect(Array.from(b.slice(0, 5))).toEqual([0x1b, 0x40, 0x1b, 0x74, 19])
    expect(Array.from(b.slice(-3))).toEqual([0x1d, 0x56, 0x01])
  })

  it('lays two columns out across the line, one character short of full, and maps currency and accents', () => {
    const b = new EscPos().columns('Milo 400g', 'GHS 58.00').text('Café ₵').toBytes()
    const text = ascii(b.slice(5))
    expect(text.split('\n')[0]).toHaveLength(WIDTH - 1) // a full line wraps on some printers (owner's photo 2026-11-28)
    expect(text.split('\n')[0].endsWith('GHS 58.00')).toBe(true)
    expect(Array.from(b)).toContain(0x82) // é in PC858
    expect(text).toContain('GHS')
  })

  it('prints the middle dot, not a question mark', () => {
    const b = Array.from(new EscPos().text('ZionEX · Powered by SES Labs').toBytes().slice(5))
    expect(b).toContain(0xfa) // · in PC858
    expect(b).not.toContain(0x3f) // no "?"
  })

  it('fits 58 mm paper: 32 characters a line, 31 used', () => {
    const p = new EscPos(32).columns('Paracetamol 500mg strip of 10', 'GHS 12.00').rule()
    const lines = ascii(p.toBytes().slice(5)).split('\n').filter(Boolean)
    expect(p.width).toBe(32)
    expect(lines.every((l) => l.length === 31)).toBe(true)
    expect(lines[0].endsWith('GHS 12.00')).toBe(true)
  })

  it('stores and prints a QR code with the right length bytes', () => {
    const b = Array.from(new EscPos().qr('HQ-T01A-000001').toBytes())
    const store = b.findIndex((_, i) => b[i] === 0x1d && b[i + 1] === 0x28 && b[i + 2] === 0x6b && b[i + 5] === 0x31 && b[i + 6] === 0x50)
    expect(b[store + 3] + (b[store + 4] << 8)).toBe('HQ-T01A-000001'.length + 3)
  })

  it('prints a sale like the screen receipt, with the GRA code when there is one', () => {
    const boot = { company: { name: 'Demo Ltd', exponent: 2, timezone: 'Africa/Accra', tin: 'C0001', vat_no: null, legal_name: null }, branch: { name: 'Head Office', address: null, phone: '0302000000' },
      receipt_footer: 'Thank you', license: { ref: 'L-1' },
      brand: { powered_by: 'ZionEX · Powered by SES Labs', credit: ['024 563 6889 · synclyticsenterprisesystems.com'] } } as unknown as Bootstrap
    const sale = { id: 's', receipt_no: 'HQ-T01A-000001', occurred_at: '2026-10-09T10:00:00Z', cashier: 'Efua', currency: 'GHS', prices_include_tax: true, net_minor: 9667,
      tax_minor: 1933, total_minor: 11600, paid_minor: 11600, change_minor: 400, tax_breakdown: [{ code: 'VAT', name: 'VAT', rate_bp: 1500, amount_minor: 1450 }],
      lines: [{ line_no: 1, sku: 'MILO', name: 'Milo 400g', uom: 'PC', qty: '2.000', unit_price_minor: 5800, line_total_minor: 11600 }],
      payments: [{ method: 'cash', amount_minor: 11600, tendered_minor: 12000, reference: null }], kind: 'sale', fiscal: { status: 'submitted', code: 'GRA-77', qr: 'https://x/77', mode: 'realtime' },
    } as unknown as PostedSale
    const text = ascii(receiptEscPos(sale, boot).toBytes())
    expect(text).toContain('Demo Ltd')
    expect(text).toContain('Receipt HQ-T01A-000001')
    expect(text).toMatch(/SN Item +Qty +Price +Total/) // the screen receipt's columns
    expect(text).toContain('Amounts in GHS')
    expect(text).toMatch(/1  Milo 400g +2 +58\.00 +116\.00/)
    expect(text).toContain('TOTAL')
    expect(text).toContain('Change')
    expect(text).toContain('GRA E-VAT GRA-77')
    expect(text).toContain('TIN C0001')
    // The maker's number and website on one line, just above "Powered by" (owner 2026-11-09), no "Software by".
    expect(text).not.toContain('Software by')
    expect(text.indexOf('024 563 6889')).toBeGreaterThan(-1)
    expect(text.indexOf('synclyticsenterprisesystems.com')).toBeLessThan(text.indexOf('Powered by'))
  })

  // Owner's photo (2026-11-28): the desktop till's printout had each amount on the line below its label, and the
  // company's name broken in the middle of a word. The printout follows the screen receipt's layout.
  const shop = { company: { name: 'OheneBa Plaza Supermarket', exponent: 2, timezone: 'Africa/Accra', tin: '0123456789', vat_no: null }, branch: { name: 'Supermarket Bogre', phone: '0539099947' },
    receipt_footer: 'Goods sold are not returnable.', brand: null } as unknown as Bootstrap
  const sale = (lines: object[], extra: object = {}) => ({ id: 's', receipt_no: 'SUPBOG-T01D-000342', occurred_at: '2026-11-14T14:50:00Z', cashier: 'Prince Addrey', currency: 'GHS',
    prices_include_tax: true, net_minor: 2000, tax_minor: 400, total_minor: 2400, paid_minor: 2400, change_minor: 2600, kind: 'sale',
    tax_breakdown: [{ name: 'VAT', rate_bp: 1500, amount_minor: 300 }, { name: 'NHIL', rate_bp: 250, amount_minor: 50 }],
    payments: [{ method: 'cash', amount_minor: 2400, tendered_minor: 5000, reference: null }], lines, ...extra }) as unknown as PostedSale
  const printed = (b: Uint8Array) => {
    const out: string[] = []
    let cur = ''
    for (let i = 5; i < b.length; i++) {
      if (b[i] === 0x1b) { i += 2; continue }
      if (b[i] === 0x1d) { if (b[i + 1] === 0x21) { i += 2; continue } break } // QR and cut: the text is over
      if (b[i] === 0x0a) { out.push(cur); cur = ''; continue }
      cur += String.fromCharCode(b[i])
    }
    return out
  }

  for (const width of [48, 32]) {
    it(`never fills a line on ${width === 48 ? '80' : '58'} mm paper, and keeps each amount beside its label`, () => {
      const lines = printed(receiptEscPos(sale([{ line_no: 1, sku: 'C', name: 'POTATO CRACKERS - 110G', uom: 'PC', qty: '2.000', unit_price_minor: 1200, line_total_minor: 2400 },
        { line_no: 2, sku: 'L', name: 'GOLDEN TREE MILK CHOCOLATE BAR WITH ROASTED PEANUTS 100G', uom: 'CTN', qty: '12.000', unit_price_minor: 123450, line_total_minor: 99914814000 }]), shop, null, width).toBytes())
      expect(lines.filter((l) => l.length >= width)).toEqual([])
      for (const label of ['TOTAL', 'cash', 'Change', 'Before tax', 'VAT 15.00%', 'NHIL 2.50%']) {
        expect(lines.find((l) => l.startsWith(label)), label).toMatch(/GHS [\d,]+\.\d\d$/)
      }
    })
  }

  it('prints a long company name whole, in bold, broken between words; a short one large', () => {
    const long = printed(receiptEscPos(sale([]), shop).toBytes())
    expect(long[0]).toBe('OheneBa Plaza Supermarket') // 25 characters: too wide at double size (24), so normal size
    const b = receiptEscPos(sale([]), { ...shop, company: { ...shop.company, name: 'Demo Ltd' } } as unknown as Bootstrap).toBytes()
    expect(Array.from(b)).toContain(0x11) // GS ! 0x11: double width and height
  })

  it('puts a unit too wide for the Qty column with the name, and very large amounts under the name', () => {
    const lines = printed(receiptEscPos(sale([{ line_no: 1, sku: 'L', name: 'SUGAR 1KG', uom: 'CTN', qty: '12.000', unit_price_minor: 123450, line_total_minor: 1481400 },
      { line_no: 2, sku: 'B', name: 'BIG ORDER', uom: 'PC', qty: '1.000', unit_price_minor: 99914814000, line_total_minor: 99914814000 }]), shop).toBytes())
    expect(lines.some((l) => /^1  SUGAR 1KG \(CTN\) +12 +1,234\.50 +14,814\.00$/.test(l))).toBe(true)
    expect(lines).toContain('BIG ORDER')
    expect(lines.some((l) => /^  1 x GHS 999,148,140\.00 +GHS 999,148,140\.00$/.test(l))).toBe(true)
  })
})
