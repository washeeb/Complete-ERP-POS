import {
  Badge, Button, Combobox, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Table, TableBody, TableCell, TableHeader, TableHeaderCell,
  TableRow, Text, tokens,
} from '@fluentui/react-components'
import { AddRegular, ArrowDownloadRegular, ArrowSyncRegular, DeleteRegular } from '@fluentui/react-icons'
import { useEffect, useMemo, useState } from 'react'
import { api, type JournalOptions, type JournalRow } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { ExportMenu } from '../list/ExportMenu'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  grow: { flex: '1 1 auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
  row2: { display: 'grid', gridTemplateColumns: '170px 1fr', gap: '10px' },
  num: { width: '110px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
})

const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Journals (release 8): every journal in the ledger, newest first — automatic ones from sales, bills and payments,
 * journals typed by hand, opening balances, reversals. Search by number or description, filter by kind and dates. New
 * journal and Opening balances post through the journal service; Post now posts what is waiting. A journal opens with
 * its lines; a mistake is reversed, never edited.
 */
export function JournalsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [options, setOptions] = useState<JournalOptions | null>(null)
  const [nonce, setNonce] = useState(0)
  const [done, setDone] = useState('')
  const [error, setError] = useState('')
  const [posting, setPosting] = useState(false)
  const [form, setForm] = useState<'manual' | 'opening' | null>(null)
  const { data, loading, error: listError } = useList(api.journals, serialize(params), nonce)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => { api.journalOptions().then(setOptions).catch(() => undefined) }, [])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  const m = (v: number) => (data ? money(v, data.currency, data.exponent) : '')
  const kind = str(params, 'kind')
  const columns: Column<JournalRow>[] = [
    { key: 'number', label: 'Number', sort: 'number', render: (r) => <Text font="monospace" weight="semibold">{r.number}</Text> },
    { key: 'date', label: 'Date', sort: 'entry_date', render: (r) => day(r.date) },
    { key: 'kind', label: 'Kind', render: (r) => <Badge appearance="tint" color={r.kind === 'auto' ? 'subtle' : 'brand'}>{r.kind_label}</Badge> },
    { key: 'memo', label: 'Description', render: (r) => r.memo ?? '—' },
    { key: 'amount', label: 'Amount', sort: 'total_minor', end: true, render: (r) => m(r.amount) },
    { key: 'by', label: 'Posted by', render: (r) => r.by ?? '—' },
  ]

  return (
    <div className={page.page}>
      <PageHead title="Journals" hint="The ledger's journals: automatic ones from sales, bills and payments, journals typed by hand, opening balances and reversals. A posted journal is reversed, never edited." classic="/admin/journal-entries" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number or description" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search journals" />
        <Dropdown aria-label="Kind" style={{ minWidth: 170 }} value={kind ? options?.kinds.find((k) => k.id === kind)?.label ?? kind : 'Any kind'} selectedOptions={[kind]}
          onOptionSelect={(_, d) => set({ kind: d.optionValue ?? '' })}>
          <Option value="">Any kind</Option>{(options?.kinds ?? []).map((k) => <Option key={k.id} value={k.id}>{k.label}</Option>)}
        </Dropdown>
        <Input type="date" aria-label="From" value={str(params, 'from')} onChange={(_, d) => set({ from: d.value })} />
        <Input type="date" aria-label="To" value={str(params, 'to')} onChange={(_, d) => set({ to: d.value })} />
        <ExportMenu list="journals" query={serialize(params)} total={data?.total ?? null} />
        <span className={s.grow} />
        {options?.can_post_now && <Button icon={<ArrowSyncRegular />} disabled={posting} onClick={async () => {
          setPosting(true); setDone(''); setError('')
          try { const r = await api.postNow(); (r.errors.length ? setError : setDone)(r.message); setNonce((n) => n + 1) } catch (e) { setError(e instanceof Error ? e.message : String(e)) }
          setPosting(false)
        }}>{posting ? 'Posting…' : 'Post now'}</Button>}
        {options?.can_journal && <Button icon={<ArrowDownloadRegular />} onClick={() => { setDone(''); setForm('opening') }}>Opening balances</Button>}
        {options?.can_journal && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); setForm('manual') }}>New journal</Button>}
      </div>
      {(listError || error) && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{listError || error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable label="Journals" columns={columns} rows={data?.rows ?? []} sort={data?.sort ?? 'number'} dir={data?.dir ?? 'desc'} loading={loading}
        empty="No journals match. Clear a filter or the search." onSort={(sort, dir) => set({ sort, dir })} onOpen={(r) => navigate(`/office/journals/${r.id}`)} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={options?.per ?? [10, 25, 50, 100]}
        onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {form && options && <JournalForm opening={form === 'opening'} options={options} currency={data?.currency ?? 'GHS'} onClose={() => setForm(null)}
        onDone={(open) => navigate(open)} />}
    </div>
  )
}

type Line = { account_id: string; debit: string; credit: string; branch_id: string }

/** New journal / Opening balances: date, description and debit/credit lines (debits must equal credits; opening differences go to "Opening balances"). */
function JournalForm({ opening, options, currency, onClose, onDone }: { opening: boolean; options: JournalOptions; currency: string; onClose: () => void; onDone: (open: string) => void }) {
  const s = useStyles()
  const today = new Date().toISOString().slice(0, 10)
  const blank = (): Line => ({ account_id: '', debit: '', credit: '', branch_id: options.hq })
  const [date, setDate] = useState(today)
  const [memo, setMemo] = useState('')
  const [lines, setLines] = useState<Line[]>(opening ? [blank()] : [blank(), blank()])
  const n = (v: string) => (v.trim() === '' || Number.isNaN(Number(v)) ? 0 : Math.round(Number(v) * 100))
  const debits = lines.reduce((t, l) => t + n(l.debit), 0)
  const credits = lines.reduce((t, l) => t + n(l.credit), 0)
  const filled = lines.filter((l) => l.account_id && (n(l.debit) > 0 || n(l.credit) > 0))
  const ready = (opening ? filled.length >= 1 : filled.length >= 2 && memo.trim() !== '' && debits === credits) && filled.length === lines.filter((l) => l.account_id || l.debit || l.credit).length
  const put = (i: number, c: Partial<Line>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...c } : l)))
  return (
    <Ask title={opening ? 'Opening balances' : 'New journal'} go={opening ? 'Post the opening balances' : 'Post the journal'} ready={ready} onClose={onClose} width={900}
      run={async () => onDone((await api.postJournal({ opening, date, memo: opening ? null : memo, lines: filled.map((l) => ({ account_id: l.account_id, debit: l.debit || null, credit: l.credit || null, branch_id: l.branch_id || null })) })).journal.open)}>
      <div className={s.form}>
        {opening && <Text>Balances from your old books on the go-live date (not customers, suppliers or stock — enter those on their own screens). Any difference goes to “Opening balances”.</Text>}
        <div className={s.row2}>
          <Field label="Date" required><Input type="date" value={date} max={today} onChange={(_, d) => setDate(d.value)} /></Field>
          {!opening && <Field label="Description" required><Input value={memo} maxLength={255} onChange={(_, d) => setMemo(d.value)} /></Field>}
        </div>
        <Table size="small" aria-label="Journal lines">
          <TableHeader><TableRow><TableHeaderCell>Account</TableHeaderCell><TableHeaderCell>Debit</TableHeaderCell><TableHeaderCell>Credit</TableHeaderCell>
            <TableHeaderCell>Branch</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {lines.map((l, i) => (
              <TableRow key={i}>
                <TableCell style={{ minWidth: 260 }}><AccountPicker accounts={options.accounts} value={l.account_id} onChange={(v) => put(i, { account_id: v })} /></TableCell>
                <TableCell><Input className={s.num} type="number" min={0} aria-label={`Debit, line ${i + 1}`} value={l.debit} onChange={(_, d) => put(i, { debit: d.value, credit: d.value ? '' : l.credit })} /></TableCell>
                <TableCell><Input className={s.num} type="number" min={0} aria-label={`Credit, line ${i + 1}`} value={l.credit} onChange={(_, d) => put(i, { credit: d.value, debit: d.value ? '' : l.debit })} /></TableCell>
                <TableCell>
                  <Dropdown aria-label={`Branch, line ${i + 1}`} style={{ minWidth: 150 }} value={options.branches.find((b) => b.id === l.branch_id)?.label ?? ''} selectedOptions={[l.branch_id]}
                    onOptionSelect={(_, d) => put(i, { branch_id: d.optionValue ?? '' })}>
                    {options.branches.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
                  </Dropdown>
                </TableCell>
                <TableCell><Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove line ${i + 1}`} disabled={lines.length <= (opening ? 1 : 2)} onClick={() => setLines(lines.filter((_, j) => j !== i))} /></TableCell>
              </TableRow>
            ))}
            <TableRow>
              <TableCell><Button size="small" icon={<AddRegular />} onClick={() => setLines([...lines, blank()])}>Add line</Button></TableCell>
              <TableCell className={s.end}><Text weight="semibold">{money(debits, currency, 2)}</Text></TableCell>
              <TableCell className={s.end}><Text weight="semibold">{money(credits, currency, 2)}</Text></TableCell>
              <TableCell colSpan={2}>{!opening && debits !== credits && <Text style={{ color: 'var(--zx-bad)' }}>Debits and credits differ by {money(Math.abs(debits - credits), currency, 2)}.</Text>}</TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </Ask>
  )
}

/** Choose an account by code or name, from the posting accounts (control accounts are posted by their own screens). */
function AccountPicker({ accounts, value, onChange }: { accounts: { id: string; label: string }[]; value: string; onChange: (id: string) => void }) {
  const chosen = accounts.find((a) => a.id === value)?.label ?? ''
  const [text, setText] = useState(chosen)
  useEffect(() => setText(chosen), [chosen])
  const shown = useMemo(() => {
    const t = text.trim().toLowerCase()
    return (t && t !== chosen.toLowerCase() ? accounts.filter((a) => a.label.toLowerCase().includes(t)) : accounts).slice(0, 60)
  }, [text, accounts, chosen])
  return (
    <Combobox aria-label="Account" style={{ width: '100%', minWidth: 0 }} value={text} selectedOptions={value ? [value] : []} placeholder="Code or name"
      onChange={(e) => setText(e.target.value)} onBlur={() => setText(chosen)} onOptionSelect={(_, d) => { onChange(d.optionValue ?? ''); setText(accounts.find((a) => a.id === d.optionValue)?.label ?? '') }}>
      {shown.map((a) => <Option key={a.id} value={a.id}>{a.label}</Option>)}
      {shown.length === 0 && <Option disabled value="">Nothing matches</Option>}
    </Combobox>
  )
}
