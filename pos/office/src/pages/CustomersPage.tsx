import { Badge, Button, Dropdown, Link, makeStyles, Option, SearchBox, Text, ToggleButton, tokens } from '@fluentui/react-components'
import { ArrowUploadRegular, PersonAddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CustomerOptions, type CustomerRow } from '../api'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { appPathFor } from '../paths'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const SHOW: [string, string][] = [['', 'In use'], ['archived', 'Archived only'], ['all', 'Both']]

/**
 * Customers (release 4): search by code, name, contact or phone (024… also finds +233…), filter by type, those who owe
 * money, credit customers, and in use or archived. Cards say how many owe and how much. A row opens the customer;
 * Add customer opens the form, and Import customers the current import page.
 */
export function CustomersPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<CustomerOptions | null>(null)
  const { data, loading, error } = useList(api.customers, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.customersOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const toggle = (key: string) => set({ [key]: str(params, key) === '1' ? '' : '1' })
  const typeLabel = (id: string) => options?.types.find((t) => t.id === id)?.label ?? id
  const columns: Column<CustomerRow>[] = [
    { key: 'code', label: 'Code', sort: 'code', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
    { key: 'name', label: 'Name', sort: 'name', render: (r) => (
      <>
        <Text weight="semibold">{r.name}</Text>{r.archived && <> <Badge appearance="tint" color="subtle" size="small">Archived</Badge></>}
        {r.contact && <Text block size={200} className={s.muted}>{r.contact}</Text>}
      </>
    ) },
    { key: 'phone', label: 'Phone', render: (r) => r.phone ?? '—' },
    { key: 'type', label: 'Type', render: (r) => typeLabel(r.type) },
    { key: 'price_list', label: 'Price list', render: (r) => r.price_list ?? <Text className={s.muted}>default</Text> },
    { key: 'balance', label: 'Balance', sort: 'balance', end: true, render: (r) => (r.balance === null ? '—'
      : <Text weight={r.balance > 0 ? 'semibold' : undefined} style={{ color: r.balance > 0 ? 'var(--zx-warn)' : undefined }}>{m(r.balance)}</Text>) },
    { key: 'credit', label: 'Credit limit', end: true, render: (r) => (
      <>{r.credit_limit === null ? <Text className={s.muted}>cash</Text> : m(r.credit_limit)}
        {r.hold && <Text block size={200} style={{ color: 'var(--zx-bad)' }}>on hold</Text>}</>
    ) },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Customers" hint="Who buys on account, their balance and credit. Open one to record a payment, print a statement or change their credit." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Code, name, contact or phone" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search customers" />
        <Dropdown aria-label="Type" value={str(params, 'type') ? typeLabel(str(params, 'type')) : 'Any type'} selectedOptions={[str(params, 'type')]}
          onOptionSelect={(_, d) => set({ type: d.optionValue ?? '' })} style={{ minWidth: 130 }}>
          <Option value="">Any type</Option>{(options?.types ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ToggleButton checked={str(params, 'owing') === '1'} onClick={() => toggle('owing')}>Owes money</ToggleButton>
        <ToggleButton checked={str(params, 'credit') === '1'} onClick={() => toggle('credit')}>Credit customers</ToggleButton>
        <Dropdown aria-label="Show" value={SHOW.find(([k]) => k === str(params, 'show'))?.[1] ?? 'In use'} selectedOptions={[str(params, 'show')]}
          onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })} style={{ minWidth: 130 }}>
          {SHOW.map(([k, l]) => <Option key={k || 'use'} value={k}>{l}</Option>)}
        </Dropdown>
        <ExportMenu list="customers" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.import && <Link onClick={() => navigate(appPathFor(options.import!))}><ArrowUploadRegular /> Import customers (CSV)</Link>}
        {options?.can_create && <Button appearance="primary" icon={<PersonAddRegular />} onClick={() => navigate('/office/customers/new')}>Add customer</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Customers shown', value: data.total.toLocaleString('en-GB') },
        { label: 'Owe money', value: data.owing.toLocaleString('en-GB') },
        { label: 'Owed to us', value: m(data.owed), tone: data.owed ? 'var(--zx-warn)' : undefined },
        { label: 'Credit customers', value: data.credit.toLocaleString('en-GB') },
        ...(data.hold ? [{ label: 'On credit hold', value: data.hold.toLocaleString('en-GB'), tone: 'var(--zx-bad)' }] : []),
      ]} />}
      <DataTable label="Customers" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'name'} dir={data?.dir ?? 'asc'} loading={loading}
        empty="No customers match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/customers/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
