import { makeStyles, ToggleButton } from '@fluentui/react-components'
import { api, type Option, type SpendLine } from '../api'
import { Choose, TextField } from './Fields'
import { UnitPicker } from './UnitPicker'

const useStyles = makeStyles({
  kinds: { display: 'flex', gap: '6px', flexWrap: 'wrap' },
  service: { display: 'grid', gap: '8px' },
})

/** A blank line of the given kind. */
export const blankSpend = (kind: 'stock' | 'service' = 'stock'): SpendLine => ({ kind, product_uom_id: '', label: null, account_id: '', description: '', uom_code: 'EA' })

/** Whether a line says what it is: a product unit, or a service with what and the kind of spending. */
export const spendReady = (l: SpendLine) => (l.kind === 'stock' ? !!l.product_uom_id : !!l.description.trim() && !!l.account_id)

/**
 * The first fields of a buying line (as the current forms' "product or service"): a product in its buying unit, or a
 * service or non-stock item with what it is, the kind of spending and its unit.
 */
export function SpendKind({ line, onChange }: { line: SpendLine; onChange: (l: Partial<SpendLine>) => void }) {
  const s = useStyles()
  return (
    <div className={s.kinds}>
      <ToggleButton size="small" checked={line.kind === 'stock'} onClick={() => onChange(blankSpend('stock'))}>Product</ToggleButton>
      <ToggleButton size="small" checked={line.kind === 'service'} onClick={() => onChange(blankSpend('service'))}>Service or non-stock item</ToggleButton>
    </div>
  )
}

export function SpendItem({ line, accounts, onChange, onProduct }: {
  line: SpendLine; accounts: Option[]; onChange: (l: Partial<SpendLine>) => void; onProduct?: (productId: string | undefined) => void
}) {
  const s = useStyles()
  if (line.kind === 'stock') {
    return <UnitPicker value={line.product_uom_id} label={line.label} search={api.procurementUnits}
      onChange={(u) => { onChange({ product_uom_id: u?.id ?? '', label: u?.label ?? null }); onProduct?.(u?.product_id) }} />
  }
  return (
    <div className={s.service}>
      <TextField label="What" required maxLength={160} placeholder="e.g. Servicing of the generator" value={line.description} onChange={(x) => onChange({ description: x })} />
      <Choose label="Kind of spending" required options={accounts} value={line.account_id} onChange={(x) => onChange({ account_id: x })} />
      <TextField label="Unit" maxLength={10} value={line.uom_code} onChange={(x) => onChange({ uom_code: x })} hint="EA, JOB, HOUR, MONTH…" />
    </div>
  )
}
