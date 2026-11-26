import {
  Button, Checkbox, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Radio, RadioGroup, Spinner, Switch, Text, tokens,
} from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, BookmarkRegular, DeleteRegular, SendRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api, type RbChoices, type RbOptions, type RbRecipient, type RbResult, type SavedReportRow } from '../api'
import { Ask } from '../form/Ask'
import { ExportMenu } from '../list/ExportMenu'
import { PageHead, usePageStyles } from '../list/ListParts'
import { str, useQueryParams } from '../list/query'
import { ReportTable } from '../list/ReportTables'
import { navigate } from '../nav'

const useStyles = makeStyles({
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '12px' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '12px' },
  full: { width: '100%', minWidth: 0 },
  pick: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '16px', marginTop: '14px' },
  col: { display: 'flex', flexDirection: 'column', gap: '2px' },
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  recipient: { display: 'grid', gridTemplateColumns: '170px 1fr auto', gap: '8px', alignItems: 'end', marginBottom: '6px' },
})

const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const KINDS: [RbRecipient['type'], string][] = [['email', 'E-mail (with the file)'], ['whatsapp', 'WhatsApp (a note)'], ['user', 'A person (back-office bell)']]

/** A new report's starting point: till items by branch, this month (as the current page), or the first data allowed. */
function start(o: RbOptions): RbChoices {
  const sales = o.datasets.find((d) => d.id === 'sales_lines')
  const d = sales ?? o.datasets[0]
  return { dataset: d?.id ?? '', dimensions: sales ? ['branch'] : d?.dims.slice(0, 1).map((x) => x.id) ?? [], measures: sales ? ['net', 'qty'] : d?.measures.slice(0, 1).map((x) => x.id) ?? [],
    period: 'this_month', from: null, to: null, branch_id: null, filter_dim: null, filter_value: null, sort: null, direction: 'desc' }
}

/**
 * Report builder (release 7): choose the data, the period, the branch and a text filter; what to group by and which
 * figures (none = the rows themselves); and the order. A line of a grouped report opens the rows behind it. Save a
 * report to run again, share it with others who may see that data, or send it on a schedule (e-mail with the file,
 * WhatsApp note, or a back-office notification). Export gives what is shown.
 */
export function ReportBuilderPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const reportId = str(params, 'report')
  const [options, setOptions] = useState<RbOptions | null>(null)
  const [choices, setChoices] = useState<RbChoices | null>(null)
  const [saved, setSaved] = useState<SavedReportRow | null>(null)
  const [drill, setDrill] = useState<Record<string, string> | null>(null)
  const [result, setResult] = useState<RbResult | null>(null)
  const [error, setError] = useState('')
  const [running, setRunning] = useState(false)
  const [saving, setSaving] = useState(false)
  const [sending, setSending] = useState(false)
  const [done, setDone] = useState('')
  const [filterText, setFilterText] = useState('')
  const seq = useRef(0)

  useEffect(() => {
    api.builderOptions().then((o) => {
      setOptions(o)
      if (!reportId) { setChoices(start(o)); setSaved(null) }
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [reportId])
  useEffect(() => {
    if (!reportId) return
    api.savedReport(reportId).then((r) => { setSaved(r.report); setChoices(r.choices); setFilterText(r.choices.filter_value ?? ''); setDrill(null) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [reportId])
  useEffect(() => {
    if (!choices) return
    const n = ++seq.current
    setRunning(true)
    api.runReport(choices, drill).then((r) => { if (n === seq.current) { setResult(r); setError('') } })
      .catch((e: unknown) => { if (n === seq.current) { setResult(null); setError(e instanceof Error ? e.message : String(e)) } })
      .finally(() => { if (n === seq.current) setRunning(false) })
  }, [choices, drill])
  useEffect(() => { // the text filter runs half a second after typing stops
    const t = setTimeout(() => { if (choices && filterText !== (choices.filter_value ?? '')) setChoices({ ...choices, filter_value: filterText }) }, 500)
    return () => clearTimeout(t)
  }, [filterText]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!options || !choices) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the report builder…" />}</div>
  const ds = options.datasets.find((d) => d.id === choices.dataset)
  const change = (c: Partial<RbChoices>) => { setDrill(null); setDone(''); setChoices({ ...choices, ...c }) }
  const toggle = (key: 'dimensions' | 'measures', id: string, on: boolean) => {
    const order = (ds?.[key === 'dimensions' ? 'dims' : 'measures'] ?? []).map((x) => x.id)
    const next = on ? [...choices[key], id] : choices[key].filter((x) => x !== id)
    change({ [key]: order.filter((x) => next.includes(x)), sort: !on && choices.sort === id ? null : choices.sort })
  }
  const label = (list: { id: string; label: string }[] | undefined, id: string | null, none: string) => (id ? list?.find((x) => x.id === id)?.label ?? id : none)
  const sorts = [...choices.dimensions.map((k) => ds?.dims.find((x) => x.id === k)), ...choices.measures.map((k) => ds?.measures.find((x) => x.id === k))].filter(Boolean) as { id: string; label: string }[]
  const mine = !saved || saved.mine
  const exportQuery = `choices=${encodeURIComponent(JSON.stringify({ ...choices, drill, report: saved?.id ?? null }))}`

  return (
    <div className={page.page}>
      <PageHead title={saved?.name ?? 'Report builder'} hint="Choose the data, what to group it by and the figures. Click a line of a grouped report to see the rows behind it; save it to run again or send it on a schedule."
        />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/saved-reports')}>Saved reports</Button>
        {saved && <Button icon={<AddRegular />} onClick={() => { set({ report: '' }); setResult(null) }}>New report</Button>}
        <span className={s.grow} />
        {running && <Spinner size="tiny" />}
        {saved?.mine && saved.schedule !== 'none' && <Button icon={<SendRegular />} onClick={() => { setDone(''); setSending(true) }}>Send now</Button>}
        {mine && <Button appearance="primary" icon={<BookmarkRegular />} onClick={() => setSaving(true)}>{saved ? 'Save changes' : 'Save report'}</Button>}
        <ExportMenu url="/office-api/report-builder/export" query={exportQuery} total={result ? result.table.rows.length || null : 0} />
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <section className={s.panel} aria-label="Report choices">
        <div className={s.grid}>
          <Field label="Data">
            <Dropdown className={s.full} value={ds?.label ?? ''} selectedOptions={[choices.dataset]} onOptionSelect={(_, d) => {
              const n = options.datasets.find((x) => x.id === d.optionValue)
              if (n && n.id !== choices.dataset) { setFilterText(''); change({ dataset: n.id, dimensions: n.dims.slice(0, 1).map((x) => x.id), measures: n.measures.slice(0, 1).map((x) => x.id), filter_dim: null, filter_value: null, sort: null }) }
            }}>
              {options.datasets.map((d) => <Option key={d.id} value={d.id}>{d.label}</Option>)}
            </Dropdown>
          </Field>
          {ds?.dated && <Field label="Period">
            <Dropdown className={s.full} value={label(options.periods, choices.period, '')} selectedOptions={[choices.period]} onOptionSelect={(_, d) => d.optionValue && change({ period: d.optionValue })}>
              {options.periods.map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
            </Dropdown>
          </Field>}
          {ds?.dated && choices.period === 'custom' && <>
            <Field label="From"><Input type="date" value={choices.from ?? ''} onChange={(_, d) => change({ from: d.value || null })} /></Field>
            <Field label="To"><Input type="date" value={choices.to ?? ''} onChange={(_, d) => change({ to: d.value || null })} /></Field>
          </>}
          <Field label="Branch">
            <Dropdown className={s.full} value={label(options.branches, choices.branch_id, 'All you may see')} selectedOptions={[choices.branch_id ?? '']} onOptionSelect={(_, d) => change({ branch_id: d.optionValue || null })}>
              <Option value="">All you may see</Option>{options.branches.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
            </Dropdown>
          </Field>
          <Field label="Filter">
            <Dropdown className={s.full} value={choices.filter_dim ? `${label(ds?.dims, choices.filter_dim, '')} contains…` : '—'} selectedOptions={[choices.filter_dim ?? '']}
              onOptionSelect={(_, d) => { setFilterText(''); change({ filter_dim: d.optionValue || null, filter_value: null }) }}>
              <Option value="">—</Option>{(ds?.dims ?? []).map((x) => <Option key={x.id} value={x.id} text={`${x.label} contains…`}>{x.label} contains…</Option>)}
            </Dropdown>
          </Field>
          {choices.filter_dim && <Field label="Text"><Input value={filterText} onChange={(_, d) => setFilterText(d.value)} /></Field>}
        </div>
        <div className={s.pick}>
          <div className={s.col} role="group" aria-label="Group by">
            <Text weight="semibold">Group by</Text>
            {(ds?.dims ?? []).map((x) => <Checkbox key={x.id} label={x.label} checked={choices.dimensions.includes(x.id)} onChange={(_, d) => toggle('dimensions', x.id, !!d.checked)} />)}
          </div>
          <div className={s.col} role="group" aria-label="Figures">
            <Text weight="semibold">Figures <Text size={200} className={s.muted}>(none = list the rows themselves)</Text></Text>
            {(ds?.measures ?? []).map((x) => <Checkbox key={x.id} label={x.label} checked={choices.measures.includes(x.id)} onChange={(_, d) => toggle('measures', x.id, !!d.checked)} />)}
          </div>
          <div className={s.col}>
            <Field label="Sort by">
              <Dropdown value={label(sorts, choices.sort, 'Default')} selectedOptions={[choices.sort ?? '']} onOptionSelect={(_, d) => change({ sort: d.optionValue || null })}>
                <Option value="">Default</Option>{sorts.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
              </Dropdown>
            </Field>
            <RadioGroup value={choices.direction} onChange={(_, d) => change({ direction: d.value as 'asc' | 'desc' })} aria-label="Order">
              <Radio value="desc" label="Largest / latest first" /><Radio value="asc" label="Smallest / earliest first" />
            </RadioGroup>
          </div>
        </div>
      </section>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {result && !error && <>
        <div className={s.bar}>
          <Text weight="semibold" size={400}>{result.title} · {result.period}</Text>
          {drill && <Button size="small" icon={<ArrowLeftRegular />} onClick={() => setDrill(null)}>Back to the summary</Button>}
        </div>
        <ReportTable table={{ ...result.table, hint: result.hint }} caption={false} onOpen={navigate} onDrill={drill ? undefined : setDrill} />
        {result.truncated && <Text size={200} className={s.muted}>Only the first {options.max_rows.toLocaleString('en-GB')} rows are shown; narrow the period or add a filter.</Text>}
      </>}
      {sending && saved && <Ask title={`Send “${saved.name}” now?`} go="Send now" ready onClose={() => setSending(false)}
        run={async () => { setDone((await api.sendReport(saved.id)).message); setSending(false) }}>
        It goes to its recipients as on its schedule, with this period's figures. The schedule itself does not change.
      </Ask>}
      {saving && <SaveReport options={options} saved={saved} choices={choices} onClose={() => setSaving(false)}
        onSaved={(r) => { setSaving(false); setSaved(r); setDone(`“${r.name}” saved.`); if (r.id !== reportId) set({ report: r.id }) }} />}
    </div>
  )
}

/** Save the report: its name, whether others may run it, and (with the permission) when to send it and to whom. */
function SaveReport({ options, saved, choices, onClose, onSaved }: {
  options: RbOptions; saved: SavedReportRow | null; choices: RbChoices; onClose: () => void; onSaved: (r: SavedReportRow) => void
}) {
  const s = useStyles()
  const [name, setName] = useState(saved?.name ?? '')
  const [shared, setShared] = useState(saved?.shared ?? false)
  const [schedule, setSchedule] = useState(saved?.schedule ?? 'none')
  const [day, setDay] = useState(saved?.schedule_day ?? 1)
  const [time, setTime] = useState(saved?.schedule_time ?? '07:00')
  const [format, setFormat] = useState(saved?.format ?? 'pdf')
  const [recipients, setRecipients] = useState<RbRecipient[]>(saved?.recipients ?? [])
  const scheduled = schedule !== 'none'
  const days = schedule === 'weekly' ? WEEKDAYS.map((w, i) => ({ id: String(i + 1), label: w })) : Array.from({ length: 28 }, (_, i) => ({ id: String(i + 1), label: String(i + 1) }))
  const ready = name.trim() !== '' && (!scheduled || (recipients.length > 0 && recipients.every((r) => r.to.trim() !== '')))
  return (
    <Ask title={saved ? 'Save changes' : 'Save report'} go="Save" ready={ready} onClose={onClose} width={600}
      run={async () => onSaved((await api.saveReport({ id: saved?.id ?? null, name, shared, schedule, schedule_day: scheduled && schedule !== 'daily' ? day : null, schedule_time: time, format, recipients, choices })).report)}>
      <Field label="Name" required><Input value={name} maxLength={120} onChange={(_, d) => setName(d.value)} /></Field>
      <Switch label="Others who may see this data can run it too" checked={shared} onChange={(_, d) => setShared(d.checked)} />
      {options.schedules.length > 0 && <>
        <Field label="Send it automatically">
          <Dropdown value={options.schedules.find((x) => x.id === schedule)?.label ?? ''} selectedOptions={[schedule]} onOptionSelect={(_, d) => d.optionValue && setSchedule(d.optionValue)}>
            {options.schedules.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
          </Dropdown>
        </Field>
        {(schedule === 'weekly' || schedule === 'monthly') && <Field label={schedule === 'weekly' ? 'Weekday' : 'Day of the month'}>
          <Dropdown value={days.find((x) => x.id === String(day))?.label ?? ''} selectedOptions={[String(day)]} onOptionSelect={(_, d) => d.optionValue && setDay(Number(d.optionValue))}>
            {days.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
          </Dropdown>
        </Field>}
        {scheduled && <>
          <Field label="At (HH:MM)"><Input value={time} onChange={(_, d) => setTime(d.value)} placeholder="07:00" /></Field>
          <Field label="Format">
            <Dropdown value={{ pdf: 'PDF', xlsx: 'Excel (.xlsx)', csv: 'CSV' }[format] ?? format} selectedOptions={[format]} onOptionSelect={(_, d) => d.optionValue && setFormat(d.optionValue)}>
              <Option value="pdf">PDF</Option><Option value="xlsx">Excel (.xlsx)</Option><Option value="csv">CSV</Option>
            </Dropdown>
          </Field>
          <Text weight="semibold" block style={{ marginTop: 8 }}>Recipients</Text>
          {recipients.map((r, i) => (
            <div key={i} className={s.recipient}>
              <Field label="Send by">
                <Dropdown value={KINDS.find((k) => k[0] === r.type)?.[1] ?? ''} selectedOptions={[r.type]}
                  onOptionSelect={(_, d) => setRecipients(recipients.map((x, j) => (j === i ? { type: d.optionValue as RbRecipient['type'], to: '' } : x)))}>
                  {KINDS.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
                </Dropdown>
              </Field>
              {r.type === 'user' ? (
                <Field label="Person">
                  <Dropdown value={options.people.find((p) => p.id === r.to)?.label ?? ''} selectedOptions={[r.to]}
                    onOptionSelect={(_, d) => setRecipients(recipients.map((x, j) => (j === i ? { ...x, to: d.optionValue ?? '' } : x)))}>
                    {options.people.map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
                  </Dropdown>
                </Field>
              ) : (
                <Field label={r.type === 'email' ? 'E-mail address' : 'WhatsApp number'}>
                  <Input value={r.to} onChange={(_, d) => setRecipients(recipients.map((x, j) => (j === i ? { ...x, to: d.value } : x)))} />
                </Field>
              )}
              <Button aria-label="Remove recipient" icon={<DeleteRegular />} onClick={() => setRecipients(recipients.filter((_, j) => j !== i))} />
            </div>
          ))}
          <Button icon={<AddRegular />} onClick={() => setRecipients([...recipients, { type: 'email', to: '' }])}>Add recipient</Button>
        </>}
      </>}
    </Ask>
  )
}
