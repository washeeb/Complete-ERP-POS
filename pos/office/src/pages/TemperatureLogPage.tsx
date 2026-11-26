import { Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Text, tokens } from '@fluentui/react-components'
import { PrintRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type TemperatureLogData } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { useProcStyles } from './ProcurementParts'

const useStyles = makeStyles({
  log: { backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusXLarge, padding: '16px', overflowX: 'auto' },
  table: { width: '100%', borderCollapse: 'collapse', fontSize: tokens.fontSizeBase200, marginBottom: '14px',
    '& th, & td': { textAlign: 'left', padding: '4px 6px', borderBottom: `1px solid ${tokens.colorNeutralStroke2}`, verticalAlign: 'top' } },
  bad: { backgroundColor: tokens.colorPaletteRedBackground1 },
  none: { color: tokens.colorNeutralForeground3 },
  sign: { marginTop: '24px' },
})

/** Only the log prints: everything else on the screen (menus, filters) is hidden on paper. */
const PRINT = `@media print { body * { visibility: hidden !important; } .zx-print-log, .zx-print-log * { visibility: visible !important; }
  .zx-print-log { position: absolute; left: 0; top: 0; width: 100%; border: none !important; } }`

/**
 * Sensors → Temperature log (part 32): a month for one sensor — lowest, highest and average each day, time out of range,
 * the checks signed — and the month's alarms with what was done. Printable for inspections. Sensor and month live in the
 * address.
 */
export function TemperatureLogPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const c = useStyles()
  const [params, set] = useQueryParams()
  const [d, setD] = useState<TemperatureLogData | null>(null)
  const [error, setError] = useState('')
  const query = serialize(params)
  useEffect(() => {
    const ctl = new AbortController()
    api.temperatureLog(query, ctl.signal).then((x) => { setD(x); setError('') }).catch((e: unknown) => { if (!ctl.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
    return () => ctl.abort()
  }, [query])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the log…" /></div>
  const log = d.log
  return (
    <div className={page.page}>
      <style>{PRINT}</style>
      <PageHead title="Temperature log" hint="A month for one sensor, printable for inspections." />
      <div className={s.bar} style={{ alignItems: 'flex-end' }}>
        <Field label="Sensor">
          <Dropdown aria-label="Sensor" value={d.sensors.find((x) => x.id === d.sensor)?.label ?? ''} selectedOptions={d.sensor ? [d.sensor] : []} style={{ minWidth: 220 }}
            onOptionSelect={(_, e) => set({ sensor: e.optionValue ?? '' })}>
            {d.sensors.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Month"><Input type="month" max={d.this_month} value={d.month} onChange={(_, e) => e.value && set({ month: e.value })} /></Field>
        <span className={s.grow} />
        {log && <Button icon={<PrintRegular />} onClick={() => window.print()}>Print / save as PDF</Button>}
      </div>
      {!log ? <Text className={s.muted}>Add a fridge, cold room, freezer or room sensor first.</Text> : (
        <div className={`${c.log} zx-print-log`} role="region" aria-label="Temperature log">
          <h2 style={{ margin: '0 0 4px' }}>{d.company} — temperature log</h2>
          <Text block><b>{log.name}</b> ({log.number}), {log.branch} · {log.period} · acceptable range {log.range}</Text>
          <Text block style={{ marginBottom: 12 }}>Lowest {log.lowest}, highest {log.highest}, out of range {log.out} this month · {log.alarm_count} alarm(s).</Text>
          <table className={c.table}>
            <thead><tr><th>Date</th><th>Lowest</th><th>Highest</th><th>Average</th><th>Out of range</th><th>Checks (time, display, signed by)</th></tr></thead>
            <tbody>{log.days.map((r) => (
              <tr key={r.date} className={r.bad ? c.bad : undefined}>
                <td>{r.date}</td><td>{r.min}</td><td>{r.max}</td><td>{r.avg}</td><td>{r.out}</td>
                <td>{r.checks.length === 0 ? <span className={c.none}>No check signed</span> : r.checks.map((x, i) => <div key={i}>{x}</div>)}</td>
              </tr>
            ))}</tbody>
          </table>
          <h3 style={{ margin: '0 0 6px' }}>Alarms</h3>
          {log.alarms.length === 0 ? <Text block>None this month.</Text> : (
            <table className={c.table}>
              <thead><tr><th>No.</th><th>What</th><th>From</th><th>Until</th><th>Worst</th><th>What was done</th><th>By</th></tr></thead>
              <tbody>{log.alarms.map((a) => (
                <tr key={a.id}><td>{a.number}</td><td>{a.kind}</td><td>{a.from}</td><td>{a.until}</td><td>{a.worst}</td><td>{a.done}</td><td>{a.by}</td></tr>
              ))}</tbody>
            </table>
          )}
          <Text block className={c.sign}>Reviewed by (name, signature, date): ____________________________________________</Text>
        </div>
      )}
    </div>
  )
}
