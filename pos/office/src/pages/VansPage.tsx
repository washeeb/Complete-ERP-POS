import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular, DeleteRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type UnitOption, type VanList, type VanValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, useFieldStyles } from '../form/Fields'
import { UnitPicker } from '../form/UnitPicker'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'

type Row = VanList['rows'][number]
type Lines = { unit: UnitOption | null; qty: string }[]

/**
 * Distribution → Vans (part 34): each van is a warehouse with its own till, loaded from a depot warehouse. New van, change,
 * Load van, Unload to warehouse (transfers received at once — both ends are at the depot), Start end-of-day settlement,
 * archive and restore.
 */
export function VansPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.vans, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [edit, setEdit] = useState<{ id: string | null; v: VanValues } | null>(null)
  const [move, setMove] = useState<{ row: Row; unload: boolean; lines: Lines } | null>(null)
  const [ask, setAsk] = useState<{ row: Row; what: 'settle' | 'archive' | 'restore' } | null>(null)
  const [why, setWhy] = useState('')
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (x: { message: string; open?: string }) => {
    if (x.open) { navigate(x.open); return }
    setEdit(null); setMove(null); setAsk(null); setDone(x.message); setRefresh((n) => n + 1)
  }
  const form = data?.form
  const put = <K extends keyof VanValues>(k: K) => (x: VanValues[K]) => setEdit((o) => (o ? { ...o, v: { ...o.v, [k]: x } } : o))
  return (
    <div className={page.page}>
      <PageHead title="Vans" hint="Each van is a warehouse with its own till, loaded from a depot. Settle each van at the end of the day." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Code or name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search vans" />
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && form && <Button appearance="primary" icon={<AddRegular />}
          onClick={() => { setDone(''); setEdit({ id: null, v: { branch_id: form.branches[0]?.id ?? '', name: '', vehicle_reg: '', rep_user_id: '', home_warehouse_id: '' } }) }}>New van</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Vans" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No vans yet." onSort={() => undefined}
        onOpen={(r) => r.can_edit && !r.archived && setEdit({ id: r.id, v: { ...r.values } })}
        columns={[
          { key: 'code', label: 'Code', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
          { key: 'name', label: 'Van', render: (r) => <><Text weight="semibold">{r.name}</Text>{r.reg && <Text size={200} block className={s.muted}>{r.reg}</Text>}</> },
          { key: 'rep', label: 'Rep', render: (r) => r.rep ?? '—' },
          { key: 'home', label: 'Loaded from', render: (r) => r.home ?? '—' },
          { key: 'stock', label: 'Stock on board', end: true, render: (r) => r.stock },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'act', label: '', render: (r) => (data?.can_move || r.can_archive || r.can_restore) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Act on ${r.name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {data?.can_move && !r.archived && <MenuItem onClick={() => setMove({ row: r, unload: false, lines: [{ unit: null, qty: '' }] })}>Load van</MenuItem>}
                {data?.can_move && !r.archived && <MenuItem onClick={() => setMove({ row: r, unload: true, lines: [{ unit: null, qty: '' }] })}>Unload to warehouse</MenuItem>}
                {data?.can_move && !r.archived && <MenuItem onClick={() => setAsk({ row: r, what: 'settle' })}>Start end-of-day settlement</MenuItem>}
                {r.can_archive && <MenuItem onClick={() => { setWhy(''); setAsk({ row: r, what: 'archive' }) }}>Archive</MenuItem>}
                {r.can_restore && <MenuItem onClick={() => setAsk({ row: r, what: 'restore' })}>Restore</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />

      {edit && form && (
        <Ask title={edit.id ? `Change ${edit.v.name}` : 'New van'} go="Save" width={640} onClose={() => setEdit(null)}
          ready={edit.v.name.trim() !== '' && !!edit.v.branch_id && !!edit.v.home_warehouse_id} run={() => api.saveVan(edit.id, edit.v).then(finish)}>
          <div className={f.grid}>
            {!edit.id && <Text className={f.spanAll}>Creates the van as a warehouse with its own till. Then register the rep's phone as a POS device on that till (System → Devices).</Text>}
            <Choose label="Branch" required disabled={!!edit.id} options={form.branches} value={edit.v.branch_id} onChange={(x) => setEdit({ ...edit, v: { ...edit.v, branch_id: x, home_warehouse_id: '' } })} />
            <TextField label="Name" required maxLength={80} placeholder="Kumasi van 1" value={edit.v.name} onChange={put('name')} />
            <TextField label="Registration" maxLength={20} placeholder="GR 1234-24" value={edit.v.vehicle_reg} onChange={put('vehicle_reg')} />
            <Choose label="Rep" options={form.reps} value={edit.v.rep_user_id} onChange={put('rep_user_id')} clearable />
            <Choose label="Loaded from (warehouse)" required options={form.warehouses.filter((w) => w.branch_id === edit.v.branch_id)} value={edit.v.home_warehouse_id} onChange={put('home_warehouse_id')} />
          </div>
        </Ask>
      )}
      {move && (
        <Ask title={move.unload ? `Unload ${move.row.name}` : `Load ${move.row.name}`} go={move.unload ? 'Unload' : 'Load'} width={680} onClose={() => setMove(null)}
          ready={move.lines.length > 0 && move.lines.every((l) => l.unit && Number(l.qty) > 0)}
          run={() => api.vanAction(move.row.id, move.unload ? 'unload' : 'load', { lines: move.lines.map((l) => ({ product_uom_id: l.unit!.id, qty: l.qty })) }).then(finish)}>
          <div className={s.one}>
            <Text>{move.unload ? 'Stock goes from the van back to its warehouse (a transfer, received at once).' : 'Stock goes from the warehouse onto the van (a transfer, received at once — both are at the depot).'}</Text>
            {move.lines.map((l, i) => (
              <div key={i} className={s.grid} role="group" aria-label={`Product ${i + 1}`}>
                <UnitPicker value={l.unit?.id ?? ''} label={l.unit?.label ?? null} search={api.distUnits}
                  onChange={(u) => setMove({ ...move, lines: move.lines.map((x, j) => (j === i ? { ...x, unit: u } : x)) })} />
                <TextField label="Quantity" required type="number" min={0.001} step="any" value={l.qty} onChange={(qq) => setMove({ ...move, lines: move.lines.map((x, j) => (j === i ? { ...x, qty: qq } : x)) })} />
                <div style={{ display: 'flex', alignItems: 'flex-end', height: '100%' }}>
                  {move.lines.length > 1 && <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove product ${i + 1}`} onClick={() => setMove({ ...move, lines: move.lines.filter((_, j) => j !== i) })} />}
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => setMove({ ...move, lines: [...move.lines, { unit: null, qty: '' }] })}>Add product</Button></div>
          </div>
        </Ask>
      )}
      {ask?.what === 'settle' && (
        <Ask title={`Start the settlement for ${ask.row.name}?`} go="Start" ready onClose={() => setAsk(null)} run={() => api.vanAction(ask.row.id, 'settle').then(finish)}>
          <Text>Takes the stock on the van and today's sales and cash, ready to count what is left.</Text>
        </Ask>
      )}
      {ask?.what === 'archive' && (
        <Ask title={`Archive ${ask.row.name}?`} go="Archive" ready={why.trim().length >= 3 && ask.row.archive_blockers.length === 0} onClose={() => setAsk(null)}
          run={() => api.distArchive('van', ask.row.id, false, why).then(finish)}>
          <div className={s.one}>
            {ask.row.archive_blockers.length > 0 && <Text>It cannot be archived yet. {ask.row.archive_blockers.join(' ')}</Text>}
            <Text>It leaves the lists. Its stock history and settlements stay.</Text>
            <TextField label="Why" required maxLength={255} value={why} onChange={setWhy} />
          </div>
        </Ask>
      )}
      {ask?.what === 'restore' && (
        <Ask title={`Restore ${ask.row.name}?`} go="Restore" ready onClose={() => setAsk(null)} run={() => api.distArchive('van', ask.row.id, true, '').then(finish)}>
          <Text>It comes back into the lists.</Text>
        </Ask>
      )}
    </div>
  )
}
