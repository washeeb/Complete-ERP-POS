import {
  Button, Dropdown, Link, makeStyles, mergeClasses, Option, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
  ToggleButton, Tooltip,
} from '@fluentui/react-components'
import {
  ArrowDownRegular, ArrowUpRegular, ChevronDownRegular, ChevronRightRegular, DataBarVerticalRegular, DataLineRegular,
  DataPieRegular, TableRegular, TextBulletListLtrRegular,
} from '@fluentui/react-icons'
import { useState } from 'react'
import type { HomeData, Kpi, Me, Menu, Period } from '../api'
import { AreaIcon } from '../icons'
import { linkClick } from '../nav'
import { appPathFor, flatMenu, tabOf } from '../paths'
import { Donut, Gauge, HBars, Sparkline, TimeChart } from './charts'
import { change, compact, count, goodNews, money, moneyShort } from './format'
import type { Tile } from './layout'

const useStyles = makeStyles({
  remBig: { display: 'grid', gap: '6px', marginBottom: '10px' },
  remRow: {
    display: 'flex', flexDirection: 'column', gap: '0', padding: '4px 0 4px 12px', borderLeft: '3px solid var(--zx-s2)', textDecorationLine: 'none',
    color: tokens.colorNeutralForeground1, borderRadius: '2px', ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover },
  },
  remCount: { fontSize: '22px', fontWeight: tokens.fontWeightBold, lineHeight: '1.1', fontVariantNumeric: 'tabular-nums' },
  remLabel: { color: tokens.colorBrandForeground1, fontSize: tokens.fontSizeBase300 },
  remList: { display: 'grid', gap: '2px', borderTop: `1px solid ${tokens.colorNeutralStroke2}`, paddingTop: '8px' },
  remSmall: {
    display: 'flex', gap: '8px', alignItems: 'baseline', padding: '3px 0 3px 10px', borderLeft: '3px solid var(--zx-s4)', textDecorationLine: 'none',
    color: tokens.colorBrandForeground1, fontSize: tokens.fontSizeBase300, ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover },
  },
  remN: { minWidth: '22px', textAlign: 'right', fontWeight: tokens.fontWeightSemibold, color: tokens.colorNeutralForeground1, fontVariantNumeric: 'tabular-nums' },
  muted: { color: tokens.colorNeutralForeground3 },
  tiles: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: '12px' },
  tile: {
    display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: '8px', minHeight: '92px', padding: '12px',
    borderRadius: tokens.borderRadiusXLarge, color: '#fff', textDecorationLine: 'none', textAlign: 'center', fontWeight: tokens.fontWeightSemibold,
    boxShadow: tokens.shadow4, transitionProperty: 'transform, box-shadow', transitionDuration: '.15s',
    ':hover': { transform: 'translateY(-2px)', boxShadow: tokens.shadow16, color: '#fff' }, ':focus-visible': { outline: `3px solid ${tokens.colorStrokeFocus2}` },
  },
  tileIcon: { fontSize: '28px' },
  cards: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: '10px', marginBottom: '12px' },
  card: {
    display: 'flex', flexDirection: 'column', gap: '4px', padding: '12px', borderRadius: tokens.borderRadiusLarge, textDecorationLine: 'none',
    border: `1px solid ${tokens.colorNeutralStroke2}`, color: tokens.colorNeutralForeground1, backgroundColor: tokens.colorNeutralBackground1,
    ':hover': { boxShadow: tokens.shadow8 },
  },
  cardRow: { display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', gap: '8px', flexWrap: 'wrap' },
  big: { fontSize: '19px', fontWeight: tokens.fontWeightBold, fontVariantNumeric: 'tabular-nums', display: 'flex', alignItems: 'center', gap: '4px', whiteSpace: 'nowrap' },
  up: { color: 'var(--zx-good)' },
  down: { color: 'var(--zx-bad)' },
  num: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  tools: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', marginBottom: '10px', flexWrap: 'wrap' },
  seg: { display: 'flex', gap: '2px' },
  short: { display: 'grid', gap: '12px' },
  shortH: { display: 'flex', alignItems: 'center', gap: '8px', fontWeight: tokens.fontWeightSemibold, marginBottom: '2px' },
  shortList: { display: 'grid', gap: '2px', paddingLeft: '28px' },
})

/** Reminders: what waits, the urgent ones big (as the template), the rest as a list; nothing waiting stays tucked away. */
export function RemindersPanel({ data }: { data: HomeData }) {
  const s = useStyles()
  const [all, setAll] = useState(false)
  const waiting = data.reminders.filter((r) => r.count > 0)
  const big = waiting.filter((r) => r.urgent).slice(0, 5)
  const rest = waiting.filter((r) => !big.includes(r))
  const empty = data.reminders.filter((r) => r.count === 0)
  return (
    <div>
      {waiting.length === 0 && <Text className={s.muted}>Nothing is waiting for you. Well done.</Text>}
      {big.length > 0 && (
        <div className={s.remBig}>
          {big.map((r) => (
            <a key={r.url + r.label} className={s.remRow} href={appPathFor(r.url)} onClick={(e) => linkClick(e, r.url)}>
              <span className={s.remCount}>{count(r.count)}</span><span className={s.remLabel}>{r.label}</span>
            </a>
          ))}
        </div>
      )}
      {(rest.length > 0 || (all && empty.length > 0)) && (
        <div className={s.remList}>
          {[...rest, ...(all ? empty : [])].map((r) => (
            <a key={r.url + r.label} className={s.remSmall} href={appPathFor(r.url)} onClick={(e) => linkClick(e, r.url)}>
              <span className={s.remN}>{count(r.count)}</span><span>{r.label}</span>
            </a>
          ))}
        </div>
      )}
      {empty.length > 0 && <Button appearance="transparent" size="small" onClick={() => setAll(!all)}>{all ? 'Hide the empty ones' : `Show ${empty.length} with nothing waiting`}</Button>}
    </div>
  )
}

const TILE_COLOURS = ['#2e7d5b', '#c66a2b', '#b5475f', '#5a6b86', '#2a6fb0', '#7a55c4', '#8a6d1f', '#38797a']
const DEFAULT_TILES = ['/office/till-requests', '/office/transfers', '/office/adjustments', '/office/purchase-orders', '/office/sales', '/office/products', '/office/customers', '/office/invoices']

/** The tiles the person chose (Personalize), or the first four everyday pages they may open. */
export function tilesFor(menu: Menu, chosen: Tile[]): Tile[] {
  const pages = flatMenu(menu)
  const open = (url: string) => pages.find((p) => p.url.split('?')[0] === url.split('?')[0])
  if (chosen.length) return chosen.filter((t) => open(t.url))
  return DEFAULT_TILES.map((u) => open(u)).filter((p): p is NonNullable<typeof p> => !!p).slice(0, 4).map((p) => ({ url: p.url, label: p.label }))
}

/** Tiles: big coloured shortcuts to the pages used most (with the company's own weave on each). */
export function TilesPanel({ menu, tiles, onChoose }: { menu: Menu; tiles: Tile[]; onChoose: () => void }) {
  const s = useStyles()
  const shown = tilesFor(menu, tiles)
  if (!shown.length) return <Button onClick={onChoose}>Choose tiles</Button>
  return (
    <div className={s.tiles}>
      {shown.map((t, i) => (
        <a key={t.url} className={mergeClasses(s.tile, 'zx-tile')} style={{ backgroundColor: TILE_COLOURS[i % TILE_COLOURS.length] }} href={appPathFor(t.url)} onClick={(e) => linkClick(e, t.url)}>
          <AreaIcon label={tabOf(menu, t.url) ?? 'More'} className={s.tileIcon} />{t.label}
        </a>
      ))}
    </div>
  )
}

/** The period before, in a few words (the figures compare to the same part of it: "last month to the same day"). */
const BEFORE: Record<Period, string> = { today: 'yesterday', week: 'last week', month: 'last month', quarter: 'last quarter', year: 'last year' }

function fmt(data: HomeData, k: Pick<Kpi, 'format'>, v: number, short = false): string {
  if (k.format === 'count') return count(v)
  return short ? moneyShort(v, data.currency, data.exponent) : money(v, data.currency, data.exponent)
}

/** Key performance indicators: cards for the headline figures, and the table of this period against the one before. */
export function KpisPanel({ data }: { data: HomeData }) {
  const s = useStyles()
  const [open, setOpen] = useState(true)
  const cards = data.kpis.slice(0, 4)
  if (!data.kpis.length) return <Text className={s.muted}>No figures you are allowed to see here.</Text>
  return (
    <div>
      <div className={s.cards}>
        {cards.map((k) => {
          const good = goodNews(k.key, k.change)
          return (
            <a key={k.key} className={s.card} href={appPathFor(k.url)} onClick={(e) => linkClick(e, k.url)}>
              <Text size={300}>{k.label}</Text>
              <div className={s.cardRow}>
                {k.previous !== null && k.change !== null
                  ? <span className={mergeClasses(s.big, good === true && s.up, good === false && s.down)}>{k.change >= 0 ? <ArrowUpRegular /> : <ArrowDownRegular />}{change(k.change, k.previous)}</span>
                  : <span className={s.big}>{fmt(data, k, k.current, true)}</span>}
                <Sparkline values={k.spark} label={`${k.label}, ${data.period.label}`} up={good !== false} />
              </div>
              {k.previous !== null && <Text size={200} className={s.muted}>{fmt(data, k, k.current, true)} · {data.period.label.toLowerCase()}</Text>}
            </a>
          )
        })}
      </div>
      <Table size="small" aria-label="Key performance indicators">
        <TableHeader>
          <TableRow>
            <TableHeaderCell style={{ width: 28 }}><Button appearance="transparent" size="small" icon={open ? <ChevronDownRegular /> : <ChevronRightRegular />} aria-label={open ? 'Fold the table' : 'Open the table'} onClick={() => setOpen(!open)} /></TableHeaderCell>
            <TableHeaderCell>Indicator</TableHeaderCell><TableHeaderCell>Period</TableHeaderCell>
            <TableHeaderCell className={s.num}>Current</TableHeaderCell><TableHeaderCell className={s.num}>Previous</TableHeaderCell><TableHeaderCell className={s.num}>Change</TableHeaderCell>
          </TableRow>
        </TableHeader>
        {open && (
          <TableBody>
            {data.kpis.map((k) => {
              const good = goodNews(k.key, k.change)
              return (
                <TableRow key={k.key}>
                  <TableCell />
                  <TableCell><Link href={appPathFor(k.url)} onClick={(e) => linkClick(e, k.url)}>{k.label}</Link></TableCell>
                  <TableCell className={s.muted}>{k.previous === null ? 'Now' : `${data.period.label} vs. ${BEFORE[data.period.key]}`}</TableCell>
                  <TableCell className={s.num}>{fmt(data, k, k.current)}</TableCell>
                  <TableCell className={s.num}>{k.previous === null ? '—' : fmt(data, k, k.previous)}</TableCell>
                  <TableCell className={mergeClasses(s.num, good === true && s.up, good === false && s.down)}>{k.change !== null && k.change !== 0 ? (k.change > 0 ? '↑ ' : '↓ ') : ''}{change(k.change, k.previous)}</TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        )}
      </Table>
    </div>
  )
}

/** The KPI meter: one figure on a dial (the person picks which), the period before marked on it. */
export function MeterPanel({ data, choice, onChoose }: { data: HomeData; choice: string | null; onChoose: (key: string) => void }) {
  const s = useStyles()
  if (!data.kpis.length) return <Text className={s.muted}>No figures you are allowed to see here.</Text>
  const k = data.kpis.find((x) => x.key === choice) ?? data.kpis.find((x) => x.key === 'stock') ?? data.kpis[0]
  const top = Math.max(k.current, k.previous ?? 0)
  const max = top <= 0 ? 1 : niceUp(top * 1.25)
  return (
    <div>
      <Dropdown aria-label="Figure on the meter" value={k.label} selectedOptions={[k.key]} onOptionSelect={(_, d) => d.optionValue && onChoose(d.optionValue)} style={{ minWidth: 0, width: '100%' }}>
        {data.kpis.map((x) => <Option key={x.key} value={x.key}>{x.label}</Option>)}
      </Dropdown>
      <Gauge value={k.current} previous={k.previous} max={max} label={k.previous === null ? k.label : data.period.label} display={fmt(data, k, k.current, true)}
        minLabel="0" maxLabel={fmt(data, k, max, true)} previousLabel={k.previous !== null ? `${data.period.previous}: ${fmt(data, k, k.previous, true)}` : undefined} />
    </div>
  )
}

function niceUp(v: number): number {
  const p = 10 ** Math.floor(Math.log10(v))
  return [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].map((m) => m * p).find((m) => m >= v) ?? v
}

/** Top 15 items by quantity sold, for a period of its own: as a donut, bars or a table. */
export function TopItemsPanel({ data, top, onTop }: { data: HomeData; top: Period; onTop: (p: Period) => void }) {
  const s = useStyles()
  const [view, setView] = useState<'donut' | 'bars' | 'table'>('donut')
  const items = data.top_items.map((i) => ({ name: i.name, value: i.qty, total: i.total }))
  return (
    <div>
      <div className={s.tools}>
        <Dropdown aria-label="Period for top items" size="small" value={data.period.options[top]} selectedOptions={[top]} onOptionSelect={(_, d) => d.optionValue && onTop(d.optionValue as Period)} style={{ minWidth: 130 }}>
          {Object.entries(data.period.options).map(([k, v]) => <Option key={k} value={k}>{v}</Option>)}
        </Dropdown>
        <div className={s.seg} role="group" aria-label="Show as">
          <Tooltip content="Donut" relationship="label"><ToggleButton size="small" appearance="subtle" checked={view === 'donut'} icon={<DataPieRegular />} onClick={() => setView('donut')} /></Tooltip>
          <Tooltip content="Bars" relationship="label"><ToggleButton size="small" appearance="subtle" checked={view === 'bars'} icon={<TextBulletListLtrRegular />} onClick={() => setView('bars')} /></Tooltip>
          <Tooltip content="Table" relationship="label"><ToggleButton size="small" appearance="subtle" checked={view === 'table'} icon={<TableRegular />} onClick={() => setView('table')} /></Tooltip>
        </div>
      </div>
      {items.length === 0 ? <Text className={s.muted}>Nothing sold in this period yet.</Text>
        : view === 'donut' ? <Donut items={items} format={(v) => `${count(v)} sold`} label={`Top items sold, ${data.period.options[top].toLowerCase()}`} />
          : view === 'bars' ? <HBars items={items} format={count} />
            : (
              <Table size="small" aria-label="Top items sold">
                <TableHeader><TableRow><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell className={s.num}>Sold</TableHeaderCell><TableHeaderCell className={s.num}>Sales</TableHeaderCell></TableRow></TableHeader>
                <TableBody>{items.map((i) => <TableRow key={i.name}><TableCell>{i.name}</TableCell><TableCell className={s.num}>{count(i.value)}</TableCell><TableCell className={s.num}>{money(i.total, data.currency, data.exponent)}</TableCell></TableRow>)}</TableBody>
              </Table>
            )}
    </div>
  )
}

/** The last 12 months: sales and purchases as columns or lines (one axis), with a dashed 3-month average of sales. */
export function TrendPanel({ data }: { data: HomeData }) {
  const s = useStyles()
  const [view, setView] = useState<'bars' | 'lines' | 'table'>('bars')
  const { months, series } = data.trend
  if (!series.length) return <Text className={s.muted}>No figures you are allowed to see here.</Text>
  const sales = series.find((x) => x.key === 'sales')
  const avg = sales ? sales.values.map((_, i) => Math.round(sales.values.slice(Math.max(0, i - 2), i + 1).reduce((a, v) => a + v, 0) / Math.min(3, i + 1))) : undefined
  const m = (v: number) => money(v, data.currency, data.exponent)
  return (
    <div>
      <div className={s.tools}>
        <Text size={200} className={s.muted}>Monthly, last 12 months</Text>
        <div className={s.seg} role="group" aria-label="Show as">
          <Tooltip content="Columns" relationship="label"><ToggleButton size="small" appearance="subtle" checked={view === 'bars'} icon={<DataBarVerticalRegular />} onClick={() => setView('bars')} /></Tooltip>
          <Tooltip content="Lines" relationship="label"><ToggleButton size="small" appearance="subtle" checked={view === 'lines'} icon={<DataLineRegular />} onClick={() => setView('lines')} /></Tooltip>
          <Tooltip content="Table" relationship="label"><ToggleButton size="small" appearance="subtle" checked={view === 'table'} icon={<TableRegular />} onClick={() => setView('table')} /></Tooltip>
        </div>
      </div>
      {view === 'table' ? (
        <Table size="small" aria-label="Monthly trend">
          <TableHeader><TableRow><TableHeaderCell>Month</TableHeaderCell>{series.map((x) => <TableHeaderCell key={x.key} className={s.num}>{x.label}</TableHeaderCell>)}</TableRow></TableHeader>
          <TableBody>{months.map((mo, i) => <TableRow key={mo}><TableCell>{mo}</TableCell>{series.map((x) => <TableCell key={x.key} className={s.num}>{m(x.values[i])}</TableCell>)}</TableRow>)}</TableBody>
        </Table>
      ) : (
        <TimeChart labels={months} series={series} kind={view} format={m} formatAxis={(v) => compact(v / 10 ** data.exponent)}
          dashed={avg ? { label: 'Sales, 3-month average', values: avg } : undefined} />
      )}
    </div>
  )
}

/** Shortcuts: the person's favourites, quick ways to add things, and the pages they opened last. */
export function ShortcutsPanel({ me, menu, recent }: { me: Me; menu: Menu; recent: { url: string; label: string }[] }) {
  const s = useStyles()
  const groups = [
    { label: 'Favourites', icon: 'More', items: me.favourites, empty: 'Star a page (☆ at its top in the classic back office) to keep it here.' },
    { label: 'Add', icon: 'Create', items: menu.create.flatMap((g) => g.items).slice(0, 6), empty: '' },
    { label: 'Opened lately', icon: 'Home', items: recent.slice(0, 6), empty: 'Pages you open appear here.' },
  ]
  return (
    <div className={s.short}>
      {groups.map((g) => (
        <div key={g.label}>
          <div className={s.shortH}><AreaIcon label={g.icon} />{g.label}</div>
          <div className={s.shortList}>
            {g.items.length ? g.items.map((i) => <Link key={i.url} href={appPathFor(i.url)} onClick={(e) => linkClick(e, i.url)}>{i.label}</Link>)
              : g.empty && <Text size={200} className={s.muted}>{g.empty}</Text>}
          </div>
        </div>
      ))}
    </div>
  )
}

