import {
  Badge, Button, Dropdown, Field, Link, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ClipboardTaskRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { day } from './SupplierReturnsPage'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '10px', marginBottom: '12px' },
  cards: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(210px, 1fr))', gap: '12px', marginBottom: '14px' },
  card: { display: 'grid', gap: '2px', padding: '14px 16px', borderRadius: tokens.borderRadiusLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    backgroundColor: tokens.colorNeutralBackground1, textAlign: 'left', color: 'inherit', font: 'inherit' },
  link: { cursor: 'pointer', ':hover': { border: `1px solid ${tokens.colorBrandStroke1}` } },
  value: { fontSize: '28px', fontWeight: tokens.fontWeightBold, lineHeight: 1.1 },
  label: { fontWeight: tokens.fontWeightSemibold },
  hint: { fontSize: tokens.fontSizeBase200, color: tokens.colorNeutralForeground3 },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px', overflowX: 'auto' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  sub: { fontWeight: tokens.fontWeightSemibold, margin: '12px 0 4px', display: 'block' },
  muted: { color: tokens.colorNeutralForeground3 },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
})

const TONE = { danger: 'var(--zx-bad)', warning: 'var(--zx-warn)', ok: undefined, info: undefined }

/**
 * My day (part 17), for the people who look after stock: in one store, today's cycle count (a few products a day
 * instead of a big stock-take: start it here), what needs doing — low stock, expiring and expired batches, negative
 * stock, products never counted, adjustments and transfers waiting, orders to receive; each opens where it is dealt
 * with — and checks worth a second look (larger losses than usual, products written off again and again, possible
 * duplicates).
 */
export function MyDayPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.myDay, serialize(params))
  const [starting, setStarting] = useState(false)

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Looking at the store…" />}</div>
  const m = (v: number) => money(v, data.currency ?? 'GHS', data.exponent ?? 2)
  const c = data.cycle
  const ch = data.checks
  return (
    <div className={page.page}>
      <PageHead title="My day" hint="What needs doing in a store today. Each figure opens the page where it is dealt with." classic="/admin/stock/my-day" />
      <div className={s.bar}>
        <Field label="Store" size="small">
          <Dropdown size="small" style={{ minWidth: 220 }} value={data.warehouses.find((x) => x.id === data.warehouse)?.label ?? ''} selectedOptions={data.warehouse ? [data.warehouse] : []}
            onOptionSelect={(_, d) => set({ warehouse: d.optionValue ?? '' })}>
            {data.warehouses.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
          </Dropdown>
        </Field>
        {loading && <Spinner size="tiny" />}
      </div>

      {c.on && (
        <section className={s.panel} aria-label="Today's cycle count">
          <h2 className={s.h2}>Today's cycle count</h2>
          <Text block className={s.muted} style={{ marginBottom: 8 }}>A few products a day instead of a big stock-take: fast-moving and valuable ones more often. Up to {c.per_day} a day (System → Settings → Stock).</Text>
          {c.started ? <Text>Started: <Link onClick={() => navigate(c.started!.open)}>{c.started.number}</Link> — {c.started.status}.</Text>
            : c.due.length === 0 ? <Text>Nothing is due today. Well done.</Text> : (
              <>
                <Table size="small" aria-label="Due today">
                  <TableHeader><TableRow><TableHeaderCell>Product</TableHeaderCell><TableHeaderCell>Class</TableHeaderCell><TableHeaderCell>Last counted</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
                  <TableBody>{c.due.map((d, i) => (
                    <TableRow key={i}><TableCell>{d.name} <Text size={200} className={s.muted}>{d.sku}</Text></TableCell><TableCell>{d.class}</TableCell>
                      <TableCell>{d.last_counted ? day(d.last_counted) : 'never'}</TableCell><TableCell>{d.often_wrong && <Badge appearance="tint" color="warning">often wrong</Badge>}</TableCell></TableRow>
                  ))}</TableBody>
                </Table>
                {c.can_start && <Button appearance="primary" icon={<ClipboardTaskRegular />} style={{ marginTop: 10 }} onClick={() => setStarting(true)}>Start today's count</Button>}
              </>
            )}
          {c.wrong.length > 0 && <Text block size={200} className={s.muted} style={{ marginTop: 8 }}>Often wrong when counted (counted twice as often): {c.wrong.join(', ')}.</Text>}
        </section>
      )}

      <div className={s.cards}>
        {data.cards.map((x) => {
          const tone = x.value ? TONE[x.tone] : undefined
          const body = <><span className={s.value} style={{ color: tone, opacity: x.value ? 1 : 0.55 }}>{x.value.toLocaleString('en-GB')}</span><span className={s.label}>{x.label}</span><span className={s.hint}>{x.hint}</span></>
          return x.url
            ? <button key={x.label} type="button" className={`${s.card} ${s.link}`} onClick={() => navigate(x.url!)}>{body}</button>
            : <div key={x.label} className={s.card}>{body}</div>
        })}
      </div>

      {ch && (ch.larger.length > 0 || ch.again.length > 0 || ch.duplicates.length > 0) && (
        <section className={s.panel} aria-label="Checks">
          <h2 className={s.h2}>Checks</h2>
          <Text block className={s.muted}>Worth a second look — not a verdict. Last 30 days in this store.</Text>
          {ch.larger.length > 0 && (
            <>
              <Text className={s.sub}>Losses larger than usual{ch.usual !== null ? ` (usual here: ${m(ch.usual)} a line)` : ''}</Text>
              <Table size="small" aria-label="Losses larger than usual"><TableBody>{ch.larger.map((r, i) => (
                <TableRow key={i}><TableCell>{day(r.when)}</TableCell><TableCell>{r.name} <Text size={200} className={s.muted}>{r.sku}</Text></TableCell>
                  <TableCell>{r.reason} · {r.by}</TableCell><TableCell className={s.end}>{m(r.value)}</TableCell>
                  <TableCell>{r.open && <Link onClick={() => navigate(r.open!)}>Open</Link>}</TableCell></TableRow>
              ))}</TableBody></Table>
            </>
          )}
          {ch.again.length > 0 && (
            <>
              <Text className={s.sub}>Written off three times or more</Text>
              <Table size="small" aria-label="Written off again and again"><TableBody>{ch.again.map((r, i) => (
                <TableRow key={i}><TableCell>{r.name} <Text size={200} className={s.muted}>{r.sku}</Text></TableCell><TableCell>{r.times} times</TableCell><TableCell className={s.end}>{m(r.value)}</TableCell></TableRow>
              ))}</TableBody></Table>
            </>
          )}
          {ch.duplicates.length > 0 && (
            <>
              <Text className={s.sub}>Possible duplicate products (same name)</Text>
              <Table size="small" aria-label="Possible duplicates"><TableBody>{ch.duplicates.map((r, i) => (
                <TableRow key={i}><TableCell>{r.name}</TableCell><TableCell className={s.muted}>{r.skus}</TableCell></TableRow>
              ))}</TableBody></Table>
              <Text block size={200} className={s.muted} style={{ marginTop: 6 }}>Move the stock and sales to one of them, or ask SyncLytics support to merge them.</Text>
            </>
          )}
        </section>
      )}

      {starting && data.warehouse && (
        <Ask title="Start today's count?" go="Start" ready onClose={() => setStarting(false)}
          run={async () => { const r = await api.startCycle(data.warehouse!); setStarting(false); navigate(r.open) }}>
          <Text>A blind count of the products listed: count them on the shelf (or with the handheld), then submit. A manager posts it.</Text>
        </Ask>
      )}
    </div>
  )
}
