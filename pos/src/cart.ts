import { lineTax, sumTax, toMilli, type TaxLine } from './money'
import { serviceAmount } from './restaurant'
import { activePromotions, computePromotions, type Applied } from './promotions'
import type { Price, PriceList, Product, PromotionRow, Unit } from './types'

export type CartLine = { key: string; product: Product; unit: Unit; qtyMilli: number; manual?: { bp?: number; price_minor?: number } | null
  markdown?: { id: string; price_minor: number } | null
  // Restaurant: the bill item this line pays, the kitchen note (choices), and the service charge line.
  diningItemId?: string; note?: string | null; service?: boolean
  // Electronics: the serial / IMEI numbers sold on this line, and the repair job it pays for.
  serials?: string[]; repairJobId?: string
  // Fashion: an alteration paid at its agreed charge (outside price lists and promotions).
  alteration?: { id: string; charge_minor: number } | null
  // Services: who did it (commission), a session paid from the customer's prepaid pass (no charge), the appointment paid for.
  staff?: { id: string; name: string } | null; pass?: { id: string; item: number; number: string } | null; appointmentId?: string }

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

export type PricedLine = CartLine & {
  price: Price | null; total: number; net: number; tax: number; breakdown: TaxLine[]; listTotal: number; discount: number; promotions: Applied[]
}
export type CartExtras = { promotions?: PromotionRow[]; branchId?: string; utcOffsetMinutes?: number; coupon?: string | null; saleDiscountBp?: number; serviceBp?: number }

/** List prices from the price list, then promotions and discounts through the same engine as the server. */
export function priceCart(lines: CartLine[], list: PriceList, at: Date, extras: CartExtras = {}) {
  // A markdown sticker carries its own price and stays out of promotions (same as the server).
  const regular = lines.filter((l) => !l.service)
  const own = (l: CartLine) => l.pass ? 0 : l.markdown?.price_minor ?? l.alteration?.charge_minor
  const withPrice = regular.map((l) => ({ l, price: own(l) !== undefined
    ? { price_list_id: list.id, product_uom_id: l.unit.id, min_qty: '1', amount_minor: own(l)!, starts_at: null, ends_at: null }
    : resolvePrice(l.product, l.unit.id, list.id, l.qtyMilli, at) }))
  const running = activePromotions(extras.promotions ?? [], at, extras.branchId ?? '', list.id, extras.utcOffsetMinutes ?? 0)
  const calc = computePromotions({
    inclusive: list.prices_include_tax, promotions: running, coupon: extras.coupon ?? null, sale_discount_bp: extras.saleDiscountBp ?? 0,
    lines: withPrice.filter((x) => x.price).map(({ l, price }) => ({ key: l.key,
      product_id: l.markdown ? `markdown:${l.markdown.id}` : l.alteration ? `alteration:${l.alteration.id}` : l.pass ? `pass:${l.pass.id}` : l.product.id,
      category_id: l.markdown || l.alteration || l.pass ? null : l.product.category_id ?? null,
      qty_milli: l.qtyMilli, unit_price_minor: price!.amount_minor, tax: l.product.tax, manual: l.manual ?? null })),
  })
  const byKey = new Map(calc.lines.map((r) => [r.key, r]))
  const priced: PricedLine[] = withPrice.map(({ l, price }) => {
    const r = price ? byKey.get(l.key)! : null
    return { ...l, price, total: r?.total ?? 0, net: r?.net ?? 0, tax: r?.tax ?? 0, breakdown: r?.breakdown ?? [],
      listTotal: r?.list_total ?? 0, discount: r?.discount ?? 0, promotions: r?.promotions ?? [] }
  })
  // Restaurant service charge: priced from the rest of the bill after discounts, outside promotions (same as the server).
  let { net, tax, total, breakdown } = { net: calc.net, tax: calc.tax, total: calc.total, breakdown: calc.breakdown }
  for (const l of lines.filter((x) => x.service)) {
    const amount = serviceAmount({ total: calc.total, net: calc.net }, list.prices_include_tax, extras.serviceBp ?? 0)
    const t = lineTax(amount, l.product.tax, list.prices_include_tax)
    priced.push({ ...l, price: { price_list_id: list.id, product_uom_id: l.unit.id, min_qty: '1', amount_minor: amount, starts_at: null, ends_at: null },
      total: t.gross, net: t.net, tax: t.tax, breakdown: t.breakdown, listTotal: amount, discount: 0, promotions: [] })
    net += t.net
    tax += t.tax
    total += t.gross
    breakdown = sumTax([breakdown, t.breakdown])
  }
  return {
    lines: priced, net, tax, total, discount: calc.discount, taxBreakdown: breakdown,
    couponApplied: calc.coupon_applied, couponError: calc.coupon_error, unpriced: priced.filter((l) => !l.price),
  }
}

/** Set (or clear) a line's manual discount: a percentage in basis points, or a new unit price. */
export function setManual(lines: CartLine[], key: string, manual: CartLine['manual']): CartLine[] {
  return lines.map((l) => (l.key === key ? { ...l, manual } : l))
}

/** Add one of a unit; the same product+unit merges into its existing line. */
export function addToCart(lines: CartLine[], product: Product, unit: Unit, qtyMilli = 1000): CartLine[] {
  const key = `${product.id}:${unit.id}`
  const existing = lines.find((l) => l.key === key)
  if (existing) return lines.map((l) => (l.key === key ? { ...l, qtyMilli: l.qtyMilli + qtyMilli } : l))
  return [...lines, { key, product, unit, qtyMilli }]
}

/** A marked-down pack (sticker): its own line, at the sticker price. */
export function addMarkdown(lines: CartLine[], product: Product, unit: Unit, markdown: { id: string; price_minor: number }): CartLine[] {
  const key = `md:${markdown.id}`
  const existing = lines.find((l) => l.key === key)
  if (existing) return lines.map((l) => (l.key === key ? { ...l, qtyMilli: l.qtyMilli + 1000 } : l))
  return [...lines, { key, product, unit, qtyMilli: 1000, markdown }]
}

/** A weighed item from a scale label: always its own line (each pack has its own weight). */
export function addWeighed(lines: CartLine[], product: Product, unit: Unit, grams: number): CartLine[] {
  return [...lines, { key: `w:${product.id}:${lines.length}:${grams}`, product, unit, qtyMilli: grams }]
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
