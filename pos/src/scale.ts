import type { Grocery, Markdown, Product, Unit } from './types'

/** EAN-13 check digit (same rule as server App\Support\Ean13). */
export function ean13Valid(code: string): boolean {
  if (!/^\d{13}$/.test(code)) return false
  const sum = code.slice(0, 12).split('').reduce((a, d, i) => a + Number(d) * (i % 2 === 0 ? 1 : 3), 0)
  return (10 - (sum % 10)) % 10 === Number(code[12])
}

/**
 * A label from the shop's scale (owner decision 2026-10-16: weight in the barcode):
 * prefix (2) · PLU (5) · weight in grams (5) · check digit — e.g. 21 00123 01250 c = item 123, 1.250 kg.
 */
export function parseScaleLabel(code: string, prefixes: string[]): { plu: string; grams: number } | null {
  const c = code.trim()
  if (!ean13Valid(c) || !prefixes.includes(c.slice(0, 2))) return null
  const grams = Number(c.slice(7, 12))
  return grams > 0 ? { plu: c.slice(2, 7), grams } : null
}

/** Match a scale PLU to a product (leading zeros ignored), sold by its weighable base unit. */
export function productForPlu(products: Product[], plu: string): { product: Product; unit: Unit } | null {
  const n = Number(plu)
  for (const product of products) {
    if (product.plu != null && Number(product.plu) === n) {
      const unit = product.units.find((u) => u.is_base && u.decimals >= 3) ?? product.units.find((u) => u.decimals >= 3)
      return unit ? { product, unit } : null
    }
  }
  return null
}

/** A near-expiry markdown sticker scanned at the till. */
export function findMarkdown(grocery: Grocery | null | undefined, code: string, products: Product[]): { markdown: Markdown; product: Product; unit: Unit } | null {
  const c = code.trim()
  if (!grocery || !c.startsWith(grocery.markdown_prefix)) return null
  const markdown = grocery.markdowns.find((m) => m.code === c)
  if (!markdown) return null
  const product = products.find((p) => p.id === markdown.product_id)
  const unit = product?.units.find((u) => u.id === markdown.product_uom_id)
  return product && unit ? { markdown, product, unit } : null
}
