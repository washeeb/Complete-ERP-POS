import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Field, Input, makeStyles, MessageBar, MessageBarBody,
  Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, ArrowUndoRegular, EyeRegular, PersonAddRegular, PrintRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SaleDetail } from '../api'
import { money } from '../home/format'
import { navigate } from '../nav'
import { ReturnGoods } from './ReturnGoods'

const useStyles = makeStyles({
  page: { flex: '1 1 auto', overflowY: 'auto', padding: '16px 20px 32px', '--zx-pad-t': '16px', '--zx-pad-x': '20px', '--zx-pad-b': '32px',
    '@media (max-width: 760px)': { padding: '12px 10px 24px', '--zx-pad-t': '12px', '--zx-pad-x': '10px', '--zx-pad-b': '24px' } },
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '14px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0, display: 'flex', alignItems: 'center', gap: '10px' },
  muted: { color: tokens.colorNeutralForeground3 },
  actions: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', marginBottom: '14px' },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 10px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: '12px 18px' },
  fact: { display: 'flex', flexDirection: 'column', gap: '2px' },
  value: { fontWeight: tokens.fontWeightSemibold, fontVariantNumeric: 'tabular-nums' },
  totalValue: { fontSize: '20px', fontWeight: tokens.fontWeightBold, fontVariantNumeric: 'tabular-nums' },
  two: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px', '@media (max-width: 900px)': { gridTemplateColumns: '1fr' } },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
})

/** One till sale in full (release 2): the figures, the customer, the items, tax and payments; print a copy; keep a walk-in. */
export function SalePage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const s = useStyles()
  const [sale, setSale] = useState<SaleDetail | null>(null)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [returning, setReturning] = useState(false)
  const [done, setDone] = useState('')

  useEffect(() => {
    api.sale(id).then((x) => { setSale(x); onTitle(`Sale ${x.receipt_no}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (error) return <div className={s.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!sale) return <div className={s.page}><Spinner label="Opening the sale…" /></div>
  const m = (v: number) => money(v, sale.currency, sale.exponent)
  const fact = (label: string, value: string, big = false) => (
    <div className={s.fact}><Text size={200} className={s.muted}>{label}</Text><span className={big ? s.totalValue : s.value}>{value}</span></div>
  )

  return (
    <div className={s.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/sales'))}>Till sales</Button>
      <div className={s.head}>
        <h1 className={s.title}>Sale {sale.receipt_no} {sale.kind === 'return' && <Badge appearance="tint" color="warning">Return{sale.return_reason ? `: ${sale.return_reason}` : ''}</Badge>}</h1>
        <div className={s.actions}>
          <Button icon={<EyeRegular />} onClick={() => window.open(sale.actions.preview, '_blank', 'noopener')}>Print preview</Button>
          <Button appearance="primary" icon={<PrintRegular />} onClick={() => window.open(sale.actions.print, '_blank', 'noopener')}>Print copy</Button>
          {sale.actions.save_customer && <Button icon={<PersonAddRegular />} onClick={() => setSaving(true)}>Save as customer</Button>}
          {sale.actions.return && <Button icon={<ArrowUndoRegular />} onClick={() => { setDone(''); setReturning(true) }}>Return goods</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {returning && <ReturnGoods saleId={sale.id} onClose={() => setReturning(false)} onDone={(r) => { setReturning(false); navigate(`/office/office-returns/${r.id}`) }} />}

      <section className={s.panel}>
        <div className={s.facts}>
          {fact('Sold at', new Date(sale.occurred_at).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' }))}
          {fact('Teller', sale.cashier ?? '—')}
          {fact('Where', sale.where)}
          {fact('Total', m(sale.total), true)}
          {fact('Before tax', m(sale.net))}
          {fact('Tax', m(sale.tax))}
          {fact('Change given', m(sale.change))}
          {fact('Discounts', m(sale.discount) + (sale.discount_note ? ` · ${sale.discount_note}` : ''))}
          {sale.points > 0 && fact('Points earned', String(sale.points))}
        </div>
      </section>

      {(sale.customer.walk_in_name || sale.customer.walk_in_phone || sale.customer.comment || sale.customer.details.length > 0) && (
        <section className={s.panel}>
          <h2 className={s.h2}>Customer details and comment</h2>
          <div className={s.facts}>
            {fact('Walk-in name', sale.customer.walk_in_name ?? '—')}
            {fact('Walk-in phone', sale.customer.walk_in_phone ?? '—')}
            {sale.customer.details.map((d) => <div key={d.label}>{fact(d.label, d.value)}</div>)}
          </div>
          {sale.customer.comment && <Text block style={{ marginTop: 10 }}>{sale.customer.comment}{sale.customer.comment_on_receipt ? ' (printed on the receipt)' : ''}</Text>}
        </section>
      )}

      <section className={s.panel}>
        <h2 className={s.h2}>Items</h2>
        <Table size="small" aria-label="Items">
          <TableHeader><TableRow>
            <TableHeaderCell>Item</TableHeaderCell><TableHeaderCell>Quantity</TableHeaderCell><TableHeaderCell className={s.end} button={{ style: { justifyContent: 'flex-end' } }}>Unit price</TableHeaderCell>
            <TableHeaderCell className={s.end} button={{ style: { justifyContent: 'flex-end' } }}>Tax</TableHeaderCell><TableHeaderCell className={s.end} button={{ style: { justifyContent: 'flex-end' } }}>Line total</TableHeaderCell>
          </TableRow></TableHeader>
          <TableBody>
            {sale.lines.map((l, i) => (
              <TableRow key={i}>
                <TableCell>{l.name}{l.dosage && <Text block size={200} className={s.muted}>Dosage: {l.dosage}</Text>}</TableCell>
                <TableCell>{l.qty}</TableCell><TableCell className={s.end}>{m(l.unit_price)}</TableCell>
                <TableCell className={s.end}>{m(l.tax)}</TableCell><TableCell className={s.end}><Text weight="semibold">{m(l.total)}</Text></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      <div className={s.two}>
        <section className={s.panel}>
          <h2 className={s.h2}>Tax</h2>
          {sale.tax_breakdown.length === 0 ? <Text className={s.muted}>No tax on this sale.</Text> : (
            <Table size="small" aria-label="Tax"><TableBody>
              {sale.tax_breakdown.map((t) => <TableRow key={t.name}><TableCell>{t.name}</TableCell><TableCell className={s.end}>{m(t.amount)}</TableCell></TableRow>)}
            </TableBody></Table>
          )}
        </section>
        <section className={s.panel}>
          <h2 className={s.h2}>Payments</h2>
          <Table size="small" aria-label="Payments"><TableBody>
            {sale.payments.map((p, i) => (
              <TableRow key={i}><TableCell>{p.method}{p.reference && <Text block size={200} className={s.muted}>{p.reference}</Text>}</TableCell><TableCell className={s.end}>{m(p.amount)}</TableCell></TableRow>
            ))}
          </TableBody></Table>
        </section>
      </div>

      {saving && <SaveCustomer sale={sale} onDone={(name) => { setSaving(false); setDone(`${name} is now a customer.`); setSale({ ...sale, actions: { ...sale.actions, save_customer: false } }) }} onCancel={() => setSaving(false)} />}
    </div>
  )
}

/** A walk-in who gave a name and phone, kept as a customer for next time (the sale itself does not change). */
function SaveCustomer({ sale, onDone, onCancel }: { sale: SaleDetail; onDone: (name: string) => void; onCancel: () => void }) {
  const [name, setName] = useState(sale.customer.walk_in_name ?? '')
  const [phone, setPhone] = useState(sale.customer.walk_in_phone ?? '')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onCancel()}>
      <DialogSurface>
        <DialogBody>
          <DialogTitle>Save as customer</DialogTitle>
          <DialogContent>
            <Field label="Name" required><Input value={name} onChange={(_, d) => setName(d.value)} maxLength={255} /></Field>
            <Field label="Phone" required style={{ marginTop: 8 }}><Input value={phone} onChange={(_, d) => setPhone(d.value)} maxLength={40} /></Field>
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 8 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onCancel}>Cancel</Button>
            <Button appearance="primary" disabled={busy || !name.trim() || !phone.trim()} onClick={() => {
              setBusy(true)
              api.saveSaleCustomer(sale.id, name.trim(), phone.trim()).then((r) => onDone(r.customer.name))
                .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Saving…' : 'Save'}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
