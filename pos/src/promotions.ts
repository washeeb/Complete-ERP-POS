import { allocate, divRound, lineTax, lineTotal, sumTax, type TaxLine, type TaxRate } from './money'

/**
 * The promotion engine. MUST stay identical to server/app/Domain/Pricing/PromotionEngine.php — the server recomputes
 * every online sale and refuses a total that differs by a pesewa. Both run the shared vectors in
 * spec/vectors/promotions.json. Integer maths only.
 *
 * Owner decision (2026-10-11): each item gets the single best item promotion; then the best "spend X" basket offer,
 * one coupon, and a manual sale discount apply on top, in that order.
 */

export type PromoType = 'percent' | 'amount_off' | 'buy_x_get_y' | 'bundle' | 'threshold' | 'coupon'
export type Promotion = {
  id: string; name: string; type: PromoType; product_ids: string[]; category_ids: string[]
  value_bp: number; value_minor: number; buy_qty: number; get_qty: number; bundle_qty: number; min_spend_minor: number; coupon_code: string | null
}
export type EngineLine = {
  key: string; product_id: string; category_id: string | null; qty_milli: number; unit_price_minor: number; tax: TaxRate[]
  manual?: { bp?: number; price_minor?: number } | null
}
export type EngineInput = { lines: EngineLine[]; inclusive: boolean; promotions: Promotion[]; coupon?: string | null; sale_discount_bp?: number }
export type Applied = { id: string; name: string; saving_minor: number }
export type EngineLineResult = {
  key: string; list_total: number; item_discount: number; manual_discount: number; basket_discount: number; discount: number
  total: number; net: number; tax: number; breakdown: TaxLine[]; promotions: Applied[]
}
export type EngineResult = {
  lines: EngineLineResult[]; net: number; tax: number; total: number; discount: number; breakdown: TaxLine[]
  coupon_applied: string | null; coupon_error: string | null
}

const ITEM: PromoType[] = ['percent', 'amount_off', 'buy_x_get_y', 'bundle']
const units = (qtyMilli: number) => Math.floor(qtyMilli / 1000)

function qualifies(p: Promotion, l: EngineLine): boolean {
  if (p.product_ids.length === 0 && p.category_ids.length === 0) return true
  return p.product_ids.includes(l.product_id) || (l.category_id !== null && p.category_ids.includes(l.category_id))
}

/** Saving per line key for one item promotion over the given lines (index order matters for ties). */
function attribute(p: Promotion, lines: EngineLine[], listTotals: Map<string, number>): Map<string, number> {
  const out = new Map<string, number>()
  const add = (k: string, v: number) => out.set(k, (out.get(k) ?? 0) + v)
  if (p.type === 'percent') for (const l of lines) add(l.key, divRound(listTotals.get(l.key)! * p.value_bp, 10000))
  if (p.type === 'amount_off') for (const l of lines) add(l.key, Math.min(listTotals.get(l.key)!, p.value_minor * units(l.qty_milli)))
  if (p.type === 'buy_x_get_y' || p.type === 'bundle') {
    // Whole units, most expensive first (ties: earlier line first).
    const pool: { key: string; price: number; idx: number }[] = []
    lines.forEach((l, idx) => { for (let i = 0; i < units(l.qty_milli); i++) pool.push({ key: l.key, price: l.unit_price_minor, idx }) })
    pool.sort((a, b) => b.price - a.price || a.idx - b.idx)
    if (p.type === 'buy_x_get_y') {
      // Groups of X+Y from the most expensive; in each group the Y cheapest are discounted. The saving is shared by every
      // unit in the group (by price), so each line sees its part of the deal when choosing its best promotion.
      const size = p.buy_qty + p.get_qty
      const bp = p.value_bp > 0 ? p.value_bp : 10000
      if (p.buy_qty > 0 && p.get_qty > 0) {
        for (let g = 0; g + size <= pool.length; g += size) {
          const group = pool.slice(g, g + size)
          const saving = group.slice(p.buy_qty).reduce((a, u) => a + divRound(u.price * bp, 10000), 0)
          if (saving > 0) allocate(saving, group.map((u) => u.price)).forEach((s, i) => add(group[i].key, s))
        }
      }
    } else if (p.bundle_qty > 0) {
      for (let g = 0; g + p.bundle_qty <= pool.length; g += p.bundle_qty) {
        const group = pool.slice(g, g + p.bundle_qty)
        const saving = Math.max(0, group.reduce((a, u) => a + u.price, 0) - p.value_minor)
        if (saving > 0) allocate(saving, group.map((u) => u.price)).forEach((s, i) => add(group[i].key, s))
      }
    }
  }
  for (const [k, v] of out) out.set(k, Math.min(v, listTotals.get(k)!))
  return out
}

const byId = (a: Promotion, b: Promotion) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0)

export function computePromotions(input: EngineInput): EngineResult {
  const lines = input.lines
  const listTotals = new Map(lines.map((l) => [l.key, lineTotal(l.qty_milli, l.unit_price_minor)]))
  const promos = [...input.promotions].sort(byId)
  const overridden = (l: EngineLine) => l.manual?.price_minor !== undefined && l.manual?.price_minor !== null

  // 1. Item promotions: each line takes the single promotion that saves it most.
  const itemPromos = promos.filter((p) => ITEM.includes(p.type) && !p.coupon_code)
  const first = itemPromos.map((p) => attribute(p, lines.filter((l) => !overridden(l) && qualifies(p, l)), listTotals))
  const chosen = new Map<string, number>() // line key → promo index
  for (const l of lines) {
    let best = -1, bestSaving = 0
    first.forEach((m, i) => { const s = m.get(l.key) ?? 0; if (s > bestSaving) { best = i; bestSaving = s } })
    if (best >= 0) chosen.set(l.key, best)
  }
  const item = new Map<string, number>()
  const applied = new Map<string, Applied[]>(lines.map((l) => [l.key, []]))
  itemPromos.forEach((p, i) => {
    const mine = lines.filter((l) => chosen.get(l.key) === i)
    if (!mine.length) return
    for (const [k, s] of attribute(p, mine, listTotals)) { // group offers recomputed on the lines that chose them
      if (s <= 0) continue
      item.set(k, (item.get(k) ?? 0) + s)
      applied.get(k)!.push({ id: p.id, name: p.name, saving_minor: s })
    }
  })

  // 2. Manual line discount (within the cashier's limit, checked elsewhere): % off what is left, or a price override.
  const manual = new Map<string, number>()
  for (const l of lines) {
    const list = listTotals.get(l.key)!
    if (overridden(l)) manual.set(l.key, Math.max(0, list - lineTotal(l.qty_milli, l.manual!.price_minor!)))
    else if (l.manual?.bp) manual.set(l.key, divRound((list - (item.get(l.key) ?? 0)) * l.manual.bp, 10000))
  }
  const remaining = new Map(lines.map((l) => [l.key, listTotals.get(l.key)! - (item.get(l.key) ?? 0) - (manual.get(l.key) ?? 0)]))
  const basket = new Map<string, number>()
  const spread = (amount: number, keys: string[], p?: Promotion) => {
    const weights = keys.map((k) => remaining.get(k)!)
    if (amount <= 0 || weights.reduce((a, b) => a + b, 0) <= 0) return
    allocate(amount, weights).forEach((s, i) => {
      const k = keys[i]
      basket.set(k, (basket.get(k) ?? 0) + s)
      remaining.set(k, remaining.get(k)! - s)
      if (p && s > 0) applied.get(k)!.push({ id: p.id, name: p.name, saving_minor: s })
    })
  }
  const basketValue = (p: Promotion, keys: string[]) => {
    const spend = keys.reduce((a, k) => a + remaining.get(k)!, 0)
    if (keys.length === 0 || spend < p.min_spend_minor || spend <= 0) return 0
    return p.value_bp > 0 ? divRound(spend * p.value_bp, 10000) : Math.min(p.value_minor, spend)
  }
  const scope = (p: Promotion) => lines.filter((l) => qualifies(p, l)).map((l) => l.key)

  // 3. The best "spend X" offer.
  let bestT: Promotion | null = null, bestTV = 0
  for (const p of promos.filter((x) => x.type === 'threshold' && !x.coupon_code)) {
    const v = basketValue(p, scope(p))
    if (v > bestTV) { bestT = p; bestTV = v }
  }
  if (bestT) spread(bestTV, scope(bestT), bestT)

  // 4. One coupon.
  let couponApplied: string | null = null, couponError: string | null = null
  const code = (input.coupon ?? '').trim().toUpperCase()
  if (code) {
    const c = promos.find((p) => p.coupon_code !== null && p.coupon_code.toUpperCase() === code)
    if (!c) couponError = 'This coupon is not valid now.'
    else {
      const v = basketValue(c, scope(c))
      if (v <= 0) couponError = c.min_spend_minor > 0 ? 'The basket does not reach the coupon’s minimum spend.' : 'Nothing in the basket qualifies for this coupon.'
      else { spread(v, scope(c), c); couponApplied = c.id }
    }
  }

  // 5. Manual discount on the whole sale.
  const saleBp = input.sale_discount_bp ?? 0
  if (saleBp > 0) {
    const keys = lines.map((l) => l.key)
    spread(divRound(keys.reduce((a, k) => a + remaining.get(k)!, 0) * saleBp, 10000), keys)
  }

  const out: EngineLineResult[] = lines.map((l) => {
    const t = lineTax(remaining.get(l.key)!, l.tax, input.inclusive)
    const itemD = item.get(l.key) ?? 0, manualD = manual.get(l.key) ?? 0, basketD = basket.get(l.key) ?? 0
    return { key: l.key, list_total: listTotals.get(l.key)!, item_discount: itemD, manual_discount: manualD, basket_discount: basketD,
      discount: itemD + manualD + basketD, total: t.gross, net: t.net, tax: t.tax, breakdown: t.breakdown, promotions: applied.get(l.key)! }
  })
  return {
    lines: out, net: out.reduce((a, l) => a + l.net, 0), tax: out.reduce((a, l) => a + l.tax, 0), total: out.reduce((a, l) => a + l.total, 0),
    discount: out.reduce((a, l) => a + l.discount, 0), breakdown: sumTax(out.map((l) => l.breakdown)), coupon_applied: couponApplied, coupon_error: couponError,
  }
}

/** Which promotions run now (date range, weekday, time window, branch, price list). Same rule as the server. */
export function activePromotions<T extends Promotion & {
  starts_at: string | null; ends_at: string | null; days: number[]; time_from: string | null; time_to: string | null; branch_ids: string[]; price_list_ids: string[]
}>(all: T[], at: Date, branchId: string, listId: string, timezoneOffsetMinutes = 0): T[] {
  const local = new Date(at.getTime() + timezoneOffsetMinutes * 60_000)
  const weekday = ((local.getUTCDay() + 6) % 7) + 1 // ISO 1 = Monday
  const hhmm = `${String(local.getUTCHours()).padStart(2, '0')}:${String(local.getUTCMinutes()).padStart(2, '0')}`
  return all.filter((p) => (!p.starts_at || Date.parse(p.starts_at) <= at.getTime()) && (!p.ends_at || Date.parse(p.ends_at) > at.getTime())
    && (p.days.length === 0 || p.days.includes(weekday))
    && (!p.time_from || !p.time_to || (p.time_from.slice(0, 5) <= hhmm && hhmm < p.time_to.slice(0, 5)))
    && (p.branch_ids.length === 0 || p.branch_ids.includes(branchId)) && (p.price_list_ids.length === 0 || p.price_list_ids.includes(listId)))
}
