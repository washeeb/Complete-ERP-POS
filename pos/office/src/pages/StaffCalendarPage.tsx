import { Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Text, tokens } from '@fluentui/react-components'
import { CalendarAddRegular, ChevronLeftRegular, ChevronRightRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CalendarData } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { Panel, useProcStyles } from './ProcurementParts'
import { ApptMenu, BookAsk } from './ServicesParts'

const useStyles = makeStyles({
  wrap: { overflowX: 'auto' },
  grid: { borderCollapse: 'collapse', fontSize: tokens.fontSizeBase200, '& th, & td': { border: `1px solid ${tokens.colorNeutralStroke2}`, padding: '2px 4px', verticalAlign: 'top', minWidth: '150px' } },
  time: { minWidth: '52px !important', width: '52px', textAlign: 'right', color: tokens.colorNeutralForeground3 },
  appt: { borderRadius: tokens.borderRadiusMedium, padding: '3px 6px', margin: '2px 0', backgroundColor: tokens.colorBrandBackground2, display: 'flex', gap: '4px', alignItems: 'flex-start' },
  done: { opacity: 0.6 },
})

const shift = (date: string, days: number) => { const d = new Date(`${date}T12:00:00`); d.setDate(d.getDate() + days); return d.toISOString().slice(0, 10) }

/**
 * Services → Staff calendar (part 26): the day's appointments as a grid — one column per staff member, one row per time
 * slot, widened for bookings outside the usual hours. Branch and day in the address; Book appointment; each booking has
 * Arrived, No-show, Change and Cancel.
 */
export function StaffCalendarPage() {
  const page = usePageStyles()
  const p = useProcStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [d, setD] = useState<CalendarData | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [booking, setBooking] = useState(false)
  const [refresh, setRefresh] = useState(0)
  const query = serialize(params)
  useEffect(() => {
    const c = new AbortController()
    api.serviceCalendar(query, c.signal).then((x) => { setD(x); setError('') }).catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
    return () => c.abort()
  }, [query, refresh])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the calendar…" /></div>
  const changed = (m: string) => { setBooking(false); setDone(m); setRefresh((n) => n + 1) }
  return (
    <div className={page.page}>
      <PageHead title="Staff calendar" hint="The day's appointments, one column per staff member." classic={`/admin/staff-calendar${query ? `?${query}` : ''}`} />
      <div className={p.bar} style={{ alignItems: 'flex-end' }}>
        <Field label="Branch">
          <Dropdown aria-label="Branch" value={d.branches.find((b) => b.id === d.branch)?.label ?? ''} selectedOptions={[d.branch]} style={{ minWidth: 200 }} onOptionSelect={(_, e) => set({ branch: e.optionValue ?? '' })}>
            {d.branches.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Day"><Input type="date" value={d.date} onChange={(_, e) => e.value && set({ date: e.value })} /></Field>
        <Button icon={<ChevronLeftRegular />} onClick={() => set({ date: shift(d.date, -1) })}>Previous day</Button>
        <Button icon={<ChevronRightRegular />} iconPosition="after" onClick={() => set({ date: shift(d.date, 1) })}>Next day</Button>
        <span className={p.grow} />
        <Button appearance="primary" icon={<CalendarAddRegular />} onClick={() => { setDone(''); setBooking(true) }}>Book appointment</Button>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Day">
        {d.staff.length === 0 ? <Text>Nobody at this branch takes appointments yet. Under HR → Employees, switch on <b>Takes appointments</b> for the staff who do services.</Text> : (
          <div className={s.wrap}>
            <table className={s.grid} aria-label="Staff calendar">
              <thead><tr><th className={s.time} />{d.staff.map((e) => <th key={e.id}>{e.name}{e.job && <Text size={100} block className={p.muted}>{e.job}</Text>}</th>)}</tr></thead>
              <tbody>{d.slots.map((slot) => (
                <tr key={slot}><th className={s.time}>{slot}</th>
                  {d.staff.map((e) => (
                    <td key={e.id}>{(d.cells[e.id]?.[slot] ?? []).map((a) => (
                      <div key={a.id} className={`${s.appt} ${['done', 'cancelled', 'no_show'].includes(a.status) ? s.done : ''}`} title={`${a.number}${a.note ? ` · ${a.note}` : ''}`}>
                        <div style={{ flex: 1 }}>
                          <b>{a.time}–{a.ends}</b> {a.customer}
                          <Text size={100} block className={p.muted}>{a.service} · {a.status_label}</Text>
                        </div>
                        <ApptMenu a={a} onDone={changed} />
                      </div>
                    ))}</td>
                  ))}
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
        <Text size={200} block className={p.muted} style={{ marginTop: 8 }}>Payment: at the till.</Text>
      </Panel>
      {booking && <BookAsk branch={d.branch} at={d.book_at} onClose={() => setBooking(false)} onDone={changed} />}
    </div>
  )
}
