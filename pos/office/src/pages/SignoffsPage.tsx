import { Badge, Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Text, tokens } from '@fluentui/react-components'
import { ArrowDownloadRegular, CheckmarkCircleRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type SignoffRow } from '../api'
import { Ask } from '../form/Ask'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '8px 12px', flexWrap: 'wrap', marginBottom: '10px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  bad: { color: tokens.colorPaletteRedForeground1 },
})

const SHOW = [['', 'Waiting to be signed off'], ['signed', 'Signed off'], ['all', 'All']]

/**
 * Sales to sign off (pharmacy): prescription-only and controlled medicines sold where tellers may sell them. A
 * pharmacist checks each sale and signs it off; the list is the record for an inspection.
 */
export function SignoffsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [ticked, setTicked] = useState<Set<string>>(new Set())
  const [signing, setSigning] = useState(false)
  const [note, setNote] = useState('')
  const [done, setDone] = useState('')
  const query = serialize(params)
  const { data, loading, error } = useList(api.signoffs, query, nonce)
  const show = str(params, 'status')
  const branch = str(params, 'branch')
  const select = (ids: string[], on: boolean) => setTicked((t) => { const n = new Set(t); ids.forEach((id) => (on ? n.add(id) : n.delete(id))); return n })
  const columns: Column<SignoffRow>[] = [
    { key: 'at', label: 'Sold', render: (r) => when(r.at) },
    { key: 'receipt', label: 'Receipt', render: (r) => <>{r.receipt}{r.branch && <Text block size={200} className={s.muted}>{r.branch}</Text>}</> },
    { key: 'sold_by', label: 'Sold by', render: (r) => r.sold_by ?? '—' },
    { key: 'medicines', label: 'Medicines', render: (r) => <>{r.controlled && <Badge appearance="tint" color="danger" style={{ marginRight: 6 }}>Controlled</Badge>}<Text className={r.controlled ? s.bad : undefined}>{r.medicines}</Text></> },
    { key: 'signed', label: 'Signed off', render: (r) => (r.signed_by ? <>{r.signed_by}<Text block size={200} className={s.muted}>{when(r.signed_at)}{r.note ? ` · ${r.note}` : ''}</Text></> : <Text className={s.muted}>waiting</Text>) },
  ]
  const csv = `/office-api/medicine-signoffs/csv${query ? `?${query}` : ''}`
  return (
    <div className={page.page}>
      <PageHead title="Medicines sold without a pharmacist" hint="Prescription-only and controlled medicines sold where tellers may sell them. A pharmacist checks each sale and signs it off; the list is the record for an inspection." />
      <div className={s.bar}>
        <Field label="Show" size="small">
          <Dropdown size="small" style={{ minWidth: 200 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => { setTicked(new Set()); set({ status: d.optionValue ?? '' }) }}>
            {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Branch" size="small">
          <Dropdown size="small" style={{ minWidth: 170 }} value={branch === '' ? 'All branches' : data?.branches.find((b) => b.id === branch)?.label ?? ''} selectedOptions={[branch]}
            onOptionSelect={(_, d) => set({ branch: d.optionValue ?? '' })}>
            <Option value="">All branches</Option>{(data?.branches ?? []).map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="From" size="small"><Input size="small" type="date" value={str(params, 'from') || data?.from || ''} onChange={(_, d) => set({ from: d.value })} /></Field>
        <Field label="To" size="small"><Input size="small" type="date" value={str(params, 'to') || data?.to || ''} onChange={(_, d) => set({ to: d.value })} /></Field>
        <span className={s.grow} />
        <Button icon={<ArrowDownloadRegular />} onClick={() => { window.location.href = csv }}>Download (CSV)</Button>
        {data?.can_sign && <Button appearance="primary" icon={<CheckmarkCircleRegular />} disabled={ticked.size === 0} onClick={() => { setDone(''); setNote(''); setSigning(true) }}>
          Sign off{ticked.size ? ` (${ticked.size})` : ''}
        </Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {data?.capped && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>Only the latest 2,000 sales are shown: choose a shorter period.</MessageBarBody></MessageBar>}
      <DataTable label="Sales to sign off" columns={columns} rows={data?.rows ?? []} sort="at" dir="desc" loading={loading}
        empty="Nothing to sign off: no prescription-only or controlled medicine was sold without a pharmacist in this period."
        selected={data?.can_sign ? ticked : undefined} onSelect={data?.can_sign ? select : undefined}
        onSort={() => undefined} onOpen={(r) => { if (data?.can_sign && !r.signed_by) select([r.id], !ticked.has(r.id)) }} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {signing && <Ask title={`Sign off ${ticked.size} sale(s)?`} go="Sign off" ready onClose={() => setSigning(false)}
        run={async () => { const r = await api.signOff([...ticked], note); setSigning(false); setTicked(new Set()); setDone(r.message); setNonce((n) => n + 1) }}>
        <Field label="Note (optional)"><Input value={note} maxLength={255} placeholder="e.g. checked with the patient, counselled" onChange={(_, d) => setNote(d.value)} /></Field>
      </Ask>}
    </div>
  )
}
