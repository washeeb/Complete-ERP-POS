import { Button, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArchiveRegular, ArrowLeftRegular, ArrowUndoRegular, PersonDeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api, type EmployeeForm } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Panel, ToneBadge, useProcStyles } from './ProcurementParts'
import { SaveBar } from '../form/SaveBar'

type Dialog = 'terminate' | 'archive' | 'restore' | null

/**
 * One employee (part 21), as the current form: the person (with their sign-in, for clocking in and self-service), the
 * job, pay per month (basic, allowances, overtime rate, junior overtime tax, SSNIT, Tier 3, welfare, commission,
 * appointments) and how they are paid. End employment (last day and reason), Archive and Restore.
 */
export function EmployeeFormPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [form, setForm] = useState<EmployeeForm | null>(null)
  const [v, setV] = useState<Record<string, string | boolean>>({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [end, setEnd] = useState({ date: new Date().toISOString().slice(0, 10), reason: '' })
  const [archived, setArchived] = useState<boolean | null>(null)
  const top = useRef<HTMLDivElement>(null)
  useEffect(() => {
    api.employeeForm(id).then((x) => { setForm(x); setV(x.values); onTitle(x.id ? `${x.number} · ${String(x.values.name)}` : 'New employee') })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (!form) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the employee…" />}</div>
  const t = (k: string) => String(v[k] ?? '')
  const put = (k: string) => (x: string | boolean) => setV((o) => ({ ...o, [k]: x }))
  const back = () => (history.length > 1 ? history.back() : navigate('/office/employees'))
  const ready = t('name').trim() !== '' && !!t('branch_id') && !!t('hire_date') && t('basic_salary') !== '' && (t('pay_method') !== 'bank' || t('bank_account_no').trim() !== '')
    && (t('pay_method') !== 'momo' || t('momo_number').trim() !== '')
  const save = () => {
    setBusy(true)
    setError('')
    api.saveEmployee(form.id, v).then(() => navigate('/office/employees', true))
      .catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); top.current?.scrollIntoView({ behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  const money = (k: string, label: string, required = false) => <TextField label={label} required={required} type="number" min={0} step="any" prefix={form.currency} value={t(k)} onChange={put(k)} />
  const isArchived = archived ?? form.archived
  return (
    <div className={page.page}>
      <div ref={top} />
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Employees</Button>
      <div className={s.head}>
        <h1 className={s.title}>
          {form.id && <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{form.number}</span>}{form.id ? t('name') : 'New employee'}
          {form.id && <ToneBadge tone={form.status === 'active' ? 'success' : 'subtle'} label={form.status === 'active' ? 'Active' : 'Left'} />}
        </h1>
        {form.id && <div className={s.actions}>
          {form.can_terminate && <Button icon={<PersonDeleteRegular />} onClick={() => setDialog('terminate')}>End employment</Button>}
          {!isArchived && (archived !== null || form.can_archive) && <Button icon={<ArchiveRegular />} onClick={() => setDialog('archive')}>Archive</Button>}
          {isArchived && (archived !== null || form.can_restore) && <Button icon={<ArrowUndoRegular />} onClick={() => setDialog('restore')}>Restore</Button>}
        </div>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Panel title="Person">
        <div className={f.grid}>
          <TextField label="Name" required maxLength={255} value={t('name')} onChange={put('name')} />
          <Choose label="Gender" options={form.genders} value={t('gender')} onChange={put('gender')} clearable />
          <TextField label="Date of birth" type="date" value={t('date_of_birth')} onChange={put('date_of_birth')} />
          <TextField label="Phone" maxLength={30} value={t('phone')} onChange={put('phone')} />
          <TextField label="Email" maxLength={255} value={t('email')} onChange={put('email')} />
          <TextField label="Address" maxLength={255} value={t('address')} onChange={put('address')} />
          <TextField label="Ghana Card no. (TIN)" maxLength={30} value={t('ghana_card_no')} onChange={put('ghana_card_no')} />
          <TextField label="SSNIT no." maxLength={30} value={t('ssnit_no')} onChange={put('ssnit_no')} />
          <Choose label="Their sign-in (for clocking in and self-service)" options={form.users} value={t('user_id')} onChange={put('user_id')} clearable />
        </div>
      </Panel>
      <Panel title="Job">
        <div className={f.grid}>
          <Choose label="Branch" required options={form.branches} value={t('branch_id')} onChange={put('branch_id')} />
          <Choose label="Department" options={form.departments} value={t('department_id')} onChange={put('department_id')} clearable />
          <TextField label="Job title" maxLength={255} value={t('job_title')} onChange={put('job_title')} />
          <Choose label="Employment type" required options={form.types} value={t('employment_type')} onChange={put('employment_type')} />
          <TextField label="Start date" required type="date" value={t('hire_date')} onChange={put('hire_date')} />
          <TextField label="Contract ends" type="date" value={t('contract_end')} onChange={put('contract_end')} />
        </div>
      </Panel>
      <Panel title="Pay (per month)">
        <div className={f.grid}>
          {money('basic_salary', 'Basic salary', true)}
          {money('transport_allowance', 'Transport allowance')}
          {money('meal_allowance', 'Meal allowance')}
          <TextField label="Overtime rate (× hourly)" type="number" min={1} step="any" value={t('overtime_rate')} onChange={put('overtime_rate')} />
          <TextField label="Provident fund (Tier 3) % of basic" type="number" min={0} max={100} step="any" value={t('tier3_pct')} onChange={put('tier3_pct')} />
          {money('welfare', 'Welfare dues')}
          <TextField label="Commission % (Services)" type="number" min={0} max={100} step="any" value={t('commission_pct')} onChange={put('commission_pct')}
            hint="Share of the price before tax of the services they do (a product can set its own rate). Paid with payroll." />
          <Toggle label="Qualifying junior staff (overtime taxed at 5 % / 10 %)" value={!!v.overtime_junior} onChange={put('overtime_junior')} />
          <Toggle label="Not in SSNIT (e.g. pensioner)" value={!!v.ssnit_exempt} onChange={put('ssnit_exempt')} />
          <Toggle label="Takes appointments (Services)" value={!!v.takes_appointments} onChange={put('takes_appointments')} hint="Shows them in the staff calendar and the till's &quot;done by&quot; list." />
        </div>
      </Panel>
      <Panel title="Paid by">
        <div className={f.grid}>
          <Choose label="Pay method" required options={form.pay_methods} value={t('pay_method')} onChange={put('pay_method')} />
          {t('pay_method') === 'bank' && <>
            <TextField label="Bank name" maxLength={255} value={t('bank_name')} onChange={put('bank_name')} />
            <TextField label="Account number" required maxLength={40} value={t('bank_account_no')} onChange={put('bank_account_no')} />
          </>}
          {t('pay_method') === 'momo' && <TextField label="MoMo number" required maxLength={20} value={t('momo_number')} onChange={put('momo_number')} />}
          <LongText label="Notes" className={f.spanAll} maxLength={5000} value={t('notes')} onChange={put('notes')} />
        </div>
      </Panel>
      <SaveBar value={v}>
        <Button onClick={back}>Cancel</Button>
        <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : form.id ? 'Save' : 'Add employee'}</Button>
      </SaveBar>

      {dialog === 'terminate' && form.id && (
        <Ask title="End employment?" go="End employment" ready={!!end.date && end.reason.trim() !== ''} onClose={() => setDialog(null)}
          run={() => api.employeeAction(form.id!, 'terminate', end).then(() => navigate('/office/employees', true))}>
          <div className={s.one}>
            <Text>They leave the next payroll after their last day. Their history and payslips stay.</Text>
            <TextField label="Last day" required type="date" value={end.date} onChange={(x) => setEnd({ ...end, date: x })} />
            <TextField label="Reason" required maxLength={255} value={end.reason} onChange={(x) => setEnd({ ...end, reason: x })} />
          </div>
        </Ask>
      )}
      {(dialog === 'archive' || dialog === 'restore') && form.id && (
        <Ask title={dialog === 'archive' ? `Archive ${t('name')}?` : `Restore ${t('name')}?`} go={dialog === 'archive' ? 'Archive' : 'Restore'}
          ready={dialog === 'restore' || end.reason.trim().length >= 3} onClose={() => setDialog(null)}
          run={() => api.employeeAction(form.id!, dialog, dialog === 'archive' ? { reason: end.reason } : {}).then(() => { setArchived(dialog === 'archive'); setDialog(null) })}>
          {dialog === 'archive'
            ? <div className={s.one}><Text>They leave every list people choose from. Their history stays. You can restore them at any time.</Text>
              <TextField label="Why" required maxLength={255} value={end.reason} onChange={(x) => setEnd({ ...end, reason: x })} /></div>
            : <Text>They come back into the lists people choose from.</Text>}
        </Ask>
      )}
    </div>
  )
}
