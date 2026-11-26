import { Button, makeStyles, MessageBar, MessageBarBody, SearchBox, Text, tokens } from '@fluentui/react-components'
import { ArrowRightRegular, SparkleRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SharedProductRow, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { Choose } from '../form/Fields'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

type Doing = { kind: 'all' } | { kind: 'suggested' } | null

/**
 * Shared products (catalog): products in no industry, which every industry's tills sell, with where they really have
 * stock or recent sales and the industry that suggests. Move the ticked ones to their suggested industry, or all of
 * them to one industry. Nothing moves by itself.
 */
export function SharedProductsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const [doing, setDoing] = useState<Doing>(null)
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.sharedProducts, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const select = (ids: string[], on: boolean) => setTicked((t) => { const n = new Set(t); ids.forEach((id) => (on ? n.add(id) : n.delete(id))); return n })
  const finish = (r: UserDone) => { setDoing(null); setDone(r.message); setTicked(new Set()); setNonce((n) => n + 1) }
  const columns: Column<SharedProductRow>[] = [
    { key: 'sku', label: 'SKU', render: (r) => <Text className={s.muted}>{r.sku}</Text> },
    { key: 'name', label: 'Name', sort: 'name', render: (r) => <Text weight="semibold">{r.name}</Text> },
    { key: 'category', label: 'Category', render: (r) => r.category ?? '—' },
    { key: 'used', label: 'Stock or sales in', render: (r) => r.used || <Text className={s.muted}>Nowhere yet</Text> },
    { key: 'suggested', label: 'Suggested industry', render: (r) => r.suggested ?? <Text className={s.muted}>Your choice</Text> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Shared products" hint="These products show on every industry's tills. Move each to its own industry, and leave only what every shop really sells." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name or code" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search shared products" />
        <span className={s.grow} />
        <Button icon={<SparkleRegular />} disabled={ticked.size === 0} onClick={() => { setDone(''); setDoing({ kind: 'suggested' }) }}>
          Move to suggested industry{ticked.size ? ` (${ticked.size})` : ''}
        </Button>
        <Button appearance="primary" icon={<ArrowRightRegular />} disabled={!data?.all} onClick={() => { setDone(''); setDoing({ kind: 'all' }) }}>Move all to one industry</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Shared products" columns={columns} rows={data?.rows ?? []} sort="name" dir={str(params, 'dir') === 'desc' ? 'desc' : 'asc'} loading={loading}
        selected={ticked} onSelect={select} empty="No shared products: every product belongs to one industry."
        onSort={(_, dir) => set({ dir: dir === 'desc' ? 'desc' : '' })} onOpen={(r) => select([r.id], !ticked.has(r.id))} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {doing?.kind === 'suggested' && <Ask title={`Move ${ticked.size} product(s) to their suggested industry?`} go="Move them" ready onClose={() => setDoing(null)}
        run={async () => finish(await api.moveSuggested([...ticked]))}>
        <Text>Each goes to the industry where it has stock or sales. Products with no suggestion stay here. Their stock and prices stay.</Text>
      </Ask>}
      {doing?.kind === 'all' && data && <MoveAll count={data.all} industries={data.industries} onClose={() => setDoing(null)} onDone={finish} />}
    </div>
  )
}

function MoveAll({ count, industries, onClose, onDone }: { count: number; industries: { id: string; label: string }[]; onClose: () => void; onDone: (r: UserDone) => void }) {
  const [to, setTo] = useState('')
  return (
    <Ask title="Move all shared products to one industry?" go="Move them" ready={to !== ''} onClose={onClose} run={async () => onDone(await api.moveAllShared(to))}>
      <Text block>{count.toLocaleString()} shared product(s) will move. Their stock and prices stay. You can still move any of them again.</Text>
      <div style={{ marginTop: 10 }}><Choose label="Industry" required options={industries} value={to} onChange={setTo} /></div>
    </Ask>
  )
}
