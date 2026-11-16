import {
  Badge, Button, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkRegular, EditRegular, QrCodeRegular, ScalesRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, type ProductDetail, type ProductsOptions } from '../api'
import { money } from '../home/format'
import { usePageStyles, when } from '../list/ListParts'
import { navigate } from '../nav'
import { ProductAction, type ProductActionKind } from './ProductActions'

const useStyles = makeStyles({
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '4px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  tiles: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: '10px', margin: '12px 0' },
  tile: { padding: '12px 14px', borderRadius: tokens.borderRadiusLarge, backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}` },
  tileValue: { display: 'block', fontSize: '20px', fontWeight: tokens.fontWeightBold, fontVariantNumeric: 'tabular-nums' },
  cols: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(380px, 1fr))', gap: '14px', alignItems: 'start', '@media (max-width: 760px)': { gridTemplateColumns: '1fr' } },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: '10px 18px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  code: { fontFamily: tokens.fontFamilyMonospace, fontSize: tokens.fontSizeBase200 },
})

const SCHEDULE: Record<string, [string, 'danger' | 'warning' | 'informative']> = { controlled: ['Controlled drug', 'danger'], pom: ['Prescription only', 'warning'], otc: ['Over the counter', 'informative'] }
const END = { style: { justifyContent: 'flex-end' } } // a numeric column's heading sits over its numbers
const day = (d: string | null) => (d ? new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * One product (release 3): what the current form holds, to read at a glance (details, medicine, units and barcodes,
 * prices with how they are set), and what the form does not show: the stock in each warehouse the person may see, the
 * batches with their expiry, and the latest stock movements. Edit opens the current form; labels and the unit are here.
 */
export function ProductPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [p, setP] = useState<ProductDetail | null>(null)
  const [options, setOptions] = useState<ProductsOptions | null>(null)
  const [error, setError] = useState('')
  const [action, setAction] = useState<ProductActionKind | null>(null)

  const load = useCallback(() => {
    api.product(id).then((x) => { setP(x); onTitle(x.name) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])
  useEffect(() => { api.productsOptions().then(setOptions).catch(() => undefined) }, [])

  if (error) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!p) return <div className={page.page}><Spinner label="Opening the product…" /></div>
  const m = (v: number) => money(v, p.currency, p.exponent)
  const yes = (v: boolean) => (v ? <CheckmarkRegular aria-label="Yes" /> : <Text className={s.muted}>—</Text>)
  const expired = p.batches.filter((b) => b.expired).length

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/products'))}>Products</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          {p.name}
          {p.active ? <Badge appearance="tint" color="success">For sale</Badge> : <Badge appearance="tint" color="subtle">Not for sale</Badge>}
          {p.schedule && SCHEDULE[p.schedule] && <Badge appearance="tint" color={SCHEDULE[p.schedule][1]}>{SCHEDULE[p.schedule][0]}</Badge>}
        </h1>
        <div className={s.actions}>
          {p.actions.edit && <Button appearance="primary" icon={<EditRegular />} onClick={() => navigate(p.actions.edit!)}>Edit</Button>}
          {p.actions.labels && <Button icon={<QrCodeRegular />} disabled={!options} onClick={() => setAction('labels')}>Print labels</Button>}
          {p.actions.change_unit && <Button icon={<ScalesRegular />} disabled={!options} onClick={() => setAction('unit')}>Change the unit</Button>}
        </div>
      </div>
      <Text className={s.muted}>SKU {p.sku}{p.description ? ` · ${p.description}` : ''}</Text>

      <div className={s.tiles}>
        {p.stock_total !== null && <Tile label="On hand (where you can see)" value={`${p.stock_total} ${p.unit ?? ''}`} tone={Number(p.stock_total) <= 0 ? 'var(--zx-bad)' : undefined} />}
        {!p.tracked && <Tile label="Stock" value="Not tracked" />}
        {p.prices[0] && <Tile label={`${p.prices[0].list ?? 'Price'} · ${p.prices[0].unit ?? ''}`} value={m(p.prices[0].amount)} tone={p.below_cost ? 'var(--zx-bad)' : undefined} />}
        {p.units.length > 1 && <Tile label="Units" value={String(p.units.length)} />}
        {p.batches.length > 0 && <Tile label="Batches in stock" value={String(p.batches.length)} />}
        {expired > 0 && <Tile label="Expired batches" value={String(expired)} tone="var(--zx-bad)" />}
      </div>

      {p.below_cost && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>A price is below the cost: each sale loses money.</MessageBarBody></MessageBar>}

      <div className={s.cols}>
        <div>
          <Panel title="Details"><Facts items={p.facts} /></Panel>
          {Object.keys(p.medicine).length > 0 && <Panel title="Medicine"><Facts items={p.medicine} /></Panel>}
          <Panel title="Barcodes and units">
            <Table size="small" aria-label="Barcodes and units">
              <TableHeader><TableRow><TableHeaderCell>Unit</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Base units in one</TableHeaderCell>
                <TableHeaderCell>Sold</TableHeaderCell><TableHeaderCell>Bought</TableHeaderCell><TableHeaderCell>Barcodes</TableHeaderCell></TableRow></TableHeader>
              <TableBody>{p.units.map((u) => (
                <TableRow key={u.unit}>
                  <TableCell><Text weight={u.base ? 'semibold' : 'regular'}>{u.unit}</Text>{u.base && <Text size={200} className={s.muted}> (base)</Text>}</TableCell>
                  <TableCell className={s.end}>{u.factor}</TableCell><TableCell>{yes(u.sold)}</TableCell><TableCell>{yes(u.bought)}</TableCell>
                  <TableCell><span className={s.code}>{u.barcodes.join(', ') || '—'}</span></TableCell>
                </TableRow>
              ))}</TableBody>
            </Table>
          </Panel>
          <Panel title="Prices">
            {p.pricing_note && <Text block size={200} style={{ marginBottom: 8, color: p.below_cost ? 'var(--zx-bad)' : tokens.colorNeutralForeground3 }}>{p.pricing_note}</Text>}
            {p.prices.length === 0 ? <Text className={s.muted}>No prices yet.</Text> : (
              <Table size="small" aria-label="Prices">
                <TableHeader><TableRow><TableHeaderCell>List</TableHeaderCell><TableHeaderCell>Unit</TableHeaderCell><TableHeaderCell className={s.end} button={END}>From qty</TableHeaderCell>
                  <TableHeaderCell className={s.end} button={END}>Price</TableHeaderCell><TableHeaderCell>When</TableHeaderCell></TableRow></TableHeader>
                <TableBody>{p.prices.map((x, i) => (
                  <TableRow key={i}>
                    <TableCell>{x.list ?? '—'}</TableCell><TableCell>{x.unit ?? '—'}</TableCell><TableCell className={s.end}>{x.from}</TableCell>
                    <TableCell className={s.end}><Text weight="semibold">{m(x.amount)}</Text></TableCell>
                    <TableCell>{x.starts || x.ends ? `${x.starts ? when(x.starts) : '…'} – ${x.ends ? when(x.ends) : '…'}` : 'Always'}</TableCell>
                  </TableRow>
                ))}</TableBody>
              </Table>
            )}
          </Panel>
        </div>
        <div>
          {p.stock_shown && p.tracked && (
            <Panel title="Stock">
              {p.stock.length === 0 ? <Text className={s.muted}>None in the warehouses you can see.</Text> : (
                <Table size="small" aria-label="Stock">
                  <TableHeader><TableRow><TableHeaderCell>Where</TableHeaderCell><TableHeaderCell className={s.end} button={END}>On hand</TableHeaderCell></TableRow></TableHeader>
                  <TableBody>{p.stock.map((x) => (
                    <TableRow key={x.where}><TableCell>{x.where}</TableCell>
                      <TableCell className={s.end}><Text weight="semibold" style={{ color: x.low ? 'var(--zx-bad)' : undefined }}>{x.on_hand} {p.unit}</Text></TableCell></TableRow>
                  ))}</TableBody>
                </Table>
              )}
            </Panel>
          )}
          {p.batches.length > 0 && (
            <Panel title="Batches in stock">
              <Table size="small" aria-label="Batches in stock">
                <TableHeader><TableRow><TableHeaderCell>Batch</TableHeaderCell><TableHeaderCell>Expires</TableHeaderCell><TableHeaderCell className={s.end} button={END}>On hand</TableHeaderCell></TableRow></TableHeader>
                <TableBody>{p.batches.map((b) => (
                  <TableRow key={b.batch}><TableCell><span className={s.code}>{b.batch}</span></TableCell>
                    <TableCell><Text style={{ color: b.expired ? 'var(--zx-bad)' : undefined }}>{day(b.expires)}{b.expired ? ' · expired' : ''}</Text></TableCell>
                    <TableCell className={s.end}>{b.on_hand} {p.unit}</TableCell></TableRow>
                ))}</TableBody>
              </Table>
            </Panel>
          )}
          {p.stock_shown && p.tracked && (
            <Panel title="Latest stock movements">
              {p.movements.length === 0 ? <Text className={s.muted}>Nothing has moved yet.</Text> : (
                <Table size="small" aria-label="Latest stock movements">
                  <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>What</TableHeaderCell><TableHeaderCell>Where</TableHeaderCell>
                    <TableHeaderCell className={s.end} button={END}>Qty</TableHeaderCell></TableRow></TableHeader>
                  <TableBody>{p.movements.map((x, i) => (
                    <TableRow key={i}>
                      <TableCell>{when(x.at)}</TableCell>
                      <TableCell>{x.type}{(x.reason || x.by) && <Text block size={200} className={s.muted}>{[x.reason, x.by].filter(Boolean).join(' · ')}</Text>}</TableCell>
                      <TableCell>{x.where ?? '—'}</TableCell>
                      <TableCell className={s.end}><Text weight="semibold" style={{ color: x.in ? 'var(--zx-good)' : undefined }}>{x.in && !x.qty.startsWith('-') ? '+' : ''}{x.qty}</Text></TableCell>
                    </TableRow>
                  ))}</TableBody>
                </Table>
              )}
            </Panel>
          )}
        </div>
      </div>

      {action && options && <ProductAction kind={action} pick={{ ids: [p.id] }} count={1} options={options} note={p.change_unit_note}
        onClose={() => setAction(null)} onDone={load} />}
    </div>
  )

  function Panel({ title, children }: { title: string; children: ReactNode }) {
    return <section className={s.panel} aria-label={title}><h2 className={s.h2}>{title}</h2>{children}</section>
  }
  function Facts({ items }: { items: Record<string, string> }) {
    return <div className={s.facts}>{Object.entries(items).map(([k, v]) => <div key={k}><Text size={200} block className={s.muted}>{k}</Text><Text weight="semibold">{v}</Text></div>)}</div>
  }
  function Tile({ label, value, tone }: { label: string; value: string; tone?: string }) {
    return <div className={s.tile}><Text size={200} className={s.muted}>{label}</Text><span className={s.tileValue} style={tone ? { color: tone } : undefined}>{value}</span></div>
  }
}
