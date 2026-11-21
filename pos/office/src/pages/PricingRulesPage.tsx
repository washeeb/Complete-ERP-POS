import { Badge, Button, Combobox, Dropdown, Field, makeStyles, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Option, Radio, RadioGroup, Switch, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArchiveRegular, ArrowSyncRegular, ArrowUndoRegular, EditRegular, MoreHorizontalRegular, ScalesRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type Option as Choice, type PricingRuleOptions as RuleOptions, type PricingRuleRow as RuleRow, type RuleValues, type UserDone } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { DataTable, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  two: { display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: '10px 14px', alignItems: 'start', '@media (max-width: 640px)': { gridTemplateColumns: '1fr' } },
})

const SHOW = [['', 'In use'], ['archived', 'Archived only'], ['both', 'Both']]
type Doing = { kind: 'edit'; row: RuleRow | null } | { kind: 'archive' | 'restore'; row: RuleRow } | { kind: 'apply' } | null

/**
 * Pricing rules (catalog and pricing setup): selling prices worked out from the latest cost, for the whole company, a
 * category or one product, on a price list. The most specific rule wins. Rises can be set at once and drops wait for a
 * manager (Prices to review).
 */
export function PricingRulesPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [doing, setDoing] = useState<Doing>(null)
  const [done, setDone] = useState('')
  const [options, setOptions] = useState<RuleOptions | null>(null)
  const { data, loading, error } = useList(api.pricingRules, serialize(params), nonce)
  useEffect(() => { void api.pricingOptions().then(setOptions).catch(() => undefined) }, [])
  const show = str(params, 'show')
  const columns: Column<RuleRow>[] = [
    { key: 'applies', label: 'Applies to', render: (r) => <Text weight="semibold">{r.applies_to}</Text> },
    { key: 'list', label: 'Price list', render: (r) => r.price_list ?? '—' },
    { key: 'rule', label: 'Rule', render: (r) => <Text weight="semibold">{r.rule}</Text> },
    { key: 'gives', label: 'Gives', render: (r) => r.gives },
    { key: 'rounding', label: 'Rounding', render: (r) => r.rounding },
    { key: 'change', label: 'When the cost changes', render: (r) => r.on_change },
    { key: 'on', label: 'In use', render: (r) => (r.archived ? <Badge appearance="tint" color="subtle">Archived</Badge> : <Badge appearance="tint" color={r.active ? 'success' : 'subtle'}>{r.active ? 'Yes' : 'Off'}</Badge>) },
    { key: 'acts', label: '', render: (r) => <span onClick={(e) => e.stopPropagation()}><Menu>
      <MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label="Actions" /></MenuTrigger>
      <MenuPopover><MenuList>
        {r.can_edit && <MenuItem icon={<EditRegular />} onClick={() => setDoing({ kind: 'edit', row: r })}>Change</MenuItem>}
        {r.can_archive && <MenuItem icon={<ArchiveRegular />} onClick={() => setDoing({ kind: 'archive', row: r })}>Archive</MenuItem>}
        {r.can_restore && <MenuItem icon={<ArrowUndoRegular />} onClick={() => setDoing({ kind: 'restore', row: r })}>Restore</MenuItem>}
      </MenuList></MenuPopover>
    </Menu></span> },
  ]
  const finish = (r: UserDone) => { setDoing(null); setDone(r.message); setNonce((n) => n + 1) }
  return (
    <div className={page.page}>
      <PageHead title="Pricing rules" hint="Selling prices worked out from the latest cost. Add a rule for the whole company (e.g. cost × 1.40), then exceptions for categories or products." classic="/admin/pricing-rules" />
      <div className={s.bar}>
        <Dropdown aria-label="Show" style={{ minWidth: 140 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => set({ show: d.optionValue ?? '' })}>
          {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Button icon={<ScalesRegular />} onClick={() => navigate('/office/prices-to-review')}>Prices to review</Button>
        <span className={s.grow} />
        {data?.can_create && <Button icon={<ArrowSyncRegular />} onClick={() => { setDone(''); setDoing({ kind: 'apply' }) }}>Apply the rules to all products now</Button>}
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setDoing({ kind: 'edit', row: null }) }}>New rule</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Pricing rules" columns={columns} rows={data?.rows ?? []} sort="applies" dir="asc" loading={loading}
        empty="No pricing rules: prices are typed by hand. Add a rule for the whole company (e.g. cost × 1.40), then exceptions for categories or products."
        onSort={() => undefined} onOpen={(r) => { if (r.can_edit) setDoing({ kind: 'edit', row: r }) }} />
      {doing?.kind === 'edit' && options && <RuleForm row={doing.row} options={options} onClose={() => setDoing(null)} onDone={finish} />}
      {doing?.kind === 'apply' && <Ask title="Apply the rules to all products now?" go="Apply them" ready onClose={() => setDoing(null)} run={async () => finish(await api.applyRules())}>
        <Text>Every product with a rule gets the price its latest cost gives. Rises (and new prices) are set at once where the rule says so; the rest wait under Prices to review.</Text>
      </Ask>}
      {(doing?.kind === 'archive' || doing?.kind === 'restore') && <ArchiveRule row={doing.row} restore={doing.kind === 'restore'} onClose={() => setDoing(null)} onDone={finish} />}
    </div>
  )
}

function ArchiveRule({ row, restore, onClose, onDone }: { row: RuleRow; restore: boolean; onClose: () => void; onDone: (r: UserDone) => void }) {
  const [why, setWhy] = useState('')
  return (
    <Ask title={`${restore ? 'Restore' : 'Archive'} this rule?`} go={restore ? 'Restore' : 'Archive'} ready={restore || why.trim().length >= 3} onClose={onClose}
      run={async () => onDone(await api.ruleArchive(row.id, restore ? 'restore' : 'archive', restore ? null : why))}>
      <Text block>{row.applies_to} on {row.price_list}: {row.rule}. {restore ? 'It comes back, switched off until you switch it on.' : 'It stops setting prices. You can restore it at any time.'}</Text>
      {!restore && <div style={{ marginTop: 10 }}><TextField label="Why" required value={why} maxLength={255} onChange={setWhy} /></div>}
    </Ask>
  )
}

function RuleForm({ row, options, onClose, onDone }: { row: RuleRow | null; options: RuleOptions; onClose: () => void; onDone: (r: UserDone) => void }) {
  const s = useStyles()
  const [v, setV] = useState<RuleValues>(row?.values ?? { scope: 'company', category_id: null, product_id: null, price_list_id: options.default_list ?? '', method: 'multiply', value: '1.40',
    amount: '', tax_mode: 'inclusive', round_minor: '10', round_mode: 'nearest', on_change: 'rise_auto', is_active: true })
  const [found, setFound] = useState<Choice[]>(row?.values.product_id ? [{ id: row.values.product_id, label: row.values.product ?? '' }] : [])
  const [typed, setTyped] = useState(row?.values.product ?? '')
  const put = <K extends keyof RuleValues>(k: K) => (x: RuleValues[K]) => setV({ ...v, [k]: x })
  useEffect(() => {
    if (v.scope !== 'product' || typed.trim().length < 2) return
    const t = setTimeout(() => { void api.pricingProducts(typed).then((r) => setFound(r.rows)) }, 250)
    return () => clearTimeout(t)
  }, [typed, v.scope])
  const num = parseFloat(v.value || '0')
  const price = v.method === 'multiply' ? 10 * num : v.method === 'markup' ? 10 * (1 + num / 100) : v.method === 'margin' ? (num < 100 ? 10 / (1 - num / 100) : null) : v.method === 'plus' ? 10 + parseFloat(v.amount || '0') : null
  const valueLabel = v.method === 'multiply' ? 'Multiply the cost by' : v.method === 'markup' ? 'Markup (% of the cost)' : 'Margin (% of the selling price)'
  const ready = !!v.price_list_id && (v.scope !== 'category' || !!v.category_id) && (v.scope !== 'product' || !!v.product_id)
  return (
    <Ask title={row ? 'Change the rule' : 'New pricing rule'} go="Save" ready={ready} onClose={onClose} width={680}
      run={async () => onDone(await api.saveRule(row?.id ?? null, { ...v, round_minor: Number(v.round_minor), value: v.value === '' ? null : v.value, amount: v.amount === '' ? null : v.amount }))}>
      <div className={s.form}>
        <Field label="Applies to" required>
          <RadioGroup value={v.scope} onChange={(_, d) => setV({ ...v, scope: d.value })}>{options.scopes.map((o) => <Radio key={o.id} value={o.id} label={o.label} />)}</RadioGroup>
        </Field>
        {v.scope === 'category' && <Choose label="Category" required options={options.categories} value={v.category_id ?? ''} onChange={(x) => put('category_id')(x || null)} />}
        {v.scope === 'product' && <Field label="Product" required hint="Type part of its name or code.">
          <Combobox freeform value={typed} selectedOptions={v.product_id ? [v.product_id] : []} onChange={(e) => setTyped(e.target.value)}
            onOptionSelect={(_, d) => { put('product_id')(d.optionValue ?? null); setTyped(d.optionText ?? '') }}>
            {found.map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
          </Combobox>
        </Field>}
        <div className={s.two}>
          <Choose label="Price list it sets" required options={options.price_lists} value={v.price_list_id} onChange={put('price_list_id')} />
          <Choose label="How the price is worked out" required options={options.methods} value={v.method} onChange={put('method')} />
          {['multiply', 'markup', 'margin'].includes(v.method) && <TextField label={valueLabel} type="number" value={v.value} onChange={put('value')} />}
          {v.method === 'plus' && <TextField label="Add to the cost" type="number" prefix={options.currency} value={v.amount} onChange={put('amount')} />}
        </div>
        {price !== null && <Text className={s.muted}>Example: a cost of {options.currency} 10.00 gives {options.currency} {price.toFixed(2)} (before rounding).</Text>}
        {v.method !== 'manual' && <div className={s.two}>
          <Choose label="The result is" options={options.tax_modes} value={v.tax_mode} onChange={put('tax_mode')} hint="Shelf price: VAT and levies are inside the price. Before tax: they are added on top for taxable items." />
          <Choose label="Rounding" options={options.rounding} value={v.round_minor} onChange={put('round_minor')} />
          <Choose label="Round" options={[{ id: 'nearest', label: 'To the nearest' }, { id: 'up', label: 'Always up' }]} value={v.round_mode} onChange={put('round_mode')} />
          <Choose label="When a delivery changes the cost" options={options.on_change} value={v.on_change} onChange={put('on_change')} hint="Prices waiting for approval are under Prices to review." />
        </div>}
        <Switch label="In use" checked={v.is_active} onChange={(_, d) => put('is_active')(d.checked)} />
      </div>
    </Ask>
  )
}
