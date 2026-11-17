import { Combobox, Field, makeStyles, Option } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type UnitOption } from '../api'

const useStyles = makeStyles({
  field: { minWidth: 0 },
  box: { width: '100%', minWidth: 0 },
  inner: { minWidth: 0, width: '100%' },
})

/**
 * "Product and unit" on a document line (adjustments, counts, transfers…): type part of the name or SKU, or scan a
 * barcode; each product's units are offered ("Milo — CTN × 24"). As the current forms' product-unit field.
 */
export function UnitPicker({ value, label, onChange, required = true }: {
  value: string; label: string | null; onChange: (u: UnitOption | null) => void; required?: boolean
}) {
  const s = useStyles()
  const [text, setText] = useState(label ?? '')
  const [found, setFound] = useState<UnitOption[]>([])
  useEffect(() => setText(label ?? ''), [label])
  useEffect(() => {
    if (text.trim().length < 2 || text === label) return
    const ctl = new AbortController()
    const t = setTimeout(() => api.stockUnits(text.trim(), ctl.signal).then((r) => setFound(r.results)).catch(() => undefined), 250)
    return () => { clearTimeout(t); ctl.abort() }
  }, [text, label])
  return (
    <Field label="Product and unit" required={required} className={s.field}>
      <Combobox className={s.box} input={{ className: s.inner }} value={text} selectedOptions={value ? [value] : []} placeholder="Name, SKU or scan the barcode"
        onChange={(e) => setText(e.target.value)} onBlur={() => setText(label ?? '')}
        onOptionSelect={(_, d) => {
          const u = found.find((x) => x.id === d.optionValue) ?? null
          onChange(u)
          setText(u?.label ?? '')
        }}>
        {found.map((u) => <Option key={u.id} value={u.id}>{u.label}</Option>)}
        {found.length === 0 && <Option disabled value="">{text.trim().length < 2 ? 'Type at least two letters' : 'Nothing matches'}</Option>}
      </Combobox>
    </Field>
  )
}
