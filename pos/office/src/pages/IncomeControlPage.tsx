import {
  Badge, Button, Dropdown, Field, Input, MessageBar, MessageBarBody, Option, Radio, RadioGroup, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text,
} from '@fluentui/react-components'
import { useState } from 'react'
import { api, type IncomeData } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { useList } from '../list/useList'
import { BlindCount } from './BlindCount'
import { dayLabel, END, Panel, useCashStyles } from './CashParts'

type Waiting = IncomeData['waiting'][number]
type Difference = IncomeData['differences'][number]
type Received = IncomeData['received'][number]
type Doing = { kind: 'receive'; h: Waiting } | { kind: 'decide'; v: Difference } | { kind: 'deposit'; h: Received } | null

/**
 * Income control (cash control): receive each cashier's handover and count it blind, decide each difference (short or
 * over, against the person responsible; nobody decides one on their own count), see the day's reconciliation (system →
 * teller → cashier → income controller) and record the bank deposit.
 */
export function IncomeControlPage() {
  const page = usePageStyles()
  const c = useCashStyles()
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.income, '', nonce)
  const [doing, setDoing] = useState<Doing>(null)
  const [done, setDone] = useState('')
  const [form, setForm] = useState({ treatment: 'over_short', months: '1', reason: '', bank: '', amount: '', date: '', slip: '' })

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening income control…" />}</div>
  const m = (v: number | null) => (v === null ? '—' : money(v, data.currency, data.exponent))
  const finish = (message: string) => { setDoing(null); setDone(message); setNonce((n) => n + 1) }
  const put = (k: keyof typeof form, v: string) => setForm({ ...form, [k]: v })
  const open = (d: Doing) => {
    setDone('')
    const deposit = d?.kind === 'deposit' ? ((d.h.counted ?? 0) / 10 ** data.exponent).toFixed(data.exponent) : ''
    setForm({ treatment: 'over_short', months: '1', reason: '', bank: data.banks[0]?.id ?? '', amount: deposit, date: data.today, slip: '' })
    setDoing(d)
  }
  const off = (a: number | null, b: number | null) => (a !== b ? c.warn : undefined)

  return (
    <div className={page.page}>
      <PageHead title="Income control" hint="Count each handover blind, decide the differences, and record what went to the bank." classic="/admin/income-control" />
      {loading && <Spinner size="tiny" style={{ marginBottom: 8 }} />}
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      <Panel title="Handovers to receive" hint="Count each bundle blind. If your count differs from the cashier's you are asked to count once more; the second count stands.">
        <Table aria-label="Handovers to receive" size="small" style={{ minWidth: 560 }}>
          <TableHeader><TableRow>
            <TableHeaderCell>Handover</TableHeaderCell><TableHeaderCell>Branch</TableHeaderCell><TableHeaderCell>Cashier</TableHeaderCell>
            <TableHeaderCell>Handed over</TableHeaderCell><TableHeaderCell />
          </TableRow></TableHeader>
          <TableBody>
            {data.waiting.length === 0 && <TableRow><TableCell colSpan={5}><Text className={c.muted}>Nothing waiting.</Text></TableCell></TableRow>}
            {data.waiting.map((h) => (
              <TableRow key={h.id}>
                <TableCell><Text weight="semibold">{h.number}</Text>{h.recount && <Text className={c.warn}> · count again</Text>}</TableCell>
                <TableCell>{h.branch}</TableCell><TableCell>{h.cashier}</TableCell><TableCell>{when(h.at)}</TableCell>
                <TableCell className={c.act}>{data.can.receive && <Button size="small" appearance="primary" onClick={() => open({ kind: 'receive', h })}>Receive and count</Button>}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      <Panel title="Differences to decide" hint="Short or over at a count, against the person responsible. Nobody decides a difference on their own count.">
        <Table aria-label="Differences to decide" size="small" style={{ minWidth: 640 }}>
          <TableHeader><TableRow>
            <TableHeaderCell>When</TableHeaderCell><TableHeaderCell>Branch</TableHeaderCell><TableHeaderCell>Where</TableHeaderCell><TableHeaderCell>Person</TableHeaderCell>
            <TableHeaderCell button={END}>Amount</TableHeaderCell><TableHeaderCell />
          </TableRow></TableHeader>
          <TableBody>
            {data.differences.length === 0 && <TableRow><TableCell colSpan={6}><Text className={c.muted}>No open differences.</Text></TableCell></TableRow>}
            {data.differences.map((v) => (
              <TableRow key={v.id}>
                <TableCell>{when(v.at)}</TableCell><TableCell>{v.branch}</TableCell><TableCell>{v.stage}</TableCell><TableCell>{v.person}</TableCell>
                <TableCell className={`${c.end} ${v.amount < 0 ? c.bad : c.good}`}>{v.amount < 0 ? 'short' : 'over'} {m(Math.abs(v.amount))}</TableCell>
                <TableCell className={c.act}>{data.can.decide && <Button size="small" onClick={() => open({ kind: 'decide', v })}>Decide</Button>}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      <Panel title="Received: reconciliation" hint="For each vault: what the system expected, what the teller counted, what the cashier counted; and for the bundle, what you counted.">
        {data.received.length === 0 && <Text className={c.muted}>Nothing received yet.</Text>}
        {data.received.map((h) => (
          <div key={h.id} style={{ marginBottom: 18 }}>
            <div className={c.panelHead} style={{ alignItems: 'center', marginBottom: 6 }}>
              <Text><Text weight="semibold">{h.number}</Text> · {h.branch} · {h.cashier} → {h.controller} · {when(h.at)}</Text>
              {h.deposited ? <Badge appearance="tint" color="success">Deposited</Badge>
                : data.can.deposit && <Button size="small" onClick={() => open({ kind: 'deposit', h })}>Record deposit</Button>}
            </div>
            <Table aria-label={`Handover ${h.number}`} size="small" style={{ minWidth: 640 }}>
              <TableHeader><TableRow>
                <TableHeaderCell>Vault</TableHeaderCell><TableHeaderCell>Teller</TableHeaderCell><TableHeaderCell button={END}>System</TableHeaderCell>
                <TableHeaderCell button={END}>Teller</TableHeaderCell><TableHeaderCell button={END}>Cashier</TableHeaderCell>
              </TableRow></TableHeader>
              <TableBody>
                {h.sessions.map((x, i) => (
                  <TableRow key={i}>
                    <TableCell>{x.vault} · {dayLabel(x.date)}</TableCell><TableCell>{x.teller}</TableCell>
                    <TableCell className={c.end}>{m(x.expected)}</TableCell>
                    <TableCell className={`${c.end} ${off(x.teller_count, x.expected) ?? ''}`}>{m(x.teller_count)}</TableCell>
                    <TableCell className={`${c.end} ${off(x.cashier_count, x.expected) ?? ''}`}>{m(x.cashier_count)}</TableCell>
                  </TableRow>
                ))}
                <TableRow>
                  <TableCell colSpan={4}><Text weight="semibold">Bundle: declared by the cashier {m(h.declared)}; you counted</Text></TableCell>
                  <TableCell className={`${c.end} ${off(h.counted, h.declared) ?? ''}`}><Text weight="semibold">{m(h.counted)}</Text></TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
        ))}
      </Panel>

      {doing?.kind === 'receive' && (
        <BlindCount title={`Count handover ${doing.h.number}`} currency={data.currency} exponent={data.exponent} denominations={data.denominations} recount={doing.h.recount}
          intro="Count all the cash in the bundle. Your count is compared with the cashier's afterwards."
          recountText="Your count does not match the cashier's. Count the bundle again from the start and enter it once more. The second count stands."
          submit={(body) => api.receiveHandover(doing.h.id, body)}
          onDone={() => finish('Handover received.')} onCancel={() => { setDoing(null); setNonce((n) => n + 1) }} />
      )}
      {doing?.kind === 'decide' && (
        <Ask title={`${doing.v.amount < 0 ? 'Short' : 'Over'} ${m(Math.abs(doing.v.amount))} · ${doing.v.person}`} go="Decide"
          ready={form.reason.trim() !== '' && (form.treatment !== 'charge_staff' || Number(form.months) >= 1)} onClose={() => setDoing(null)}
          run={async () => finish((await api.decideDifference(doing.v.id, { treatment: form.treatment, months: form.months, reason: form.reason })).message)}>
          <div className={c.form}>
            <Field label="Treat it as" required>
              <RadioGroup value={form.treatment} onChange={(_, d) => put('treatment', d.value)}>
                {doing.v.amount < 0
                  ? data.treatments.map((t) => <Radio key={t.id} value={t.id} label={t.id === 'charge_staff'
                    ? <>{t.label}<Text block size={200} className={c.muted}>Recovered from the person's pay as a staff loan (needs their employee record under HR).</Text></> : t.label} />)
                  : <Radio value="over_short" label="Cash over (kept by the business)" />}
              </RadioGroup>
            </Field>
            {form.treatment === 'charge_staff' && (
              <Field label="Recover over (months)"><Input type="number" min={1} max={12} step={1} value={form.months} onChange={(_, d) => put('months', d.value)} /></Field>
            )}
            <Field label="Reason" required><Input maxLength={255} value={form.reason} onChange={(_, d) => put('reason', d.value)} /></Field>
          </div>
        </Ask>
      )}
      {doing?.kind === 'deposit' && (
        <Ask title={`Record the deposit: ${doing.h.number}`} go="Record deposit" ready={form.bank !== '' && form.amount !== '' && form.date !== '' && form.slip.trim() !== ''}
          onClose={() => setDoing(null)}
          run={async () => finish((await api.recordDeposit(doing.h.id, { bank: form.bank, amount: form.amount, date: form.date, slip: form.slip })).message)}>
          <div className={c.form}>
            <Field label="Bank account" required>
              <Dropdown value={data.banks.find((b) => b.id === form.bank)?.label ?? ''} selectedOptions={form.bank ? [form.bank] : []} placeholder="Choose the bank account"
                onOptionSelect={(_, d) => put('bank', d.optionValue ?? '')}>
                {data.banks.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
              </Dropdown>
            </Field>
            <Field label="Amount deposited" required><Input type="number" min={0} step="any" value={form.amount} onChange={(_, d) => put('amount', d.value)} /></Field>
            <Field label="Date" required><Input type="date" max={data.today} value={form.date} onChange={(_, d) => put('date', d.value)} /></Field>
            <Field label="Deposit slip number" required><Input maxLength={40} value={form.slip} onChange={(_, d) => put('slip', d.value)} /></Field>
          </div>
        </Ask>
      )}
    </div>
  )
}
