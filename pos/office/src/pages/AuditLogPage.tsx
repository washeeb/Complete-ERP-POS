import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Link, MessageBar, MessageBarBody, Option, SearchBox, Table, TableBody,
  TableCell, TableRow, Text } from '@fluentui/react-components'
import { ShieldCheckmarkRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type AuditEntry, type AuditRow, type Option as Choice } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Fact, useProcStyles } from './ProcurementParts'

const SIGNINS: [string, string][] = [['', 'Everything'], ['auth', 'Back-office sign-ins and sign-outs'], ['pos', 'Till sign-ins']]

function Pick({ label, any, options, value, onChange }: { label: string; any: string; options: Choice[]; value: string; onChange: (v: string) => void }) {
  return (
    <Dropdown aria-label={label} value={value ? options.find((o) => o.id === value)?.label ?? '' : any} selectedOptions={[value]} style={{ minWidth: 170 }}
      onOptionSelect={(_, d) => onChange(d.optionValue ?? '')}>
      <Option value="">{any}</Option>{options.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
    </Dropdown>
  )
}

/**
 * System → Audit log (part 22): every change, newest first, in words — who, what, which record, from where and which
 * computer. Search by person, action or IP; filter by where it came from, the computer, or sign-ins. A row opens the
 * entry in full with each change before → after. Verify chain checks no row was altered.
 */
export function AuditLogPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.auditLog, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  const [open, setOpen] = useState<AuditEntry | null>(null)
  const [note, setNote] = useState<{ good: boolean; text: string } | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const verify = () => {
    setBusy(true)
    setNote(null)
    api.verifyAudit().then((x) => setNote({ good: x.good, text: x.message })).catch((e: unknown) => setNote({ good: false, text: e instanceof Error ? e.message : String(e) })).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <PageHead title="Audit log" hint="Every change, who made it, and from where. Rows are chained: none can be altered unseen." classic="/admin/audit-events" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Person, action or IP" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search the audit log" />
        <Pick label="From" any="From anywhere" options={data?.sources ?? []} value={str(params, 'source')} onChange={(v) => set({ source: v })} />
        <Pick label="Computer" any="Any computer" options={data?.computers ?? []} value={str(params, 'computer')} onChange={(v) => set({ computer: v })} />
        <Pick label="Sign-ins" any="Everything" options={SIGNINS.slice(1).map(([id, label]) => ({ id, label }))} value={str(params, 'signins')} onChange={(v) => set({ signins: v })} />
        <span className={s.grow} />
        {data?.can_verify && <Button icon={<ShieldCheckmarkRegular />} disabled={busy} onClick={verify}>{busy ? 'Verifying…' : 'Verify chain'}</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {note && <MessageBar intent={note.good ? 'success' : 'error'} style={{ marginBottom: 12 }}><MessageBarBody>{note.text}</MessageBarBody></MessageBar>}
      <DataTable<AuditRow> label="Audit log" rows={data?.rows ?? []} sort="seq" dir="desc" loading={loading} empty="Nothing matches." onSort={() => undefined}
        onOpen={(r) => api.auditEntry(r.id).then(setOpen).catch((e: unknown) => setNote({ good: false, text: e instanceof Error ? e.message : String(e) }))}
        columns={[
          { key: 'seq', label: '#', end: true, render: (r) => r.seq },
          { key: 'when', label: 'When', render: (r) => when(r.when) },
          { key: 'by', label: 'By', render: (r) => r.by ?? '—' },
          { key: 'action', label: 'What', render: (r) => r.action },
          { key: 'record', label: 'Record', render: (r) => r.record },
          { key: 'source', label: 'From', render: (r) => r.source },
          { key: 'computer', label: 'Computer', render: (r) => r.computer ?? '—' },
          { key: 'ip', label: 'IP address', render: (r) => r.ip ?? '—' },
          { key: 'reason', label: 'Reason', render: (r) => r.reason ?? '' },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {open && (
        <Dialog open onOpenChange={(_, d) => { if (!d.open) setOpen(null) }}>
          <DialogSurface style={{ maxWidth: 860 }}>
            <DialogBody>
              <DialogTitle>#{open.seq}: {open.action}</DialogTitle>
              <DialogContent>
                <div className={s.facts}>
                  <Fact label="When">{when(open.when)}</Fact>
                  <Fact label="By">{open.by ?? '—'}</Fact>
                  <Fact label="From">{open.source}</Fact>
                  <Fact label="Node">{open.node ?? '—'}</Fact>
                  <Fact label="Record">{open.record}</Fact>
                  <Fact label="Record id"><Text font="monospace" size={200}>{open.record_id ?? '—'}</Text></Fact>
                  <Fact label="IP address">{open.ip ? (open.ip_lookup ? <Link href={open.ip_lookup} target="_blank" rel="noopener" title="Roughly where this address is (opens ipinfo.io)">{open.ip}</Link> : open.ip) : '—'}</Fact>
                  <Fact label="Computer (System → Computers)">{open.computer ?? '—'}</Fact>
                  <Fact label="Computer name">{open.computer_name ?? '— (browser: not available)'}</Fact>
                  <Fact label="MAC address"><Text font="monospace">{open.mac ?? '—'}</Text></Fact>
                  <Fact label="Address on the shop network">{open.local_ip ?? '—'}</Fact>
                  <Fact label="Browser and system">{open.browser ?? '—'}</Fact>
                  <Fact label="App">{open.app ?? '—'}</Fact>
                  <Fact label="Action code"><Text font="monospace" size={200}>{open.action_key}</Text></Fact>
                </div>
                {open.reason && <Text block style={{ marginTop: 10 }}><b>Reason:</b> {open.reason}</Text>}
                <Text weight="semibold" block style={{ margin: '12px 0 4px' }}>Changes (before → after)</Text>
                {open.changes.length === 0 ? <Text className={s.muted}>None recorded.</Text> : (
                  <Table size="small" aria-label="Changes"><TableBody>{open.changes.map((c, i) => <TableRow key={i}><TableCell style={{ width: '35%' }}>{c.field}</TableCell><TableCell>{c.value}</TableCell></TableRow>)}</TableBody></Table>
                )}
                <Text block size={200} className={s.muted} style={{ marginTop: 10, wordBreak: 'break-all', fontFamily: 'monospace' }}>{open.hash}</Text>
              </DialogContent>
              <DialogActions><Button onClick={() => setOpen(null)}>Close</Button></DialogActions>
            </DialogBody>
          </DialogSurface>
        </Dialog>
      )}
    </div>
  )
}
