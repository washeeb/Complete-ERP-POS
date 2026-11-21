import {
  Button, Dropdown, Field, Input, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { CheckmarkCircleRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import { money } from '../home/format'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { BranchPick, dayLabel, END, Panel, useCashStyles } from './CashParts'

const useStyles = makeStyles({
  list: { display: 'grid', gap: '8px', marginBottom: '12px' },
  item: { display: 'flex', gap: '12px', alignItems: 'flex-start', padding: '12px 14px', borderRadius: tokens.borderRadiusLarge,
    border: `1px solid ${tokens.colorNeutralStroke2}`, backgroundColor: tokens.colorNeutralBackground1 },
  fail: { border: '1px solid var(--zx-bad)' },
  mark: { width: '26px', height: '26px', flex: 'none', borderRadius: '50%', display: 'grid', placeItems: 'center', fontWeight: tokens.fontWeightBold, color: '#fff' },
  grid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '12px' },
  wide: { gridColumn: '1 / -1' },
})

const MARK = { ok: ['✓', 'var(--zx-good)'], warn: ['!', 'var(--zx-warn)'], fail: ['✗', 'var(--zx-bad)'] } as const
const EMPTY = { banked: '', bank_ref: '', safe: '', bank_account: '', note: '' }

/**
 * Close the day: before leaving, one screen per branch says whether the day is wrapped up (tills closed, cash counted
 * and handed over, sales received, approvals, backup…), then closes it with the cash taken to the bank (and its slip)
 * or kept in the safe. Something not right can be fixed and the day closed again; the recent closes are below.
 */
export function CloseDayPage() {
  const page = usePageStyles()
  const c = useCashStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.closeDay, serialize(params), nonce)
  const [form, setForm] = useState(EMPTY)
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const [fail, setFail] = useState('')
  const [done, setDone] = useState('')

  useEffect(() => { if (data) { setForm(data.form); setDirty(false) } }, [data])
  useEffect(() => {
    if (!dirty) return
    const warn = (e: BeforeUnloadEvent) => { e.preventDefault() }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the day…" />}</div>
  const m = (v: number) => money(v, data.currency, data.exponent)
  const problems = data.checks.filter((x) => x.status === 'fail').length
  const put = (k: keyof typeof EMPTY, v: string) => { setForm({ ...form, [k]: v }); setDirty(true); setDone(''); setFail('') }
  const go = (q: { branch?: string; date?: string }) => { if (dirty && !window.confirm('Leave without closing the day?')) return; setDone(''); setFail(''); set(q) }
  const close = () => {
    setBusy(true)
    setFail('')
    api.closeTheDay({ branch: data.branch ?? '', date: data.date, ...form }).then((r: { message: string }) => { setDone(r.message); setDirty(false); setNonce((n) => n + 1) })
      .catch((e: unknown) => setFail(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <PageHead title="Close the day" hint="Before leaving: is the day wrapped up? Then say where the cash went and close it." classic="/admin/cash/close-day" />
      <div className={c.bar}>
        <BranchPick branches={data.branches} value={data.branch} onChange={(branch) => go({ branch })} />
        <Field label="Day" size="small"><Input size="small" type="date" max={data.today} value={str(params, 'date') || data.date} onChange={(_, d) => go({ date: d.value })} /></Field>
        {loading && <Spinner size="tiny" />}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {!data.branch && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>There is no branch you may close.</MessageBarBody></MessageBar>}
      {data.closed && (
        <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>
          Closed by {data.closed.by} at {when(data.closed.at)}{data.closed.problems ? ` — with ${data.closed.problems} problem(s): ${data.closed.note ?? ''}` : ''}. You can close it again after fixing something.
        </MessageBarBody></MessageBar>
      )}

      <div className={s.list}>
        {data.checks.map((x) => (
          <div key={x.key} className={x.status === 'fail' ? `${s.item} ${s.fail}` : s.item}>
            <span className={s.mark} style={{ backgroundColor: MARK[x.status][1] }} aria-hidden="true">{MARK[x.status][0]}</span>
            <span><Text weight="semibold">{x.label}</Text><Text block size={200} className={c.muted}>{x.detail}</Text></span>
          </div>
        ))}
      </div>

      {data.branch && (
        <Panel title="Close the day" hint={`Cash counted at the till closes: ${m(data.cash_counted)}. Say where it went.`}>
          <div className={s.grid}>
            <Field label="Taken to the bank"><Input inputMode="decimal" placeholder="0.00" value={form.banked} onChange={(_, d) => put('banked', d.value)} /></Field>
            <Field label="Deposit slip no."><Input maxLength={60} value={form.bank_ref} onChange={(_, d) => put('bank_ref', d.value)} /></Field>
            {data.deposits_here && (
              <Field label="Into bank account">
                <Dropdown style={{ minWidth: 0, width: '100%' }} value={data.bank_accounts.find((b) => b.id === form.bank_account)?.label ?? ''} selectedOptions={form.bank_account ? [form.bank_account] : []}
                  placeholder="— choose —" onOptionSelect={(_, d) => put('bank_account', d.optionValue ?? '')}>
                  {data.bank_accounts.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
                </Dropdown>
              </Field>
            )}
            <Field label="Kept in the safe"><Input inputMode="decimal" placeholder="0.00" value={form.safe} onChange={(_, d) => put('safe', d.value)} /></Field>
            <Field className={s.wide} label={problems ? `Why the day is closed with ${problems} problem(s)` : 'Note (optional)'} required={problems > 0}>
              <Input maxLength={500} value={form.note} onChange={(_, d) => put('note', d.value)} />
            </Field>
            {fail && <Text block className={`${s.wide} ${c.bad}`}>{fail}</Text>}
            {done && <MessageBar className={s.wide} intent="success"><MessageBarBody>{done}</MessageBarBody></MessageBar>}
            <div className={s.wide}>
              <Button appearance="primary" icon={<CheckmarkCircleRegular />} disabled={busy || (problems > 0 && form.note.trim() === '')} onClick={close}
                style={problems ? { backgroundColor: 'var(--zx-warn)' } : undefined}>
                {busy ? 'Closing…' : data.closed ? 'Close the day again' : 'Close the day'}
              </Button>
            </div>
          </div>
        </Panel>
      )}

      {data.recent.length > 0 && (
        <Panel title="Recent days">
          <Table aria-label="Recent days" size="small" style={{ minWidth: 900 }}>
            <TableHeader><TableRow>
              <TableHeaderCell>Day</TableHeaderCell><TableHeaderCell>Closed by</TableHeaderCell>
              <TableHeaderCell button={END}>Cash counted</TableHeaderCell><TableHeaderCell button={END}>Over / short</TableHeaderCell>
              <TableHeaderCell button={END}>Banked</TableHeaderCell><TableHeaderCell>Slip</TableHeaderCell><TableHeaderCell>Bank statement</TableHeaderCell>
              <TableHeaderCell button={END}>Safe</TableHeaderCell><TableHeaderCell>Problems</TableHeaderCell>
            </TableRow></TableHeader>
            <TableBody>
              {data.recent.map((r) => (
                <TableRow key={r.date}>
                  <TableCell>{dayLabel(r.date)}</TableCell><TableCell>{r.by}</TableCell>
                  <TableCell className={c.end}>{m(r.cash_counted)}</TableCell>
                  <TableCell className={`${c.end} ${r.over_short < 0 ? c.bad : ''}`}>{m(r.over_short)}</TableCell>
                  <TableCell className={c.end}>{r.banked !== null ? m(r.banked) : '—'}</TableCell><TableCell>{r.bank_ref ?? '—'}</TableCell>
                  <TableCell>{r.statement}</TableCell>
                  <TableCell className={c.end}>{r.safe !== null ? m(r.safe) : '—'}</TableCell>
                  <TableCell>{r.problems ? `${r.problems}: ${r.note ?? ''}` : '—'}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Panel>
      )}
    </div>
  )
}

