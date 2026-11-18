import { Badge, Button, Dropdown, Link, makeStyles, Option, SearchBox, Text, ToggleButton, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowUploadRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SupplierOptions, type SupplierRow } from '../api'
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
const TONE: Record<string, 'success' | 'danger' | 'warning'> = { approved: 'success', blocked: 'danger', pending: 'warning' }

/**
 * Suppliers (release 5): search by code, name, contact, phone or email; filter by status, bank details not yet
 * checked, those we owe, and in use or archived. Each row says what is missing (documents), their scorecard grade, terms,
 * how many products and what we owe. A row opens the supplier; Add supplier opens the form.
 */
export function SuppliersPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<SupplierOptions | null>(null)
  const { data, loading, error } = useList(api.suppliers, serialize(params))
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.suppliersOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const toggle = (key: string) => set({ [key]: str(params, key) === '1' ? '' : '1' })
  const statusLabel = (id: string) => options?.statuses.find((o) => o.id === id)?.label ?? id
  const columns: Column<SupplierRow>[] = [
    { key: 'code', label: 'Code', sort: 'code', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
    { key: 'name', label: 'Name', sort: 'name', render: (r) => (
      <>
        <Text weight="semibold">{r.name}</Text>{r.archived && <> <Badge appearance="tint" color="subtle" size="small">Archived</Badge></>}
        {r.contact && <Text block size={200} className={s.muted}>{r.contact}</Text>}
      </>
    ) },
    { key: 'status', label: 'Status', render: (r) => (
      <>
        <Badge appearance="tint" color={TONE[r.status] ?? 'informative'}>{statusLabel(r.status)}</Badge>
        {r.note && <Text block size={200} style={{ color: r.status === 'blocked' ? 'var(--zx-bad)' : 'var(--zx-warn)' }}>{r.note}</Text>}
        {r.bank_unchecked && <Text block size={200} style={{ color: 'var(--zx-warn)' }}>Bank details not checked</Text>}
      </>
    ) },
    { key: 'score', label: 'Score', render: (r) => r.score ?? <Text className={s.muted}>—</Text> },
    { key: 'phone', label: 'Phone', render: (r) => r.phone ?? '—' },
    { key: 'terms', label: 'Terms', render: (r) => r.terms },
    { key: 'products', label: 'Products', end: true, render: (r) => r.products.toLocaleString('en-GB') },
    ...(options?.sees_owed ? [{ key: 'owed', label: 'We owe', sort: 'owed', end: true, render: (r: SupplierRow) => (r.owed
      ? <Text weight="semibold" style={{ color: r.owed > 0 ? 'var(--zx-warn)' : undefined }}>{m(r.owed)}</Text> : <Text className={s.muted}>—</Text>) }] : []),
  ]

  return (
    <div className={page.page}>
      <PageHead title="Suppliers" hint="Who you buy from, the products they supply, their documents and how they are paid. Open one to approve it, check its bank details or send its portal link." classic="/admin/suppliers" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Code, name, contact, phone or email" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search suppliers" />
        <Dropdown aria-label="Status" value={str(params, 'status') ? statusLabel(str(params, 'status')) : 'Any status'} selectedOptions={[str(params, 'status')]}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? '' })} style={{ minWidth: 150 }}>
          <Option value="">Any status</Option>{(options?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <ToggleButton checked={str(params, 'unverified') === '1'} onClick={() => toggle('unverified')}>Bank details not checked</ToggleButton>
        {options?.sees_owed && <ToggleButton checked={str(params, 'owed') === '1'} onClick={() => toggle('owed')}>We owe them</ToggleButton>}
        <Dropdown aria-label="Show" value={SHOW.find(([k]) => k === str(params, 'show'))?.[1] ?? 'In use'} selectedOptions={[str(params, 'show')]}
          onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })} style={{ minWidth: 130 }}>
          {SHOW.map(([k, l]) => <Option key={k || 'use'} value={k}>{l}</Option>)}
        </Dropdown>
        <ExportMenu list="suppliers" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.import && <Link onClick={() => navigate(appPathFor(options.import!))}><ArrowUploadRegular /> Import suppliers (CSV)</Link>}
        {options?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/suppliers/new')}>Add supplier</Button>}
      </div>
      {error && <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text>}
      {data && <Cards items={[
        { label: 'Suppliers shown', value: data.total.toLocaleString('en-GB') },
        ...(data.pending ? [{ label: 'Waiting for approval', value: data.pending.toLocaleString('en-GB'), tone: 'var(--zx-warn)' }] : []),
        ...(data.blocked ? [{ label: 'Blocked', value: data.blocked.toLocaleString('en-GB'), tone: 'var(--zx-bad)' }] : []),
        ...(data.owed !== null ? [{ label: 'We owe', value: m(data.owed), tone: data.owed ? 'var(--zx-warn)' : undefined }] : []),
      ]} />}
      <DataTable label="Suppliers" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'name'} dir={data?.dir ?? 'asc'} loading={loading}
        empty="No suppliers match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/suppliers/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
    </div>
  )
}
