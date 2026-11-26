import {
  Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, EditRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type AssetKind, type AssetKinds } from '../api'
import { Ask } from '../form/Ask'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '6px 10px', overflowX: 'auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

/** Kinds of asset (release 8): computers, vehicles, furniture…, each with its useful life and the accounts it posts to (cost, accumulated depreciation, depreciation expense). */
export function AssetKindsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [data, setData] = useState<AssetKinds | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [nonce, setNonce] = useState(0)
  const [editing, setEditing] = useState<AssetKind | 'new' | null>(null)
  useEffect(() => { api.assetKinds().then((d) => { setData(d); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [nonce])

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Reading the kinds of asset…" />}</div>

  return (
    <div className={page.page}>
      <PageHead title="Kinds of asset" hint="Each kind's useful life (the default for new assets) and the accounts its cost and depreciation go to." />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/fixed-assets')}>Fixed assets</Button>
        <span className={s.grow} />
        {data.can_manage && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setEditing('new') }}>New kind</Button>}
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <section className={s.panel} aria-label="Kinds of asset">
        <Table size="small" aria-label="Kinds of asset" style={{ minWidth: 760 }}>
          <TableHeader><TableRow><TableHeaderCell>Code</TableHeaderCell><TableHeaderCell>Name</TableHeaderCell><TableHeaderCell>Life (months)</TableHeaderCell>
            <TableHeaderCell>Asset account</TableHeaderCell><TableHeaderCell>Depreciation accounts</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>{data.rows.map((k) => (
            <TableRow key={k.id}>
              <TableCell><Text font="monospace">{k.code}</Text></TableCell><TableCell>{k.name}</TableCell><TableCell>{k.life_months}</TableCell>
              <TableCell>{k.cost_account ?? '—'}</TableCell>
              <TableCell>{k.accumulated_account ?? '—'}<Text block size={200} className={s.muted}>{k.expense_account}</Text></TableCell>
              <TableCell>{data.can_manage && <Button size="small" appearance="subtle" icon={<EditRegular />} onClick={() => { setDone(''); setEditing(k) }}>Change</Button>}</TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </section>
      {editing && data.accounts && <KindDialog accounts={data.accounts} kind={editing === 'new' ? null : editing} onClose={() => setEditing(null)}
        onDone={(msg) => { setEditing(null); setDone(msg); setNonce((n) => n + 1) }} />}
    </div>
  )
}

function KindDialog({ accounts, kind, onClose, onDone }: { accounts: NonNullable<AssetKinds['accounts']>; kind: AssetKind | null; onClose: () => void; onDone: (m: string) => void }) {
  const s = useStyles()
  const [name, setName] = useState(kind?.name ?? '')
  const [life, setLife] = useState(String(kind?.life_months ?? ''))
  const [cost, setCost] = useState(kind?.cost_account_id ?? '')
  const [accumulated, setAccumulated] = useState(kind?.accumulated_account_id ?? '')
  const [expense, setExpense] = useState(kind?.expense_account_id ?? '')
  const pick = (label: string, value: string, set: (v: string) => void, list: { id: string; label: string }[]) => (
    <Field label={label} required>
      <Dropdown value={list.find((x) => x.id === value)?.label ?? ''} selectedOptions={[value]} onOptionSelect={(_, d) => set(d.optionValue ?? '')}>
        {list.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
      </Dropdown>
    </Field>
  )
  return (
    <Ask title={kind ? `Change ${kind.name}` : 'New kind of asset'} go="Save" ready={name.trim() !== '' && Number(life) >= 1 && !!cost && !!accumulated && !!expense} onClose={onClose} width={600}
      run={async () => { await api.saveAssetKind(kind?.id ?? null, { name, life_months: Number(life), cost_account_id: cost, accumulated_account_id: accumulated, expense_account_id: expense }); onDone(`${name} saved.`) }}>
      <div className={s.form}>
        {!kind && <Text>The code is given automatically when it is saved.</Text>}
        <Field label="Name" required><Input value={name} maxLength={255} onChange={(_, d) => setName(d.value)} /></Field>
        <Field label="Useful life (months)" required><Input type="number" min={1} value={life} onChange={(_, d) => setLife(d.value)} /></Field>
        {pick('Asset account (cost)', cost, setCost, accounts.asset)}
        {pick('Accumulated depreciation account', accumulated, setAccumulated, accounts.asset)}
        {pick('Depreciation expense account', expense, setExpense, accounts.expense)}
      </div>
    </Ask>
  )
}
