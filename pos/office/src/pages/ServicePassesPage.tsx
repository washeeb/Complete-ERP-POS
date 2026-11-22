import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Table, TableBody, TableCell,
  TableRow, Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { PassList } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = PassList['rows'][number]
const SOURCES = [{ id: 'pack', label: 'Pack' }, { id: 'membership', label: 'Membership' }]

/**
 * Services → Prepaid passes (part 26): customers' packs and membership periods — sessions left, expiry and status. A row
 * opens its history (sessions used and given back); Void stops it (refund anything owed separately). Sessions are used at
 * the till.
 */
export function ServicePassesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.servicePasses, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [hist, setHist] = useState<Awaited<ReturnType<typeof api.passHistory>> | null>(null)
  const [voiding, setVoiding] = useState<Row | null>(null)
  const [why, setWhy] = useState('')
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = data?.status ?? (str(params, 'status') || 'active')
  return (
    <div className={page.page}>
      <PageHead title="Prepaid passes" hint="Packs and membership periods: sessions left, history, void. Sessions are used at the till." classic="/admin/service-passes" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, customer or phone" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search passes" />
        <Dropdown aria-label="Status" value={status === 'all' ? 'Any status' : data?.statuses.find((x) => x.id === status)?.label ?? 'Active'} selectedOptions={[status]} style={{ minWidth: 130 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? 'active' })}>
          {(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}<Option value="all">Any status</Option>
        </Dropdown>
        <Pick label="Source" any="Packs and memberships" options={SOURCES} value={str(params, 'source')} onChange={(v) => set({ source: v })} />
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Prepaid passes" rows={data?.rows ?? []} sort="added" dir="desc" loading={loading} empty="No passes match." onSort={() => undefined}
        onOpen={(r) => api.passHistory(r.id).then(setHist).catch(() => undefined)}
        columns={[
          { key: 'number', label: 'Number', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'customer', label: 'Customer', render: (r) => <>{r.customer ?? '—'}{r.phone && <Text size={200} block className={s.muted}>{r.phone}</Text>}</> },
          { key: 'name', label: 'Pass', render: (r) => r.name },
          { key: 'left', label: 'Sessions left', render: (r) => r.left },
          { key: 'expires', label: 'Expires', render: (r) => (r.expires ? day(r.expires) : 'Never') },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.tone} label={r.status} />{r.void_reason && <Text size={200} block className={s.muted}>{r.void_reason}</Text>}</> },
          { key: 'act', label: '', render: (r) => r.can_void && <Button size="small" onClick={(e) => { e.stopPropagation(); setWhy(''); setDone(''); setVoiding(r) }}>Void</Button> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {hist && (
        <Dialog open onOpenChange={(_, d) => { if (!d.open) setHist(null) }}>
          <DialogSurface style={{ maxWidth: 620 }}><DialogBody>
            <DialogTitle>{hist.title}</DialogTitle>
            <DialogContent>{hist.rows.length === 0 ? <Text>No sessions used yet.</Text> : (
              <Table size="small" aria-label="Pass history"><TableBody>{hist.rows.map((u) => (
                <TableRow key={u.id}><TableCell>{u.when}</TableCell><TableCell>{u.item}</TableCell><TableCell className={s.end}>{u.sessions}</TableCell><TableCell>{u.by}</TableCell></TableRow>
              ))}</TableBody></Table>
            )}</DialogContent>
            <DialogActions><Button onClick={() => setHist(null)}>Close</Button></DialogActions>
          </DialogBody></DialogSurface>
        </Dialog>
      )}
      {voiding && (
        <Ask title={`Void ${voiding.number}?`} go="Void" ready={why.trim() !== ''} onClose={() => setVoiding(null)}
          run={() => api.voidPass(voiding.id, why).then((x) => { setVoiding(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <div className={s.one}>
            <Text>The customer can no longer use it. Refund anything owed separately (e.g. a refund without receipt at the till).</Text>
            <TextField label="Reason" required maxLength={255} value={why} onChange={setWhy} />
          </div>
        </Ask>
      )}
    </div>
  )
}
