import { Button, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type LoanRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { day, useProcStyles } from './ProcurementParts'

/**
 * HR & payroll → Loans & advances (part 21): staff loans, salary advances and cash shortages recovered from pay — the
 * amount, what is deducted each month and what is still owed. Give a loan or advance (paid from a cash or bank
 * account); payroll deducts it.
 */
export function LoansPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.loans, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [v, setV] = useState<Record<string, string> | null>(null)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const m = (x: number) => (data ? money(x, data.currency, data.exponent) : '')
  const f = data?.form
  const put = (k: string) => (x: string) => setV((o) => (o ? { ...o, [k]: x } : o))
  return (
    <div className={page.page}>
      <PageHead title="Loans & advances" hint="Staff loans and salary advances, repaid through payroll." classic="/admin/employee-loans" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or employee" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search loans" />
        <span className={s.grow} />
        {f && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setV({ employee_id: '', kind: 'advance', amount: '', monthly: '', first_month: f.first_month, account_id: f.account, issued_on: f.today, note: '' }) }}>
          Give a loan or advance</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<LoanRow> label="Loans and advances" rows={data?.rows ?? []} sort="issued" dir="desc" loading={loading} empty="No loans or advances." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'number', label: 'Number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
          { key: 'employee', label: 'Employee', render: (r) => r.employee ?? '—' },
          { key: 'kind', label: 'Kind', render: (r) => r.kind },
          { key: 'issued', label: 'Paid on', render: (r) => day(r.issued_on) },
          { key: 'amount', label: 'Amount', end: true, render: (r) => m(r.amount) },
          { key: 'monthly', label: 'Per month', end: true, render: (r) => m(r.monthly) },
          { key: 'owed', label: 'Still owed', end: true, render: (r) => <Text weight="semibold">{m(r.owed)}</Text> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {v && f && data && (
        <Ask title="Give a loan or advance" go="Record it" width={560} onClose={() => setV(null)}
          ready={!!v.employee_id && Number(v.amount) > 0 && Number(v.monthly) > 0 && !!v.first_month && !!v.account_id && !!v.issued_on}
          run={() => api.issueLoan({ ...v, note: v.note || null }).then((x) => { setV(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <div className={s.one}>
            <Choose label="Employee" required options={f.employees} value={v.employee_id} onChange={put('employee_id')} />
            <Choose label="Kind" required options={f.kinds} value={v.kind} onChange={put('kind')} />
            <div className={s.grid}>
              <TextField label="Amount" required type="number" min={0} step="any" prefix={data.currency} value={v.amount} onChange={put('amount')} />
              <TextField label="Deduct each month" required type="number" min={0} step="any" prefix={data.currency} value={v.monthly} onChange={put('monthly')} />
            </div>
            <TextField label="First deduction in the payroll of" required type="date" value={v.first_month} onChange={put('first_month')} />
            <Choose label="Paid from" required options={f.accounts} value={v.account_id} onChange={put('account_id')} />
            <TextField label="Paid on" required type="date" value={v.issued_on} onChange={put('issued_on')} />
            <TextField label="Note" maxLength={255} value={v.note} onChange={put('note')} />
          </div>
        </Ask>
      )}
    </div>
  )
}
