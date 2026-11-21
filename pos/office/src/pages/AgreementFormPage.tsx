import { Button, Field, MessageBar, MessageBarBody, Spinner, Text } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api, type AgreementForm, type AgreementLine, type AgreementValues } from '../api'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { blankSpend, SpendItem, SpendKind, spendReady } from '../form/SpendLineFields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, useProcStyles } from './ProcurementParts'

const blank = (kind: 'stock' | 'service' = 'stock'): AgreementLine => ({ ...blankSpend(kind), unit_cost: '', max_qty: '', note: '' })

/**
 * A contract or blanket order draft (part 18), as the current form: the supplier, kind, title, period, the most that may
 * be ordered in total, the terms, the signed agreement (PDF or photo) and the items with their agreed prices (and, for a
 * blanket order, the most of each that may be ordered). Someone else puts it in force from its page.
 */
export function AgreementFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<AgreementForm | null>(null)
  const [v, setV] = useState<AgreementValues | null>(null)
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const top = useRef<HTMLDivElement>(null)
  useEffect(() => {
    api.agreementForm(id ?? undefined).then((x) => {
      setForm(x)
      setV({ ...x.values, lines: x.values.lines.length ? x.values.lines : [blank()] })
      onTitle(x.number ? `Edit ${x.number}` : 'New agreement')
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (!form || !v) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const set = <K extends keyof AgreementValues>(k: K) => (x: AgreementValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const line = (i: number, patch: Partial<AgreementLine>) => setV((o) => (o ? { ...o, lines: o.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)) } : o))
  const blanket = v.kind === 'blanket'
  const ready = !!v.supplier_id && !!v.title.trim() && !!v.starts_on && !!v.ends_on && v.lines.length > 0 && v.lines.every((l) => spendReady(l) && Number(l.unit_cost) > 0)
  const back = () => (history.length > 1 ? history.back() : navigate(id ? `/office/agreements/${id}` : '/office/agreements'))
  const save = () => {
    setBusy(true)
    setError('')
    const body = new FormData()
    for (const k of ['supplier_id', 'kind', 'title', 'starts_on', 'ends_on', 'value_cap', 'terms'] as const) body.append(k, v[k] ?? '')
    body.append('lines', JSON.stringify(v.lines.map(({ label: _l, ...l }) => ({ ...l, max_qty: blanket ? l.max_qty : '' }))))
    if (file) body.append('file', file)
    api.saveAgreement(id, body).then((r) => navigate(`/office/agreements/${r.id}`, true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>{id ? 'The agreement' : 'Contracts and blanket orders'}</Button>
      <h1 className={s.title} style={{ marginBottom: 4 }}>{id ? `Edit ${form.number}` : 'New agreement'}</h1>
      <Text className={s.muted}>A draft: someone other than who made it puts it in force from its page.</Text>
      {error && <MessageBar intent="error" style={{ margin: '12px 0' }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <div style={{ height: 12 }} />
      <Panel title="Agreement">
        <div className={f.grid}>
          <Choose label="Supplier" required options={form.suppliers} value={v.supplier_id} onChange={set('supplier_id')} />
          <Choose label="Kind" required options={form.kinds} value={v.kind} onChange={set('kind')} />
          <TextField label="Title" required maxLength={160} placeholder="e.g. Antimalarials prices 2027" value={v.title} onChange={set('title')} />
          <TextField label="From" required type="date" value={v.starts_on} onChange={set('starts_on')} />
          <TextField label="Until" required type="date" value={v.ends_on} onChange={set('ends_on')} />
          <TextField label="Most that may be ordered in total (before VAT, optional)" type="number" min={0} step="any" prefix={form.currency} value={v.value_cap} onChange={set('value_cap')} />
          <LongText label="Terms (delivery, credit, returns…)" className={f.spanAll} maxLength={2000} value={v.terms} onChange={set('terms')} />
          <Field label="The signed agreement (PDF or photo, optional)" className={f.spanAll} hint={v.file_name ? `Now: ${v.file_name}. Choose a file only to replace it.` : 'Up to 10 MB.'}>
            <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </Field>
        </div>
      </Panel>
      <Panel title="Items and agreed prices">
        {v.lines.map((l, i) => (
          <div key={i} className={s.line} role="group" aria-label={`Item ${i + 1}`}>
            <div className={s.lineHead}>
              <Text weight="semibold">{i + 1}.</Text>
              <SpendKind line={l} onChange={(p) => line(i, p)} />
              <span className={s.grow} />
              <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove item ${i + 1}`} disabled={v.lines.length === 1} onClick={() => set('lines')(v.lines.filter((_, j) => j !== i))} />
            </div>
            <div className={s.grid}>
              <SpendItem line={l} accounts={form.accounts} onChange={(p) => line(i, p)} />
              <TextField label="Agreed price per unit (before VAT)" required type="number" min={0} step="any" prefix={form.currency} value={l.unit_cost} onChange={(x) => line(i, { unit_cost: x })} />
              {blanket
                ? <TextField label="Most that may be ordered (optional)" type="number" min={0} step="any" value={l.max_qty} onChange={(x) => line(i, { max_qty: x })} />
                : <TextField label="Note" maxLength={255} value={l.note} onChange={(x) => line(i, { note: x })} />}
            </div>
          </div>
        ))}
        <Button icon={<AddRegular />} style={{ marginTop: 10 }} onClick={() => set('lines')([...v.lines, blank()])}>Add item</Button>
      </Panel>
      <div className={s.foot}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Save draft'}</Button>
      </div>
    </div>
  )
}
