import { Button, Dropdown, MessageBar, MessageBarBody, Option, Text } from '@fluentui/react-components'
import { CalculatorRegular, ScalesRegular } from '@fluentui/react-icons'
import { useRef, useState } from 'react'
import { api, type PayrollList, type PayrollRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { classicPathFor } from '../paths'
import { ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * HR & payroll → Payroll (part 21): each month's payroll — staff, gross, PAYE, net and status. Prepare payroll works a
 * month out from pay, clock-ins, leave and loans (a draft until someone else approves it); a row opens it. The tax and
 * SSNIT rules have their own page.
 */
export function PayrollPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const cur = useRef<PayrollList | null>(null)
  const { data, loading, error } = useList((q, sig) => api.payrollRuns(q, sig).then((d) => { cur.current = d; return d }), serialize(params))
  const [prep, setPrep] = useState<{ month: string; year: string; pay_date: string } | null>(null)
  const status = str(params, 'status')
  const m = (x: number) => (data ? money(x, data.currency, data.exponent) : '')
  return (
    <div className={page.page}>
      <PageHead title="Payroll" hint="Each month: prepare, then someone else approves (it posts to the books), then record the payment." classic="/admin/payroll-runs" />
      <div className={s.bar}>
        <Dropdown aria-label="Status" value={status ? data?.statuses.find((x) => x.id === status)?.label ?? '' : 'Any status'} selectedOptions={[status]} style={{ minWidth: 160 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })}>
          <Option value="">Any status</Option>{(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        <Button icon={<ScalesRegular />} onClick={() => navigate(classicPathFor('/admin/payroll-rules'))}>Tax and SSNIT rules</Button>
        {data?.can_prepare && <Button appearance="primary" icon={<CalculatorRegular />} onClick={() => setPrep({ ...data.now })}>Prepare payroll</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<PayrollRow> label="Payroll" rows={data?.rows ?? []} sort="month" dir="desc" loading={loading} empty="No payroll yet." onSort={() => undefined}
        onOpen={(r) => navigate(`/office/payroll/${r.id}`)}
        columns={[
          { key: 'number', label: 'Number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
          { key: 'month', label: 'Month', render: (r) => r.month ?? '—' },
          { key: 'people', label: 'Staff', end: true, render: (r) => r.people },
          { key: 'gross', label: 'Gross', end: true, render: (r) => m(r.gross) },
          { key: 'paye', label: 'PAYE', end: true, render: (r) => m(r.paye) },
          { key: 'net', label: 'Net pay', end: true, render: (r) => <Text weight="semibold">{m(r.net)}</Text> },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {prep && data && (
        <Ask title="Prepare payroll" go="Prepare" ready={!!prep.month && /^\d{4}$/.test(prep.year)} onClose={() => setPrep(null)}
          run={() => api.preparePayroll({ month: Number(prep.month), year: Number(prep.year), pay_date: prep.pay_date || null }).then((x) => navigate(`/office/payroll/${x.id}`))}>
          <div className={s.one}>
            <Text>Works the month out from each employee's pay, clock-ins, unpaid leave and loans. Preparing a month again replaces its draft.</Text>
            <div className={s.grid}>
              <Choose label="Month" required options={data.months} value={prep.month} onChange={(x) => setPrep({ ...prep, month: x })} />
              <TextField label="Year" required type="number" min={2000} max={2100} value={prep.year} onChange={(x) => setPrep({ ...prep, year: x })} />
              <TextField label="Pay day" type="date" value={prep.pay_date} onChange={(x) => setPrep({ ...prep, pay_date: x })} />
            </div>
          </div>
        </Ask>
      )}
    </div>
  )
}
