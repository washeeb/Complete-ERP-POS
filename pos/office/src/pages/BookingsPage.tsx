import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, SearchBox, Switch, Text } from '@fluentui/react-components'
import { CalendarAddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api, type BookingList, type BookingValues, type Option } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, useFieldStyles } from '../form/Fields'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'

type Row = BookingList['rows'][number]
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

/** The booking form: branch (new bookings only), name, phone, guests, date and time, minutes, table and a note. */
function BookingAsk({ id, start, onClose, onDone }: { id: string | null; start: BookingValues; onClose: () => void; onDone: (m: string) => void }) {
  const f = useFieldStyles()
  const [v, setV] = useState(start)
  const [branches, setBranches] = useState<Option[]>([])
  const [tables, setTables] = useState<Option[]>([])
  useEffect(() => {
    api.restBookingForm(v.branch_id).then((x) => {
      setBranches(x.branches)
      setTables(x.tables)
      setV((o) => ({ ...o, branch_id: o.branch_id || x.branch, starts_at: o.starts_at || x.starts_at }))
    }).catch(() => undefined)
  }, [v.branch_id])
  const put = <K extends keyof BookingValues>(k: K) => (x: BookingValues[K]) => setV((o) => ({ ...o, [k]: x }))
  return (
    <Ask title={id ? 'Change the booking' : 'New booking'} go={id ? 'Save' : 'Book'} width={640} onClose={onClose}
      ready={!!v.branch_id && v.guest_name.trim() !== '' && Number(v.party_size) >= 1 && !!v.starts_at}
      run={() => api.saveBooking(id, { ...v, party_size: Number(v.party_size), duration_minutes: Number(v.duration_minutes) || 90 }).then((x) => onDone(x.message))}>
      <div className={f.grid}>
        <Choose label="Branch" required disabled={!!id} options={branches} value={v.branch_id} onChange={(x) => setV({ ...v, branch_id: x, dining_table_id: '' })} />
        <TextField label="Name" required maxLength={120} value={v.guest_name} onChange={put('guest_name')} />
        <TextField label="Phone" maxLength={20} hint="For the SMS confirmation and reminder." value={v.phone} onChange={put('phone')} />
        <TextField label="Guests" required type="number" min={1} max={200} value={v.party_size} onChange={put('party_size')} />
        <TextField label="Date and time" required type="datetime-local" step="900" value={v.starts_at} onChange={put('starts_at')} />
        <TextField label="Minutes" type="number" min={15} max={600} value={v.duration_minutes} onChange={put('duration_minutes')} />
        <Choose label="Table" options={tables} value={v.dining_table_id} onChange={put('dining_table_id')} placeholder="Choose on arrival" clearable />
        <TextField label="Note" className={f.spanAll} maxLength={255} value={v.note} onChange={put('note')} />
      </div>
    </Ask>
  )
}

/**
 * Restaurant → Bookings (part 30): who, when, which table and the deposit (taken at the till; kept here after a
 * no-show), from today by default. New booking; Change, No-show (once its time has passed), Cancel (with the reason) and
 * Keep deposit (after a no-show or cancellation; it becomes income — to give it back, refund it at a till).
 */
export function BookingsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const cur = useRef<BookingList | null>(null)
  const { data, loading, error } = useList((qq, sig) => api.restBookings(qq, sig).then((d) => { cur.current = d; return d }), serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [form, setForm] = useState<{ id: string | null; v: BookingValues } | null>(null)
  const [act, setAct] = useState<{ row: Row; action: 'no_show' | 'cancel' | 'forfeit' } | null>(null)
  const [why, setWhy] = useState('')
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (m: string) => { setForm(null); setAct(null); setDone(m); setRefresh((n) => n + 1) }
  const m = (x: number) => (cur.current ? money(x, cur.current.currency, cur.current.exponent) : '')
  const blank: BookingValues = { branch_id: '', guest_name: '', phone: '', party_size: '2', starts_at: '', duration_minutes: '90', dining_table_id: '', note: '' }
  return (
    <div className={page.page}>
      <PageHead title="Bookings" hint="Table bookings: who, when, which table, and the deposit (taken at the till)." classic="/admin/reservations" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name, phone or number" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search bookings" />
        <Switch label="From today" checked={str(params, 'upcoming') !== '0'} onChange={(_, d) => set({ upcoming: d.checked ? '' : '0' })} />
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<CalendarAddRegular />} onClick={() => { setDone(''); setForm({ id: null, v: blank }) }}>New booking</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Bookings" rows={data?.rows ?? []} sort="when" dir="asc" loading={loading} empty="No bookings match." onSort={() => undefined}
        onOpen={(r) => r.can_change && setForm({ id: r.id, v: { ...r.values } })}
        columns={[
          { key: 'when', label: 'When', render: (r) => when(r.when) },
          { key: 'name', label: 'Name', render: (r) => <><Text weight="semibold">{r.name}</Text>{r.phone && <Text size={200} block className={s.muted}>{r.phone}</Text>}</> },
          { key: 'guests', label: 'Guests', end: true, render: (r) => r.guests },
          { key: 'table', label: 'Table', render: (r) => r.table ?? '—' },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.tone} label={r.status_label} />{r.note && <Text size={200} block className={s.muted}>{r.note}</Text>}</> },
          { key: 'deposit', label: 'Deposit', render: (r) => (r.deposit ? `${m(r.deposit)} · ${r.deposit_status ?? ''}` : '—') },
          { key: 'act', label: '', render: (r) => (r.can_change || r.can_no_show || r.can_cancel || r.can_forfeit) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Act on the booking for ${r.name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_change && <MenuItem onClick={() => setForm({ id: r.id, v: { ...r.values } })}>Change</MenuItem>}
                {r.can_no_show && <MenuItem onClick={() => setAct({ row: r, action: 'no_show' })}>No-show</MenuItem>}
                {r.can_cancel && <MenuItem onClick={() => { setWhy(''); setAct({ row: r, action: 'cancel' }) }}>Cancel</MenuItem>}
                {r.can_forfeit && <MenuItem onClick={() => { setWhy(''); setAct({ row: r, action: 'forfeit' }) }}>Keep deposit</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {form && <BookingAsk id={form.id} start={form.v} onClose={() => setForm(null)} onDone={finish} />}
      {act && (
        <Ask title={{ no_show: 'Mark as no-show?', cancel: 'Cancel this booking?', forfeit: 'Keep the deposit?' }[act.action]}
          go={{ no_show: 'No-show', cancel: 'Cancel booking', forfeit: 'Keep deposit' }[act.action]} ready={act.action === 'no_show' || why.trim() !== ''} onClose={() => setAct(null)}
          run={() => api.bookingAction(act.row.id, act.action, why).then((x) => finish(x.message))}>
          <div className={s.one}>
            <Text>{{ no_show: `${act.row.name} did not come.`, cancel: `The booking for ${act.row.name} is cancelled.`,
              forfeit: `The deposit left (${m(act.row.left)}) becomes income (Other income). To give it back instead, refund it at a till.` }[act.action]}</Text>
            {act.action !== 'no_show' && <TextField label="Reason" required maxLength={255} value={why} onChange={setWhy} />}
          </div>
        </Ask>
      )}
    </div>
  )
}
