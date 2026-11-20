import {
  Badge, Button, Checkbox, Dropdown, Field, makeStyles, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Option, SearchBox, Switch, Text, tokens,
} from '@fluentui/react-components'
import {
  AddRegular, ArchiveRegular, MergeRegular, ArrowSwapRegular, ArrowUndoRegular, CheckmarkCircleRegular, DeleteRegular, EditRegular, MoreHorizontalRegular,
} from '@fluentui/react-icons'
import { useEffect, useState, type ReactNode } from 'react'
import { api, type RefKind, type RefOptions, type RefRow, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { DataTable, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

const SHOW = [['', 'In use'], ['archived', 'Archived only'], ['both', 'Both']]
const yes = (v: unknown) => (v ? 'Yes' : '—')

/** What each list shows: title, hint, the current page, and its columns beyond the name. */
const KIND: Record<RefKind, { title: string; one: string; hint: string; classic: string; columns: [string, (r: RefRow) => ReactNode][] }> = {
  categories: { title: 'Categories', one: 'category', hint: 'Groups of products, one inside another if you like. Move its products to another before deleting one.', classic: '/admin/categories',
    columns: [['Inside', (r) => (r.parent as string) ?? '—'], ['Industry', (r) => r.industry as string], ['Products', (r) => r.products]] },
  brands: { title: 'Brands', one: 'brand', hint: 'Who makes each product. Move its products to another before deleting one.', classic: '/admin/brands',
    columns: [['Industry', (r) => r.industry as string], ['Products', (r) => r.products]] },
  units: { title: 'Units of measure', one: 'unit', hint: 'The units products are sold and counted in (PC, CTN, KG, TAB). Two codes for the same thing? Merge one into the other.', classic: '/admin/uoms',
    columns: [['Code', (r) => <Text font="monospace" weight="semibold">{r.code as string}</Text>], ['Decimals', (r) => r.decimals as number], ['Products', (r) => r.products],
      ['Merged into', (r) => (r.merged_into ? <Badge appearance="tint" color="subtle">{r.merged_into as string}</Badge> : '—')]] },
  'tax-rates': { title: 'Tax rates', one: 'tax rate', hint: 'Each tax and its rate. A change affects new sales only; posted sales keep the tax they were charged.', classic: '/admin/tax-rates',
    columns: [['Code', (r) => <Text font="monospace">{r.code as string}</Text>], ['Rate', (r) => r.rate as string]] },
  'tax-groups': { title: 'Tax groups', one: 'tax group', hint: 'Which taxes a product is charged. Each is calculated on the price before tax (no tax on tax). An accountant should check each group.', classic: '/admin/tax-groups',
    columns: [['Code', (r) => <Text font="monospace">{r.code as string}</Text>], ['Taxes', (r) => r.taxes as string],
      ['Accountant check', (r) => <Badge appearance="tint" color={r.verified ? 'success' : 'warning'}>{(r.verified as string) ?? 'Not verified'}</Badge>], ['Products', (r) => r.products]] },
  'price-lists': { title: 'Price lists', one: 'price list', hint: 'Retail, wholesale, staff…: each product can have a price on each list. The default list is used when the till starts a sale.', classic: '/admin/price-lists',
    columns: [['Code', (r) => <Text font="monospace">{r.code as string}</Text>], ['Incl. tax', (r) => yes(r.incl_tax)], ['Default', (r) => yes(r.default)], ['At till', (r) => yes(r.at_till)],
      ['Industry', (r) => r.industry as string], ['Branch', (r) => r.branch as string], ['Prices', (r) => r.prices as number]] },
}

type Doing = { kind: 'edit'; row: RefRow | null } | { kind: 'move' | 'merge' | 'verify' | 'delete' | 'archive' | 'restore'; row: RefRow } | null

/**
 * Categories, brands, units of measure, tax rates, tax groups and price lists (catalog and pricing setup): one list each,
 * with Add and Change in a dialog, and per list Move products, Merge into, Delete when unused, Record accountant check,
 * Archive and Restore.
 */
export function ReferenceListPage({ kind }: { kind: RefKind }) {
  const page = usePageStyles()
  const s = useStyles()
  const k = KIND[kind]
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [doing, setDoing] = useState<Doing>(null)
  const [done, setDone] = useState('')
  const [options, setOptions] = useState<RefOptions | null>(null)
  const { data, loading, error } = useList((q, signal) => api.refList(kind, q, signal), serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { void api.refOptions(kind).then(setOptions).catch(() => undefined) }, [kind, nonce])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const show = str(params, 'show')
  const columns: Column<RefRow>[] = [
    { key: 'name', label: 'Name', render: (r) => <Text weight="semibold">{r.name}</Text> },
    ...k.columns.map(([label, render]) => ({ key: label, label, render })),
    ...(data?.archivable ? [{ key: 'status', label: 'Status', render: (r: RefRow) => <Badge appearance="tint" color={r.archived ? 'subtle' : 'success'}>{r.archived ? 'Archived' : 'In use'}</Badge> }] : []),
    { key: 'acts', label: '', render: (r) => <span onClick={(e) => e.stopPropagation()}><Menu>
      <MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Actions for ${r.name}`} /></MenuTrigger>
      <MenuPopover><MenuList>
        {r.can_edit && <MenuItem icon={<EditRegular />} onClick={() => setDoing({ kind: 'edit', row: r })}>Change</MenuItem>}
        {r.can_move && <MenuItem icon={<ArrowSwapRegular />} onClick={() => setDoing({ kind: 'move', row: r })}>Move products</MenuItem>}
        {!!r.can_merge && <MenuItem icon={<MergeRegular />} onClick={() => setDoing({ kind: 'merge', row: r })}>Merge into…</MenuItem>}
        {!!r.can_verify && <MenuItem icon={<CheckmarkCircleRegular />} onClick={() => setDoing({ kind: 'verify', row: r })}>Record accountant check</MenuItem>}
        {r.can_delete && <MenuItem icon={<DeleteRegular />} onClick={() => setDoing({ kind: 'delete', row: r })}>Delete</MenuItem>}
        {r.can_archive && <MenuItem icon={<ArchiveRegular />} onClick={() => setDoing({ kind: 'archive', row: r })}>Archive</MenuItem>}
        {r.can_restore && <MenuItem icon={<ArrowUndoRegular />} onClick={() => setDoing({ kind: 'restore', row: r })}>Restore</MenuItem>}
      </MenuList></MenuPopover>
    </Menu></span> },
  ]
  const finish = (r: UserDone) => { setDoing(null); setDone(r.message); setNonce((n) => n + 1) }

  return (
    <div className={page.page}>
      <PageHead title={k.title} hint={k.hint} classic={k.classic} />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name or code" value={q} onChange={(_, d) => setQ(d.value)} aria-label={`Search ${k.title.toLowerCase()}`} />
        {data?.archivable && <Dropdown aria-label="Show" style={{ minWidth: 140 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })}>
          {SHOW.map(([v, l]) => <Option key={v} value={v}>{l}</Option>)}
        </Dropdown>}
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setDoing({ kind: 'edit', row: null }) }}>Add a {k.one}</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label={k.title} columns={columns} rows={data?.rows ?? []} sort="name" dir="asc" loading={loading} empty={`No ${k.title.toLowerCase()} match.`}
        onSort={() => undefined} onOpen={(r) => { if (r.can_edit) { setDone(''); setDoing({ kind: 'edit', row: r }) } }} />
      {doing && options && (doing.kind === 'edit'
        ? <RefForm kind={kind} one={k.one} row={doing.row} options={options} onClose={() => setDoing(null)} onDone={finish} />
        : <RefAction kind={kind} doing={doing} options={options} onClose={() => setDoing(null)} onDone={finish} />)}
    </div>
  )
}

function RefForm({ kind, one, row, options, onClose, onDone }: { kind: RefKind; one: string; row: RefRow | null; options: RefOptions; onClose: () => void; onDone: (r: UserDone) => void }) {
  const s = useStyles()
  const init: Record<string, unknown> = row?.values ?? ({
    categories: { name: '', industry_id: options.default_industry ?? '', parent_id: '' }, brands: { name: '', industry_id: options.default_industry ?? '' },
    units: { code: '', name: '', decimals: 0 }, 'tax-rates': { code: '', name: '', rate_bp: '', sort: 0 }, 'tax-groups': { name: '', rate_ids: [] },
    'price-lists': { name: '', prices_include_tax: true, is_default: false, pos_selectable: true, industry_id: options.default_industry ?? '', branch_id: '' },
  } as Record<RefKind, Record<string, unknown>>)[kind]
  const [v, setV] = useState<Record<string, unknown>>({ ...init, ...(kind === 'tax-groups' && row ? { rate_ids: row.values.rate_ids } : {}) })
  const put = (key: string) => (x: unknown) => setV({ ...v, [key]: x })
  const txt = (key: string) => String(v[key] ?? '')
  const pick = (key: string, label: string, list: { id: string; label: string }[] | undefined, hint?: string, placeholder?: string) => (
    <Choose label={label} hint={hint} placeholder={placeholder} options={list ?? []} value={txt(key)} onChange={put(key)} />
  )
  const body = (): Record<string, unknown> => {
    const b = { ...v }
    for (const key of ['industry_id', 'parent_id', 'branch_id']) if (b[key] === '') b[key] = null
    if (kind === 'tax-rates' && row) delete b.code
    if ('rate_bp' in b) b.rate_bp = Number(b.rate_bp)
    if ('decimals' in b) b.decimals = Number(b.decimals)
    if ('sort' in b) b.sort = Number(b.sort || 0)
    return b
  }
  const ready = txt('name').trim() !== '' && (kind !== 'units' || txt('code').trim() !== '') && (kind !== 'tax-rates' || (!!row || txt('code').trim() !== '') && txt('rate_bp') !== '')
  return (
    <Ask title={row ? `Change ${row.name}` : `Add a ${one}`} go="Save" ready={ready} onClose={onClose} width={560}
      run={async () => onDone(await api.refSave(kind, row?.id ?? null, body()))}>
      <div className={s.form}>
        {(kind === 'units' || kind === 'tax-rates') && <TextField label="Code" required={!row || kind === 'units'} disabled={kind === 'tax-rates' && !!row} value={txt('code')} maxLength={kind === 'units' ? 10 : 20}
          onChange={put('code')} hint={kind === 'units' ? 'Short code printed on receipts, e.g. PC, CTN, KG, TAB. Changing it changes it on every product, the tills and new documents; receipts already made keep the old code.' : 'Letters, digits and _ ; it stays once added.'} />}
        {(kind === 'tax-groups' || kind === 'price-lists') && row && <Text className={s.muted}>Code: {String(row.code)} (made from the name when it was added)</Text>}
        <TextField label="Name" required value={txt('name')} maxLength={kind === 'units' ? 60 : kind === 'categories' || kind === 'brands' ? 120 : 80} onChange={put('name')}
          hint={kind === 'tax-rates' ? 'Printed on receipts' : kind === 'price-lists' && !row ? 'The code is made from it (Staff price → STAFF_PRICE). Product files use it for this list\'s price column.' : undefined} />
        {(kind === 'categories' || kind === 'brands') && (options.industries?.length ?? 0) > 1 && pick('industry_id', 'Industry', options.industries, 'Only people in this industry see it. Shared ones are seen by every industry.')}
        {kind === 'categories' && pick('parent_id', 'Inside category', (options.parents ?? []).filter((p) => p.id !== row?.id), undefined, 'None (top level)')}
        {kind === 'units' && pick('decimals', 'Quantity decimals', options.decimals, 'How precisely this unit can be sold or counted.')}
        {kind === 'tax-rates' && <>
          <TextField label="Rate (basis points)" required type="number" min={0} max={10000} value={txt('rate_bp')} onChange={put('rate_bp')} hint="1500 = 15.00%. A change affects new sales only; posted sales keep the tax they were charged." />
          <TextField label="Order on receipts" type="number" value={txt('sort')} onChange={put('sort')} />
        </>}
        {kind === 'tax-groups' && <Field label="Taxes charged" hint="Each is calculated on the price before tax (no tax on tax). Changing this clears the accountant sign-off.">
          {(options.rates ?? []).map((r) => <Checkbox key={r.id} label={r.label} checked={(v.rate_ids as string[]).includes(r.id)}
            onChange={(_, d) => put('rate_ids')(d.checked ? [...(v.rate_ids as string[]), r.id] : (v.rate_ids as string[]).filter((x) => x !== r.id))} />)}
        </Field>}
        {kind === 'price-lists' && <>
          <Switch label="Prices include tax" checked={!!v.prices_include_tax} onChange={(_, d) => put('prices_include_tax')(d.checked)} />
          <Text size={200} className={s.muted}>Retail shelf prices normally include tax. Cannot change once the list has prices.</Text>
          <Switch label="Default list (used when the till starts a sale)" checked={!!v.is_default} onChange={(_, d) => put('is_default')(d.checked)} />
          <Switch label="Cashier can switch to it at the till" checked={!!v.pos_selectable} onChange={(_, d) => put('pos_selectable')(d.checked)} />
          {(options.industries?.length ?? 0) > 0 && pick('industry_id', 'Industry', options.industries, undefined, 'All industries (shared)')}
          {pick('branch_id', 'Only at branch', options.branches, undefined, 'All branches')}
        </>}
      </div>
    </Ask>
  )
}

function RefAction({ kind, doing, options, onClose, onDone }: { kind: RefKind; doing: Exclude<Doing, null | { kind: 'edit' }>; options: RefOptions; onClose: () => void; onDone: (r: UserDone) => void }) {
  const [to, setTo] = useState('')
  const [text, setText] = useState('')
  const r = doing.row
  const blocked = doing.kind === 'archive' && r.archive_blockers.length > 0
  const conf = {
    move: { title: `Move ${r.products} product(s) from ${r.name}`, go: 'Move them', note: `${r.products} product(s) will move. Nothing else about them changes.` },
    merge: { title: `Merge ${String(r.code)} into…`, go: 'Merge', note: `Every product using ${String(r.code)} moves to the unit you choose, with its pack size, prices and barcodes. Past documents keep what they showed. ${String(r.code)} is then no longer offered.` },
    verify: { title: 'Record the accountant check', go: 'Record it', note: 'Confirm that a qualified accountant has checked these rates and the calculation method (tax-inclusive prices split per line, no tax on tax) against current GRA rules.' },
    delete: { title: `Delete ${r.name}?`, go: 'Delete', note: 'Only possible when nothing uses it; otherwise move its products first.' },
    archive: { title: `Archive ${r.name}?`, go: 'Archive', note: blocked ? `It cannot be archived yet. ${r.archive_blockers.join(' ')}` : 'It leaves every list people choose from. Its history stays. You can restore it at any time.' },
    restore: { title: `Restore ${r.name}?`, go: 'Restore', note: 'It comes back into the lists people choose from.' },
  }[doing.kind]
  const ready = !blocked && (doing.kind === 'merge' ? !!to : doing.kind === 'verify' ? text.trim() !== '' : doing.kind === 'archive' ? text.trim().length >= 3 : true)
  return (
    <Ask title={conf.title} go={conf.go} ready={ready} onClose={onClose}
      run={async () => onDone(await api.refAction(kind, r.id, doing.kind, { to: to || null, note: doing.kind === 'verify' ? text : null, reason: doing.kind === 'archive' ? text : null }))}>
      <Text block style={{ marginBottom: 10 }}>{conf.note}</Text>
      {doing.kind === 'move' && <Choose label="Move them to" options={options.move_to.filter((o) => o.id !== r.id)} value={to} onChange={setTo} placeholder="None" />}
      {doing.kind === 'merge' && <Choose label="Merge into" required options={(options.merge_into ?? []).filter((o) => o.id !== r.id)} value={to} onChange={setTo} />}
      {doing.kind === 'verify' && <TextField label="Who checked it, and against what" required value={text} maxLength={255} onChange={setText} />}
      {doing.kind === 'archive' && !blocked && <TextField label="Why" required value={text} maxLength={255} onChange={setText} />}
    </Ask>
  )
}
