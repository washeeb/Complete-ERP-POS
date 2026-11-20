import { Badge, Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { ArrowSyncRegular, SendRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type EvatOptions, type EvatRow } from '../api'
import { Ask } from '../form/Ask'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  bad: { color: tokens.colorPaletteRedForeground1 },
})

const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')
export const EVAT_TONE: Record<string, 'success' | 'danger' | 'warning' | 'subtle'> = { submitted: 'success', rejected: 'danger', failed: 'danger', pending: 'warning' }

/**
 * GRA E-VAT (release 8): every till sale, invoice and credit note reported to GRA, GRA's reference, and what is still
 * waiting or was refused. A report opens with what was sent and what GRA answered; Send again tries one now; in daily
 * mode, Send the waiting sales now sends the batch without waiting for the night.
 */
export function EvatPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<EvatOptions | null>(null)
  const [nonce, setNonce] = useState(0)
  const [done, setDone] = useState('')
  const [batching, setBatching] = useState(false)
  const { data, loading, error } = useList(api.graEvat, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.graEvatOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const status = str(params, 'status')
  const type = str(params, 'type')
  const statuses = [['', 'Any status'], ...Object.entries(options?.statuses ?? {})]
  const types = [['', 'Any document'], ...Object.entries(options?.types ?? {})]
  const columns: Column<EvatRow>[] = [
    { key: 'date', label: 'Date', sort: 'doc_date', render: (r) => day(r.date) },
    { key: 'number', label: 'Document', sort: 'doc_number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'type', label: 'Type', render: (r) => r.type_label },
    { key: 'reference', label: 'GRA reference', render: (r) => r.reference ?? '—' },
    { key: 'status', label: 'Status', render: (r) => <><Badge appearance="tint" color={EVAT_TONE[r.status] ?? 'subtle'}>{r.status_label}</Badge>
      {r.error && <Text block size={200} className={s.bad}>{r.error}</Text>}</> },
  ]

  return (
    <div className={page.page}>
      <PageHead title="GRA E-VAT" hint={`What was reported to GRA, GRA's answer, and what is still waiting or was refused. Mode: ${options?.mode_label ?? '…'} (System → Settings → GRA E-VAT).`} classic="/admin/gra-evat" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Document or GRA reference" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search GRA reports" />
        <Dropdown aria-label="Status" style={{ minWidth: 160 }} value={statuses.find((x) => x[0] === status)?.[1] ?? ''} selectedOptions={[status]} onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
          {statuses.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Type" style={{ minWidth: 150 }} value={types.find((x) => x[0] === type)?.[1] ?? ''} selectedOptions={[type]} onOptionSelect={(_, d) => set({ type: d.optionValue ?? '' })}>
          {types.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Button icon={<ArrowSyncRegular />} onClick={() => setNonce((n) => n + 1)}>Refresh</Button>
        <span className={s.grow} />
        {options?.can_batch && <Button appearance="primary" icon={<SendRegular />} onClick={() => { setDone(''); setBatching(true) }}>Send the waiting sales now</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {data && <Cards items={[{ label: 'Accepted by GRA', value: (data.counts.submitted ?? 0).toLocaleString('en-GB') }, { label: 'Waiting to send', value: (data.counts.pending ?? 0).toLocaleString('en-GB') },
        { label: 'Refused or failed', value: ((data.counts.rejected ?? 0) + (data.counts.failed ?? 0)).toLocaleString('en-GB') }]} />}
      <DataTable label="GRA reports" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'created_at'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="Nothing reported to GRA matches. With GRA E-VAT off, nothing is reported." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/gra-evat/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {batching && <Ask title="Send the waiting sales now?" go="Send them" ready onClose={() => setBatching(false)}
        run={async () => { const r = await api.graEvatSendWaiting(); setBatching(false); setDone(r.message); setNonce((n) => n + 1) }}>
        <Text>The till sales still waiting, up to today, go to GRA now instead of in tonight's batch.</Text>
      </Ask>}
    </div>
  )
}
