import { Button, Combobox, Field, makeStyles, Option } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'

const useStyles = makeStyles({
  field: { minWidth: 0 },
  row: { display: 'flex', gap: '6px', alignItems: 'center' },
  box: { flex: '1 1 auto', width: '100%', minWidth: 0 },
  inner: { minWidth: 0, width: '100%' },
})

export type Found = { id: string; label: string; detail?: string }

/**
 * Choose one of many records by typing (a customer by name, code or phone…): what matches is offered as you type, with
 * a detail line; "Add" beside it when the person may add a missing one. For lists too long to send in full.
 */
export function SearchPicker({ label, value, text: chosen, onChange, search, required, placeholder, onAdd }: {
  label: string; value: string; text: string | null; onChange: (f: Found | null) => void
  search: (q: string, signal?: AbortSignal) => Promise<{ results: Found[] }>
  required?: boolean; placeholder?: string; onAdd?: () => void
}) {
  const s = useStyles()
  const [text, setText] = useState(chosen ?? '')
  const [found, setFound] = useState<Found[]>([])
  useEffect(() => setText(chosen ?? ''), [chosen])
  useEffect(() => {
    if (text.trim().length < 2 || text === chosen) return
    const ctl = new AbortController()
    const t = setTimeout(() => search(text.trim(), ctl.signal).then((r) => setFound(r.results)).catch(() => undefined), 250)
    return () => { clearTimeout(t); ctl.abort() }
  }, [text, chosen, search])
  return (
    <Field label={label} required={required} className={s.field}>
      <div className={s.row}>
        <Combobox className={s.box} input={{ className: s.inner }} value={text} selectedOptions={value ? [value] : []} placeholder={placeholder}
          onChange={(e) => setText(e.target.value)} onBlur={() => setText(chosen ?? '')}
          onOptionSelect={(_, d) => {
            const f = found.find((x) => x.id === d.optionValue) ?? null
            onChange(f)
            setText(f?.label ?? '')
          }}>
          {found.map((f) => <Option key={f.id} value={f.id} text={f.label}>{f.label}{f.detail ? ` · ${f.detail}` : ''}</Option>)}
          {found.length === 0 && <Option disabled value="">{text.trim().length < 2 ? 'Type at least two letters' : 'Nothing matches'}</Option>}
        </Combobox>
        {onAdd && <Button icon={<AddRegular />} aria-label={`Add ${label.toLowerCase()}`} title={`Add ${label.toLowerCase()}`} onClick={onAdd} />}
      </div>
    </Field>
  )
}
