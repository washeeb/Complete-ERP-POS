import { Button, Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Text } from '@fluentui/react-components'
import { ArrowSyncRegular, CheckmarkRegular, DismissRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { AlertList } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = AlertList['rows'][number]

/**
 * Insights → Alerts (part 29): what the nightly insights found that needs looking at, open by default. Dealt with (what
 * was done) or Not a problem (it stays quiet for 30 days); Refresh now runs the insights again (company-wide only).
 */
export function AlertsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.alerts, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [handle, setHandle] = useState<{ row: Row; status: 'resolved' | 'dismissed' } | null>(null)
  const [note, setNote] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)
  const [fail, setFail] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = data?.status ?? (str(params, 'status') || 'open')
  const open = (row: Row, st: 'resolved' | 'dismissed') => { setNote(''); setDone(''); setHandle({ row, status: st }) }
  const rerun = () => {
    setBusy(true); setFail(''); setDone('')
    api.refreshAlerts().then((x) => { setDone(x.message); setRefresh((n) => n + 1) }).catch((e: unknown) => setFail(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <PageHead title="Alerts" hint="What the nightly insights found that needs looking at today." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Search alerts" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search alerts" />
        <Dropdown aria-label="Status" value={status === 'all' ? 'Any status' : data?.statuses.find((x) => x.id === status)?.label ?? 'Open'} selectedOptions={[status]} style={{ minWidth: 130 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? 'open' })}>
          {(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}<Option value="all">Any status</Option>
        </Dropdown>
        <Pick label="Type" any="Any type" options={data?.kinds ?? []} value={str(params, 'kind')} onChange={(v) => set({ kind: v })} />
        <Pick label="Severity" any="Any severity" options={data?.severities ?? []} value={str(params, 'severity')} onChange={(v) => set({ severity: v })} />
        <span className={s.grow} />
        {data?.can_refresh && <Button icon={<ArrowSyncRegular />} disabled={busy} onClick={rerun}>{busy ? 'Refreshing…' : 'Refresh now'}</Button>}
      </div>
      {(error || fail) && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error || fail}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Alerts" rows={data?.rows ?? []} sort="seen" dir="desc" loading={loading} empty="No alerts match." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'severity', label: 'Severity', render: (r) => <ToneBadge tone={r.tone} label={r.severity} /> },
          { key: 'kind', label: 'Type', render: (r) => r.kind },
          { key: 'title', label: 'Alert', render: (r) => <><Text weight="semibold">{r.title}</Text>{r.detail && <Text size={200} block className={s.muted}>{r.detail}</Text>}</> },
          { key: 'seen', label: 'Last seen', render: (r) => r.seen ?? '—' },
          { key: 'status', label: 'Status', render: (r) => <>{r.status_label}{r.handled && <Text size={200} block className={s.muted}>{r.handled}</Text>}</> },
          { key: 'act', label: '', render: (r) => r.open && <div className={s.actions}>
            <Button size="small" icon={<CheckmarkRegular />} onClick={() => open(r, 'resolved')}>Dealt with</Button>
            <Button size="small" icon={<DismissRegular />} onClick={() => open(r, 'dismissed')}>Not a problem</Button>
          </div> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {handle && (
        <Ask title={handle.status === 'resolved' ? 'Mark as dealt with?' : 'Not a problem?'} go={handle.status === 'resolved' ? 'Dealt with' : 'Not a problem'} ready onClose={() => setHandle(null)}
          run={() => api.handleAlert(handle.row.id, handle.status, note).then((x) => { setHandle(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <div className={s.one}>
            <Text>{handle.row.title}</Text>
            <TextField label={handle.status === 'resolved' ? 'What was done' : 'Why (it stays quiet for 30 days)'} maxLength={255} value={note} onChange={setNote} />
          </div>
        </Ask>
      )}
    </div>
  )
}
