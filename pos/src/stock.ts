import type { Product } from './types'
import { toMilli } from './money'

/** A sale saved on this till but not yet on the server: its lines, as queued. */
export type QueuedLine = { product_id: string; product_uom_id: string; qty: string }

/** In base units (thousandths): what the lines take of each product. */
export function takenMilli(products: Map<string, Product>, lines: { productId: string; unitId: string; qtyMilli: number }[]): Map<string, number> {
  const out = new Map<string, number>()
  for (const l of lines) {
    const unit = products.get(l.productId)?.units.find((u) => u.id === l.unitId)
    if (!unit) continue
    out.set(l.productId, (out.get(l.productId) ?? 0) + Math.round(l.qtyMilli * Number(unit.factor)))
  }
  return out
}

/** The queued sales' lines in the shape takenMilli reads. */
export function queuedLines(sales: { lines?: QueuedLine[] }[]): { productId: string; unitId: string; qtyMilli: number }[] {
  return sales.flatMap((s) => (s.lines ?? []).map((l) => ({ productId: l.product_id, unitId: l.product_uom_id, qtyMilli: toMilli(l.qty) })))
}

/**
 * What is left of a product, in its base unit (thousandths): the stock the till last received, less what is in the
 * cart and in sales not yet sent. null when the product's stock is not tracked. Sent sales are already in the
 * server's figure, which the till re-reads after every stock change while online.
 */
export function leftMilli(product: Product, cart: Map<string, number>, queued: Map<string, number>): number | null {
  if (!product.track_stock || product.on_hand === null) return null
  return toMilli(product.on_hand) - (cart.get(product.id) ?? 0) - (queued.get(product.id) ?? 0)
}

/** At or below the product's minimum in this store (own figure, smart level or the company default): shown in amber. */
export function isLow(product: Product, left: number | null): boolean {
  return left !== null && left > 0 && product.min_qty != null && left <= toMilli(product.min_qty)
}
