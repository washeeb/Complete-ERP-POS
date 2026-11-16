/** Money and numbers as the company shows them (its currency and decimals), whole or short ("GH₵ 6.4M"). */
export function money(minor: number, currency: string, exponent: number): string {
  const v = minor / 10 ** exponent
  try {
    return new Intl.NumberFormat('en-GH', { style: 'currency', currency, minimumFractionDigits: exponent, maximumFractionDigits: exponent }).format(v)
  } catch {
    return `${currency} ${v.toFixed(exponent)}`
  }
}

export function moneyShort(minor: number, currency: string, exponent: number): string {
  const v = minor / 10 ** exponent
  if (Math.abs(v) < 10_000) return money(minor, currency, exponent).replace(/\.0+$/, '')
  try {
    return new Intl.NumberFormat('en-GH', { style: 'currency', currency, notation: 'compact', maximumFractionDigits: 1 }).format(v)
  } catch {
    return `${currency} ${compact(v)}`
  }
}

export function compact(n: number): string {
  return new Intl.NumberFormat('en-GB', { notation: 'compact', maximumFractionDigits: 1 }).format(n)
}

export function count(n: number): string {
  return new Intl.NumberFormat('en-GB', { maximumFractionDigits: 3 }).format(n)
}

/** "+6.9 %", "−12.0 %", "new" when there was nothing before, "—" when it cannot be compared. */
export function change(pct: number | null, previous: number | null): string {
  if (previous === null) return '—'
  if (pct === null) return 'new'
  const sign = pct > 0 ? '+' : pct < 0 ? '−' : ''
  return `${sign}${Math.abs(pct).toFixed(1)} %`
}

/**
 * A figure going up is good for sales and bad for what is owed: the colour follows the meaning, not the sign. Buying
 * more or less is neither (null: no colour).
 */
export function goodWhenUp(key: string): boolean | null {
  if (['purchases', 'open_po', 'open_po_value', 'stock'].includes(key)) return null
  return !['we_owe', 'owed_to_us'].includes(key)
}

/** Whether this change is good news (true), bad (false) or neither (null). */
export function goodNews(key: string, pct: number | null): boolean | null {
  const up = goodWhenUp(key)
  return pct === null || pct === 0 || up === null ? null : (pct > 0) === up
}
