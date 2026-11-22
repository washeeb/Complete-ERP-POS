import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Link, MessageBar, MessageBarBody, Option, SearchBox, Text } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type ProblemReport } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Fact, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * System → Problem reports (part 22): problems staff reported from the back office or a till ("Report a problem"), open
 * ones first. A row opens the report (what happened, the screen picture, details for support); Close says what was
 * done; Send again retries the e-mail to support.
 */
export function ProblemReportsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.problemReports, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [open, setOpen] = useState<ProblemReport | null>(null)
  const [closing, setClosing] = useState(false)
  const [res, setRes] = useState('')
  const [note, setNote] = useState<{ good: boolean; text: string } | null>(null)
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = data?.status ?? 'open'
  const source = str(params, 'source')
  const done = (x: { message: string; good: boolean; report: ProblemReport }) => { setOpen(x.report); setClosing(false); setNote({ good: x.good, text: x.message }); setRefresh((n) => n + 1) }
  return (
    <div className={page.page}>
      <PageHead title="Problem reports" hint={data?.sent_to ?? 'Problems staff reported, for support to follow up and close.'} classic="/admin/problem-reports" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, person or words" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search problem reports" />
        <Dropdown aria-label="Status" value={status === 'all' ? 'Any status' : data?.statuses.find((x) => x.id === status)?.label ?? 'Open'} selectedOptions={[status]} style={{ minWidth: 140 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? 'open' })}>
          {(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}<Option value="all">Any status</Option>
        </Dropdown>
        <Dropdown aria-label="From" value={source ? data?.sources.find((x) => x.id === source)?.label ?? '' : 'Back office or till'} selectedOptions={[source]} style={{ minWidth: 170 }}
          onOptionSelect={(_, d) => set({ source: d.optionValue ?? '' })}>
          <Option value="">Back office or till</Option>{(data?.sources ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {note && !open && <MessageBar intent={note.good ? 'success' : 'warning'} style={{ marginBottom: 12 }}><MessageBarBody>{note.text}</MessageBarBody></MessageBar>}
      <DataTable<ProblemReport> label="Problem reports" rows={data?.rows ?? []} sort="when" dir="desc" loading={loading} empty="No problem reports here." onSort={() => undefined}
        onOpen={(r) => { setNote(null); setOpen(r) }}
        columns={[
          { key: 'number', label: 'Number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
          { key: 'when', label: 'When', render: (r) => r.ago ?? '—' },
          { key: 'from', label: 'From', render: (r) => <>{r.from ?? '—'}<Text size={200} block className={s.muted}>{r.source}</Text></> },
          { key: 'message', label: 'What happened', render: (r) => (r.message.length > 80 ? `${r.message.slice(0, 80)}…` : r.message) },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.status === 'open' ? 'warning' : 'success'} label={r.status_label} />{r.resolution && <Text size={200} block className={s.muted}>{r.resolution}</Text>}</> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {open && !closing && (
        <Dialog open onOpenChange={(_, d) => { if (!d.open) setOpen(null) }}>
          <DialogSurface style={{ maxWidth: 820 }}>
            <DialogBody>
              <DialogTitle>{open.number}</DialogTitle>
              <DialogContent>
                {note && <MessageBar intent={note.good ? 'success' : 'warning'} style={{ marginBottom: 10 }}><MessageBarBody>{note.text}</MessageBarBody></MessageBar>}
                <div className={s.facts}>
                  <Fact label="When">{when(open.when)}</Fact><Fact label="From">{open.from ?? '—'}</Fact><Fact label="Source">{open.source}</Fact>
                  <Fact label="Page">{open.page ?? '—'}</Fact><Fact label="Status"><ToneBadge tone={open.status === 'open' ? 'warning' : 'success'} label={open.status_label} /></Fact>
                  <Fact label="E-mail to support">{open.send_error ?? 'Sent'}</Fact>
                </div>
                <Text weight="semibold" block style={{ margin: '12px 0 4px' }}>What happened</Text>
                <Text block style={{ whiteSpace: 'pre-wrap' }}>{open.message}</Text>
                <Text block style={{ marginTop: 10 }}>{open.screenshot ? <Link href={open.screenshot} target="_blank" rel="noopener">Open the picture of the screen</Link> : <Text className={s.muted}>No picture</Text>}</Text>
                {open.resolution && <Text block style={{ marginTop: 10 }}><b>Resolution:</b> {open.resolution}</Text>}
                <Text weight="semibold" block style={{ margin: '12px 0 4px' }}>Details for support</Text>
                <pre style={{ whiteSpace: 'pre-wrap', fontSize: 12, margin: 0, maxHeight: 240, overflow: 'auto' }}>{open.context}</pre>
              </DialogContent>
              <DialogActions>
                {open.send_error !== null && <Button onClick={() => api.problemReportAction(open.id, 'resend').then(done).catch((e: unknown) => setNote({ good: false, text: e instanceof Error ? e.message : String(e) }))}>Send again</Button>}
                {open.status === 'open' && <Button appearance="primary" onClick={() => { setRes(''); setClosing(true) }}>Close</Button>}
                <Button onClick={() => setOpen(null)}>Back</Button>
              </DialogActions>
            </DialogBody>
          </DialogSurface>
        </Dialog>
      )}
      {open && closing && (
        <Ask title={`Close ${open.number}?`} go="Close" ready={res.trim() !== ''} onClose={() => setClosing(false)} run={() => api.problemReportAction(open.id, 'close', res).then(done)}>
          <TextField label="What was done" required maxLength={255} value={res} onChange={setRes} />
        </Ask>
      )}
    </div>
  )
}
