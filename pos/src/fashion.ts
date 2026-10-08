import type { Product, Style } from './types'

/** Fashion at the till: the size × colour grid of a style's products (same layout as the back office). */
export type Cell = { product: Product; onHand: number | null }

/** Rows by colour, columns by size, in the style's order; null where that combination has no product. */
export function styleGrid(style: Style, products: Product[]): { sizes: string[]; colours: string[]; cells: (Cell | null)[][] } {
  const variants = products.filter((p) => p.style_id === style.id)
  const cells = style.colours.map((c) => style.sizes.map((s) => {
    const product = variants.find((p) => p.colour === c && p.size === s)
    return product ? { product, onHand: product.on_hand === null ? null : Number(product.on_hand) } : null
  }))
  return { sizes: style.sizes, colours: style.colours, cells }
}

/** The style a product belongs to, if the till knows it. */
export const styleOf = (p: Product, styles: Style[]) => (p.style_id ? styles.find((s) => s.id === p.style_id) ?? null : null)
