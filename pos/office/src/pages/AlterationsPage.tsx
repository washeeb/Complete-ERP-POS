import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { AlterationList } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { Pick } from './CrmLeadsPage'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = AlterationList['rows'][number]
const BLANK = { branch_id: '', customer_name: '', phone: '', garment: '', charge: '0', due_on: '', work: '' }

/**
 * Fashion → Alterations (part 36): garments in for tailoring, their agreed charge, ready-by date and progress. New
 * alteration; Update (where it stands and the tailor); Hand over a free one. One with a charge is collected by paying at
 * the till.
 */
export function AlterationsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.alterations, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [add, setAdd] = useState<typeof BLANK | null>(null)
  const [upd, setUpd] = useState<{ row: Row; status: string; tailor: string } | null>(null)
  const [give, setGive] = useState<Row | null>(null)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const m = (x: number) => (!data ? '' : x ? money(x, data.currency, data.exponent) : 'Free')
  const finish = (x: { message: string }) => { setAdd(null); setUpd(null); setGive(null); setDone(x.message); setRefresh((n) => n + 1) }
  return (
    <div className={page.page}>
      <PageHead title="Alterations" hint="Garments in for tailoring. One with a charge is collected by paying for it at the till." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, customer, phone or garment" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search alterations" />
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />}
          onClick={() => { setDone(''); setAdd({ ...BLANK, branch_id: data.branches.length === 1 ? data.branches[0].id : '' }) }}>New alteration</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Alterations" rows={data?.rows ?? []} sort="received" dir="desc" loading={loading} empty="No alterations yet." onSort={() => undefined}
        onOpen={(r) => r.next.length > 0 && setUpd({ row: r, status: '', tailor: r.tailor ?? '' })}
        columns={[
          { key: 'number', label: 'No.', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'customer', label: 'Customer', render: (r) => <><Text weight="semibold">{r.customer}</Text>{r.phone && <Text size={200} block className={s.muted}>{r.phone}</Text>}</> },
          { key: 'garment', label: 'Garment', render: (r) => <>{r.garment}<Text size={200} block className={s.muted}>{r.work}</Text></> },
          { key: 'charge', label: 'Charge', end: true, render: (r) => m(r.charge) },
          { key: 'due', label: 'Ready by', render: (r) => day(r.due) },
          { key: 'status', label: 'Status', render: (r) => <><ToneBadge tone={r.tone} label={r.status_label} />{r.tailor && <Text size={200} block className={s.muted}>{r.tailor}</Text>}</> },
          { key: 'act', label: '', render: (r) => (r.next.length > 0 || r.can_hand_over) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Act on ${r.number}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.next.length > 0 && <MenuItem onClick={() => { setDone(''); setUpd({ row: r, status: '', tailor: r.tailor ?? '' }) }}>Update</MenuItem>}
                {r.can_hand_over && <MenuItem onClick={() => { setDone(''); setGive(r) }}>Hand over</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {add && data && (
        <Ask title="New alteration" go="Take in" width={640} onClose={() => setAdd(null)}
          ready={!!add.branch_id && add.customer_name.trim() !== '' && add.garment.trim() !== '' && add.work.trim() !== '' && Number(add.charge) >= 0}
          run={() => api.newAlteration(add).then(finish)}>
          <div className={f.grid}>
            <Choose label="Branch" required options={data.branches} value={add.branch_id} onChange={(x) => setAdd({ ...add, branch_id: x })} />
            <TextField label="Customer" required maxLength={120} value={add.customer_name} onChange={(x) => setAdd({ ...add, customer_name: x })} />
            <TextField label="Phone" maxLength={20} value={add.phone} onChange={(x) => setAdd({ ...add, phone: x })} />
            <TextField label="Garment" required maxLength={160} value={add.garment} onChange={(x) => setAdd({ ...add, garment: x })} />
            <TextField label="Charge" hint="0 = free (e.g. with the purchase)." type="number" min={0} step="any" prefix={data.currency} value={add.charge} onChange={(x) => setAdd({ ...add, charge: x })} />
            <TextField label="Ready by" type="date" value={add.due_on} onChange={(x) => setAdd({ ...add, due_on: x })} />
            <LongText label="Work to do" className={f.spanAll} maxLength={2000} value={add.work} onChange={(x) => setAdd({ ...add, work: x })} />
          </div>
        </Ask>
      )}
      {upd && (
        <Ask title={`Update ${upd.row.number}`} go="Update" ready={!!upd.status} onClose={() => setUpd(null)}
          run={() => api.alterationAction(upd.row.id, 'progress', { status: upd.status, tailor: upd.tailor }).then(finish)}>
          <div className={s.one}>
            <Text>{upd.row.garment} for {upd.row.customer}: {upd.row.status_label.toLowerCase()} now.</Text>
            <Choose label="Now" required options={upd.row.next} value={upd.status} onChange={(x) => setUpd({ ...upd, status: x })} />
            <TextField label="Tailor" maxLength={120} value={upd.tailor} onChange={(x) => setUpd({ ...upd, tailor: x })} />
          </div>
        </Ask>
      )}
      {give && (
        <Ask title={`Hand over ${give.number}?`} go="Hand over" ready onClose={() => setGive(null)} run={() => api.alterationAction(give.id, 'hand_over').then(finish)}>
          <Text>{give.garment} goes back to {give.customer}. It is free, so no payment is taken.</Text>
        </Ask>
      )}
    </div>
  )
}
