import { Input, makeStyles, Spinner, Switch, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type ReturnForm } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'

const useStyles = makeStyles({
  line: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 90px 110px', gap: '8px 10px', alignItems: 'center', padding: '8px 0',
    borderBottom: `1px solid ${tokens.colorNeutralStroke2}`, '@media (max-width: 600px)': { gridTemplateColumns: '1fr 1fr' } },
  muted: { color: tokens.colorNeutralForeground3 },
  one: { display: 'grid', gap: '10px', marginTop: '10px' },
})

/**
 * "Return goods" on a sale's page (part 16, as the current page): how many of each line come back (and whether damaged),
 * where they go, why, and how the customer is refunded. A manager approves it before anything changes; good goods go
 * back on the shelf, damaged or expired ones to quarantine.
 */
export function ReturnGoods({ saleId, onClose, onDone }: { saleId: string; onClose: () => void; onDone: (r: { id: string; message: string }) => void }) {
  const s = useStyles()
  const [f, setF] = useState<ReturnForm | null>(null)
  const [error, setError] = useState('')
  const [qty, setQty] = useState<Record<string, string>>({})
  const [damaged, setDamaged] = useState<Record<string, boolean>>({})
  const [v, setV] = useState({ warehouse_id: '', reason_code: '', reason: '', refund_method: 'store_credit', account_id: '', reference: '' })
  useEffect(() => {
    api.returnForm(saleId).then((x) => { setF(x); setV((o) => ({ ...o, warehouse_id: x.warehouse_id })) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [saleId])
  const money = v.refund_method === 'money'
  const any = Object.values(qty).some((q) => Number(q) > 0)
  const ready = !!f && any && !!v.warehouse_id && !!v.reason_code && (!money || (!!v.account_id && v.reference.trim() !== ''))
  return (
    <Ask title="Return goods" go="Send for approval" width={680} ready={ready} onClose={onClose}
      run={() => api.submitReturn(saleId, { ...v, lines: (f?.lines ?? []).map((l) => ({ line_id: l.line_id, qty: qty[l.line_id] || '0', damaged: !!damaged[l.line_id] })) }).then(onDone)}>
      <Text block style={{ marginBottom: 8 }}>A manager approves it before anything changes. Goods go back on the shelf, or to quarantine if damaged or expired.</Text>
      {error && <Text block style={{ color: 'var(--zx-bad)' }}>{error}</Text>}
      {!f && !error && <Spinner size="tiny" />}
      {f && f.lines.length === 0 && <Text className={s.muted}>Everything on this receipt has been returned already.</Text>}
      {f?.lines.map((l) => (
        <div key={l.line_id} className={s.line}>
          <span>{l.name}<Text block size={200} className={s.muted}>Can return {l.can}</Text></span>
          <Input type="number" min={0} step="any" aria-label={`Return: ${l.name}`} placeholder="0" value={qty[l.line_id] ?? ''} onChange={(_, d) => setQty({ ...qty, [l.line_id]: d.value })} />
          <Switch label="Damaged" checked={!!damaged[l.line_id]} onChange={(_, d) => setDamaged({ ...damaged, [l.line_id]: d.checked })} />
        </div>
      ))}
      {f && (
        <div className={s.one}>
          <Choose label="Goes back to" required options={f.warehouses} value={v.warehouse_id} onChange={(x) => setV({ ...v, warehouse_id: x })} />
          <Choose label="Reason" required options={f.reasons} value={v.reason_code} onChange={(x) => setV({ ...v, reason_code: x })} />
          <TextField label="Details" maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
          <Choose label="Refund as" required options={f.refunds} value={v.refund_method} onChange={(x) => setV({ ...v, refund_method: x })} />
          {money && <Choose label="Paid from" required options={f.accounts} value={v.account_id} onChange={(x) => setV({ ...v, account_id: x })} />}
          {money && <TextField label="Reference" required maxLength={80} value={v.reference} onChange={(x) => setV({ ...v, reference: x })} />}
        </div>
      )}
    </Ask>
  )
}
