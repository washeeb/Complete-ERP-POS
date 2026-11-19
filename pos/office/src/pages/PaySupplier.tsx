import { makeStyles, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, ApiError, type PayForm, type SpRow } from '../api'
import { Ask } from '../form/Ask'
import { ConfirmItsYou } from '../form/ConfirmItsYou'
import { Choose, TextField, Toggle } from '../form/Fields'

const useStyles = makeStyles({
  form: { display: 'grid', gap: '10px', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', '@media (max-width: 560px)': { gridTemplateColumns: '1fr' } },
  wide: { gridColumn: '1 / -1' },
  wht: { gridColumn: '1 / -1', padding: '8px 10px', borderRadius: tokens.borderRadiusMedium, backgroundColor: tokens.colorNeutralBackground3 },
})

const today = () => new Date().toISOString().slice(0, 10)

/**
 * Pay a supplier (Supplier payments and Aged payables): from which account, how, the amount it settles (the oldest
 * bills first) and, where withholding tax is on, what is kept back — worked out as the amount is typed. A payment by
 * transfer or MoMo goes only to bank details someone has checked; above the company's limit it asks to confirm it is you.
 */
export function PaySupplier({ form, supplier, currency, onClose, onDone }: {
  form: PayForm; supplier?: string; currency: string; onClose: () => void; onDone: (p: SpRow) => void
}) {
  const s = useStyles()
  const [v, setV] = useState({ supplier_id: supplier ?? '', account_id: form.account, method: 'bank', amount: '', withhold: true, wht: '', reference: '', paid_on: today(),
    branch_id: form.branch, note: '' })
  const [preview, setPreview] = useState('Choose the supplier and the amount.')
  const [confirmUrl, setConfirmUrl] = useState<string | null>(null)
  const set = <K extends keyof typeof v>(k: K) => (x: (typeof v)[K]) => setV({ ...v, [k]: x })
  useEffect(() => {
    if (!form.wht) return
    const t = setTimeout(() => api.payPreview(v.supplier_id, v.amount, v.withhold, v.wht).then((r) => setPreview(r.text)).catch(() => undefined), 300)
    return () => clearTimeout(t)
  }, [form.wht, v.supplier_id, v.amount, v.withhold, v.wht])
  const pay = () => api.paySupplier({ ...v, wht: v.withhold && v.wht ? v.wht : null, reference: v.reference || null, note: v.note || null }).then((r) => onDone(r.payment))
    .catch((e: unknown) => {
      if (e instanceof ApiError && e.code === 'reconfirm' && e.url) { setConfirmUrl(e.url); return }
      throw e
    })
  if (confirmUrl) {
    return <ConfirmItsYou url={confirmUrl} why="Paying a supplier above the company's limit is protected." go="Pay now"
      onCancel={() => setConfirmUrl(null)} onGo={() => { setConfirmUrl(null); void pay() }} />
  }
  return (
    <Ask title="Pay a supplier" go="Record the payment" ready={!!v.supplier_id && !!v.account_id && !!v.method && Number(v.amount) > 0 && !!v.paid_on && !!v.branch_id}
      onClose={onClose} width={620} run={pay}>
      <Text block style={{ marginBottom: 10 }}>It settles the oldest bills first.</Text>
      <div className={s.form}>
        {!supplier && <Choose label="Supplier" required className={s.wide} options={form.suppliers} value={v.supplier_id} onChange={set('supplier_id')} />}
        <Choose label="Paid from" required options={form.accounts} value={v.account_id} onChange={set('account_id')} />
        <Choose label="Method" required options={form.methods} value={v.method} onChange={set('method')} />
        <TextField label={form.wht ? 'Amount to settle (before withholding tax)' : 'Amount'} required type="number" min={0} step="any" prefix={currency} value={v.amount} onChange={set('amount')} />
        <TextField label="Transfer / MoMo / cheque reference" maxLength={80} value={v.reference} onChange={set('reference')} />
        {form.wht && (
          <>
            <Toggle label="Keep back withholding tax" value={v.withhold} onChange={set('withhold')} />
            {v.withhold && <TextField label="Withholding tax to keep back, if different" type="number" min={0} step="any" prefix={currency} value={v.wht} onChange={set('wht')}
              hint="Leave empty to use the figure worked out (your accountant may give another)." />}
            <Text className={s.wht}><strong>Withholding tax: </strong>{preview}</Text>
          </>
        )}
        <TextField label="Paid on" required type="date" value={v.paid_on} onChange={set('paid_on')} />
        <Choose label="Branch" required options={form.branches} value={v.branch_id} onChange={set('branch_id')} />
        <TextField label="Note" className={s.wide} maxLength={255} value={v.note} onChange={set('note')} />
      </div>
    </Ask>
  )
}
