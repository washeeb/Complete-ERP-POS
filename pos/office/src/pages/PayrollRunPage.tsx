import { Button, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { ArrowDownloadRegular, ArrowLeftRegular, ArrowSyncRegular, CheckmarkRegular, ClipboardTaskListLtrRegular, MoneyRegular, PrintRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type PayrollDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { day, END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * One month's payroll (part 21), as the current page: gross, PAYE, SSNIT, net, who prepared, approved and paid it, the
 * tax and SSNIT rules used (and whether an accountant checked them), and each payslip. Recalculate (a draft), Approve
 * (someone other than who prepared it; posts to the books), Record payment (bank and MoMo from one account, cash from
 * another), and the payslips, pay sheet and bank / MoMo list.
 */
export function PayrollRunPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [r, setR] = useState<PayrollDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [dialog, setDialog] = useState<'approve' | 'pay' | null>(null)
  const [pay, setPay] = useState({ bank_account_id: '', cash_account_id: '', paid_on: '' })
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => {
    api.payrollRun(id).then((x) => { setR(x); onTitle(`${x.number} · ${x.month ?? ''}`) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !r) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!r) return <div className={page.page}><Spinner label="Opening the payroll…" /></div>
  const m = (x: number) => money(x, r.currency, r.exponent)
  const act = (action: 'recalculate' | 'approve' | 'pay', body: Record<string, unknown> = {}) => api.payrollAction(r.id, action, body).then((x) => { setR(x.run); setDialog(null); setDone(x.message) })
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/payroll'))}>Payroll</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{r.number}</span>{r.month}<ToneBadge tone={r.tone} label={r.status_label} /></h1>
        <div className={s.actions}>
          {r.actions.recalculate && <Button icon={<ArrowSyncRegular />} disabled={busy}
            onClick={() => { setBusy(true); setError(''); setDone(''); act('recalculate').catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false)) }}>
            {busy ? 'Working…' : 'Recalculate'}</Button>}
          {r.actions.approve && <Button appearance="primary" icon={<CheckmarkRegular />} onClick={() => { setDone(''); setDialog('approve') }}>Approve</Button>}
          {r.actions.pay && <Button appearance="primary" icon={<MoneyRegular />} onClick={() => { setDone(''); setPay({ bank_account_id: r.actions.pay!.bank, cash_account_id: r.actions.pay!.cash, paid_on: r.actions.pay!.today }); setDialog('pay') }}>Record payment</Button>}
          <Button icon={<PrintRegular />} as="a" href={r.print.payslips} target="_blank" rel="noopener">Payslips</Button>
          <Button icon={<ClipboardTaskListLtrRegular />} as="a" href={r.print.sheet} target="_blank" rel="noopener">Pay sheet</Button>
          <Button icon={<ArrowDownloadRegular />} as="a" href={r.print.list}>Bank / MoMo list (CSV)</Button>
        </div>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {r.profile && !r.checked && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>The tax and SSNIT rules ({r.profile}) are NOT YET CHECKED BY AN ACCOUNTANT.</MessageBarBody></MessageBar>}
      <Panel title="Details">
        <div className={s.facts}>
          <Fact label="Pay day">{day(r.pay_date)}</Fact>
          <Fact label="Gross pay">{m(r.gross)}</Fact>
          <Fact label="PAYE">{m(r.paye)}</Fact>
          <Fact label="SSNIT (employee + employer)">{m(r.ssnit)}</Fact>
          <Fact label="Net pay"><b>{m(r.net)}</b></Fact>
          <Fact label="Prepared by">{r.prepared_by ?? '—'}</Fact>
          <Fact label="Approved by">{r.approved_by ?? '—'}</Fact>
          <Fact label="Paid">{r.paid ?? '—'}</Fact>
          <Fact label="Tax and SSNIT rules">{r.profile ?? '—'}{r.checked && <Text size={200} block className={s.muted}>{r.checked}</Text>}</Fact>
        </div>
      </Panel>
      <Panel title="Payslips">
        <Table size="small" aria-label="Payslips" style={{ minWidth: 640 }}>
          <TableHeader><TableRow><TableHeaderCell>Employee</TableHeaderCell><TableHeaderCell button={END}>Gross</TableHeaderCell><TableHeaderCell button={END}>SSNIT</TableHeaderCell>
            <TableHeaderCell button={END}>PAYE</TableHeaderCell><TableHeaderCell button={END}>Net</TableHeaderCell><TableHeaderCell>Paid by</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {r.payslips.length === 0 && <TableRow><TableCell colSpan={6}><Text className={s.muted}>No payslips.</Text></TableCell></TableRow>}
            {r.payslips.map((p) => (
              <TableRow key={p.id}><TableCell><Link href={p.open} target="_blank" rel="noopener">{p.employee}</Link></TableCell><TableCell className={s.end}>{m(p.gross)}</TableCell>
                <TableCell className={s.end}>{m(p.ssnit)}</TableCell><TableCell className={s.end}>{m(p.paye)}</TableCell><TableCell className={s.end}><b>{m(p.net)}</b></TableCell><TableCell>{p.paid_by}</TableCell></TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      {dialog === 'approve' && (
        <Ask title="Approve this payroll?" go="Approve" ready onClose={() => setDialog(null)} run={() => act('approve')}>
          <Text>Approving locks the payslips and posts the payroll to the books (wages, SSNIT, PAYE, Tier 2 and 3, loan repayments).</Text>
        </Ask>
      )}
      {dialog === 'pay' && r.actions.pay && (
        <Ask title="Record payment" go="Record payment" ready={!!pay.paid_on} onClose={() => setDialog(null)} run={() => act('pay', pay)}>
          <div className={s.one}>
            <Choose label="Bank and MoMo pay from" options={r.actions.pay.accounts} value={pay.bank_account_id} onChange={(x) => setPay({ ...pay, bank_account_id: x })} clearable />
            <Choose label="Cash pay from" options={r.actions.pay.accounts} value={pay.cash_account_id} onChange={(x) => setPay({ ...pay, cash_account_id: x })} clearable />
            <TextField label="Paid on" required type="date" value={pay.paid_on} onChange={(x) => setPay({ ...pay, paid_on: x })} />
          </div>
        </Ask>
      )}
    </div>
  )
}
