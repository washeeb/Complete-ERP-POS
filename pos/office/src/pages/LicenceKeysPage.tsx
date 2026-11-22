import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { ArrowUploadRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { KeyList } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField } from '../form/Fields'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'
import { ShownOnceDialog } from './ShownOnce'

type Row = KeyList['rows'][number]

/**
 * Services → Licence keys (part 26): software keys loaded per product and branch, handed out one per unit sold (printed on
 * the receipt). The list shows only each key's last characters. Load keys, Hand out keys still owed (sales made while a
 * product had none left), Show key and Void.
 */
export function LicenceKeysPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.licenceKeys, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [load, setLoad] = useState<{ product_id: string; branch_id: string; keys: string } | null>(null)
  const [owed, setOwed] = useState(false)
  const [voiding, setVoiding] = useState<Row | null>(null)
  const [why, setWhy] = useState('')
  const [shown, setShown] = useState<string | null>(null)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (m: string) => { setLoad(null); setOwed(false); setVoiding(null); setDone(m); setRefresh((n) => n + 1) }
  return (
    <div className={page.page}>
      <PageHead title="Licence keys" hint="Loaded per product and branch, handed out one per unit sold (printed on the receipt)." classic="/admin/licence-keys" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Last characters of the key" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search keys" />
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <Pick label="Product" any="Any product" options={data?.products ?? []} value={str(params, 'product')} onChange={(v) => set({ product: v })} />
        <span className={s.grow} />
        <Button onClick={() => { setDone(''); setOwed(true) }}>Hand out keys still owed</Button>
        <Button appearance="primary" icon={<ArrowUploadRegular />} onClick={() => { setDone(''); setLoad({ product_id: '', branch_id: data?.branches[0]?.id ?? '', keys: '' }) }}>Load keys</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Licence keys" rows={data?.rows ?? []} sort="added" dir="desc" loading={loading} empty="No keys match." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'product', label: 'Product', render: (r) => r.product },
          { key: 'branch', label: 'Branch', render: (r) => r.branch },
          { key: 'key', label: 'Key', render: (r) => <Text font="monospace">{r.hint}</Text> },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'sold', label: 'Sold', render: (r) => <>{r.sold_at ?? '—'}{r.receipt && <Text size={200} block className={s.muted}>{r.receipt}</Text>}</> },
          { key: 'act', label: '', render: (r) => (
            <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for key ${r.hint}`} /></MenuTrigger>
              <MenuPopover><MenuList>
                <MenuItem onClick={() => api.showKey(r.id).then((x) => setShown(x.key)).catch(() => undefined)}>Show key</MenuItem>
                {r.can_void && <MenuItem onClick={() => { setWhy(''); setVoiding(r) }}>Void</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {load && data && (
        <Ask title="Load keys" go="Load" width={600} ready={!!load.product_id && !!load.branch_id && load.keys.trim() !== ''} onClose={() => setLoad(null)}
          run={() => api.loadKeys(load).then((x) => finish(x.message))}>
          <div className={s.one}>
            <Choose label="Product" required options={data.products} value={load.product_id} onChange={(x) => setLoad({ ...load, product_id: x })}
              hint='Products with "Hands out a licence key" switched on (Product → Services).' />
            <Choose label="Sold at (branch)" required options={data.branches} value={load.branch_id} onChange={(x) => setLoad({ ...load, branch_id: x })} />
            <LongText label="Keys, one per line" value={load.keys} onChange={(x) => setLoad({ ...load, keys: x })} />
          </div>
        </Ask>
      )}
      {owed && (
        <Ask title="Hand out keys still owed?" go="Hand them out" ready onClose={() => setOwed(false)} run={() => api.keysOwed().then((x) => finish(x.message))}>
          <Text>For sales made while a product had no keys left, take keys from the pool now. Reprint the receipt or tell the customer the key.</Text>
        </Ask>
      )}
      {voiding && (
        <Ask title={`Void key ${voiding.hint}?`} go="Void" ready={why.trim() !== ''} onClose={() => setVoiding(null)} run={() => api.voidKey(voiding.id, why).then((x) => finish(x.message))}>
          <TextField label="Reason" required maxLength={255} value={why} onChange={setWhy} />
        </Ask>
      )}
      {shown && <ShownOnceDialog secret={{ title: 'Licence key', value: shown }} onClose={() => setShown(null)} />}
    </div>
  )
}
