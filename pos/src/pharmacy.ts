import type { Drug, Interaction, Product } from './types'

/** Pharmacy at the till: which medicines need a prescription, and the allergy / interaction warnings (same rule as the server's PharmacyService). */
export type Warning = { kind: 'allergy' | 'interaction'; severity: string; message: string }

export const needsPrescription = (p: Product) => p.drug?.schedule === 'pom' || p.drug?.schedule === 'controlled'

export function names(list: string[] | string | null | undefined): string[] {
  const items = Array.isArray(list) ? list : String(list ?? '').split(',')
  return [...new Set(items.map((s) => String(s).trim().toLowerCase()).filter(Boolean))]
}

const ingredientsOf = (d: Drug | null | undefined) => names([...(d?.active_ingredients ?? []), d?.generic_name ?? ''])

export function warnings(products: Product[], allergies: string[], interactions: Interaction[]): Warning[] {
  const allergic = names(allergies)
  const out: Warning[] = []
  for (const p of products) {
    for (const i of ingredientsOf(p.drug).filter((x) => allergic.includes(x))) {
      out.push({ kind: 'allergy', severity: 'major', message: `${p.name}: patient is allergic to ${i}.` })
    }
  }
  for (let x = 0; x < products.length; x++) {
    for (let y = x + 1; y < products.length; y++) {
      if (products[x].id === products[y].id) continue
      const ix = ingredientsOf(products[x].drug)
      const iy = ingredientsOf(products[y].drug)
      for (const r of interactions) {
        const a = r.ingredient_a.toLowerCase()
        const b = r.ingredient_b.toLowerCase()
        if ((ix.includes(a) && iy.includes(b)) || (ix.includes(b) && iy.includes(a))) {
          out.push({ kind: 'interaction', severity: r.severity, message: `${products[x].name} + ${products[y].name}: ${r.note}` })
        }
      }
    }
  }
  return out
}

/** Other products with the same generic name and strength (to offer a cheaper or in-stock substitute). */
export function generics(products: Product[], p: Product): Product[] {
  const g = p.drug?.generic_name?.trim().toLowerCase()
  if (!g) return []
  const strength = (p.drug?.strength ?? '').replace(/\s/g, '').toLowerCase()
  return products.filter((o) => o.id !== p.id && o.drug?.generic_name?.trim().toLowerCase() === g
    && (o.drug?.strength ?? '').replace(/\s/g, '').toLowerCase() === strength)
}
