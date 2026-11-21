import { Badge, Button, Dropdown, Field, Input, Link, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Text, tokens } from '@fluentui/react-components'
import { AddRegular, EditRegular, ListRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type DrugInteractionRow, type Option as Choice } from '../api'
import { Ask } from '../form/Ask'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '280px' },
  grow: { flex: '1 1 auto' },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const COLOUR: Record<string, 'danger' | 'warning' | 'subtle'> = { major: 'danger', moderate: 'warning' }

/**
 * Drug interactions (pharmacy): the table of ingredient pairs that interact; the till warns the pharmacist when both
 * are dispensed together. Rows are never deleted: a rule that no longer applies is set to "minor" with a note, so past
 * warnings stay explainable.
 */
export function DrugInteractionsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const [editing, setEditing] = useState<DrugInteractionRow | 'new' | null>(null)
  const [starter, setStarter] = useState(false)
  const [done, setDone] = useState('')
  const { data, loading, error } = useList(api.drugInteractions, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const severity = str(params, 'severity')
  const finish = (m: string) => { setEditing(null); setStarter(false); setDone(m); setNonce((n) => n + 1) }
  const columns: Column<DrugInteractionRow>[] = [
    { key: 'a', label: 'Ingredient', render: (r) => <Text weight="semibold">{r.ingredient_a}</Text> },
    { key: 'b', label: 'Interacts with', render: (r) => <Text weight="semibold">{r.ingredient_b}</Text> },
    { key: 'severity', label: 'Severity', render: (r) => <Badge appearance="tint" color={COLOUR[r.severity] ?? 'subtle'}>{r.severity_label}</Badge> },
    { key: 'note', label: 'What to do', render: (r) => r.note },
    ...(data?.can_manage ? [{ key: 'edit', label: '', render: (r: DrugInteractionRow) => <Button size="small" appearance="subtle" icon={<EditRegular />}
      onClick={(e) => { e.stopPropagation(); setDone(''); setEditing(r) }}>Change</Button> }] : []),
  ]
  return (
    <div className={page.page}>
      <PageHead title="Drug interactions" hint="Ingredient pairs that interact. The till warns the pharmacist when both are dispensed together." classic="/admin/drug-interactions" />
      {!!data?.unchecked && <MessageBar intent="warning" style={{ marginBottom: 10 }}><MessageBarBody>
        {data.unchecked} medicine(s) have neither a generic name nor active ingredients, so allergies and interactions cannot be checked for them. <Link onClick={() => navigate('/office/products')}>Add them on the products</Link>.
      </MessageBarBody></MessageBar>}
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Ingredient" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search interactions" />
        <Dropdown aria-label="Severity" style={{ minWidth: 160 }} value={severity === '' ? 'Every severity' : data?.severities.find((x) => x.id === severity)?.label ?? ''} selectedOptions={[severity]}
          onOptionSelect={(_, d) => set({ severity: d.optionValue ?? '' })}>
          <Option value="">Every severity</Option>{(data?.severities ?? []).map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        {data?.can_manage && <Button icon={<ListRegular />} onClick={() => { setDone(''); setStarter(true) }}>Add the starter list</Button>}
        {data?.can_manage && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setEditing('new') }}>New interaction</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Drug interactions" columns={columns} rows={data?.rows ?? []} sort="ingredient_a" dir="asc" loading={loading}
        empty={data?.can_manage ? 'No interactions yet. Add the starter list or your own.' : 'No interactions yet.'} onSort={() => undefined}
        onOpen={(r) => { if (data?.can_manage) { setDone(''); setEditing(r) } }} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {editing && data && <InteractionDialog rule={editing === 'new' ? null : editing} severities={data.severities} onClose={() => setEditing(null)} onDone={finish} />}
      {starter && data && <Ask title="Add the starter list of interactions?" go="Add them" ready onClose={() => setStarter(false)}
        run={async () => finish((await api.drugInteractionStarter()).message)}>
        <Text>{data.starter} well-known interactions between common medicines (warfarin, methotrexate, sildenafil and nitrates, statins and some antibiotics, antacids and iron with
          ciprofloxacin or doxycycline, the pill with rifampicin…). Pairs already in your table are left as they are. It is not a complete reference: the superintendent pharmacist should
          read it, change rows, and add others.</Text>
      </Ask>}
    </div>
  )
}

function InteractionDialog({ rule, severities, onClose, onDone }: { rule: DrugInteractionRow | null; severities: Choice[]; onClose: () => void; onDone: (m: string) => void }) {
  const s = useStyles()
  const [a, setA] = useState(rule?.ingredient_a ?? '')
  const [b, setB] = useState(rule?.ingredient_b ?? '')
  const [severity, setSeverity] = useState(rule?.severity ?? '')
  const [note, setNote] = useState(rule?.note ?? '')
  return (
    <Ask title={rule ? 'Change interaction' : 'New interaction'} go="Save" width={560} ready={a.trim() !== '' && b.trim() !== '' && severity !== '' && note.trim() !== ''} onClose={onClose}
      run={async () => onDone((await api.saveDrugInteraction(rule?.id ?? null, { ingredient_a: a, ingredient_b: b, severity, note })).message)}>
      <div className={s.form}>
        <Field label="Ingredient" required><Input value={a} maxLength={120} onChange={(_, d) => setA(d.value)} /></Field>
        <Field label="Interacts with" required><Input value={b} maxLength={120} onChange={(_, d) => setB(d.value)} /></Field>
        <Field label="Severity" required>
          <Dropdown value={severities.find((x) => x.id === severity)?.label ?? ''} selectedOptions={[severity]} onOptionSelect={(_, d) => setSeverity(d.optionValue ?? '')}>
            {severities.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="What to do" required><Input value={note} maxLength={255} onChange={(_, d) => setNote(d.value)} /></Field>
        <Text size={200} className={s.muted}>Names are kept in lower case. Rows are never deleted: set one that no longer applies to Minor with a note.</Text>
      </div>
    </Ask>
  )
}
