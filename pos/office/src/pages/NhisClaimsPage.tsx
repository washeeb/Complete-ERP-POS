import { Badge, Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { DocumentAddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type NhisClaimList, type NhisClaimRow } from '../api'
import { Ask } from '../form/Ask'
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
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

export const CLAIM_COLOUR: Record<string, 'warning' | 'informative' | 'success' | 'subtle'> = { open: 'warning', submitted: 'informative', settled: 'success' }
export const day = (d: string | null) => (d ? new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * NHIS claims (pharmacy): the NHIS parts of sales claimed from the NHIA. Prepare a claim for a branch up to a day,
 * then open it to download the list, mark it sent, and record what the NHIA paid.
 */
export function NhisClaimsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [preparing, setPreparing] = useState(false)
  const { data, loading, error } = useList(api.nhisClaims, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = str(params, 'status')
  const m = (v: number | null) => (v === null || !data ? '—' : money(v, data.currency, data.exponent))
  const columns: Column<NhisClaimRow>[] = [
    { key: 'number', label: 'Claim', render: (r) => <Text weight="semibold">{r.number}</Text> },
    { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
    { key: 'period_to', label: 'Sales up to', sort: 'created_at', render: (r) => day(r.period_to) },
    { key: 'claimed', label: 'Claimed', end: true, render: (r) => m(r.claimed) },
    { key: 'paid', label: 'Paid', end: true, render: (r) => m(r.paid) },
    { key: 'rejected', label: 'Not paid', end: true, render: (r) => m(r.rejected) },
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={CLAIM_COLOUR[r.status] ?? 'subtle'}>{r.status_label}</Badge> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="NHIS claims" hint="The NHIS parts of sales claimed from the NHIA: prepare a claim, send it, and record what the NHIA paid." classic="/admin/nhis-claims" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Claim number or NHIA reference" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search NHIS claims" />
        <Dropdown aria-label="Status" style={{ minWidth: 170 }} value={status === '' ? 'Every status' : data?.statuses.find((x) => x.id === status)?.label ?? ''} selectedOptions={[status]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
          <Option value="">Every status</Option>{(data?.statuses ?? []).map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        <Button appearance="primary" icon={<DocumentAddRegular />} disabled={!data?.branches.length} onClick={() => setPreparing(true)}>Prepare a claim</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable label="NHIS claims" columns={columns} rows={data?.rows ?? []} sort="created_at" dir={data?.dir ?? 'desc'} loading={loading}
        empty="No NHIS claims yet. Sales the NHIS paid part of at the till are claimed here: Prepare a claim."
        onSort={(_, dir) => set({ dir: dir === 'asc' ? 'asc' : '' })} onOpen={(r) => navigate(`/office/nhis-claims/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {preparing && data && <PrepareClaim data={data} onClose={() => setPreparing(false)} />}
    </div>
  )
}

function PrepareClaim({ data, onClose }: { data: NhisClaimList; onClose: () => void }) {
  const s = useStyles()
  const [branch, setBranch] = useState(data.default_branch ?? '')
  const [upTo, setUpTo] = useState(data.default_up_to)
  const [preview, setPreview] = useState('')
  useEffect(() => {
    setPreview('')
    if (!branch || !upTo) return
    const t = setTimeout(() => {
      api.nhisUnclaimed(branch, upTo).then((r) => setPreview(`Not claimed yet: ${r.sales} sale(s), ${money(r.amount, r.currency, r.exponent)}.`)).catch(() => setPreview(''))
    }, 250)
    return () => clearTimeout(t)
  }, [branch, upTo])
  return (
    <Ask title="Prepare a claim" go="Prepare it" ready={branch !== '' && upTo !== '' && upTo <= data.today} onClose={onClose}
      run={async () => { const r = await api.prepareNhisClaim(branch, upTo); navigate(`/office/nhis-claims/${r.id}`) }}>
      <div className={s.form}>
        <Text>Puts together the NHIS parts of the branch's sales not claimed yet, up to the day you choose (returns come off). Then download the list, send it to the NHIA, and mark it sent.</Text>
        <Field label="Branch" required>
          <Dropdown value={data.branches.find((b) => b.id === branch)?.label ?? ''} selectedOptions={[branch]} onOptionSelect={(_, d) => setBranch(d.optionValue ?? '')}>
            {data.branches.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Sales up to and including" required hint={preview || undefined}>
          <Input type="date" value={upTo} max={data.today} onChange={(_, d) => setUpTo(d.value)} />
        </Field>
      </div>
    </Ask>
  )
}
