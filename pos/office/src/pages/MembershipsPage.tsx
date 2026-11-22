import { Button, Dropdown, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Option, SearchBox, Text } from '@fluentui/react-components'
import { MoreHorizontalRegular, PersonAddRegular } from '@fluentui/react-icons'
import { useEffect, useRef, useState } from 'react'
import { api } from '../api'
import type { MembershipList } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = MembershipList['rows'][number]

/**
 * Services → Memberships (part 26): customers' memberships and subscriptions — plan, price, since, next invoice and
 * status; active by default. Sign up a customer (the first period is invoiced straight away), and on each: Invoice due
 * periods, Pause (no invoices until resumed), Resume and Cancel.
 */
export function MembershipsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const cur = useRef<MembershipList | null>(null)
  const { data, loading, error } = useList((qq, sig) => api.memberships(qq, sig).then((d) => { cur.current = d; return d }), serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [enrol, setEnrol] = useState<{ customer_id: string; customer: string | null; membership_plan_id: string; branch_id: string; starts_on: string; price: string } | null>(null)
  const [doing, setDoing] = useState<{ row: Row; action: 'bill' | 'pause' | 'resume' | 'cancel' } | null>(null)
  const [why, setWhy] = useState('')
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (m: string) => { setEnrol(null); setDoing(null); setDone(m); setRefresh((n) => n + 1) }
  const status = data?.status ?? (str(params, 'status') || 'active')
  const m = (x: number) => (cur.current ? money(x, cur.current.currency, cur.current.exponent) : '')
  return (
    <div className={page.page}>
      <PageHead title="Memberships" hint="Invoiced every period (Receivables), paused or cancelled here." classic="/admin/memberships" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, customer or phone" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search memberships" />
        <Dropdown aria-label="Status" value={status === 'all' ? 'Any status' : data?.statuses.find((x) => x.id === status)?.label ?? 'Active'} selectedOptions={[status]} style={{ minWidth: 130 }}
          onOptionSelect={(_, d) => set({ status: d.optionValue ?? 'active' })}>
          {(data?.statuses ?? []).map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}<Option value="all">Any status</Option>
        </Dropdown>
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<PersonAddRegular />}
          onClick={() => { setDone(''); setEnrol({ customer_id: '', customer: null, membership_plan_id: '', branch_id: data.branches[0]?.id ?? '', starts_on: data.today, price: '' }) }}>Sign up a customer</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Memberships" rows={data?.rows ?? []} sort="next" dir="asc" loading={loading} empty="No memberships match." onSort={() => undefined} onOpen={() => undefined}
        columns={[
          { key: 'number', label: 'Number', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'customer', label: 'Customer', render: (r) => <>{r.customer ?? '—'}{r.phone && <Text size={200} block className={s.muted}>{r.phone}</Text>}</> },
          { key: 'plan', label: 'Plan', render: (r) => r.plan ?? '—' },
          { key: 'price', label: 'Price', end: true, render: (r) => m(r.price) },
          { key: 'since', label: 'Since', render: (r) => day(r.since) },
          { key: 'next', label: 'Next invoice', render: (r) => <>{day(r.next)}{r.ends && <Text size={200} block className={s.muted}>Ends {day(r.ends)}</Text>}</> },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'act', label: '', render: (r) => (r.can_bill || r.can_pause || r.can_resume || r.can_cancel) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Act on ${r.number}`} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_bill && <MenuItem onClick={() => setDoing({ row: r, action: 'bill' })}>Invoice due periods</MenuItem>}
                {r.can_pause && <MenuItem onClick={() => { setWhy(''); setDoing({ row: r, action: 'pause' }) }}>Pause</MenuItem>}
                {r.can_resume && <MenuItem onClick={() => setDoing({ row: r, action: 'resume' })}>Resume</MenuItem>}
                {r.can_cancel && <MenuItem onClick={() => { setWhy(''); setDoing({ row: r, action: 'cancel' }) }}>Cancel</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {enrol && data && (
        <Ask title="Sign up a customer" go="Sign up" width={600} ready={!!enrol.customer_id && !!enrol.membership_plan_id && !!enrol.branch_id && !!enrol.starts_on} onClose={() => setEnrol(null)}
          run={() => api.enrolMember({ customer_id: enrol.customer_id, membership_plan_id: enrol.membership_plan_id, branch_id: enrol.branch_id, starts_on: enrol.starts_on, price: enrol.price || null })
            .then((x) => finish(x.message))}>
          <div className={s.one}>
            <Text>The first period is invoiced straight away (Receivables → Invoices).</Text>
            <SearchPicker label="Customer" required value={enrol.customer_id} text={enrol.customer} search={api.serviceCustomers} placeholder="Type a name or phone"
              onChange={(x) => setEnrol({ ...enrol, customer_id: x?.id ?? '', customer: x?.label ?? null })} />
            <Choose label="Plan" required options={data.plans} value={enrol.membership_plan_id} onChange={(x) => setEnrol({ ...enrol, membership_plan_id: x })} />
            <Choose label="Invoiced from (branch)" required options={data.branches} value={enrol.branch_id} onChange={(x) => setEnrol({ ...enrol, branch_id: x })} />
            <TextField label="Starts" required type="date" value={enrol.starts_on} onChange={(x) => setEnrol({ ...enrol, starts_on: x })} />
            <TextField label="Agreed price per period (optional)" type="number" min={0} step="any" prefix={data.currency} hint="Empty = the plan price." value={enrol.price} onChange={(x) => setEnrol({ ...enrol, price: x })} />
          </div>
        </Ask>
      )}
      {doing && (
        <Ask title={{ bill: 'Invoice the due periods?', pause: 'Pause this membership?', resume: 'Resume this membership?', cancel: 'Cancel this membership?' }[doing.action]}
          go={{ bill: 'Invoice', pause: 'Pause', resume: 'Resume', cancel: 'Cancel the membership' }[doing.action]}
          ready={!['pause', 'cancel'].includes(doing.action) || why.trim() !== ''} onClose={() => setDoing(null)}
          run={() => api.membershipAction(doing.row.id, doing.action, why).then((x) => finish(x.message))}>
          <div className={s.one}>
            <Text>{{ bill: 'Every period due up to today is invoiced now.', pause: 'No invoices until it is resumed.', resume: 'Invoices start again from the next period.',
              cancel: 'No more invoices. The membership runs to the end of the period already invoiced.' }[doing.action]}</Text>
            {['pause', 'cancel'].includes(doing.action) && <TextField label="Reason" required maxLength={255} value={why} onChange={setWhy} />}
          </div>
        </Ask>
      )}
    </div>
  )
}
