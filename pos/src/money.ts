/**
 * Integer money and quantity maths. MUST stay identical to server/app/Support/Qty.php and
 * server/app/Domain/Pricing/TaxCalculator.php — the server rejects a total that differs by a pesewa.
 * Quantities are integer thousandths ("milli"); money is integer minor units. No floats.
 */

export type TaxRate = { code: string; name: string; rate_bp: number }
export type TaxLine = TaxRate & { amount_minor: number }

/** Integer division rounding half away from zero. */
export function divRound(numerator: number, denominator: number): number {
  const q = Math.floor((Math.abs(numerator) * 2 + Math.abs(denominator)) / (2 * Math.abs(denominator)))
  return (numerator < 0) !== (denominator < 0) ? -q : q
}

export function toMilli(qty: string): number {
  const s = qty.trim()
  if (!/^-?\d{1,12}(\.\d{1,3})?$/.test(s)) throw new Error(`Invalid quantity “${qty}”`)
  const negative = s.startsWith('-')
  const [whole, frac = ''] = s.replace('-', '').split('.')
  const milli = Number(whole) * 1000 + Number(frac.padEnd(3, '0'))
  return negative ? -milli : milli
}

export function formatMilli(milli: number): string {
  const sign = milli < 0 ? '-' : ''
  const abs = Math.abs(milli)
  return `${sign}${Math.floor(abs / 1000)}.${String(abs % 1000).padStart(3, '0')}`
}

/** "1.500" → "1.5", "2.000" → "2" for display. */
export function prettyQty(milli: number): string {
  return formatMilli(milli).replace(/\.?0+$/, '')
}

export function factorMicro(factor: string): number {
  const [w, d = ''] = factor.split('.')
  return Number(w) * 1_000_000 + Number(d.slice(0, 6).padEnd(6, '0'))
}

export function lineTotal(qtyMilli: number, unitPriceMinor: number): number {
  return divRound(qtyMilli * unitPriceMinor, 1000)
}

/** Split total across weights so parts sum exactly (largest remainder, ties to the earlier one). */
export function allocate(total: number, weights: number[]): number[] {
  const sum = weights.reduce((a, b) => a + b, 0)
  const parts = weights.map((w) => Math.floor((total * w) / sum))
  const rema = weights.map((w) => (total * w) % sum)
  let left = total - parts.reduce((a, b) => a + b, 0)
  const order = weights.map((_, i) => i).sort((a, b) => rema[b] - rema[a] || a - b)
  for (let k = 0; left > 0; k++, left--) parts[order[k]]++
  return parts
}

export function lineTax(totalMinor: number, rates: TaxRate[], inclusive: boolean) {
  const totalBp = rates.reduce((a, r) => a + r.rate_bp, 0)
  if (totalBp === 0) return { gross: totalMinor, net: totalMinor, tax: 0, breakdown: [] as TaxLine[] }
  let gross: number, net: number, amounts: number[]
  if (inclusive) {
    gross = totalMinor
    net = divRound(gross * 10000, 10000 + totalBp)
    amounts = allocate(gross - net, rates.map((r) => r.rate_bp))
  } else {
    net = totalMinor
    amounts = rates.map((r) => divRound(net * r.rate_bp, 10000))
    gross = net + amounts.reduce((a, b) => a + b, 0)
  }
  return { gross, net, tax: gross - net, breakdown: rates.map((r, i) => ({ ...r, amount_minor: amounts[i] })) }
}

export function sumTax(lines: TaxLine[][]): TaxLine[] {
  const out = new Map<string, TaxLine>()
  for (const l of lines) for (const b of l) {
    const cur = out.get(b.code) ?? { ...b, amount_minor: 0 }
    cur.amount_minor += b.amount_minor
    out.set(b.code, cur)
  }
  return [...out.values()]
}

const SYMBOLS: Record<string, string> = { GHS: 'GH₵', NGN: '₦', KES: 'KSh', USD: '$', EUR: '€', GBP: '£', XOF: 'CFA' }

export function formatMoney(minor: number, currency: string, exponent = 2): string {
  const sign = minor < 0 ? '-' : ''
  const abs = Math.abs(minor)
  const div = 10 ** exponent
  const whole = Math.floor(abs / div).toLocaleString('en-US')
  const frac = exponent ? '.' + String(abs % div).padStart(exponent, '0') : ''
  return `${sign}${SYMBOLS[currency] ?? currency} ${whole}${frac}`
}

/** "12.5" → 1250 (exponent 2). Throws on bad input. */
export function parseMoney(input: string, exponent = 2): number {
  const s = input.replace(/[, ]/g, '').trim()
  const re = exponent > 0 ? new RegExp(`^\\d{1,13}(\\.\\d{1,${exponent}})?$`) : /^\d{1,15}$/
  if (!re.test(s)) throw new Error('Enter an amount like 50 or 50.00')
  const [w, f = ''] = s.split('.')
  return Number(w) * 10 ** exponent + Number(f.padEnd(exponent, '0') || '0')
}
