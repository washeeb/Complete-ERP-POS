import { Button, makeStyles, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular, DeleteRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ModifierList, type ModifierValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'
import { ArchiveMenu } from './RestaurantParts'

type Row = ModifierList['rows'][number]

const useStyles = makeStyles({
  choice: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr) auto', gap: '8px 10px', alignItems: 'start',
    '@media (max-width: 760px)': { gridTemplateColumns: '1fr' } },
})

/**
 * Restaurant → Modifiers (part 30): choices offered with dishes — cooking, sides, extras — with how many must or may be
 * chosen. A priced extra is charged as its own product. New, change, switch off (not offered), archive and restore; past
 * bills keep the choices they were sold with.
 */
export function ModifiersPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const c = useStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.restModifiers, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [edit, setEdit] = useState<{ id: string | null; v: ModifierValues } | null>(null)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (m: string) => { setEdit(null); setDone(m); setRefresh((n) => n + 1) }
  const put = <K extends keyof ModifierValues>(k: K) => (x: ModifierValues[K]) => setEdit((o) => (o ? { ...o, v: { ...o.v, [k]: x } } : o))
  const opt = (i: number, patch: Partial<ModifierValues['options'][number]>) =>
    setEdit((o) => (o ? { ...o, v: { ...o.v, options: o.v.options.map((x, j) => (j === i ? { ...x, ...patch } : x)) } } : o))
  const named = (edit?.v.options ?? []).filter((o) => o.name.trim() !== '').length
  return (
    <div className={page.page}>
      <PageHead title="Modifiers" hint="Choices offered with dishes: cooking, sides, extras. A priced extra is charged as its own product." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search modifiers" />
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => {
          setDone('')
          setEdit({ id: null, v: { name: '', industry_id: data.default_industry, min_choices: '0', max_choices: '1', is_active: true,
            options: [{ name: '', product_id: '', product: null }, { name: '', product_id: '', product: null }] } })
        }}>New modifier group</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Modifiers" rows={data?.rows ?? []} sort="name" dir="asc" loading={loading} empty="No modifier groups yet." onSort={() => undefined}
        onOpen={(r) => !r.archived && setEdit({ id: r.id, v: { ...r.values, options: r.values.options.map((x) => ({ ...x })) } })}
        columns={[
          { key: 'name', label: 'Name', render: (r) => <Text weight="semibold">{r.name}</Text> },
          { key: 'rule', label: 'Choose', render: (r) => r.rule },
          { key: 'choices', label: 'Choices', render: (r) => r.choices },
          { key: 'active', label: 'Offered', render: (r) => (r.active ? 'Yes' : 'No') },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'more', label: '', render: (r) => <ArchiveMenu kind="modifier" row={r} name={r.name} onDone={finish} /> },
        ]} />
      {edit && data && (
        <Ask title={edit.id ? `Change ${edit.v.name}` : 'New modifier group'} go="Save" width={720} onClose={() => setEdit(null)}
          ready={edit.v.name.trim() !== '' && named > 0 && Number(edit.v.max_choices) >= 1 && Number(edit.v.min_choices) <= Number(edit.v.max_choices) && Number(edit.v.min_choices) <= named}
          run={() => api.saveModifier(edit.id, { ...edit.v, min_choices: Number(edit.v.min_choices), max_choices: Number(edit.v.max_choices),
            industry_id: data.industries ? edit.v.industry_id : '',
            options: edit.v.options.map((o) => ({ id: o.id, name: o.name, product_id: o.product_id || null })) }).then((x) => finish(x.message))}>
          <div className={s.one}>
            <div className={f.grid}>
              <TextField label="Name" required className={f.spanAll} maxLength={255} placeholder="Spice level, Sides, Extras…" value={edit.v.name} onChange={put('name')} />
              {data.industries && <Choose label="Industry" required options={data.industries} value={edit.v.industry_id} onChange={put('industry_id')}
                hint="Only people in this industry see it. Shared ones are seen by every industry." />}
              <TextField label="Choose at least" required type="number" min={0} hint="1 = the waiter must choose" value={edit.v.min_choices} onChange={put('min_choices')} />
              <TextField label="Choose at most" required type="number" min={1} value={edit.v.max_choices} onChange={put('max_choices')} />
              <Toggle label="Offered" value={edit.v.is_active} onChange={put('is_active')} />
            </div>
            <Text weight="semibold">Choices</Text>
            {edit.v.options.map((o, i) => (
              <div key={o.id ?? `new-${i}`} className={c.choice} role="group" aria-label={`Choice ${i + 1}`}>
                <TextField label="Name" required maxLength={120} value={o.name} onChange={(x) => opt(i, { name: x })} />
                <SearchPicker label="Charged as (optional)" value={o.product_id} text={o.product} search={api.modifierProducts} placeholder="Free"
                  onChange={(x) => opt(i, { product_id: x?.id ?? '', product: x?.label ?? null })} />
                <div style={{ display: 'flex', alignItems: 'flex-end', height: '100%' }}>
                  {edit.v.options.length > 1 && <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove choice ${i + 1}`}
                    onClick={() => put('options')(edit.v.options.filter((_, j) => j !== i))} />}
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => put('options')([...edit.v.options, { name: '', product_id: '', product: null }])}>Add a choice</Button></div>
          </div>
        </Ask>
      )}
    </div>
  )
}
