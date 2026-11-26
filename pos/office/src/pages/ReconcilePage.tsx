import {
  Accordion, AccordionHeader, AccordionItem, AccordionPanel, Badge, Button, Checkbox, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner,
  Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowLeftRegular, LinkRegular, SparkleRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type BankForms, type Reconcile, type RecLine } from '../api'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { str, useQueryParams } from '../list/query'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  grow: { flex: '1 1 auto' },
  sum: { display: 'flex', flexWrap: 'wrap', gap: '10px 22px', alignItems: 'flex-end', marginBottom: '12px', backgroundColor: tokens.colorNeutralBackground1,
    borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`, boxShadow: tokens.shadow4, padding: '12px 16px' },
  fig: { display: 'flex', flexDirection: 'column' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 460px), 1fr))', gap: '12px' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '10px 12px', overflowX: 'auto', marginBottom: '12px' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  sug: { backgroundColor: 'rgba(250, 204, 21, .14)' },
  actions: { display: 'flex', flexWrap: 'wrap', gap: '8px', alignItems: 'flex-end', marginBottom: '12px' },
})

const END = { style: { justifyContent: 'flex-end' } }

/**
 * Reconcile one bank, MoMo or cash account (release 8): the books against the statement at a date (what the statement
 * should show and the difference), the statement lines and the ledger lines still to match side by side (suggestions
 * marked). Tick lines on both sides and Match; Match suggestions does the obvious ones; a bank charge or interest is
 * posted from its statement line; a line that is not money (a test credit) is ignored with a reason; ledger lines from
 * before the first imported statement are cleared as earlier. Recent matches can be undone.
 */
export function ReconcilePage({ id }: { id: string }) {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<Reconcile | null>(null)
  const [forms, setForms] = useState<BankForms | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [nonce, setNonce] = useState(0)
  const [busy, setBusy] = useState(false)
  const [statement, setStatement] = useState<string[]>([])
  const [ledger, setLedger] = useState<string[]>([])
  const [postTo, setPostTo] = useState('')
  const [memo, setMemo] = useState('')
  const [reason, setReason] = useState('')
  const asAt = str(params, 'as_at')
  useEffect(() => { api.bankForms().then(setForms).catch(() => undefined) }, [])
  useEffect(() => { api.reconcile(id, asAt).then((d) => { setData(d); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [id, asAt, nonce])

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Reading the statement and the books…" />}</div>
  const m = (v: number | null) => (v === null ? '—' : money(v, data.currency, data.exponent))
  const sum = data.summary
  const step = async (name: Parameters<typeof api.reconcileStep>[1], body: Record<string, unknown> = {}) => {
    setBusy(true); setError(''); setDone('')
    try {
      setDone((await api.reconcileStep(id, name, body)).message)
      setStatement([]); setLedger([]); setPostTo(''); setMemo(''); setReason(''); setNonce((n) => n + 1)
    } catch (e) { setError(e instanceof Error ? e.message : String(e)) }
    setBusy(false)
  }
  const ticked = (list: RecLine[], picked: string[]) => list.filter((l) => picked.includes(l.id)).reduce((t, l) => t + l.amount, 0)
  const lines = (label: string, list: RecLine[], picked: string[], setPicked: (v: string[]) => void, empty: string, isLedger: boolean) => (
    <section className={s.panel} aria-label={label}>
      <Text weight="semibold" size={400} block>{label}</Text>
      {picked.length > 0 && <Text size={200} className={s.muted}>{picked.length} ticked · {m(ticked(list, picked))}</Text>}
      <Table size="small" aria-label={label}>
        <TableHeader><TableRow><TableHeaderCell style={{ width: 36 }} /><TableHeaderCell style={{ width: 110 }}>Date</TableHeaderCell>
          <TableHeaderCell>{isLedger ? 'Journal' : 'Description'}</TableHeaderCell><TableHeaderCell className={s.end} button={END} style={{ width: 130 }}>Amount</TableHeaderCell></TableRow></TableHeader>
        <TableBody>
          {list.length === 0 && <TableRow><TableCell colSpan={4}><Text className={s.muted}>{empty}</Text></TableCell></TableRow>}
          {list.map((l) => (
            <TableRow key={l.id} className={l.suggested ? s.sug : undefined}>
              <TableCell><Checkbox checked={picked.includes(l.id)} aria-label={`${isLedger ? 'Ledger' : 'Statement'} ${l.date} ${m(l.amount)}`}
                onChange={(_, d) => setPicked(d.checked ? [...picked, l.id] : picked.filter((x) => x !== l.id))} /></TableCell>
              <TableCell>{l.date}</TableCell>
              <TableCell>{isLedger ? <>{l.number} <Text className={s.muted}>{l.memo}</Text></> : <>{l.description}{l.reference && <Text className={s.muted}> · {l.reference}</Text>}</>}
                {l.suggested && <Badge appearance="tint" color="warning" size="small" style={{ marginLeft: 6 }}>suggested</Badge>}</TableCell>
              <TableCell className={s.end}>{m(l.amount)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </section>
  )
  const fig = (label: string, value: string, tone?: string) => <div className={s.fig}><Text size={200} className={s.muted}>{label}</Text><Text weight="semibold" size={400} style={tone ? { color: tone } : undefined}>{value}</Text></div>

  return (
    <div className={page.page}>
      <PageHead title={`Reconcile ${data.bank.name}`} hint={`The books (${data.bank.ledger ?? ''}) against the bank's statement. Tick matching lines on both sides, or let the suggestions match themselves.`}
        />
      <div className={s.bar}><Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/bank-accounts')}>Bank & cash</Button></div>
      <div className={s.sum} role="group" aria-label="Reconciliation">
        <Field label="As at"><Input type="date" value={data.as_at} onChange={(_, d) => d.value && set({ as_at: d.value })} /></Field>
        {fig('Ledger balance', m(sum.ledger))}
        {fig('− In the books, not yet on the statement', m(sum.outstanding))}
        {fig('+ On the statement, not yet in the books', m(sum.unrecorded))}
        {fig('= Statement should show', m(sum.expected_statement))}
        {fig('Statement shows', sum.statement === null ? '— (no balance column)' : m(sum.statement))}
        {sum.difference !== null && fig('Difference', m(sum.difference), sum.difference ? 'var(--zx-bad)' : 'var(--zx-good)')}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <div className={s.actions}>
        <Button appearance="primary" icon={<LinkRegular />} disabled={busy || statement.length === 0 || ledger.length === 0} onClick={() => step('match', { statement, ledger })}>Match ticked lines</Button>
        <Button icon={<SparkleRegular />} disabled={busy || data.suggestions === 0} onClick={() => step('auto')}>Match suggestions ({data.suggestions})</Button>
        <Button disabled={busy || ledger.length === 0 || statement.length > 0} onClick={() => step('earlier', { ledger })}>Ticked ledger lines were on an earlier statement</Button>
      </div>
      <div className={s.actions}>
        <Field label="Post the ticked statement line to">
          <Dropdown style={{ minWidth: 260 }} value={forms?.post_accounts.find((a) => a.id === postTo)?.label ?? ''} placeholder="Account (e.g. bank charges)" selectedOptions={[postTo]}
            onOptionSelect={(_, d) => setPostTo(d.optionValue ?? '')}>
            {(forms?.post_accounts ?? []).map((a) => <Option key={a.id} value={a.id}>{a.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Description (optional)"><Input value={memo} maxLength={255} onChange={(_, d) => setMemo(d.value)} /></Field>
        <Button disabled={busy || statement.length !== 1 || !postTo} onClick={() => step('post', { statement, account_id: postTo, memo: memo || null })}>Post</Button>
        <Field label="Reason to ignore"><Input value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} /></Field>
        <Button disabled={busy || statement.length === 0 || reason.trim() === ''} style={{ color: 'var(--zx-bad)' }} onClick={() => step('ignore', { statement, reason })}>Ignore</Button>
      </div>
      <div className={s.grid}>
        {lines('Statement lines to match', data.statement, statement, setStatement, 'Nothing left to match. Import a statement to continue.', false)}
        {lines('Ledger lines not yet on a statement', data.ledger, ledger, setLedger, 'Every ledger line is matched.', true)}
      </div>
      {data.matches.length > 0 && (
        <Accordion collapsible>
          <AccordionItem value="recent">
            <AccordionHeader>Recent matches</AccordionHeader>
            <AccordionPanel>
              <Table size="small" aria-label="Recent matches">
                <TableBody>{data.matches.map((x) => (
                  <TableRow key={x.id}>
                    <TableCell>{x.at} · {x.by}</TableCell><TableCell className={s.end}>{m(x.amount)}</TableCell>
                    <TableCell><Button size="small" appearance="subtle" disabled={busy} style={{ color: 'var(--zx-bad)' }} onClick={() => step('unmatch', { match_id: x.id })}>Undo</Button></TableCell>
                  </TableRow>
                ))}</TableBody>
              </Table>
            </AccordionPanel>
          </AccordionItem>
        </Accordion>
      )}
    </div>
  )
}
