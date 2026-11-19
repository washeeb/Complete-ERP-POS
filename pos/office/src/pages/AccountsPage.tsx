import {
  Badge, Button, Dropdown, Field, Input, Link, makeStyles, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Option, SearchBox, Spinner, Switch,
  Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { AddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type AccountForm, type AccountRow } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '6px 10px', overflowX: 'auto' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  head: { fontWeight: tokens.fontWeightBold },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  two: { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' },
})

const END = { style: { justifyContent: 'flex-end' } }
const SHOW = [['in_use', 'In use'], ['archived', 'Archived'], ['all', 'Both']]

/**
 * Chart of accounts (release 8): every account by code, headings in bold, its type, what automatic posting uses it for,
 * the sub-ledger that keeps it, and its balance (debit +, credit −); Ledger opens its entries. Add or change an account
 * (the next free code is suggested), archive one no longer used (history stays) or restore it.
 */
export function AccountsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<{ currency: string; exponent: number; rows: AccountRow[] } | null>(null)
  const [form, setForm] = useState<AccountForm | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [nonce, setNonce] = useState(0)
  const [editing, setEditing] = useState<AccountRow | 'new' | null>(null)
  const [archiving, setArchiving] = useState<AccountRow | null>(null)
  const [q, setQ] = useState(str(params, 'q'))
  const query = serialize(params)
  useEffect(() => { api.accountForm().then(setForm).catch(() => undefined) }, [nonce])
  useEffect(() => {
    const c = new AbortController()
    api.accounts(query, c.signal).then((d) => { setData(d); setError('') }).catch((e: unknown) => { if (!c.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
    return () => c.abort()
  }, [query, nonce])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const type = str(params, 'type')
  const show = str(params, 'show') || 'in_use'
  const refresh = (msg: string) => { setDone(msg); setNonce((n) => n + 1) }

  return (
    <div className={page.page}>
      <PageHead title="Chart of accounts" hint="Your accounts by code: headings group them; automatic posting uses the ones with a purpose; customer, supplier and stock accounts are kept by their sub-ledgers." classic="/admin/accounts" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Code or name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search accounts" />
        <Dropdown aria-label="Type" style={{ minWidth: 150 }} value={type ? form?.types.find((t) => t.id === type)?.label ?? type : 'Any type'} selectedOptions={[type]}
          onOptionSelect={(_, d) => set({ type: d.optionValue ?? '' })}>
          <Option value="">Any type</Option>{(form?.types ?? []).map((t) => <Option key={t.id} value={t.id}>{t.label}</Option>)}
        </Dropdown>
        <Dropdown aria-label="Show" style={{ minWidth: 130 }} value={SHOW.find((x) => x[0] === show)?.[1] ?? ''} selectedOptions={[show]} onOptionSelect={(_, d) => set({ show: d.optionValue === 'in_use' ? '' : d.optionValue ?? '' })}>
          {SHOW.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
        <span className={s.grow} />
        {form?.can_manage && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setEditing('new') }}>New account</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {!data ? <Spinner label="Reading the chart…" /> : (
        <section className={s.panel} aria-label="Chart of accounts">
          <Table size="small" aria-label="Chart of accounts" style={{ minWidth: 760 }}>
            <TableHeader><TableRow>
              <TableHeaderCell>Code</TableHeaderCell><TableHeaderCell>Name</TableHeaderCell><TableHeaderCell>Type</TableHeaderCell><TableHeaderCell>Automatic posting</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Balance (Dr + / Cr −)</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
            <TableBody>
              {data.rows.length === 0 && <TableRow><TableCell colSpan={6}><Text className={s.muted}>No accounts match.</Text></TableCell></TableRow>}
              {data.rows.map((a) => (
                <TableRow key={a.id}>
                  <TableCell className={a.header ? s.head : undefined}><Text font="monospace">{a.code}</Text></TableCell>
                  <TableCell className={a.header ? s.head : undefined}>{a.name}
                    {a.control && <Text block size={200} className={s.muted}>Kept by: {a.control}</Text>}
                    {a.archived && <Text block size={200} className={s.muted}>{a.archive_note}</Text>}</TableCell>
                  <TableCell><Badge appearance="tint" color="informative">{a.type_label}</Badge></TableCell>
                  <TableCell>{a.purpose ?? <Text className={s.muted}>—</Text>}</TableCell>
                  <TableCell className={s.end}>{a.balance === null ? '' : money(a.balance, data.currency, data.exponent)}</TableCell>
                  <TableCell>
                    {a.ledger && <Link onClick={() => navigate(a.ledger!)}>Ledger</Link>}
                    {(a.can_edit || a.can_archive || a.can_restore) && (
                      <Menu>
                        <MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${a.code}`} /></MenuTrigger>
                        <MenuPopover><MenuList>
                          {a.can_edit && <MenuItem onClick={() => { setDone(''); setEditing(a) }}>Change</MenuItem>}
                          {a.can_archive && <MenuItem onClick={() => { setDone(''); setArchiving(a) }}>Archive</MenuItem>}
                          {a.can_restore && <MenuItem onClick={async () => { try { await api.archiveAccount(a.id, null); refresh(`${a.code} restored.`) } catch (e) { setError(e instanceof Error ? e.message : String(e)) } }}>Restore</MenuItem>}
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
      {editing && form && <AccountDialog form={form} account={editing === 'new' ? null : editing} onClose={() => setEditing(null)}
        onDone={(msg) => { setEditing(null); refresh(msg) }} />}
      {archiving && <ArchiveDialog account={archiving} onClose={() => setArchiving(null)} onDone={() => { setArchiving(null); refresh(`${archiving.code} archived.`) }} />}
    </div>
  )
}

function AccountDialog({ form, account, onClose, onDone }: { form: AccountForm; account: AccountRow | null; onClose: () => void; onDone: (msg: string) => void }) {
  const s = useStyles()
  const [code, setCode] = useState(account?.code ?? '')
  const [name, setName] = useState(account?.name ?? '')
  const [type, setType] = useState(account?.type ?? '')
  const [parent, setParent] = useState(account?.parent_id ?? '')
  const [header, setHeader] = useState(account?.header ?? false)
  const [purpose, setPurpose] = useState(account?.system_key ?? '')
  const [description, setDescription] = useState(account?.description ?? '')
  const [suggested, setSuggested] = useState('')
  const suggest = (next: string | null | undefined) => { if (next && (code === '' || code === suggested)) { setCode(next); setSuggested(next) } }
  const purposes = form.purposes.filter((p) => !type || p.type === type)
  return (
    <Ask title={account ? `Change ${account.code}` : 'New account'} go={account ? 'Save' : 'Add the account'} ready={code.trim() !== '' && name.trim() !== '' && type !== ''} onClose={onClose} width={620}
      run={async () => { await api.saveAccount(account?.id ?? null, { code, name, type, parent_id: parent || null, is_header: header, system_key: purpose || null, description: description || null }); onDone(account ? `${code} saved.` : `${code} added.`) }}>
      <div className={s.form}>
        <div className={s.two}>
          <Field label="Type" required>
            <Dropdown value={form.types.find((t) => t.id === type)?.label ?? ''} selectedOptions={[type]} onOptionSelect={(_, d) => { setType(d.optionValue ?? ''); if (!account) suggest(form.next_by_type[d.optionValue ?? '']) }}>
              {form.types.map((t) => <Option key={t.id} value={t.id}>{t.label}</Option>)}
            </Dropdown>
          </Field>
          <Field label="Under heading">
            <Dropdown value={form.headers.find((h) => h.id === parent)?.label ?? '—'} selectedOptions={[parent]} onOptionSelect={(_, d) => { setParent(d.optionValue ?? ''); if (!account) suggest(form.next_by_header[d.optionValue ?? '']) }}>
              <Option value="">—</Option>{form.headers.filter((h) => !type || h.type === type).map((h) => <Option key={h.id} value={h.id}>{h.label}</Option>)}
            </Dropdown>
          </Field>
          <Field label="Code" required hint="Your accountant's numbering. The next free number in the type or heading is suggested."><Input value={code} maxLength={20} onChange={(_, d) => setCode(d.value)} /></Field>
          <Field label="Name" required><Input value={name} maxLength={255} onChange={(_, d) => setName(d.value)} /></Field>
        </div>
        <Switch label="Heading (groups other accounts; nothing posts to it)" checked={header} onChange={(_, d) => setHeader(d.checked)} />
        <Field label="Used by automatic posting for" hint="Moving a purpose here takes it away from the account that had it.">
          <Dropdown value={form.purposes.find((p) => p.id === purpose)?.label ?? '—'} selectedOptions={[purpose]} onOptionSelect={(_, d) => setPurpose(d.optionValue ?? '')}>
            <Option value="">—</Option>{purposes.map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Description"><Input value={description} maxLength={255} onChange={(_, d) => setDescription(d.value)} /></Field>
      </div>
    </Ask>
  )
}

function ArchiveDialog({ account, onClose, onDone }: { account: AccountRow; onClose: () => void; onDone: () => void }) {
  const s = useStyles()
  const [reason, setReason] = useState('')
  const blocked = account.blockers.length > 0
  return (
    <Ask title={`Archive ${account.code} ${account.name}?`} go="Archive" ready={!blocked && reason.trim().length >= 3} onClose={onClose}
      run={async () => { await api.archiveAccount(account.id, reason); onDone() }}>
      <div className={s.form}>
        {blocked ? <Text>It cannot be archived yet. {account.blockers.join(' ')}</Text> : <>
          <Text>It leaves every list people choose from, so nothing new is posted to it. Its history stays: the books and the audit log keep showing it. You can restore it at any time.</Text>
          <Field label="Why" required><Input value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} /></Field>
        </>}
      </div>
    </Ask>
  )
}
