import { makeStyles, mergeClasses, Tab, TabList, Text, tokens } from '@fluentui/react-components'
import { useMemo, useState } from 'react'
import type { Category, Product, Unit } from '../types'

const MAX = 60 // tiles per category: a large shop types to find the rest, so a slow till stays quick

const useStyles = makeStyles({
  root: { display: 'flex', flexDirection: 'column', gap: '10px', minHeight: 0, flex: '1 1 auto' },
  tabs: { overflowX: 'auto', scrollbarWidth: 'thin', flex: '0 0 auto' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(132px, 1fr))', gap: '8px', overflowY: 'auto', alignContent: 'start', paddingBottom: '4px' },
  tile: {
    display: 'flex', flexDirection: 'column', justifyContent: 'space-between', gap: '6px', minHeight: '92px', padding: '10px 12px', textAlign: 'left',
    border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusLarge, backgroundColor: tokens.colorNeutralBackground1,
    color: tokens.colorNeutralForeground1, cursor: 'pointer', font: 'inherit', boxShadow: tokens.shadow2, touchAction: 'manipulation',
    ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover, border: `1px solid ${tokens.colorNeutralStroke1Hover}` },
    ':active': { backgroundColor: tokens.colorNeutralBackground1Pressed, transform: 'scale(0.98)' },
    ':focus-visible': { outline: `2px solid ${tokens.colorStrokeFocus2}`, outlineOffset: '2px' },
  },
  name: { fontWeight: tokens.fontWeightSemibold, lineHeight: '1.25', overflow: 'hidden', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical' },
  price: { color: tokens.colorBrandForeground1, fontWeight: tokens.fontWeightBold, fontVariantNumeric: 'tabular-nums' },
  out: { opacity: 0.6 },
  note: { color: tokens.colorNeutralForeground3, fontSize: tokens.fontSizeBase200 },
  empty: { color: tokens.colorNeutralForeground3, padding: '12px 4px' },
})

const OTHER = '__other'

/**
 * Product tiles beside the cart (owner 2026-11-28, as Square, Shopify POS and Dynamics Store Commerce): categories as
 * tabs, the category's products as large touch tiles with their price. Pressing a tile adds the item as scanning it
 * does; typing or scanning still searches (the list replaces the tiles while there is text in the box).
 */
export function ProductTiles({ products, categories, price, stock, onAdd }: {
  products: Product[]; categories: Category[]
  price: (p: Product, u: Unit) => string | null
  stock: (p: Product) => { text: string; out: boolean; low: boolean } | null
  onAdd: (p: Product) => void
}) {
  const s = useStyles()
  // Categories that have something to sell here, in name order; products without one last ("Other").
  const tabs = useMemo(() => {
    const used = new Set(products.map((p) => p.category_id ?? OTHER))
    const named = categories.filter((c) => used.has(c.id)).map((c) => ({ id: c.id, name: c.name }))
    return used.has(OTHER) || named.length === 0 ? [...named, { id: OTHER, name: named.length ? 'Other' : 'All products' }] : named
  }, [products, categories])
  const [tab, setTab] = useState<string>(() => tabs[0]?.id ?? OTHER)
  const current = tabs.some((t) => t.id === tab) ? tab : tabs[0]?.id ?? OTHER
  const inTab = useMemo(() => products.filter((p) => (p.category_id ?? OTHER) === current && !p.is_container), [products, current])
  const shown = inTab.slice(0, MAX)

  return (
    <div className={s.root}>
      {tabs.length > 1 && (
        <TabList className={s.tabs} size="large" selectedValue={current} onTabSelect={(_, d) => setTab(String(d.value))} aria-label="Product categories">
          {tabs.map((t) => <Tab key={t.id} value={t.id}>{t.name}</Tab>)}
        </TabList>
      )}
      <div className={s.grid} role="group" aria-label="Products">
        {shown.map((p) => {
          const base = p.units.find((u) => u.is_base) ?? p.units[0]
          const pr = base ? price(p, base) : null
          const st = stock(p)
          return (
            <button key={p.id} type="button" className={mergeClasses(s.tile, st?.out && s.out)} onClick={() => onAdd(p)}
              aria-label={`${p.name}, ${pr ?? 'no price'}${st ? `, ${st.text}` : ''}`}>
              <span className={s.name}>{p.name}</span>
              <span>
                <span className={s.price}>{pr ?? 'No price'}</span>
                {st && <span className={s.note}> · {st.text}</span>}
              </span>
            </button>
          )
        })}
      </div>
      {inTab.length > MAX && <Text className={s.empty}>Showing {MAX} of {inTab.length}. Type part of a name or scan to find the rest.</Text>}
      {inTab.length === 0 && <Text className={s.empty}>Nothing to sell in this category here.</Text>}
    </div>
  )
}
