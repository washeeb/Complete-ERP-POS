import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Switch, Text,
  tokens } from '@fluentui/react-components'
import { AddRegular, InfoRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { TicketList } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = TicketList['rows'][number]
const OPEN = [{ id: '1', label: 'Open only' }, { id: '0', label: 'Resolved and closed' }, { id: 'all', label: 'All' }]

/**
 * Service desk → Tickets (part 28): requests and complaints with the time left to answer and to fix them, open only by
 * default; given to me, late, kind and priority. New ticket (for a customer at the counter or on the phone) and, for
 * managers, where tickets come from. Someone who may only ask for help sees "My requests" and "Ask for help".
 */
export function TicketsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.tickets, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  const [info, setInfo] = useState(false)
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const open = data?.open ?? (str(params, 'open') || '1')
  const title = data?.title ?? 'Tickets'
  return (
    <div className={page.page}>
      <PageHead title={title}
        hint={data && !data.all ? 'Ask for help (a broken printer, a stock problem…) and follow what happens to it.'
          : 'Requests and complaints, with the time left to answer and to fix them. Open one for a customer at the counter or on the phone.'} />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, subject, name, phone or e-mail" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search tickets" />
        <Dropdown aria-label="Open or closed" value={OPEN.find((x) => x.id === open)?.label ?? 'Open only'} selectedOptions={[open]} style={{ minWidth: 150 }}
          onOptionSelect={(_, d) => set({ open: d.optionValue ?? '1' })}>
          {OPEN.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        {data?.work && <Switch label="Given to me" checked={str(params, 'mine') === '1'} onChange={(_, d) => set({ mine: d.checked ? '1' : '' })} />}
        <Switch label="Late" checked={str(params, 'late') === '1'} onChange={(_, d) => set({ late: d.checked ? '1' : '' })} />
        <Pick label="Kind" any="Any kind" options={data?.kinds ?? []} value={str(params, 'kind')} onChange={(v) => set({ kind: v })} />
        <Pick label="Priority" any="Any priority" options={data?.priorities ?? []} value={str(params, 'priority')} onChange={(v) => set({ priority: v })} />
        <span className={s.grow} />
        {data?.channels && <Button icon={<InfoRegular />} onClick={() => setInfo(true)}>Where tickets come from</Button>}
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/tickets/new')}>{data.create_label}</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label={title} rows={data?.rows ?? []} sort="opened" dir="desc" loading={loading}
        empty="No tickets. Tickets come from WhatsApp, e-mail and the web form by themselves; open one here for a customer at the counter or on the phone."
        onSort={() => undefined} onOpen={(r) => navigate(`/office/tickets/${r.id}`)}
        columns={[
          { key: 'number', label: 'No.', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'subject', label: 'Subject', render: (r) => <><Text weight="semibold">{r.subject}</Text>{r.from && <Text size={200} block className={s.muted}>{r.from}</Text>}</> },
          { key: 'kind', label: 'Kind', render: (r) => r.kind },
          { key: 'priority', label: 'Priority', render: (r) => <ToneBadge tone={r.priority_tone} label={r.priority} /> },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'due', label: 'Due', render: (r) => (r.due ? <><span style={r.late ? { color: tokens.colorPaletteRedForeground1 } : undefined}>{r.due}</span>
            <Text size={200} block className={s.muted}>{r.due_what}</Text></> : '—') },
          { key: 'with', label: 'With', render: (r) => r.with ?? <Text className={s.muted}>Nobody yet</Text> },
          { key: 'opened', label: 'Opened', render: (r) => r.opened ?? '—' },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {info && data?.channels && (
        <Dialog open onOpenChange={(_, d) => { if (!d.open) setInfo(false) }}>
          <DialogSurface style={{ maxWidth: 640 }}><DialogBody>
            <DialogTitle>Where tickets come from</DialogTitle>
            <DialogContent><div className={s.one}>
              <Text><b>WhatsApp</b>: messages to your WhatsApp Business number arrive under Online → WhatsApp orders; turn one into a ticket there. A customer with a ticket open on WhatsApp has their next messages added to it.</Text>
              <Text><b>Web form</b>: {data.channels.web_form ?? 'off — switch it on under System → Settings → Service desk and maintenance'}</Text>
              <Text><b>E-mail</b>: {data.channels.email ? <>have your mail provider forward incoming mail (inbound parse / route) to {data.channels.email}. Keep this address private.</>
                : 'off — set a key under System → Settings → Service desk and maintenance'}</Text>
              <Text><b>Staff</b>: “New ticket” here, for a customer at the counter or on the phone; staff ask for help for themselves the same way.</Text>
            </div></DialogContent>
            <DialogActions><Button onClick={() => setInfo(false)}>Close</Button></DialogActions>
          </DialogBody></DialogSurface>
        </Dialog>
      )}
    </div>
  )
}
