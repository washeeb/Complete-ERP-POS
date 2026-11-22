import { Button, Link, makeStyles, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { CheckmarkRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type Option, type SensorAlarm } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText } from '../form/Fields'
import { navigate } from '../nav'
import { ToneBadge, useProcStyles } from './ProcurementParts'

const ACTIONS: Option[] = [
  { id: 'checked_ok', label: 'Checked: stock is fine' }, { id: 'moved', label: 'Stock moved to another fridge' },
  { id: 'discarded', label: 'Stock quarantined or discarded' },
]

/** "Acknowledge": what was done about an alarm (part 32), as the current button. */
export function AcknowledgeAsk({ alarm, actions, onClose, onDone }: { alarm: SensorAlarm; actions?: Option[]; onClose: () => void; onDone: (m: string) => void }) {
  const s = useProcStyles()
  const [action, setAction] = useState('')
  const [notes, setNotes] = useState('')
  return (
    <Ask title={`Acknowledge ${alarm.number}`} go="Acknowledge" width={560} ready={action !== ''} onClose={onClose}
      run={() => api.acknowledgeAlarm(alarm.id, action, notes).then((x) => onDone(x.message))}>
      <div className={s.one}>
        <Text>Check the stock (has it been warm, how long?) and say what you did. Moved or discarded stock: name the product, batch and quantity.</Text>
        <Choose label="What was done" required options={actions ?? ACTIONS} value={action} onChange={setAction} />
        <LongText label="Notes" maxLength={2000} value={notes} onChange={setNotes} />
      </div>
    </Ask>
  )
}

/** The alarms table, on the Alarms page and on a sensor's page (without the sensor column). */
export function AlarmTable({ rows, withSensor, actions, onDone }: { rows: SensorAlarm[]; withSensor: boolean; actions?: Option[]; onDone: (m: string) => void }) {
  const s = useProcStyles()
  const [ack, setAck] = useState<SensorAlarm | null>(null)
  if (rows.length === 0) return <Text className={s.muted}>No alarms.</Text>
  return (
    <>
      <Table size="small" aria-label="Alarms" style={{ minWidth: 720 }}>
        <TableHeader><TableRow><TableHeaderCell>No.</TableHeaderCell>{withSensor && <TableHeaderCell>Sensor</TableHeaderCell>}<TableHeaderCell>What</TableHeaderCell>
          <TableHeaderCell>Worst</TableHeaderCell><TableHeaderCell>From</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell><TableHeaderCell>What was done</TableHeaderCell>
          <TableHeaderCell>Work order</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
        <TableBody>{rows.map((a) => (
          <TableRow key={a.id}>
            <TableCell><Text font="monospace" size={200}>{a.number}</Text></TableCell>
            {withSensor && <TableCell><Link onClick={() => navigate(`/office/sensors/${a.sensor_id}`)}>{a.sensor ?? '—'}</Link></TableCell>}
            <TableCell><ToneBadge tone="danger" label={a.kind} /></TableCell>
            <TableCell>{a.worst ?? '—'}</TableCell>
            <TableCell>{a.from ?? '—'}<Text size={200} block className={s.muted}>{a.for}</Text></TableCell>
            <TableCell><ToneBadge tone={a.tone} label={a.status_label} /></TableCell>
            <TableCell>{a.action ?? '—'}{a.by && <Text size={200} block className={s.muted}>{a.by}</Text>}</TableCell>
            <TableCell>{a.order ? (a.order.open ? <Link onClick={() => navigate(a.order!.open!)}>{a.order.number}</Link> : a.order.number) : '—'}</TableCell>
            <TableCell>{a.can_acknowledge && <Button size="small" icon={<CheckmarkRegular />} onClick={() => setAck(a)}>Acknowledge</Button>}</TableCell>
          </TableRow>
        ))}</TableBody>
      </Table>
      {ack && <AcknowledgeAsk alarm={ack} actions={actions} onClose={() => setAck(null)} onDone={(m) => { setAck(null); onDone(m) }} />}
    </>
  )
}

const useChart = makeStyles({
  svg: { width: '100%', maxWidth: '760px', height: 'auto', display: 'block' },
  band: { fill: tokens.colorPaletteGreenBackground2 },
  line: { fill: 'none', stroke: tokens.colorBrandForeground1, strokeWidth: '2' },
  out: { fill: tokens.colorPaletteRedForeground1 },
  axis: { fill: tokens.colorNeutralForeground3, fontSize: '10px' },
})

/** The last 24 hours of a sensor: readings as a line, the acceptable range as a band, readings out of range as red dots. */
export function DayChart({ rows, min: lo, max: hi }: { rows: { at: string; value: number }[]; min: number | null; max: number | null }) {
  const c = useChart()
  const s = useProcStyles()
  if (rows.length === 0) return <Text className={s.muted}>No readings in the last 24 hours.</Text>
  const w = 720
  const h = 160
  const pad = 28
  const vals = rows.map((r) => r.value)
  let min = Math.min(...vals, ...(lo !== null ? [lo - 1] : []))
  let max = Math.max(...vals, ...(hi !== null ? [hi + 1] : []))
  if (max <= min) max = min + 1
  if (!Number.isFinite(min)) min = 0
  const start = Date.now() - 86400000
  const x = (iso: string) => pad + ((new Date(iso).getTime() - start) / 86400000) * (w - 2 * pad)
  const y = (v: number) => h - pad - ((v - min) / (max - min)) * (h - 2 * pad)
  const out = (v: number) => (hi !== null && v > hi) || (lo !== null && v < lo)
  return (
    <svg className={c.svg} viewBox={`0 0 ${w} ${h}`} role="img" aria-label={`Temperature over the last 24 hours, ${Math.min(...vals)} to ${Math.max(...vals)} °C`}>
      {lo !== null && hi !== null && <>
        <rect x={pad} y={y(hi)} width={w - 2 * pad} height={Math.max(1, y(lo) - y(hi))} className={c.band} />
        <text x={2} y={y(hi) + 4} className={c.axis}>{hi}°</text>
        <text x={2} y={y(lo) + 4} className={c.axis}>{lo}°</text>
      </>}
      <polyline points={rows.map((r) => `${x(r.at).toFixed(1)},${y(r.value).toFixed(1)}`).join(' ')} className={c.line} />
      {rows.filter((r) => out(r.value)).map((r) => (
        <circle key={r.at} cx={x(r.at)} cy={y(r.value)} r={2.5} className={c.out}><title>{new Date(r.at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })} {r.value} °C</title></circle>
      ))}
    </svg>
  )
}
