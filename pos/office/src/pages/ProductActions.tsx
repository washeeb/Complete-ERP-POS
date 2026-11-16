import {
  Button, Checkbox, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Field, Input, Link, MessageBar, MessageBarBody,
  Option, Radio, RadioGroup, Text, tokens,
} from '@fluentui/react-components'
import { useState } from 'react'
import { api, type BulkResult, type Option as Choice, type Pick, type ProductsOptions } from '../api'

/** The actions on products the current screen has, for the ticked products (or one product). */
export type ProductActionKind = 'labels' | 'move' | 'schedule' | 'batches' | 'industry' | 'unit'

const TITLES: Record<ProductActionKind, string> = {
  labels: 'Print labels', move: 'Change category, brand or tax', schedule: 'Set medicine schedule', batches: 'Switch on batches and expiry',
  industry: 'Move to industry', unit: 'Change the unit',
}

function Pickone({ label, options, value, onChange, placeholder, required }: {
  label: string; options: Choice[]; value: string; onChange: (v: string) => void; placeholder?: string; required?: boolean
}) {
  return (
    <Field label={label} required={required}>
      <Dropdown placeholder={placeholder ?? 'Choose'} value={options.find((o) => o.id === value)?.label ?? ''} selectedOptions={value ? [value] : []}
        onOptionSelect={(_, d) => onChange(d.optionValue ?? '')}>
        {options.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      </Dropdown>
    </Field>
  )
}

/**
 * One action, as a dialog: its form, then what happened ("12 products changed"; any product left as it was is listed
 * with why). `count` is how many products it is for; `note` says more where the action needs it (the unit now).
 */
export function ProductAction({ kind, pick, count, options, note, onClose, onDone }: {
  kind: ProductActionKind; pick: Pick; count: number; options: ProductsOptions; note?: string | null; onClose: () => void; onDone: () => void
}) {
  const [f, setF] = useState<Record<string, string>>(() => ({
    kind: 'shelf', layout: 'a4_24', price_list_id: options.labels.price_lists[0]?.id ?? '', copies: '1', skip: '0', give: options.can.manage ? '1' : '',
    field: 'category_id', to: '', schedule: '', industry: '', mode: 'convert', uom_id: '', per: '', reason: '',
  }))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [result, setResult] = useState<BulkResult | null>(null)
  const [labels, setLabels] = useState<{ url: string; given: number } | null>(null)
  const set = (k: string) => (v: string) => setF((x) => ({ ...x, [k]: v }))
  const what = `${count.toLocaleString('en-GB')} product${count === 1 ? '' : 's'}`
  const layout = options.labels.layouts.find((l) => l.id === f.layout)

  const ready = kind === 'labels' ? Number(f.copies) >= 1
    : kind === 'move' ? f.field !== 'tax_group_id' || f.to !== ''
      : kind === 'schedule' ? f.schedule !== ''
        : kind === 'industry' ? f.industry !== ''
          : kind === 'unit' ? f.uom_id !== '' && f.reason.trim() !== '' && (f.mode === 'relabel' || Number(f.per) > 0)
            : true

  const go = () => {
    setBusy(true)
    setError('')
    const done = kind === 'labels'
      ? api.productLabels(pick, { kind: f.kind, layout: f.layout, price_list_id: f.price_list_id || null, copies: Number(f.copies), skip: Number(f.skip) || 0, give: f.give === '1' })
        .then((r) => setLabels({ url: r.url, given: r.given }))
      : api.productsBulk(pick, kind, kind === 'move' ? { field: f.field, to: f.to || null } : kind === 'schedule' ? { schedule: f.schedule }
        : kind === 'industry' ? { industry: f.industry } : kind === 'unit' ? { mode: f.mode, uom_id: f.uom_id, per: f.mode === 'convert' ? f.per : null, reason: f.reason.trim() } : {})
        .then((r) => { setResult(r); onDone() })
    done.catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }

  const finished = result !== null || labels !== null
  const choices = f.field === 'brand_id' ? options.brands : f.field === 'tax_group_id' ? options.tax_groups : options.categories

  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 560 }}>
        <DialogBody>
          <DialogTitle>{TITLES[kind]}</DialogTitle>
          <DialogContent>
            {finished ? (
              labels ? (
                <MessageBar intent="success"><MessageBarBody>
                  Labels ready for {what}{labels.given ? `; ${labels.given} given an in-store barcode` : ''}.{' '}
                  <Link href={labels.url} target="_blank" rel="noopener">Open the labels</Link> (a new tab, ready to print).
                </MessageBarBody></MessageBar>
              ) : result && (
                <>
                  <MessageBar intent={result.skipped.length ? 'warning' : 'success'}><MessageBarBody>
                    {result.done.toLocaleString('en-GB')} product{result.done === 1 ? '' : 's'} changed
                    {result.untouched ? `, ${result.untouched.toLocaleString('en-GB')} already on` : ''}{result.skipped.length ? `, ${result.skipped.length} left as they were:` : '.'}
                  </MessageBarBody></MessageBar>
                  {result.skipped.length > 0 && <ul style={{ margin: '8px 0 0', paddingInlineStart: 20 }}>{result.skipped.slice(0, 20).map((x) => <li key={x}><Text size={200}>{x}</Text></li>)}</ul>}
                </>
              )
            ) : (
              <div style={{ display: 'grid', gap: 10 }}>
                <Text>For {what}.</Text>
                {kind === 'labels' && <>
                  <Pickone label="Label" options={options.labels.kinds} value={f.kind} onChange={set('kind')} required />
                  <Pickone label="Print on" options={options.labels.layouts} value={f.layout} onChange={set('layout')} required />
                  <Pickone label="Prices from" options={options.labels.price_lists} value={f.price_list_id} onChange={set('price_list_id')} />
                  <Field label="Labels per product" required><Input type="number" min={1} max={500} value={f.copies} onChange={(_, d) => set('copies')(d.value)} /></Field>
                  {layout?.sheet && <Field label="Leave empty at the start (a sheet already partly used)"><Input type="number" min={0} max={64} value={f.skip} onChange={(_, d) => set('skip')(d.value)} /></Field>}
                  {options.can.manage && <Checkbox label="Give products without a barcode an in-store barcode first" checked={f.give === '1'} onChange={(_, d) => set('give')(d.checked ? '1' : '')} />}
                </>}
                {kind === 'move' && <>
                  <Pickone label="Change" required value={f.field} onChange={(v) => setF((x) => ({ ...x, field: v, to: '' }))}
                    options={[{ id: 'category_id', label: 'Category' }, { id: 'brand_id', label: 'Brand' }, { id: 'tax_group_id', label: 'Tax group' }]} />
                  <Pickone label="To" options={f.field === 'tax_group_id' ? choices : [{ id: '', label: 'None' }, ...choices]} value={f.to} onChange={set('to')}
                    placeholder="None" required={f.field === 'tax_group_id'} />
                </>}
                {kind === 'schedule' && <>
                  <Text size={200}>Prescription-only and controlled medicines need a pharmacist's approval at the till; controlled drugs go in the controlled-drug register. Medicines are switched to batches and expiry dates.</Text>
                  <Pickone label="Schedule for these products" options={options.medicine_schedules} value={f.schedule} onChange={set('schedule')} required />
                </>}
                {kind === 'batches' && <Text>{options.batches_note}</Text>}
                {kind === 'industry' && <Pickone label="Move these products to" options={options.industry_choices} value={f.industry} onChange={set('industry')} required />}
                {kind === 'unit' && <>
                  {note && <Text size={200}>{note}</Text>}
                  <Text size={200}>The same change for every product. Products on open documents, or already in that unit, are left as they are and listed.</Text>
                  <Field label="What is changing" required>
                    <RadioGroup value={f.mode} onChange={(_, d) => set('mode')(d.value)}>
                      <Radio value="convert" label="Convert by pack size (e.g. a strip of 10 becomes 10 tablets: stock × 10, price ÷ 10)" />
                      <Radio value="relabel" label="The label was wrong (same numbers: 40 Pack becomes 40 Tablet)" />
                    </RadioGroup>
                  </Field>
                  <Pickone label="New unit" options={options.uoms} value={f.uom_id} onChange={set('uom_id')} required />
                  {f.mode === 'convert' && (
                    <Field label="How many of the new unit in one of the current unit" required
                      hint="e.g. 10 tablets in a strip. Stock is multiplied by it; the new unit's price is the old price divided by it. The old unit stays as a selling unit.">
                      <Input type="number" min={0.001} step="any" value={f.per} onChange={(_, d) => set('per')(d.value)} />
                    </Field>
                  )}
                  <Field label="Reason" required><Input value={f.reason} maxLength={255} placeholder="e.g. sold in single tablets from now on" onChange={(_, d) => set('reason')(d.value)} /></Field>
                </>}
                {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
              </div>
            )}
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>{finished ? 'Close' : 'Cancel'}</Button>
            {!finished && <Button appearance="primary" disabled={busy || !ready} onClick={go}>{busy ? 'Working…' : kind === 'labels' ? 'Make the labels' : TITLES[kind]}</Button>}
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
