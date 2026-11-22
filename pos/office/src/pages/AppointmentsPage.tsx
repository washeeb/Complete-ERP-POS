import { Button, MessageBar, MessageBarBody, SearchBox, Switch, Text } from '@fluentui/react-components'
import { CalendarAddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ApptRow } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { ToneBadge, useProcStyles } from './ProcurementParts'
import { ApptMenu, BookAsk } from './ServicesParts'

/**
 * Services → Appointments (part 26): services booked with a staff member — when, the customer, the service, with whom
 * and the status; from today by default. Book appointment, and on each: Arrived, No-show, Change and Cancel. Paid at the
 * till.
 */
export function AppointmentsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.appointments, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [booking, setBooking] = useState(false)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (m: string) => { setBooking(false); setDone(m); setRefresh((n) => n + 1) }
  return (
    <div className={page.page}>
      <PageHead title="Appointments" hint="Services booked with a staff member; arrivals, no-shows and cancellations. Paid at the till." classic="/admin/appointments" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Customer, phone or number" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search appointments" />
        <Switch label="From today" checked={str(params, 'upcoming') !== '0'} onChange={(_, d) => set({ upcoming: d.checked ? '' : '0' })} />
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <Pick label="With" any="Anyone" options={data?.staff ?? []} value={str(params, 'with')} onChange={(v) => set({ with: v })} />
        <span className={s.grow} />
        {data?.can_book && <Button appearance="primary" icon={<CalendarAddRegular />} onClick={() => { setDone(''); setBooking(true) }}>Book appointment</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<ApptRow> label="Appointments" rows={data?.rows ?? []} sort="when" dir="asc" loading={loading} empty="No appointments match." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'when', label: 'When', render: (r) => r.starts_at ?? '—' },
          { key: 'customer', label: 'Customer', render: (r) => <>{r.customer ?? '—'}{r.phone && <Text size={200} block className={s.muted}>{r.phone}</Text>}</> },
          { key: 'service', label: 'Service', render: (r) => r.service ?? '—' },
          { key: 'with', label: 'With', render: (r) => r.with ?? '—' },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'act', label: '', render: (r) => <ApptMenu a={r} onDone={(m) => { setDone(m); setRefresh((n) => n + 1) }} /> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {booking && <BookAsk onClose={() => setBooking(false)} onDone={finish} />}
    </div>
  )
}
