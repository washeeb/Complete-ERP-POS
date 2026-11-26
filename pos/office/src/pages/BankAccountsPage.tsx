import {
  Badge, Button, Dropdown, Field, Input, makeStyles, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Option, SearchBox, Spinner,
  Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { AddRegular, ArrowSwapRegular, CheckmarkCircleRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type BankForms, type BankList, type BankRow } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { navigate } from '../nav'
import { MoveMoney } from './MoveMoney'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '6px 10px', overflowX: 'auto' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  two: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' },
  three: { display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '10px' },
})

const END = { style: { justifyContent: 'flex-end' } }
const SHOW = [['in_use', 'In use'], ['archived', 'Archived'], ['all', 'Both']]

/**
 * Bank & cash (release 8): your bank, mobile money and cash accounts with their ledger balance and the statement lines
 * still to match. Reconcile opens an account's matching; Import statement reads the bank's or MoMo portal's CSV (its
 * columns named once, remembered for the account; lines already imported are skipped); Move money moves between your
 * own accounts. Add, change, archive or restore an account.
 */
export function BankAccountsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<BankList | null>(null)
  const [forms, setForms] = useState<BankForms | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [nonce, setNonce] = useState(0)
  const [editing, setEditing] = useState<BankRow | 'new' | null>(null)
  const [importing, setImporting] = useState<BankRow | null>(null)
  const [archiving, setArchiving] = useState<BankRow | null>(null)
  const [moving, setMoving] = useState(false)
  const [q, setQ] = useState(str(params, 'q'))
  const query = serialize(params)
  useEffect(() => { api.bankForms().then(setForms).catch(() => undefined) }, [])
  useEffect(() => {
    const c = new AbortController()
    api.bankAccounts(query, c.signal).then((d) => { setData(d); setError('') }).catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
    return () => c.abort()
  }, [query, nonce])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const show = str(params, 'show') || 'in_use'
  const refresh = (m: string) => { setDone(m); setNonce((n) => n + 1) }
  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')

  return (
    <div className={page.page}>
      <PageHead title="Bank & cash" hint="Bank, mobile money and cash accounts: their balance in the books and the statement lines still to match. Import a statement, then reconcile." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Name, bank or number" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search bank and cash accounts" />
        <Dropdown aria-label="Show" style={{ minWidth: 130 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => set({ show: d.optionValue === 'in_use' ? '' : d.optionValue ?? '' })}>
          {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <Button onClick={() => navigate('/office/money-moved')}>Money moved</Button>
        <span className={s.grow} />
        {data?.can_manage && forms && <Button icon={<ArrowSwapRegular />} onClick={() => { setDone(''); setMoving(true) }}>Move money</Button>}
        {data?.can_manage && forms && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setEditing('new') }}>New account</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {!data ? <Spinner label="Reading the accounts…" /> : (
        <section className={s.panel} aria-label="Bank and cash accounts">
          <Table size="small" aria-label="Bank and cash accounts" style={{ minWidth: 760 }}>
            <TableHeader><TableRow><TableHeaderCell>Name</TableHeaderCell><TableHeaderCell>Kind</TableHeaderCell><TableHeaderCell>Ledger</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Ledger balance</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Statement lines to match</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
            <TableBody>
              {data.rows.length === 0 && <TableRow><TableCell colSpan={6}><Text className={s.muted}>No bank or cash accounts yet. Add one with New account.</Text></TableCell></TableRow>}
              {data.rows.map((b) => (
                <TableRow key={b.id}>
                  <TableCell>{b.name}<Text block size={200} className={s.muted}>{[b.provider, b.number].filter(Boolean).join(' ')}{b.archived ? ' · archived' : ''}</Text></TableCell>
                  <TableCell><Badge appearance="tint" color="informative">{b.kind_label}</Badge></TableCell>
                  <TableCell>{b.ledger}</TableCell>
                  <TableCell className={s.end}>{m(b.balance)}</TableCell>
                  <TableCell className={s.end}>{b.to_match || <Text className={s.muted}>0</Text>}</TableCell>
                  <TableCell>
                    {data.can_reconcile && <Button size="small" icon={<CheckmarkCircleRegular />} onClick={() => navigate(`/office/bank-accounts/${b.id}/reconcile`)}>Reconcile</Button>}
                    {data.can_manage && (
                      <Menu>
                        <MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${b.name}`} /></MenuTrigger>
                        <MenuPopover><MenuList>
                          <MenuItem onClick={() => { setDone(''); setImporting(b) }}>Import statement</MenuItem>
                          <MenuItem onClick={() => { setDone(''); setEditing(b) }}>Change</MenuItem>
                          {b.can_archive && <MenuItem onClick={() => { setDone(''); setArchiving(b) }}>Archive</MenuItem>}
                          {b.can_restore && <MenuItem onClick={async () => { try { await api.archiveBank(b.id, null); refresh(`${b.name} restored.`) } catch (e) { setError(e instanceof Error ? e.message : String(e)) } }}>Restore</MenuItem>}
                        </MenuList></MenuPopover>
                      </Menu>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </section>
      )}
      {editing && forms && <BankDialog forms={forms} bank={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={(msg) => { setEditing(null); refresh(msg) }} />}
      {importing && forms && <ImportStatement forms={forms} bank={importing} onClose={() => setImporting(null)} onDone={(msg) => { setImporting(null); refresh(msg) }} />}
      {moving && forms && <MoveMoney forms={forms} onClose={() => setMoving(false)} onDone={(msg) => { setMoving(false); refresh(msg) }} />}
      {archiving && <ArchiveBank bank={archiving} onClose={() => setArchiving(null)} onDone={() => { setArchiving(null); refresh(`${archiving.name} archived.`) }} />}
    </div>
  )
}

function ArchiveBank({ bank, onClose, onDone }: { bank: BankRow; onClose: () => void; onDone: () => void }) {
  const s = useStyles()
  const [reason, setReason] = useState('')
  const blocked = bank.blockers.length > 0
  return (
    <Ask title={`Archive ${bank.name}?`} go="Archive" ready={!blocked && reason.trim().length >= 3} onClose={onClose} run={async () => { await api.archiveBank(bank.id, reason); onDone() }}>
      <div className={s.form}>
        {blocked ? <Text>It cannot be archived yet. {bank.blockers.join(' ')}</Text> : <>
          <Text>It leaves every list people choose from. Its history stays: the books, statements and the audit log keep showing it. You can restore it at any time.</Text>
          <Field label="Why" required><Input value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} /></Field>
        </>}
      </div>
    </Ask>
  )
}

function BankDialog({ forms, bank, onClose, onDone }: { forms: BankForms; bank: BankRow | null; onClose: () => void; onDone: (m: string) => void }) {
  const s = useStyles()
  const [name, setName] = useState(bank?.name ?? '')
  const [kind, setKind] = useState(bank?.kind ?? 'bank')
  const [provider, setProvider] = useState(bank?.provider ?? '')
  const [number, setNumber] = useState(bank?.number ?? '')
  const [account, setAccount] = useState(bank?.account_id ?? '')
  const [code, setCode] = useState('')
  return (
    <Ask title={bank ? `Change ${bank.name}` : 'New bank or cash account'} go={bank ? 'Save' : 'Add the account'} ready={name.trim() !== '' && (!!bank || !!account || code.trim() !== '')} onClose={onClose} width={620}
      run={async () => { await api.saveBank(bank?.id ?? null, { name, kind, provider: provider || null, number: number || null, account_id: account || null, code: code || null }); onDone(bank ? `${name} saved.` : `${name} added.`) }}>
      <div className={s.form}>
        <div className={s.two}>
          <Field label="Name" required><Input value={name} maxLength={255} placeholder="e.g. GCB current account, MTN MoMo merchant" onChange={(_, d) => setName(d.value)} /></Field>
          <Field label="Kind" required>
            <Dropdown value={forms.kinds.find((k) => k.id === kind)?.label ?? ''} selectedOptions={[kind]} onOptionSelect={(_, d) => setKind(d.optionValue ?? 'bank')}>
              {forms.kinds.map((k) => <Option key={k.id} value={k.id}>{k.label}</Option>)}
            </Dropdown>
          </Field>
          <Field label="Bank / network"><Input value={provider} maxLength={255} onChange={(_, d) => setProvider(d.value)} /></Field>
          <Field label="Account / wallet number"><Input value={number} maxLength={60} onChange={(_, d) => setNumber(d.value)} /></Field>
        </div>
        <Field label="Ledger account" hint={bank ? undefined : 'Leave blank to create a new ledger account with the code below.'}>
          <Dropdown value={forms.ledger_accounts.find((a) => a.id === account)?.label ?? (bank?.ledger ?? '—')} selectedOptions={[account]} onOptionSelect={(_, d) => setAccount(d.optionValue ?? '')}>
            {!bank && <Option value="">— a new ledger account</Option>}{forms.ledger_accounts.map((a) => <Option key={a.id} value={a.id}>{a.label}</Option>)}
          </Dropdown>
        </Field>
        {!bank && !account && <Field label="New ledger account code" required><Input value={code} maxLength={20} onChange={(_, d) => setCode(d.value)} /></Field>}
      </div>
    </Ask>
  )
}

/** Import statement: the bank's or MoMo portal's CSV, with its columns named (header text or column number), remembered for the account. */
function ImportStatement({ forms, bank, onClose, onDone }: { forms: BankForms; bank: BankRow; onClose: () => void; onDone: (m: string) => void }) {
  const s = useStyles()
  const saved = bank.mapping ?? {}
  const [file, setFile] = useState<File | null>(null)
  const [map, setMap] = useState<Record<string, string>>({ header_rows: String(saved.header_rows ?? 1), date_format: String(saved.date_format ?? 'auto'),
    ...Object.fromEntries(['date', 'description', 'reference', 'amount', 'money_in', 'money_out', 'balance'].map((k) => [k, saved[k] === undefined ? '' : String(saved[k])])) })
  const put = (k: string, v: string) => setMap({ ...map, [k]: v })
  const col = (k: string, label: string, required = false) => <Field label={label} required={required}><Input value={map[k]} maxLength={60} onChange={(_, d) => put(k, d.value)} /></Field>
  const ready = !!file && map.date.trim() !== '' && (map.amount.trim() !== '' || map.money_in.trim() !== '' || map.money_out.trim() !== '')
  return (
    <Ask title={`Import a statement for ${bank.name}`} go="Import" ready={ready} onClose={onClose} width={720}
      run={async () => {
        const body = new FormData()
        body.append('file', file!)
        for (const [k, v] of Object.entries(map)) if (v.trim() !== '') body.append(k, v.trim())
        onDone((await api.importStatement(bank.id, body)).message)
      }}>
      <div className={s.form}>
        <Text>Export the statement from your bank or MoMo portal as CSV (in Excel: Save As → CSV). Name the columns once; they are remembered for this account. Lines already imported are skipped.</Text>
        <Field label="Statement (CSV)" required><input type="file" accept=".csv,text/csv,text/plain" aria-label="Statement file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></Field>
        <Text weight="semibold">Columns (header text or column number)</Text>
        <div className={s.three}>
          <Field label="Header rows to skip" required><Input type="number" min={0} max={20} value={map.header_rows} onChange={(_, d) => put('header_rows', d.value)} /></Field>
          {col('date', 'Date', true)}
          <Field label="Date format" required>
            <Dropdown value={forms.date_formats.find((f) => f.id === map.date_format)?.label ?? ''} selectedOptions={[map.date_format]} onOptionSelect={(_, d) => put('date_format', d.optionValue ?? 'auto')}>
              {forms.date_formats.map((f) => <Option key={f.id} value={f.id}>{f.label}</Option>)}
            </Dropdown>
          </Field>
          {col('description', 'Description')}
          {col('reference', 'Reference')}
          {col('amount', 'Amount (+ in, − out)')}
          {col('money_in', '…or money in')}
          {col('money_out', '…and money out')}
          {col('balance', 'Balance (optional)')}
        </div>
      </div>
    </Ask>
  )
}
