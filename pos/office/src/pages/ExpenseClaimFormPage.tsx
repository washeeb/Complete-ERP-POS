import { Button, Field, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type EcForm } from '../api'
import { Choose, TextField, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px' },
  muted: { color: tokens.colorNeutralForeground3 },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px 16px', margin: '12px 0 14px' },
  foot: { display: 'flex', justifyContent: 'flex-end', gap: '8px' },
})

const today = () => new Date().toISOString().slice(0, 10)

/**
 * New expense claim (release 6): money spent for the business — the branch, the day, what for, the amount, a short
 * description, who was paid and the receipt photo. Someone else approves it, then it is paid back.
 */
export function ExpenseClaimFormPage({ onTitle }: { onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<EcForm | null>(null)
  const [v, setV] = useState({ branch_id: '', spent_on: today(), account_id: '', amount: '', description: '', payee: '', project_id: '' })
  const [receipt, setReceipt] = useState<File | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => {
    onTitle('New expense claim')
    api.expenseClaimForm().then((x) => { setForm(x); if (x.branches.length === 1) setV((o) => ({ ...o, branch_id: x.branches[0].id })) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [onTitle])

  if (!form) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the form…" />}</div>
  const set = (k: keyof typeof v) => (x: string) => setV({ ...v, [k]: x })
  const ready = !!v.branch_id && !!v.spent_on && !!v.account_id && Number(v.amount) > 0 && !!v.description.trim()
  const save = () => {
    setBusy(true)
    setError('')
    const body = new FormData()
    Object.entries(v).forEach(([k, x]) => { if (x) body.append(k, x) })
    if (receipt) body.append('receipt', receipt)
    api.saveExpenseClaim(body).then((r) => navigate(`/office/expense-claims/${r.id}`, true))
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/expense-claims'))}>Expense claims</Button>
      <h1 className={s.title}>New expense claim</h1>
      <Text className={s.muted}>Someone else approves it, then it is paid back. Attach the receipt so they can check it.</Text>
      {error && <MessageBar intent="error" style={{ marginTop: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <section className={s.panel} aria-label="The claim">
        <div className={f.grid}>
          <Choose label="Branch" required options={form.branches} value={v.branch_id} onChange={set('branch_id')} />
          <TextField label="Date spent" required type="date" value={v.spent_on} onChange={set('spent_on')} />
          <Choose label="What for" required options={form.accounts} value={v.account_id} onChange={set('account_id')} />
          <TextField label="Amount" required type="number" min={0} step="any" prefix={form.currency} value={v.amount} onChange={set('amount')} />
          <TextField label="Description" required className={f.span2} maxLength={255} value={v.description} onChange={set('description')} />
          <TextField label="Paid to (shop, person)" maxLength={255} value={v.payee} onChange={set('payee')} />
          {form.projects && <Choose label="For project" options={form.projects} value={v.project_id} onChange={set('project_id')} />}
          <Field label="Receipt photo" hint="A photo (JPEG, PNG, WebP) or a PDF, up to 5 MB.">
            <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(e) => setReceipt(e.target.files?.[0] ?? null)} />
          </Field>
        </div>
      </section>
      <div className={s.foot}>
        <Button onClick={() => (history.length > 1 ? history.back() : navigate('/office/expense-claims'))}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : 'Submit the claim'}</Button>
      </div>
    </div>
  )
}
