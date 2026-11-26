import { Button, Dropdown, Field, Input, Link, MessageBar, MessageBarBody, Option, Radio, RadioGroup, Text } from '@fluentui/react-components'
import { AddRegular, HeartPulseRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type ReadingForm, type ReadingRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ToneBadge, useProcStyles } from './ProcurementParts'

const VALUE_LABEL: Record<string, string> = { sugar: 'Reading (mmol/L)', weight: 'Weight (kg)', temperature: 'Temperature (°C)', hb: 'HB (g/dL)' }
const NO_VALUE = ['bp', 'hiv', 'typhoid', 'wound']
type Reading = { customer: { id: string; label: string } | null; branch_id: string; kind: string; systolic: string; diastolic: string; pulse: string; value: string; result: string; note: string }

/**
 * Pharmacy → Health checks (part 20): every reading taken, by date, what was measured, result and patient (a patient's
 * name in a row shows their history). Record a reading, and Add a fee service (sold at the till like a product, with
 * no stock; with a reading, the till asks for it). Health data: only people who may see patients. Flags follow common
 * screening limits, to prompt a referral; they are not a diagnosis.
 */
export function HealthChecksPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.healthChecks, serialize(params), refresh)
  const [form, setForm] = useState<ReadingForm | null>(null)
  const [dialog, setDialog] = useState<'take' | 'service' | null>(null)
  const [r, setR] = useState<Reading | null>(null)
  const [svc, setSvc] = useState({ name: '', price: '', reading_kind: '', tax_group_id: '' })
  const [done, setDone] = useState('')
  const [formError, setFormError] = useState('')
  const flags = str(params, 'flags') ? str(params, 'flags').split(',') : []
  const kind = str(params, 'kind')
  const open = (d: 'take' | 'service') => {
    setDone('')
    setFormError('')
    ;(form ? Promise.resolve(form) : api.readingForm()).then((f) => {
      setForm(f)
      setR({ customer: null, branch_id: f.branch, kind: 'bp', systolic: '', diastolic: '', pulse: '', value: '', result: '', note: '' })
      setSvc({ name: '', price: '', reading_kind: '', tax_group_id: f.tax_groups[0]?.id ?? '' })
      setDialog(d)
    }).catch((e: unknown) => setFormError(e instanceof Error ? e.message : String(e)))
  }
  const finish = (m: string) => { setDialog(null); setDone(m); setRefresh((n) => n + 1) }
  const results = r && form ? form.results[r.kind] : undefined
  const readingReady = !!r && !!r.branch_id && (r.kind === 'bp' ? !!r.systolic && !!r.diastolic : results ? !!r.result : r.kind === 'wound' ? r.note.trim().length >= 3 : !!r.value)
  return (
    <div className={page.page}>
      <PageHead title="Health checks" hint="Flags follow common screening limits, to prompt a referral. They are not a diagnosis." />
      <div className={s.bar} style={{ alignItems: 'flex-end' }}>
        <Field label="From"><Input type="date" value={str(params, 'from')} onChange={(_, d) => set({ from: d.value })} /></Field>
        <Field label="To"><Input type="date" value={str(params, 'to')} onChange={(_, d) => set({ to: d.value })} /></Field>
        <Field label="Measured">
          <Dropdown aria-label="Measured" value={kind ? data?.kinds.find((k) => k.id === kind)?.label ?? '' : 'Anything'} selectedOptions={[kind]} style={{ minWidth: 170 }}
            onOptionSelect={(_, d) => set({ kind: d.optionValue ?? '' })}>
            <Option value="">Anything</Option>{(data?.kinds ?? []).map((k) => <Option key={k.id} value={k.id}>{k.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Result">
          <Dropdown aria-label="Result" multiselect value={flags.map((f) => data?.flags.find((x) => x.id === f)?.label ?? f).join(', ') || 'Any'} selectedOptions={flags} style={{ minWidth: 170 }}
            onOptionSelect={(_, d) => set({ flags: d.selectedOptions.join(',') })}>
            {(data?.flags ?? []).map((k) => <Option key={k.id} value={k.id}>{k.label}</Option>)}
          </Dropdown>
        </Field>
        {data?.patient_name && <Field label="Patient"><Text>{data.patient_name} · <Link onClick={() => set({ patient: '' })}>Everyone</Link></Text></Field>}
        <span className={s.grow} />
        {data?.can_service && <Button icon={<AddRegular />} onClick={() => open('service')}>Add a fee service</Button>}
        <Button appearance="primary" icon={<HeartPulseRegular />} onClick={() => open('take')}>Record a reading</Button>
      </div>
      {(error || formError) && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error || formError}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<ReadingRow> label="Health checks" rows={data?.rows ?? []} sort="taken" dir="desc" loading={loading} empty="No readings yet." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'when', label: 'When', render: (x) => when(x.taken_at) },
          { key: 'person', label: 'Person', render: (x) => (x.customer_id ? <Link onClick={() => set({ patient: x.customer_id! })} title="Their history">{x.person}</Link> : x.person) },
          { key: 'kind', label: 'Measured', render: (x) => x.kind },
          { key: 'reading', label: 'Reading', render: (x) => <Text weight="semibold">{x.reading}</Text> },
          { key: 'flag', label: 'Result', render: (x) => (x.flag ? <ToneBadge tone={x.tone} label={x.flag_label} /> : '—') },
          { key: 'note', label: 'Note', render: (x) => x.note ?? '—' },
          { key: 'branch', label: 'Branch', render: (x) => x.branch ?? '—' },
          { key: 'by', label: 'Taken by', render: (x) => x.by ?? '—' },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(n) => set({ page: String(n) })} onPer={(n) => set({ per: String(n) })} />}

      {dialog === 'take' && form && r && (
        <Ask title="Record a reading" go="Save" ready={readingReady} onClose={() => setDialog(null)}
          run={() => api.recordReading({ customer_id: r.customer?.id ?? null, branch_id: r.branch_id, kind: r.kind, systolic: r.systolic || null, diastolic: r.diastolic || null,
            pulse: r.pulse || null, value: r.value || null, result: r.result || null, note: r.note || null }).then((x) => finish(x.message))}>
          <div className={s.one}>
            <SearchPicker label="Patient" value={r.customer?.id ?? ''} text={r.customer?.label ?? null} search={api.patients} placeholder="Empty for a walk-in"
              onChange={(x) => setR({ ...r, customer: x ? { id: x.id, label: x.label } : null })} />
            <Choose label="Branch" required options={form.branches} value={r.branch_id} onChange={(x) => setR({ ...r, branch_id: x })} />
            <Choose label="Measured" required options={form.kinds} value={r.kind} onChange={(x) => setR({ ...r, kind: x, result: '', value: '' })} />
            {r.kind === 'bp' && <div className={s.grid}>
              <TextField label="Top number (systolic)" required type="number" value={r.systolic} onChange={(x) => setR({ ...r, systolic: x })} />
              <TextField label="Bottom number (diastolic)" required type="number" value={r.diastolic} onChange={(x) => setR({ ...r, diastolic: x })} />
              <TextField label="Pulse" type="number" value={r.pulse} onChange={(x) => setR({ ...r, pulse: x })} />
            </div>}
            {!NO_VALUE.includes(r.kind) && <TextField label={VALUE_LABEL[r.kind] ?? 'Reading'} required type="number" step="any" value={r.value} onChange={(x) => setR({ ...r, value: x })} />}
            {results && <Field label="Result" required>
              <RadioGroup value={r.result} onChange={(_, d) => setR({ ...r, result: d.value })}>{results.map((o) => <Radio key={o.id} value={o.id} label={o.label} />)}</RadioGroup>
            </Field>}
            <LongText label={r.kind === 'wound' ? 'What was done (required)' : 'Note'} maxLength={255} value={r.note} onChange={(x) => setR({ ...r, note: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'service' && form && (
        <Ask title="Add a fee service" go="Add" ready={svc.name.trim() !== '' && svc.price !== '' && !!svc.tax_group_id} onClose={() => setDialog(null)}
          run={() => api.addFeeService({ ...svc, reading_kind: svc.reading_kind || null }).then((x) => finish(x.message))}>
          <div className={s.one}>
            <Text>A service sold at the till like a product, with no stock. If it has a reading, the till asks for it.</Text>
            <TextField label="Name" required maxLength={160} placeholder="Blood pressure check" value={svc.name} onChange={(x) => setSvc({ ...svc, name: x })} />
            <TextField label="Fee" required type="number" min={0} step="any" prefix={form.currency} value={svc.price} onChange={(x) => setSvc({ ...svc, price: x })} />
            <Choose label="Reading taken" options={form.kinds} value={svc.reading_kind} onChange={(x) => setSvc({ ...svc, reading_kind: x })} placeholder="None" clearable />
            <Choose label="Tax" required options={form.tax_groups} value={svc.tax_group_id} onChange={(x) => setSvc({ ...svc, tax_group_id: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}
