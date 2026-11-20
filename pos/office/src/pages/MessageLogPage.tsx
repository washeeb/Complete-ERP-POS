import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens,
} from '@fluentui/react-components'
import { ArrowSyncRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type MessageRow } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  muted: { color: tokens.colorNeutralForeground3 },
  bad: { color: tokens.colorPaletteRedForeground1 },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))', gap: '8px 14px', marginBottom: '10px' },
})

const TONE: Record<string, 'success' | 'danger' | 'warning' | 'subtle'> = { sent: 'success', failed: 'danger', queued: 'warning' }
const at = (d: string | null) => (d ? new Date(d).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

/** Message log (settings and admin): every SMS, WhatsApp message and e-mail sent (or not), to whom, what, and how delivery went. A failed one can be tried again. */
export function MessageLogPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [open, setOpen] = useState<MessageRow | null>(null)
  const [done, setDone] = useState('')
  const [err, setErr] = useState('')
  const { data, loading, error } = useList(api.messageLog, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = str(params, 'status')
  const channel = str(params, 'channel')
  const statuses = [['', 'Any status'], ...(data?.statuses ?? []).map((c) => [c.id, c.label])]
  const channels = [['', 'Any channel'], ...(data?.channels ?? []).map((c) => [c.id, c.label])]
  const retry = async (m: MessageRow) => {
    setErr(''); setDone('')
    try { setDone((await api.retryMessage(m.id)).message); setOpen(null); setNonce((n) => n + 1) } catch (e) { setErr(e instanceof Error ? e.message : String(e)) }
  }
  const columns: Column<MessageRow>[] = [
    { key: 'when', label: 'When', render: (r) => at(r.when) },
    { key: 'channel', label: 'Channel', render: (r) => <Badge appearance="tint">{r.channel}</Badge> },
    { key: 'to', label: 'To', render: (r) => r.to },
    { key: 'ref', label: 'Reference', render: (r) => r.reference ?? '—' },
    { key: 'body', label: 'Text', render: (r) => (r.body.length > 60 ? `${r.body.slice(0, 60)}…` : r.body) },
    { key: 'status', label: 'Status', render: (r) => <><Badge appearance="tint" color={TONE[r.status] ?? 'subtle'}>{r.status_label}</Badge>
      {r.status !== 'sent' && r.error && <Text block size={200} className={s.bad}>{r.error}</Text>}</> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Message log" hint="Every SMS, WhatsApp message and e-mail sent (or not): to whom, what, and how delivery went." classic="/admin/message-log" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="To or reference" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search messages" />
        <Dropdown aria-label="Status" style={{ minWidth: 190 }} value={statuses.find((x) => x[0] === status)?.[1] ?? ''} selectedOptions={[status]} onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
          {statuses.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Channel" style={{ minWidth: 140 }} value={channels.find((x) => x[0] === channel)?.[1] ?? ''} selectedOptions={[channel]} onOptionSelect={(_, d) => set({ channel: d.optionValue ?? '' })}>
          {channels.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Button icon={<ArrowSyncRegular />} onClick={() => setNonce((n) => n + 1)}>Refresh</Button>
      </div>
      {(error || err) && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error || err}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Message log" columns={columns} rows={data?.rows ?? []} sort="when" dir="desc" loading={loading} empty="No messages match."
        onSort={() => undefined} onOpen={setOpen} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {open && (
        <Dialog open onOpenChange={(_, d) => !d.open && setOpen(null)}>
          <DialogSurface style={{ maxWidth: 620 }}>
            <DialogBody>
              <DialogTitle>{open.channel} to {open.to}</DialogTitle>
              <DialogContent>
                <div className={s.facts}>
                  <div><Text block size={200} className={s.muted}>When</Text>{at(open.when)}</div>
                  <div><Text block size={200} className={s.muted}>Status</Text><Badge appearance="tint" color={TONE[open.status] ?? 'subtle'}>{open.status_label}</Badge></div>
                  <div><Text block size={200} className={s.muted}>Provider</Text>{open.provider ?? '—'}{open.provider_ref && <Text block size={200} className={s.muted}>{open.provider_ref}</Text>}</div>
                  <div><Text block size={200} className={s.muted}>Tries</Text>{open.attempts}</div>
                  <div><Text block size={200} className={s.muted}>Attachment</Text>{open.attachment ?? '—'}</div>
                </div>
                {open.subject && <Text block weight="semibold">{open.subject}</Text>}
                <Text block style={{ whiteSpace: 'pre-wrap' }}>{open.body}</Text>
                {open.error && <Text block className={s.bad} style={{ marginTop: 8 }}>{open.error}</Text>}
              </DialogContent>
              <DialogActions>
                {open.can_retry && <Button appearance="primary" icon={<ArrowSyncRegular />} onClick={() => void retry(open)}>Try again</Button>}
                <Button onClick={() => setOpen(null)}>Close</Button>
              </DialogActions>
            </DialogBody>
          </DialogSurface>
        </Dialog>
      )}
    </div>
  )
}
