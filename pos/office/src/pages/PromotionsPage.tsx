import { Badge, Button, Checkbox, Combobox, Dropdown, Field, Input, makeStyles, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Option, SearchBox, Spinner, Switch, Tag, TagGroup, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArchiveRegular, ArrowUndoRegular, EditRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type Option as Choice, type PromotionOptions, type PromotionRow, type PromotionValues, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { Choose, ChooseMany, TextField } from '../form/Fields'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '14px' },
  section: { display: 'flex', flexDirection: 'column', gap: '10px', paddingTop: '10px', borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
  three: { display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: '10px 14px', alignItems: 'start', '@media (max-width: 720px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' }, '@media (max-width: 480px)': { gridTemplateColumns: '1fr' } },
  two: { display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: '10px 14px', alignItems: 'start', '@media (max-width: 480px)': { gridTemplateColumns: '1fr' } },
  span2: { gridColumn: 'span 2', '@media (max-width: 480px)': { gridColumn: 'auto' } },
  days: { display: 'flex', flexWrap: 'wrap', gap: '2px 6px' },
  picks: { display: 'flex', flexDirection: 'column', gap: '6px' },
})

const RUNNING = [['', 'Running'], ['no', 'Switched off'], ['both', 'Both']]
const SHOW = [['', 'In use'], ['archived', 'Archived only'], ['both', 'Both']]
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
/** The fields each kind of promotion asks for (as the current form). */
const ASKS: Record<string, string[]> = {
  percent: ['percent'], amount_off: ['amount'], buy_x_get_y: ['percent', 'buy_qty', 'get_qty'], bundle: ['amount', 'bundle_qty'],
  threshold: ['percent', 'amount', 'min_spend'], coupon: ['coupon_code', 'percent', 'amount', 'min_spend'],
}
type Doing = { kind: 'edit'; id: string | null } | { kind: 'archive' | 'restore'; row: PromotionRow } | null

/** Promotions and coupons (catalog and pricing setup), set up at head office and run by the same engine on the tills and the server. */
export function PromotionsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [doing, setDoing] = useState<Doing>(str(params, 'new') === '1' ? { kind: 'edit', id: null } : str(params, 'edit') ? { kind: 'edit', id: str(params, 'edit') } : null)
  const [done, setDone] = useState('')
  const [options, setOptions] = useState<PromotionOptions | null>(null)
  const { data, loading, error } = useList(api.promotions, serialize({ ...params, new: '', edit: '' }), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { void api.promotionOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q, page: '' }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const pick = (key: string, list: string[][], label: string) => {
    const v = str(params, key)
    return <Dropdown aria-label={label} style={{ minWidth: 130 }} value={list.find((x) => x[0] === v)?.[1] ?? ''} selectedOptions={[v]} onOptionSelect={(_, d) => set({ [key]: d.optionValue ?? '', page: '' })}>
      {list.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
    </Dropdown>
  }
  const types = [['', 'All kinds'], ...(options?.types ?? []).map((t) => [t.id, t.label])]
  const columns: Column<PromotionRow>[] = [
    { key: 'name', label: 'Name', render: (r) => <><Text weight="semibold">{r.name}</Text>{r.coupon && <Text block size={200} className={s.muted}>Code {r.coupon}</Text>}</> },
    { key: 'type', label: 'Kind', render: (r) => <Badge appearance="tint" color="informative">{r.type}</Badge> },
    { key: 'offer', label: 'Offer', render: (r) => r.offer },
    { key: 'when', label: 'When', render: (r) => r.when },
    { key: 'running', label: 'Running', render: (r) => (r.archived ? <Badge appearance="tint" color="subtle">Archived</Badge> : <Badge appearance="tint" color={r.running ? 'success' : 'subtle'}>{r.running ? 'Yes' : 'Off'}</Badge>) },
    { key: 'acts', label: '', render: (r) => (r.can_edit || r.can_archive || r.can_restore) && <span onClick={(e) => e.stopPropagation()}><Menu>
      <MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label="Actions" /></MenuTrigger>
      <MenuPopover><MenuList>
        {r.can_edit && <MenuItem icon={<EditRegular />} onClick={() => setDoing({ kind: 'edit', id: r.id })}>Change</MenuItem>}
        {r.can_archive && <MenuItem icon={<ArchiveRegular />} onClick={() => setDoing({ kind: 'archive', row: r })}>Archive</MenuItem>}
        {r.can_restore && <MenuItem icon={<ArrowUndoRegular />} onClick={() => setDoing({ kind: 'restore', row: r })}>Restore</MenuItem>}
      </MenuList></MenuPopover>
    </Menu></span> },
  ]
  const close = () => { setDoing(null); if (str(params, 'new') || str(params, 'edit')) set({ new: '', edit: '' }) }
  const finish = (r: UserDone) => { close(); setDone(r.message); setNonce((n) => n + 1) }
  return (
    <div className={page.page}>
      <PageHead title="Promotions" hint="Discounts, multi-buys, bundles, spend-and-save and coupons. Set up here, they reach every branch and till at the next sync." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name or coupon code" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search promotions" />
        {pick('type', types, 'Kind')}
        {pick('running', RUNNING, 'Running')}
        {pick('show', SHOW, 'Show')}
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setDoing({ kind: 'edit', id: null }) }}>New promotion</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Promotions" columns={columns} rows={data?.rows ?? []} sort="name" dir="asc" loading={loading}
        empty="No promotions here. Add one with New promotion: a % off, buy 2 get 1 free, a bundle price, spend-and-save or a coupon."
        onSort={() => undefined} onOpen={(r) => { if (r.can_edit) setDoing({ kind: 'edit', id: r.id }) }} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {doing?.kind === 'edit' && options && <PromotionForm id={doing.id} options={options} onClose={close} onDone={finish} />}
      {(doing?.kind === 'archive' || doing?.kind === 'restore') && <ArchivePromotion row={doing.row} restore={doing.kind === 'restore'} onClose={close} onDone={finish} />}
    </div>
  )
}

function ArchivePromotion({ row, restore, onClose, onDone }: { row: PromotionRow; restore: boolean; onClose: () => void; onDone: (r: UserDone) => void }) {
  const [why, setWhy] = useState('')
  return (
    <Ask title={`${restore ? 'Restore' : 'Archive'} ${row.name}?`} go={restore ? 'Restore' : 'Archive'} ready={restore || why.trim().length >= 3} onClose={onClose}
      run={async () => onDone(await api.promotionArchive(row.id, restore ? 'restore' : 'archive', restore ? null : why))}>
      <Text block>{restore ? 'It comes back to the list. Switch it on to run it again.' : 'It stops and leaves the list; past sales still show it. You can restore it at any time.'}</Text>
      {!restore && <div style={{ marginTop: 10 }}><TextField label="Why" required value={why} maxLength={255} onChange={setWhy} /></div>}
    </Ask>
  )
}

const BLANK = (o: PromotionOptions): PromotionValues => ({ name: '', industry_id: o.default_industry ?? '', is_active: true, type: 'percent', coupon_code: '', percent: '', amount: '', min_spend: '',
  buy_qty: '', get_qty: '', bundle_qty: '', product_ids: [], category_ids: [], starts_at: '', ends_at: '', days: [], time_from: '', time_to: '', branch_ids: [], price_list_ids: [] })

function PromotionForm({ id, options, onClose, onDone }: { id: string | null; options: PromotionOptions; onClose: () => void; onDone: (r: UserDone) => void }) {
  const s = useStyles()
  const [v, setV] = useState<PromotionValues | null>(id ? null : BLANK(options))
  const [err, setErr] = useState('')
  const [picked, setPicked] = useState<Choice[]>([])
  const [found, setFound] = useState<Choice[]>([])
  const [typed, setTyped] = useState('')
  useEffect(() => {
    if (!id) return
    api.promotion(id).then((r) => { setV(r.values); setPicked(r.values.products ?? []) }).catch((e) => setErr(e instanceof Error ? e.message : String(e)))
  }, [id])
  useEffect(() => {
    if (typed.trim().length < 2) return
    const t = setTimeout(() => { void api.promotionProducts(typed).then((r) => setFound(r.rows)) }, 250)
    return () => clearTimeout(t)
  }, [typed])
  if (!v) return <Ask title="Change the promotion" go="Save" ready={false} onClose={onClose} run={async () => undefined}>{err ? <MessageBar intent="error"><MessageBarBody>{err}</MessageBarBody></MessageBar> : <Spinner size="small" label="Loading…" />}</Ask>
  const put = <K extends keyof PromotionValues>(k: K) => (x: PromotionValues[K]) => setV({ ...v, [k]: x })
  const asks = ASKS[v.type] ?? []
  const has = (f: string) => asks.includes(f)
  const amountLabel = v.type === 'amount_off' ? 'Amount off each item' : v.type === 'bundle' ? 'Bundle price' : 'Amount off the basket'
  const basket = v.type === 'threshold' || v.type === 'coupon'
  const ready = v.name.trim() !== '' && (!has('coupon_code') || v.coupon_code.trim() !== '')
  const branches = [...options.branches, ...(v.archived_branches ?? [])]
  const add = (p: Choice) => { if (!v.product_ids.includes(p.id)) { setV({ ...v, product_ids: [...v.product_ids, p.id] }); setPicked([...picked, p]) } setTyped('') }
  const body = () => {
    const b: Record<string, unknown> = { ...v }
    delete b.products; delete b.archived_branches
    for (const f of ['coupon_code', 'percent', 'amount', 'min_spend', 'buy_qty', 'get_qty', 'bundle_qty']) b[f] = has(f) && v[f as keyof PromotionValues] !== '' ? v[f as keyof PromotionValues] : null
    b.days = v.days.map(Number)
    return b
  }
  return (
    <Ask title={id ? 'Change the promotion' : 'New promotion'} go="Save" ready={ready} onClose={onClose} width={760} run={async () => onDone(await api.savePromotion(id, body()))}>
      <div className={s.form}>
        <div className={s.three}>
          <div className={s.span2}><TextField label="Name" required hint="Shown on the receipt." value={v.name} maxLength={255} onChange={put('name')} /></div>
          <Field label="Running"><Switch checked={v.is_active} onChange={(_, d) => put('is_active')(d.checked)} label={v.is_active ? 'Yes' : 'Off'} /></Field>
          {options.industries.length > 0 && <Choose label="Industry" options={options.industries} value={v.industry_id} onChange={put('industry_id')} placeholder="Your own industry" hint="Only people in this industry see it. Shared ones are seen by every industry." />}
          <div className={s.span2}><Choose label="Kind" required options={options.types} value={v.type} onChange={(x) => setV({ ...v, type: x || 'percent' })} /></div>
          {has('coupon_code') && <TextField label="Coupon code" required value={v.coupon_code} maxLength={30} onChange={(x) => put('coupon_code')(x.toUpperCase())} hint="3 to 30 letters, digits or dashes." />}
          {has('percent') && <TextField label={v.type === 'buy_x_get_y' ? 'Discount on the extra items (%)' : '% off'} type="number" min={0.01} max={100} value={v.percent} onChange={put('percent')}
            hint={v.type === 'buy_x_get_y' ? '100 = free (e.g. buy 2 get 1 free).' : undefined} />}
          {has('amount') && <TextField label={amountLabel} type="number" prefix={options.currency} value={v.amount} onChange={put('amount')} hint={basket ? 'Give a % or an amount, not both.' : undefined} />}
          {has('buy_qty') && <TextField label="Buy" type="number" min={1} value={v.buy_qty} onChange={put('buy_qty')} />}
          {has('get_qty') && <TextField label="Get" type="number" min={1} value={v.get_qty} onChange={put('get_qty')} />}
          {has('bundle_qty') && <TextField label="Items in the bundle" type="number" min={2} value={v.bundle_qty} onChange={put('bundle_qty')} />}
          {has('min_spend') && <TextField label="Minimum spend" type="number" prefix={options.currency} value={v.min_spend} onChange={put('min_spend')} />}
        </div>
        <div className={s.section}>
          <Text weight="semibold">What it applies to</Text>
          <Text size={200} className={s.muted}>Leave both empty for everything. Several products together make a mix &amp; match.</Text>
          <div className={s.two}>
            <Field label="Products" hint="Type part of a name or code, then pick.">
              <div className={s.picks}>
                <Combobox freeform value={typed} selectedOptions={[]} placeholder="Add a product" onChange={(e) => setTyped(e.target.value)}
                  onOptionSelect={(_, d) => { const p = found.find((x) => x.id === d.optionValue); if (p) add(p) }}>
                  {found.filter((p) => !v.product_ids.includes(p.id)).map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
                </Combobox>
                {v.product_ids.length > 0 && <TagGroup aria-label="Products" onDismiss={(_, d) => put('product_ids')(v.product_ids.filter((x) => x !== d.value))}>
                  {v.product_ids.map((x) => <Tag key={x} value={x} size="small" dismissible dismissIcon={{ 'aria-label': 'Remove' }}>{picked.find((p) => p.id === x)?.label ?? x}</Tag>)}
                </TagGroup>}
              </div>
            </Field>
            <ChooseMany label="Categories" options={options.categories} value={v.category_ids} onChange={put('category_ids')} placeholder="All" />
          </div>
        </div>
        <div className={s.section}>
          <Text weight="semibold">When and where</Text>
          <Text size={200} className={s.muted}>Leave empty for always and everywhere. Happy hour uses the branch’s local time.</Text>
          <div className={s.two}>
            <TextField label="From" type="datetime-local" value={v.starts_at} onChange={put('starts_at')} />
            <TextField label="Until" type="datetime-local" value={v.ends_at} onChange={put('ends_at')} />
          </div>
          <Field label="Days" hint="None ticked: every day.">
            <div className={s.days}>{DAYS.map((d, i) => {
              const k = String(i + 1)
              return <Checkbox key={k} label={d} checked={v.days.includes(k)} onChange={(_, x) => put('days')(x.checked ? [...v.days, k].sort() : v.days.filter((y) => y !== k))} />
            })}</div>
          </Field>
          <div className={s.two}>
            <Field label="Happy hour from"><Input type="time" value={v.time_from} onChange={(_, d) => put('time_from')(d.value)} /></Field>
            <Field label="to"><Input type="time" value={v.time_to} onChange={(_, d) => put('time_to')(d.value)} /></Field>
            <ChooseMany label="Branches" options={branches} value={v.branch_ids} onChange={put('branch_ids')} placeholder="All branches" />
            <ChooseMany label="Price lists" options={options.price_lists} value={v.price_list_ids} onChange={put('price_list_ids')} placeholder="All price lists" />
          </div>
        </div>
      </div>
    </Ask>
  )
}
