import { Button, Combobox, Field, Input, makeStyles, mergeClasses, Option, Switch, Tag, TagGroup, Textarea, tokens } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useMemo, useState } from 'react'
import type { Option as Choice } from '../api'

/**
 * Form pieces for the new back office's forms: a list to choose from (type to narrow it, "+" to add a missing entry),
 * one to choose several, a box that collects codes (scan or type, then Enter), and labelled inputs and switches. Each
 * carries its label, help and whether it is required, like the current forms.
 */
const useStyles = makeStyles({
  withAdd: { display: 'flex', gap: '4px', alignItems: 'flex-start', '& > :first-child': { flex: '1 1 auto', minWidth: 0 } },
  full: { width: '100%', minWidth: 0 },
  field: { minWidth: 0 },
  inner: { minWidth: 0, width: '100%' }, // the text box inside a list: no natural width of its own // a grid cell may be narrower than a box's own minimum width
  tags: { display: 'flex', flexDirection: 'column', gap: '6px' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: '12px 16px', alignItems: 'start',
    '@media (max-width: 1000px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' }, '@media (max-width: 640px)': { gridTemplateColumns: '1fr' } },
  span2: { gridColumn: 'span 2', '@media (max-width: 640px)': { gridColumn: 'auto' } },
  spanAll: { gridColumn: '1 / -1' },
  muted: { color: tokens.colorNeutralForeground3 },
})

export function useFieldStyles() {
  return useStyles()
}

type Base = { label: string; hint?: string; required?: boolean; disabled?: boolean; className?: string }

/** One from a list; typing narrows it. `onAdd` shows "+" to add a missing one (it returns the new entry's id). */
export function Choose({ label, hint, required, disabled, className, options, value, onChange, placeholder, onAdd, clearable = !required }: Base & {
  options: Choice[]; value: string; onChange: (v: string) => void; placeholder?: string; onAdd?: () => void; clearable?: boolean
}) {
  const s = useStyles()
  const selected = options.find((o) => o.id === value)
  const [text, setText] = useState(selected?.label ?? '')
  useEffect(() => setText(selected?.label ?? ''), [selected?.label])
  const shown = useMemo(() => {
    const t = text.trim().toLowerCase()
    return !t || t === selected?.label.toLowerCase() ? options : options.filter((o) => o.label.toLowerCase().includes(t))
  }, [options, text, selected?.label])
  const box = (
    <Combobox className={s.full} input={{ className: s.inner }} value={text} selectedOptions={value ? [value] : []} placeholder={placeholder ?? (clearable ? 'None' : 'Choose')} disabled={disabled}
      clearable={clearable && !disabled} onChange={(e) => setText(e.target.value)}
      onOptionSelect={(_, d) => { onChange(d.optionValue ?? ''); setText(d.optionValue ? d.optionText ?? '' : '') }}
      onBlur={() => setText(selected?.label ?? '')}>
      {shown.slice(0, 300).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      {shown.length === 0 && <Option disabled value="">Nothing matches</Option>}
    </Combobox>
  )
  return (
    <Field label={label} hint={hint} required={required} className={mergeClasses(s.field, className)}>
      {onAdd && !disabled ? <div className={s.withAdd}>{box}<Button icon={<AddRegular />} aria-label={`Add a new ${label.toLowerCase()}`} onClick={onAdd} /></div> : box}
    </Field>
  )
}

/** Several from a list. */
export function ChooseMany({ label, hint, className, options, value, onChange, placeholder }: Base & {
  options: Choice[]; value: string[]; onChange: (v: string[]) => void; placeholder?: string
}) {
  const s = useStyles()
  const names = value.map((v) => options.find((o) => o.id === v)?.label ?? v)
  return (
    <Field label={label} hint={hint} className={mergeClasses(s.field, className)}>
      <Combobox className={s.full} input={{ className: s.inner }} multiselect selectedOptions={value} value={names.join(', ')} placeholder={placeholder ?? 'None'}
        onOptionSelect={(_, d) => onChange(d.selectedOptions)}>
        {options.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
      </Combobox>
    </Field>
  )
}

/** Codes (barcodes, ingredients…): scan or type, then Enter or a comma; each shows as a tag that can be taken off. */
export function Tags({ label, hint, className, value, onChange, placeholder }: Base & { value: string[]; onChange: (v: string[]) => void; placeholder?: string }) {
  const s = useStyles()
  const [text, setText] = useState('')
  const add = () => {
    const more = text.split(',').map((x) => x.trim()).filter((x) => x && !value.includes(x))
    if (more.length) onChange([...value, ...more])
    setText('')
  }
  return (
    <Field label={label} hint={hint} className={mergeClasses(s.field, className)}>
      <div className={s.tags}>
        <Input value={text} placeholder={placeholder ?? 'Scan or type, then Enter'} onChange={(_, d) => setText(d.value)} onBlur={add}
          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add() } }} />
        {value.length > 0 && (
          <TagGroup aria-label={label} onDismiss={(_, d) => onChange(value.filter((x) => x !== d.value))}>
            {value.map((x) => <Tag key={x} value={x} size="small" dismissible dismissIcon={{ 'aria-label': `Remove ${x}` }}>{x}</Tag>)}
          </TagGroup>
        )}
      </div>
    </Field>
  )
}

/** A labelled text box (type="number" for figures). */
export function TextField({ label, hint, required, disabled, className, value, onChange, type = 'text', placeholder, maxLength, min, max, step, prefix }: Base & {
  value: string; onChange: (v: string) => void; type?: 'text' | 'number' | 'date' | 'datetime-local'; placeholder?: string; maxLength?: number
  min?: number; max?: number; step?: string; prefix?: string
}) {
  const s = useStyles()
  return (
    <Field label={label} hint={hint} required={required} className={mergeClasses(s.field, className)}>
      <Input className={s.full} value={value} type={type} placeholder={placeholder} maxLength={maxLength} min={min} max={max} step={step} disabled={disabled}
        contentBefore={prefix} onChange={(_, d) => onChange(d.value)} />
    </Field>
  )
}

export function LongText({ label, hint, className, value, onChange, maxLength }: Base & { value: string; onChange: (v: string) => void; maxLength?: number }) {
  const s = useStyles()
  return (
    <Field label={label} hint={hint} className={mergeClasses(s.field, className)}>
      <Textarea value={value} maxLength={maxLength} resize="vertical" rows={3} onChange={(_, d) => onChange(d.value)} />
    </Field>
  )
}

/** On or off, with its meaning underneath. */
export function Toggle({ label, hint, className, value, onChange, disabled }: Base & { value: boolean; onChange: (v: boolean) => void }) {
  const s = useStyles()
  return (
    <Field label={label} hint={hint} className={mergeClasses(s.field, className)}>
      <Switch checked={value} disabled={disabled} onChange={(_, d) => onChange(d.checked)} />
    </Field>
  )
}
