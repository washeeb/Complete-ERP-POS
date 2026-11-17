import { Badge, Dropdown, Field, Link, makeStyles, Option, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type DrawerRow, type ShiftsOptions } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, PeriodButtons, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '8px 12px', flexWrap: 'wrap', marginBottom: '12px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const COLOUR: Record<string, 'warning' | 'informative' | 'success' | 'danger' | 'subtle'> = { payout: 'warning', drop: 'informative', payin: 'success', limit_alert: 'danger' }

/**
 * Till drawer (release 2): what went in and out of the drawers besides sales — expenses paid out (with the receipt photo),
 * pay-ins, cash drops, drawers opened without a sale, cash-limit warnings — who did it and who approved it. A row opens
 * its shift.
 */
export function DrawerPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<ShiftsOptions | null>(null)
  const { data, loading, error } = useList(api.drawer, serialize(params))
  useEffect(() => { api.shiftsOptions().then(setOptions).catch(() => undefined) }, [])
  const m = (v: number | null) => (v === null || !data ? '—' : money(v, data.currency, data.exponent))

  const columns: Column<DrawerRow>[] = [
    { key: 'when', label: 'When', sort: 'occurred_at', render: (r) => when(r.occurred_at) },
    { key: 'till', label: 'Till', render: (r) => r.where },
    { key: 'what', label: 'What', render: (r) => <Badge appearance="tint" color={COLOUR[r.kind] ?? 'subtle'}>{r.kind_label}</Badge> },
    { key: 'amount', label: 'Amount', end: true, render: (r) => <Text weight="semibold">{m(r.amount)}</Text> },
    { key: 'why', label: 'Spent on · why', render: (r) => <>{r.spent_on && <Text block weight="semibold">{r.spent_on}</Text>}{r.reason ?? '—'}{r.payee && <Text block size={200} className={s.muted}>Paid to {r.payee}</Text>}</> },
    { key: 'by', label: 'By', render: (r) => <>{r.by ?? '—'}{r.offline && <Text block size={200} className={s.muted}>while offline</Text>}</> },
    { key: 'approved', label: 'Approved by', render: (r) => r.approved_by ?? '—' },
    { key: 'receipt', label: 'Receipt', render: (r) => (r.receipt ? <Link href={r.receipt} target="_blank" rel="noopener" onClick={(e) => e.stopPropagation()}>Open</Link> : '—') },
  ]
  const money2 = (k: string) => data?.kinds.find((x) => x.id === k)

  return (
    <div className={page.page}>
      <PageHead title="Till drawer" hint="Money in and out of the drawers besides sales, who did it and who approved it." classic="/admin/till-drawer-events" />
      <div className={s.bar}>
        <PeriodButtons value={str(params, 'period')} onChange={(v) => set({ period: v })} />
        <Field label="What" size="small">
          <Dropdown size="small" value={options?.kinds.find((k) => k.id === str(params, 'kind'))?.label ?? 'Everything'} selectedOptions={[str(params, 'kind')]}
            onOptionSelect={(_, d) => set({ kind: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
            <Option value="">Everything</Option>{(options?.kinds ?? []).map((k) => <Option key={k.id} value={k.id}>{k.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="By" size="small">
          <Dropdown size="small" value={options?.people.find((p) => p.id === str(params, 'person'))?.label ?? 'Everyone'} selectedOptions={[str(params, 'person')]}
            onOptionSelect={(_, d) => set({ person: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
            <Option value="">Everyone</Option>{(options?.people ?? []).map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
          </Dropdown>
        </Field>
        <ExportMenu list="drawer" query={serialize(params)} total={data?.total ?? null} />
        <Field label="Branch" size="small">
          <Dropdown size="small" value={options?.branches.find((b) => b.id === str(params, 'branch'))?.label ?? 'All branches'} selectedOptions={[str(params, 'branch')]}
            onOptionSelect={(_, d) => set({ branch: d.optionValue ?? '' })} style={{ minWidth: 170 }}>
            <Option value="">All branches</Option>{(options?.branches ?? []).map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
          </Dropdown>
        </Field>
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Paid out', value: `${m(money2('payout')?.amount ?? 0)} (${money2('payout')?.count ?? 0})` },
        { label: 'Paid in', value: `${m(money2('payin')?.amount ?? 0)} (${money2('payin')?.count ?? 0})` },
        { label: 'Cash drops', value: `${m(money2('drop')?.amount ?? 0)} (${money2('drop')?.count ?? 0})` },
        { label: 'Opened without a sale', value: String(money2('no_sale')?.count ?? 0) },
      ]} />}
      <DataTable label="Till drawer" columns={columns} rows={data?.rows ?? []} sort="occurred_at" dir={data?.dir ?? 'desc'} loading={loading}
        empty="Nothing went in or out of a drawer in this period." onSort={(_, dir) => set({ dir })} onOpen={(r) => navigate(`/office/shifts/${r.shift_id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
