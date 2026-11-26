import { Button, Checkbox, Field, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular, ArrowDownRegular, ArrowUpRegular, DeleteRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type RouteList, type RouteValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'

type Row = RouteList['rows'][number]

/**
 * Distribution → Routes (part 34): the customers a rep visits, in visiting order, on the days the route runs, with its
 * van. New route, change (add, remove and move customers up or down), archive and restore.
 */
export function SalesRoutesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.salesRoutes, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [edit, setEdit] = useState<{ id: string | null; v: RouteValues } | null>(null)
  const [adding, setAdding] = useState<{ id: string; label: string } | null>(null)
  const [ask, setAsk] = useState<{ row: Row; restore: boolean } | null>(null)
  const [why, setWhy] = useState('')
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (m: string) => { setEdit(null); setAsk(null); setDone(m); setRefresh((n) => n + 1) }
  const put = <K extends keyof RouteValues>(k: K) => (x: RouteValues[K]) => setEdit((o) => (o ? { ...o, v: { ...o.v, [k]: x } } : o))
  const moveStop = (i: number, by: number) => setEdit((o) => {
    if (!o) return o
    const stops = [...o.v.stops]
    const j = i + by
    if (j < 0 || j >= stops.length) return o
    ;[stops[i], stops[j]] = [stops[j], stops[i]]
    return { ...o, v: { ...o.v, stops } }
  })
  return (
    <div className={page.page}>
      <PageHead title="Routes" hint="The customers a rep visits, in order, on the days the route runs. The rep's phone shows today's route." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Code or name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search routes" />
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />}
          onClick={() => { setDone(''); setAdding(null); setEdit({ id: null, v: { name: '', branch_id: data.branches[0]?.id ?? '', van_warehouse_id: '', rep_user_id: '', days: [], stops: [] } }) }}>New route</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Routes" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No routes yet." onSort={() => undefined}
        onOpen={(r) => r.can_edit && !r.archived && (setAdding(null), setEdit({ id: r.id, v: { ...r.values, stops: [...r.values.stops], days: [...r.values.days] } }))}
        columns={[
          { key: 'code', label: 'Code', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
          { key: 'name', label: 'Route', render: (r) => <Text weight="semibold">{r.name}</Text> },
          { key: 'days', label: 'Runs on', render: (r) => r.days || '—' },
          { key: 'van', label: 'Van', render: (r) => r.van ?? '—' },
          { key: 'rep', label: 'Rep', render: (r) => r.rep ?? '—' },
          { key: 'customers', label: 'Customers', end: true, render: (r) => r.customers },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'more', label: '', render: (r) => (r.can_archive || r.can_restore) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${r.name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_archive && <MenuItem onClick={() => { setWhy(''); setAsk({ row: r, restore: false }) }}>Archive</MenuItem>}
                {r.can_restore && <MenuItem onClick={() => setAsk({ row: r, restore: true })}>Restore</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />

      {edit && data && (
        <Ask title={edit.id ? `Change ${edit.v.name}` : 'New route'} go="Save" width={720} onClose={() => setEdit(null)} ready={edit.v.name.trim() !== '' && !!edit.v.branch_id}
          run={() => api.saveRoute(edit.id, { name: edit.v.name, branch_id: edit.v.branch_id, van_warehouse_id: edit.v.van_warehouse_id || null, rep_user_id: edit.v.rep_user_id || null,
            days: edit.v.days.map(Number), customer_ids: edit.v.stops.map((x) => x.id) }).then((x) => finish(x.message))}>
          <div className={s.one}>
            <div className={f.grid}>
              <TextField label="Name" required className={f.spanAll} maxLength={255} value={edit.v.name} onChange={put('name')} />
              <Choose label="Branch" required options={data.branches} value={edit.v.branch_id} onChange={(x) => setEdit({ ...edit, v: { ...edit.v, branch_id: x, van_warehouse_id: '' } })} />
              <Choose label="Van" options={data.vans.filter((v) => v.branch_id === edit.v.branch_id)} value={edit.v.van_warehouse_id} onChange={put('van_warehouse_id')} clearable />
              <Choose label="Rep" options={data.reps} value={edit.v.rep_user_id} onChange={put('rep_user_id')} clearable />
            </div>
            <Field label="Runs on">
              <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>{data.days.map((d) => (
                <Checkbox key={d.id} label={d.label} checked={edit.v.days.includes(d.id)}
                  onChange={(_, e) => put('days')(e.checked ? [...edit.v.days, d.id] : edit.v.days.filter((x) => x !== d.id))} />
              ))}</div>
            </Field>
            <Text weight="semibold">Customers, in visiting order</Text>
            {edit.v.stops.length === 0 && <Text className={s.muted}>No customers yet.</Text>}
            {edit.v.stops.map((st, i) => (
              <div key={st.id} style={{ display: 'flex', alignItems: 'center', gap: 6 }} role="group" aria-label={`Stop ${i + 1}`}>
                <Text style={{ width: 28 }}>{i + 1}.</Text>
                <Text style={{ flex: 1, minWidth: 0 }}>{st.label}</Text>
                <Button size="small" appearance="subtle" icon={<ArrowUpRegular />} aria-label={`Move ${st.label} up`} disabled={i === 0} onClick={() => moveStop(i, -1)} />
                <Button size="small" appearance="subtle" icon={<ArrowDownRegular />} aria-label={`Move ${st.label} down`} disabled={i === edit.v.stops.length - 1} onClick={() => moveStop(i, 1)} />
                <Button size="small" appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove ${st.label}`} onClick={() => put('stops')(edit.v.stops.filter((_, j) => j !== i))} />
              </div>
            ))}
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end' }}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <SearchPicker label="Add customer" value={adding?.id ?? ''} text={adding?.label ?? null} search={api.routeCustomers} placeholder="Name, phone or code"
                  onChange={(x) => setAdding(x ? { id: x.id, label: x.label } : null)} />
              </div>
              <Button icon={<AddRegular />} disabled={!adding || edit.v.stops.some((x) => x.id === adding.id)}
                onClick={() => { if (adding) { put('stops')([...edit.v.stops, adding]); setAdding(null) } }}>Add</Button>
            </div>
          </div>
        </Ask>
      )}
      {ask && (
        <Ask title={`${ask.restore ? 'Restore' : 'Archive'} ${ask.row.name}?`} go={ask.restore ? 'Restore' : 'Archive'}
          ready={ask.restore || (why.trim().length >= 3 && ask.row.archive_blockers.length === 0)} onClose={() => setAsk(null)}
          run={() => api.distArchive('route', ask.row.id, ask.restore, why).then((x) => finish(x.message))}>
          {ask.restore ? <Text>It comes back into the lists and onto the rep's phone.</Text> : (
            <div className={s.one}>
              {ask.row.archive_blockers.length > 0 && <Text>It cannot be archived yet. {ask.row.archive_blockers.join(' ')}</Text>}
              <Text>It leaves the lists and the rep's phone. Its visits stay.</Text>
              <TextField label="Why" required maxLength={255} value={why} onChange={setWhy} />
            </div>
          )}
        </Ask>
      )}
    </div>
  )
}
