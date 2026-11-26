import { Button, Dropdown, Field, Input, Link, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text,
  tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type TimesheetData } from '../api'
import { Choose, TextField } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { END, Panel, useProcStyles } from './ProcurementParts'

const KINDS = [{ id: 'in', label: 'Clock in' }, { id: 'out', label: 'Clock out' }]

/**
 * HR & payroll → Timesheets (part 21): hours from clock-ins on the tills, per employee of a branch for a month — days,
 * hours, overtime and clock-ins without a clock-out. A name opens their clock events: a manager adds a missing
 * clock-in or clock-out, or cancels a mistake, each with the reason. Branch, month and person live in the address.
 */
export function TimesheetsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<TimesheetData | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [refresh, setRefresh] = useState(0)
  const [add, setAdd] = useState({ kind: 'out', at: '', reason: '' })
  const [busy, setBusy] = useState(false)
  const query = serialize(params)
  useEffect(() => {
    const c = new AbortController()
    api.timesheets(query, c.signal).then((d) => { setData(d); setError('') }).catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
    return () => c.abort()
  }, [query, refresh])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Adding up the hours…" /></div>
  const send = (body: Record<string, unknown>) => {
    setBusy(true)
    setError('')
    setDone('')
    api.clock({ branch: data.branch, person: data.person?.id, ...body }).then((x) => { setDone(x.message); setAdd({ kind: 'out', at: '', reason: '' }); setRefresh((n) => n + 1) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <PageHead title="Timesheets" hint="Hours from clock-ins on the tills. Add a missing clock-out or cancel a mistake, with the reason." />
      <div className={s.bar} style={{ alignItems: 'flex-end' }}>
        <Field label="Branch">
          <Dropdown aria-label="Branch" value={data.branches.find((b) => b.id === data.branch)?.label ?? ''} selectedOptions={[data.branch]} style={{ minWidth: 220 }}
            onOptionSelect={(_, d) => set({ branch: d.optionValue ?? '', person: '' })}>
            {data.branches.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Month"><Input type="month" value={data.month} onChange={(_, d) => d.value && set({ month: d.value })} /></Field>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Hours this month">
        <Table size="small" aria-label="Timesheets" style={{ minWidth: 560 }}>
          <TableHeader><TableRow><TableHeaderCell>Employee</TableHeaderCell><TableHeaderCell button={END}>Days</TableHeaderCell><TableHeaderCell button={END}>Hours</TableHeaderCell>
            <TableHeaderCell button={END}>Overtime</TableHeaderCell><TableHeaderCell>Clock-ins without a clock-out</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {data.rows.length === 0 && <TableRow><TableCell colSpan={5}><Text className={s.muted}>No employees with a sign-in at this branch. Link each employee to their sign-in so their clock-ins count.</Text></TableCell></TableRow>}
            {data.rows.map((r) => (
              <TableRow key={r.id} style={data.person?.id === r.id ? { background: tokens.colorBrandBackground2 } : undefined}>
                <TableCell><Link onClick={() => set({ person: r.id })}>{r.name}</Link></TableCell>
                <TableCell className={s.end}>{r.days}</TableCell><TableCell className={s.end}>{r.hours}</TableCell><TableCell className={s.end}>{r.overtime}</TableCell>
                <TableCell>{r.open.length > 0 && <Text style={{ color: 'var(--zx-bad)' }}>{r.open.join(', ')}</Text>}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
      {data.person && (
        <Panel title={`Clock events — ${data.person.name}`}>
          <Table size="small" aria-label="Clock events" style={{ minWidth: 560 }}>
            <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>In / out</TableHeaderCell><TableHeaderCell>How</TableHeaderCell><TableHeaderCell>Note</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
            <TableBody>
              {data.events.length === 0 && <TableRow><TableCell colSpan={5}><Text className={s.muted}>No clock events this month.</Text></TableCell></TableRow>}
              {data.events.map((e) => (
                <TableRow key={e.id} style={e.voided || e.is_void ? { opacity: 0.5, textDecoration: 'line-through' } : undefined}>
                  <TableCell>{e.at}</TableCell><TableCell>{e.is_void ? `cancels ${e.kind}` : e.kind}</TableCell><TableCell>{e.source}{e.by ? ` · ${e.by}` : ''}</TableCell><TableCell>{e.reason ?? ''}</TableCell>
                  <TableCell>{!e.voided && !e.is_void && <Button size="small" appearance="subtle" disabled={busy || add.reason.trim() === ''} title="Give the reason below first"
                    onClick={() => send({ action: 'void', event: e.id, reason: add.reason })}>Cancel</Button>}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <div className={s.bar} style={{ alignItems: 'flex-end', marginTop: 12 }}>
            <Choose label="Add" options={KINDS} value={add.kind} onChange={(x) => setAdd({ ...add, kind: x })} />
            <TextField label="When" type="datetime-local" value={add.at} onChange={(x) => setAdd({ ...add, at: x })} />
            <TextField label="Reason (needed to add or cancel)" maxLength={255} value={add.reason} onChange={(x) => setAdd({ ...add, reason: x })} />
            <Button appearance="primary" disabled={busy || !add.at || add.reason.trim() === ''} onClick={() => send({ action: 'add', kind: add.kind, at: add.at, reason: add.reason })}>Add</Button>
          </div>
        </Panel>
      )}
    </div>
  )
}
