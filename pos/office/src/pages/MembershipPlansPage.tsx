import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Text } from '@fluentui/react-components'
import { AddRegular, DeleteRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useRef, useState } from 'react'
import { api } from '../api'
import type { PlanList, PlanValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, ChooseMany, LongText, TextField, Toggle } from '../form/Fields'
import { money } from '../home/format'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'

type Row = PlanList['rows'][number]
const blank: PlanValues = { name: '', price: '', interval_months: '1', product_id: '', description: '', is_active: true, items: [] }

/**
 * Services → Membership plans (part 26): price per period, how often, the product it is invoiced as, and the sessions
 * included each period (empty sessions = unlimited; they do not carry over). Add, change, archive and restore.
 */
export function MembershipPlansPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const cur = useRef<PlanList | null>(null)
  const { data, loading, error } = useList((q, sig) => api.membershipPlans(q, sig).then((d) => { cur.current = d; return d }), serialize(params), refresh)
  const [edit, setEdit] = useState<{ id: string | null; v: PlanValues } | null>(null)
  const [arch, setArch] = useState<{ row: Row; restore: boolean } | null>(null)
  const [why, setWhy] = useState('')
  const [done, setDone] = useState('')
  const finish = (m: string) => { setEdit(null); setArch(null); setDone(m); setRefresh((n) => n + 1) }
  const put = <K extends keyof PlanValues>(k: K) => (x: PlanValues[K]) => setEdit((o) => (o ? { ...o, v: { ...o.v, [k]: x } } : o))
  const item = (i: number, patch: Partial<PlanValues['items'][number]>) => setEdit((o) => (o ? { ...o, v: { ...o.v, items: o.v.items.map((x, j) => (j === i ? { ...x, ...patch } : x)) } } : o))
  return (
    <div className={page.page}>
      <PageHead title="Membership plans" hint="Price per period, how often, and the sessions included each period." classic="/admin/membership-plans" />
      <div className={s.bar}>
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setEdit({ id: null, v: { ...blank } }) }}>New plan</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Membership plans" rows={data?.rows ?? []} sort="code" dir="asc" loading={loading} empty="No plans yet." onSort={() => undefined}
        onOpen={(r) => data?.can_create && setEdit({ id: r.id, v: { ...r.values, items: r.values.items.map((x) => ({ ...x })) } })}
        columns={[
          { key: 'code', label: 'Code', render: (r) => <Text font="monospace" size={200}>{r.code}</Text> },
          { key: 'name', label: 'Name', render: (r) => <Text weight="semibold">{r.name}</Text> },
          { key: 'price', label: 'Price', end: true, render: (r) => (cur.current ? money(r.price, cur.current.currency, cur.current.exponent) : '') },
          { key: 'interval', label: 'Billed', render: (r) => r.interval },
          { key: 'includes', label: 'Includes', render: (r) => r.includes },
          { key: 'active', label: 'Offered', render: (r) => (r.active ? 'Yes' : 'No') },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'more', label: '', render: (r) => (r.can_archive || r.can_restore) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${r.name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_archive && <MenuItem onClick={() => { setWhy(''); setArch({ row: r, restore: false }) }}>Archive</MenuItem>}
                {r.can_restore && <MenuItem onClick={() => setArch({ row: r, restore: true })}>Restore</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />

      {edit && data && (
        <Ask title={edit.id ? `Change ${edit.v.name}` : 'New plan'} go="Save" width={720} onClose={() => setEdit(null)}
          ready={edit.v.name.trim() !== '' && Number(edit.v.price) > 0 && !!edit.v.product_id && edit.v.items.every((x) => x.product_ids.length > 0)}
          run={() => api.savePlan(edit.id, { ...edit.v, interval_months: Number(edit.v.interval_months), description: edit.v.description || null,
            items: edit.v.items.map((x) => ({ product_ids: x.product_ids, sessions: x.sessions === '' ? null : Number(x.sessions), label: x.label || null })) }).then((x) => finish(x.message))}>
          <div className={s.one}>
            <TextField label="Name" required maxLength={255} value={edit.v.name} onChange={put('name')} />
            <div className={s.grid}>
              <TextField label="Price per period" required type="number" min={0} step="any" prefix={data.currency} hint="Including tax when your default price list includes tax." value={edit.v.price} onChange={put('price')} />
              <Choose label="Billed" required options={data.intervals} value={edit.v.interval_months} onChange={put('interval_months')} />
              <Choose label="Invoiced as (product)" required options={data.products} value={edit.v.product_id} onChange={put('product_id')}
                hint='A product without stock, e.g. "Gym membership". Its tax group taxes the invoice.' />
            </div>
            <Text weight="semibold">Sessions included each period (optional)</Text>
            <Text size={200} className={s.muted}>Leave empty for a plain subscription. Sessions empty = unlimited (e.g. gym access); they do not carry over to the next period.</Text>
            {edit.v.items.map((x, i) => (
              <div key={i} className={s.grid} role="group" aria-label={`Included ${i + 1}`}>
                <ChooseMany label="Services" options={data.services} value={x.product_ids} onChange={(v) => item(i, { product_ids: v })} />
                <TextField label="Sessions" type="number" min={1} max={1000} value={x.sessions} onChange={(v) => item(i, { sessions: v })} />
                <div style={{ display: 'flex', gap: 6, alignItems: 'flex-end' }}>
                  <TextField label="Shown as (optional)" maxLength={120} value={x.label} onChange={(v) => item(i, { label: v })} />
                  <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove included ${i + 1}`} onClick={() => put('items')(edit.v.items.filter((_, j) => j !== i))} />
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => put('items')([...edit.v.items, { product_ids: [], sessions: '', label: '' }])}>Add sessions</Button></div>
            <LongText label="Description" maxLength={2000} value={edit.v.description} onChange={put('description')} />
            <Toggle label="Offered" value={edit.v.is_active} onChange={put('is_active')} />
          </div>
        </Ask>
      )}
      {arch && (
        <Ask title={`${arch.restore ? 'Restore' : 'Archive'} ${arch.row.name}?`} go={arch.restore ? 'Restore' : 'Archive'}
          ready={arch.restore || (arch.row.archive_blockers.length === 0 && why.trim().length >= 3)} onClose={() => setArch(null)}
          run={() => api.archivePlan(arch.row.id, arch.restore, why).then((x) => finish(x.message))}>
          {arch.restore ? <Text>It comes back into the lists people choose from. It stays not offered until you switch it on.</Text>
            : arch.row.archive_blockers.length ? <Text>It cannot be archived yet. {arch.row.archive_blockers.join(' ')}</Text>
              : <div className={s.one}><Text>Nobody new can sign up for it. Its memberships and history stay.</Text><TextField label="Why" required maxLength={255} value={why} onChange={setWhy} /></div>}
        </Ask>
      )}
    </div>
  )
}
