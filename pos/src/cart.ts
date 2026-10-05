import { lineTax, lineTotal, sumTax, toMilli, type TaxLine } from './money'
import type { Price, PriceList, Product, Unit } from './types'

export type CartLine = { key: string; product: Product; unit: Unit; qtyMilli: number }

/** Same rule as server PriceResolver: active at `at`, min_qty ≤ qty, highest tier, dated beats open-ended. */
export function resolvePrice(product: Product, unitId: string, listId: string, qtyMilli: number, at: Date): Price | null {
  const t = at.getTime()
  const candidates = product.prices.filter(
    (p) =>
      p.price_list_id === listId &&
      p.product_uom_id === unitId &&
      toMilli(p.min_qty) <= qtyMilli &&
      (!p.starts_at || Date.parse(p.starts_at) <= t) &&
      (!p.ends_at || Date.parse(p.ends_at) > t),
  )
  candidates.sort(
    (a, b) =>
      toMilli(b.min_qty) - toMilli(a.min_qty) ||
      Number(!!b.starts_at) - Number(!!a.starts_at) ||
      Date.parse(b.starts_at ?? '1970-01-01') - Date.parse(a.starts_at ?? '1970-01-01'),
  )
  return candidates[0] ?? null
}

export type PricedLine = CartLine & { price: Price | null; total: number; net: number; tax: number; breakdown: TaxLine[] }

export function priceCart(lines: CartLine[], list: PriceList, at: Date) {
  const priced: PricedLine[] = lines.map((l) => {
    const price = resolvePrice(l.product, l.unit.id, list.id, l.qtyMilli, at)
    if (!price) return { ...l, price: null, total: 0, net: 0, tax: 0, breakdown: [] }
    const t = lineTax(lineTotal(l.qtyMilli, price.amount_minor), l.product.tax, list.prices_include_tax)
    return { ...l, price, total: t.gross, net: t.net, tax: t.tax, breakdown: t.breakdown }
  })
  return {
    lines: priced,
    net: priced.reduce((a, l) => a + l.net, 0),
    tax: priced.reduce((a, l) => a + l.tax, 0),
    total: priced.reduce((a, l) => a + l.total, 0),
    taxBreakdown: sumTax(priced.map((l) => l.breakdown)),
    unpriced: priced.filter((l) => !l.price),
  }
}

/** Add one of a unit; the same product+unit merges into its existing line. */
export function addToCart(lines: CartLine[], product: Product, unit: Unit, qtyMilli = 1000): CartLine[] {
  const key = `${product.id}:${unit.id}`
  const existing = lines.find((l) => l.key === key)
  if (existing) return lines.map((l) => (l.key === key ? { ...l, qtyMilli: l.qtyMilli + qtyMilli } : l))
  return [...lines, { key, product, unit, qtyMilli }]
}

export function setQty(lines: CartLine[], key: string, qtyMilli: number): CartLine[] {
  return qtyMilli <= 0 ? lines.filter((l) => l.key !== key) : lines.map((l) => (l.key === key ? { ...l, qtyMilli } : l))
}

/** Quantity allowed in this unit? (e.g. pieces are whole numbers, kg allows 3 decimals) */
export function qtyAllowed(unit: Unit, qtyMilli: number): boolean {
  return qtyMilli > 0 && qtyMilli % 10 ** (3 - unit.decimals) === 0
}

/** Barcode → product + unit (exact match). */
export function findByBarcode(products: Product[], code: string): { product: Product; unit: Unit } | null {
  const c = code.trim()
  for (const product of products) for (const unit of product.units) if (unit.barcodes.includes(c)) return { product, unit }
  return null
}

/** Name/SKU search, words in any order, case-insensitive. */
export function search(products: Product[], q: string, limit = 30): Product[] {
  const words = q.toLowerCase().split(/\s+/).filter(Boolean)
  if (!words.length) return []
  return products.filter((p) => words.every((w) => p.name.toLowerCase().includes(w) || p.sku.toLowerCase().includes(w))).slice(0, limit)
}
