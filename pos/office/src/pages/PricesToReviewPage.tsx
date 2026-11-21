import { Button, makeStyles, MessageBar, MessageBarBody, SearchBox, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, CheckmarkRegular, DismissRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SuggestionRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  acts: { display: 'flex', gap: '4px' },
})

/** Prices to review (catalog and pricing setup): new prices from the pricing rules waiting for a manager. Approve them, or keep the current price (one, or several ticked at once). */
export function PricesToReviewPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const [done, setDone] = useState('')
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const { data, loading, error } = useList(api.pricesToReview, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const m = (v: number | null) => (v === null || !data ? '—' : money(v, data.currency, data.exponent))
  const decide = async (step: 'approve' | 'keep', ids: string[]) => {
    setBusy(true); setErr(''); setDone('')
    try { setDone((await api.decidePrices(step, ids)).message); setTicked(new Set()); setNonce((n) => n + 1) } catch (e) { setErr(e instanceof Error ? e.message : String(e)) } finally { setBusy(false) }
  }
  const select = (ids: string[], on: boolean) => setTicked((t) => { const n = new Set(t); ids.forEach((id) => (on ? n.add(id) : n.delete(id))); return n })
  const columns: Column<SuggestionRow>[] = [
    { key: 'product', label: 'Product', render: (r) => <><Text weight="semibold">{r.product ?? '—'}</Text><Text block size={200} className={s.muted}>{r.sku}</Text></> },
    { key: 'unit', label: 'Unit', render: (r) => r.unit ?? '—' },
    { key: 'list', label: 'Price list', render: (r) => r.price_list ?? '—' },
    { key: 'cost', label: 'Cost', end: true, render: (r) => m(r.cost) },
    { key: 'now', label: 'Now', end: true, render: (r) => m(r.now) },
    { key: 'new', label: 'New price', end: true, render: (r) => <><Text weight="bold">{m(r.new)}</Text><Text block size={200} className={s.muted}>{r.change}</Text></> },
    { key: 'why', label: 'Why', render: (r) => <Text size={200} className={s.muted}>{r.why}</Text> },
    { key: 'acts', label: '', render: (r) => <div className={s.acts} onClick={(e) => e.stopPropagation()}>
      <Button size="small" icon={<CheckmarkRegular />} disabled={busy} onClick={() => void decide('approve', [r.id])}>Approve</Button>
      <Button size="small" appearance="subtle" icon={<DismissRegular />} disabled={busy} onClick={() => void decide('keep', [r.id])}>Keep current</Button>
    </div> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Prices to review" hint="New prices from the pricing rules, waiting for your approval. Approve them (several at once with the boxes) or keep the current price." classic="/admin/prices-to-review" />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/pricing-rules')}>Pricing rules</Button>
        <SearchBox className={s.search} placeholder="Product name or code" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search prices to review" />
        <span className={s.grow} />
        <Button icon={<DismissRegular />} disabled={busy || ticked.size === 0} onClick={() => void decide('keep', [...ticked])}>Keep current prices</Button>
        <Button appearance="primary" icon={<CheckmarkRegular />} disabled={busy || ticked.size === 0} onClick={() => void decide('approve', [...ticked])}>Approve selected{ticked.size ? ` (${ticked.size})` : ''}</Button>
      </div>
      {(error || err) && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error || err}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Prices to review" columns={columns} rows={data?.rows ?? []} sort="since" dir="desc" loading={loading} selected={ticked} onSelect={select}
        empty="No prices waiting. New prices from the pricing rules appear here when a rule asks for approval." onSort={() => undefined} onOpen={() => undefined} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
