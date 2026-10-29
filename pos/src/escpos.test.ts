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

  it('lays two columns out across the 48-character line and maps currency and accents', () => {
    const b = new EscPos().columns('Milo 400g', 'GHS 58.00').text('Café ₵').toBytes()
    const text = ascii(b.slice(5))
    expect(text.split('\n')[0]).toHaveLength(WIDTH)
    expect(text.split('\n')[0].endsWith('GHS 58.00')).toBe(true)
    expect(Array.from(b)).toContain(0x82) // é in PC858
    expect(text).toContain('GHS')
  })

  it('fits 58 mm paper: 32 characters a line', () => {
    const p = new EscPos(32).columns('Paracetamol 500mg strip of 10', 'GHS 12.00').rule()
    const lines = ascii(p.toBytes().slice(5)).split('\n').filter(Boolean)
    expect(p.width).toBe(32)
    expect(lines.every((l) => l.length === 32)).toBe(true)
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
      brand: { powered_by: 'Powered by SES Labs', credit: ['Software by SyncLytics: 024 563 6889', 'synclyticsenterprisesystems.com'] } } as unknown as Bootstrap
    const sale = { id: 's', receipt_no: 'HQ-T01A-000001', occurred_at: '2026-10-09T10:00:00Z', cashier: 'Efua', currency: 'GHS', prices_include_tax: true, net_minor: 9667,
      tax_minor: 1933, total_minor: 11600, paid_minor: 11600, change_minor: 400, tax_breakdown: [{ code: 'VAT', name: 'VAT', rate_bp: 1500, amount_minor: 1450 }],
      lines: [{ line_no: 1, sku: 'MILO', name: 'Milo 400g', uom: 'PC', qty: '2.000', unit_price_minor: 5800, line_total_minor: 11600 }],
      payments: [{ method: 'cash', amount_minor: 11600, tendered_minor: 12000, reference: null }], kind: 'sale', fiscal: { status: 'submitted', code: 'GRA-77', qr: 'https://x/77', mode: 'realtime' },
    } as unknown as PostedSale
    const text = ascii(receiptEscPos(sale, boot).toBytes())
    expect(text).toContain('Demo Ltd')
    expect(text).toContain('Receipt HQ-T01A-000001')
    expect(text).toContain('2 x GHS 58.00')
    expect(text).toContain('TOTAL')
    expect(text).toContain('Change')
    expect(text).toContain('GRA E-VAT GRA-77')
    expect(text).toContain('TIN C0001')
    expect(text).toContain('Software by SyncLytics: 024 563 6889')
    expect(text).toContain('synclyticsenterprisesystems.com')
  })
})
