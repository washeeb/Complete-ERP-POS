import { Button, Dropdown, MessageBar, MessageBarBody, Option, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular, PeopleTeamRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type EmployeeRow } from '../api'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { classicPathFor } from '../paths'
import { ShowFilter, Status } from './CompanyParts'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

const STATUS: [string, string][] = [['active', 'Active'], ['terminated', 'Left'], ['all', 'Everyone']]

/**
 * HR & payroll → Employees (part 21): staff number, name and job, branch, department, type, start date and whether they
 * still work here. Someone who manages HR opens an employee (person, job, pay, how they are paid); pay and bank details
 * only reach them. Departments are kept on their own page.
 */
export function EmployeesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.employees, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const status = str(params, 'status') || 'active'
  const branch = str(params, 'branch')
  return (
    <div className={page.page}>
      <PageHead title="Employees" hint="Everyone on the payroll. Pay and bank details are only shown to HR." classic="/admin/employees" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, name or phone" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search employees" />
        <Dropdown aria-label="Status" value={STATUS.find(([k]) => k === status)?.[1] ?? 'Active'} selectedOptions={[status]} onOptionSelect={(_, d) => set({ status: d.optionValue ?? 'active' })} style={{ minWidth: 130 }}>
          {STATUS.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Branch" value={branch ? data?.branches.find((b) => b.id === branch)?.label ?? '' : 'Any branch'} selectedOptions={[branch]} style={{ minWidth: 170 }}
          onOptionSelect={(_, d) => set({ branch: d.optionValue ?? '' })}>
          <Option value="">Any branch</Option>{(data?.branches ?? []).map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
        </Dropdown>
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_manage && <Button icon={<PeopleTeamRegular />} onClick={() => navigate(classicPathFor('/admin/departments'))}>Departments</Button>}
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/employees/new')}>Add employee</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<EmployeeRow> label="Employees" rows={data?.rows ?? []} sort="number" dir="asc" loading={loading} empty="No employees match." onSort={() => undefined}
        onOpen={(r) => data?.can_manage && navigate(`/office/employees/${r.id}`)}
        columns={[
          { key: 'number', label: 'Number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
          { key: 'name', label: 'Name', render: (r) => <>{r.name}{r.job_title && <Text size={200} block className={s.muted}>{r.job_title}</Text>}</> },
          { key: 'branch', label: 'Branch', render: (r) => r.branch ?? '—' },
          { key: 'department', label: 'Department', render: (r) => r.department ?? '—' },
          { key: 'type', label: 'Type', render: (r) => r.type },
          { key: 'started', label: 'Started', render: (r) => day(r.hire_date) },
          { key: 'status', label: 'Status', render: (r) => (r.archived ? <Status r={r} /> : <ToneBadge tone={r.status === 'active' ? 'success' : 'subtle'} label={r.status === 'active' ? 'Active' : 'Left'} />) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
