import { Badge, Button, Dropdown, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { DismissRegular, SendRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type RefillReminderRow } from '../api'
import { Ask } from '../form/Ask'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  muted: { color: tokens.colorNeutralForeground3 },
  acts: { display: 'flex', gap: '4px' },
})

const COLOUR: Record<string, 'warning' | 'success' | 'danger' | 'subtle'> = { waiting: 'warning', sent: 'success', bought: 'success', no_contact: 'danger' }
const day = (d: string | null) => (d ? new Date(d).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Refill reminders (pharmacy): made from sales of medicines with "Used a day" to named customers, and sent before the
 * medicine runs out to those who agreed to WhatsApp or SMS. Send one now, or cancel it.
 */
export function RefillRemindersPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [doing, setDoing] = useState<{ row: RefillReminderRow; step: 'send' | 'cancel' } | null>(null)
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.refillReminders, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = str(params, 'status')
  const statuses = [{ id: '', label: 'Waiting' }, ...(data?.statuses ?? []).filter((x) => x.id !== 'waiting'), { id: 'all', label: 'Every status' }]
  const ask = (row: RefillReminderRow, step: 'send' | 'cancel') => { setDone(''); setDoing({ row, step }) }
  const columns: Column<RefillReminderRow>[] = [
    { key: 'status', label: 'Status', render: (r) => <Badge appearance="tint" color={COLOUR[r.status] ?? 'subtle'}>{r.status_label}</Badge> },
    { key: 'customer', label: 'Customer', render: (r) => <>{r.customer ?? '—'}{r.phone && <Text block size={200} className={s.muted}>{r.phone}</Text>}</> },
    { key: 'medicine', label: 'Medicine', render: (r) => r.medicine },
    { key: 'sold', label: 'Sold', end: true, render: (r) => r.sold },
    { key: 'runs_out', label: 'Runs out', render: (r) => day(r.runs_out_on) },
    { key: 'remind_on', label: 'Remind on', sort: 'remind_on', render: (r) => day(r.remind_on) },
    { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
    { key: 'sent', label: 'Sent', render: (r) => (r.sent_at ? <>{when(r.sent_at)}{r.channel && <Text block size={200} className={s.muted}>{r.channel}</Text>}</> : r.cancelled_by ? <Text className={s.muted}>Cancelled by {r.cancelled_by}</Text> : '—') },
    { key: 'acts', label: '', render: (r) => r.can_act && <span className={s.acts} onClick={(e) => e.stopPropagation()}>
      <Button size="small" appearance="subtle" icon={<SendRegular />} onClick={() => ask(r, 'send')}>Send now</Button>
      <Button size="small" appearance="subtle" icon={<DismissRegular />} onClick={() => ask(r, 'cancel')}>Cancel</Button>
    </span> },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Refill reminders" hint="Made from sales of medicines with “Used a day” to named customers; sent before the medicine runs out to those who agreed to WhatsApp or SMS. Switch on under Settings → Refill reminders." classic="/admin/refill-reminders" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Customer or medicine" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search refill reminders" />
        <Dropdown aria-label="Status" style={{ minWidth: 170 }} value={statuses.find((x) => x.id === status)?.label ?? ''} selectedOptions={[status]} onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
          {statuses.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
        </Dropdown>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Refill reminders" columns={columns} rows={data?.rows ?? []} sort="remind_on" dir={data?.dir ?? 'asc'} loading={loading} empty="No reminders here."
        onSort={(_, dir) => set({ dir: dir === 'desc' ? 'desc' : '' })} onOpen={() => undefined} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {doing && <Ask title={doing.step === 'send' ? 'Send this reminder now?' : 'Cancel this reminder?'} go={doing.step === 'send' ? 'Send it' : 'Cancel the reminder'} ready
        onClose={() => setDoing(null)} run={async () => { const r = await api.refillReminder(doing.row.id, doing.step); setDoing(null); setDone(r.message); setNonce((n) => n + 1) }}>
        <Text>{doing.step === 'send'
          ? `Sends the reminder for ${doing.row.medicine} to ${doing.row.customer ?? 'the customer'} now by WhatsApp (or SMS).`
          : 'No reminder will be sent for this sale (for example: the patient changed medicine or asked not to be reminded).'}</Text>
      </Ask>}
    </div>
  )
}
