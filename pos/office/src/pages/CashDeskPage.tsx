import {
  Button, Checkbox, Dropdown, Field, Input, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text,
} from '@fluentui/react-components'
import { ArrowCircleRightRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type DeskRow, type Option as Choice } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { BlindCount } from './BlindCount'
import { BranchPick, dayLabel, Panel, useCashStyles } from './CashParts'

type Doing = { kind: 'issue'; row: DeskRow } | { kind: 'cancel'; row: DeskRow } | { kind: 'receive'; row: DeskRow } | { kind: 'hand-over' } | null

/**
 * The Cash desk (cash control): one tray per till and shift. The cashier issues each vault's float to its teller in the
 * morning, receives each tray once its teller has closed the till and counted it (counting blind again), and hands the
 * trays received over to the income controller.
 */
export function CashDeskPage() {
  const page = usePageStyles()
  const c = useCashStyles()
  const [params, set] = useQueryParams()
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.cashDesk, serialize(params), nonce)
  const [doing, setDoing] = useState<Doing>(null)
  const [done, setDone] = useState('')
  const [tellers, setTellers] = useState<Choice[] | null>(null)
  const [teller, setTeller] = useState('')
  const [float, setFloat] = useState('')
  const [text, setText] = useState('')
  const [ticked, setTicked] = useState<string[]>([])

  useEffect(() => {
    if (doing?.kind !== 'issue') return
    setTellers(null)
    api.cashTellers(doing.row.vault_id).then((r) => setTellers(r.tellers)).catch(() => setTellers([]))
  }, [doing])

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening the cash desk…" />}</div>
  const m = (v: number) => money(v, data.currency, data.exponent)
  const amount = (v: number) => (v / 10 ** data.exponent).toFixed(data.exponent)
  const finish = (message: string) => { setDoing(null); setDone(message); setNonce((n) => n + 1) }
  const open = (d: Doing) => {
    setDone('')
    setText('')
    setTeller('')
    if (d?.kind === 'issue') setFloat(amount(d.row.standard_float))
    if (d?.kind === 'hand-over') setTicked(data.mine.map((x) => x.id))
    setDoing(d)
  }

  const action = (r: DeskRow) => {
    const st = r.session?.status
    if (!r.session) return data.can.issue && <Button size="small" onClick={() => open({ kind: 'issue', row: r })}>Issue float</Button>
    if (st === 'issued') return data.can.issue && <Button size="small" appearance="subtle" onClick={() => open({ kind: 'cancel', row: r })}>Take back</Button>
    if (st === 'counted') return data.can.receive && <Button size="small" appearance="primary" onClick={() => open({ kind: 'receive', row: r })}>Receive and count</Button>
    return null
  }

  return (
    <div className={page.page}>
      <PageHead title="Cash desk" hint="Issue each vault's float to its teller, receive the trays the tellers have counted, and hand them over to the income controller." classic="/admin/cash-desk" />
      <div className={c.bar}>
        <BranchPick branches={data.branches} value={data.branch} onChange={(branch) => { setDone(''); set({ branch }) }} />
        {loading && <Spinner size="tiny" />}
        <span className={c.grow} />
        {data.can.hand_over && data.mine.length > 0 && (
          <Button appearance="primary" icon={<ArrowCircleRightRegular />} onClick={() => open({ kind: 'hand-over' })}>Hand over to the income controller</Button>
        )}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      <Panel title="Vaults" hint="One tray per till and shift. Issue the float in the morning; receive each tray when its teller has closed the till and counted it.">
        <Table aria-label="Vaults" size="small" style={{ minWidth: 640 }}>
          <TableHeader><TableRow>
            <TableHeaderCell>Vault</TableHeaderCell><TableHeaderCell>Teller</TableHeaderCell><TableHeaderCell>Float</TableHeaderCell>
            <TableHeaderCell>Status</TableHeaderCell><TableHeaderCell />
          </TableRow></TableHeader>
          <TableBody>
            {data.rows.length === 0 && (
              <TableRow><TableCell colSpan={5}><Text className={c.muted}>No vaults: the branch has no active tills, or no active shifts (Cash → Shifts and vaults).</Text></TableCell></TableRow>
            )}
            {data.rows.map((r) => (
              <TableRow key={r.vault_id}>
                <TableCell><Text weight="semibold">{r.vault}</Text><Text block size={200} className={c.muted}>{r.shift}</Text></TableCell>
                <TableCell>{r.session?.teller ?? '—'}</TableCell>
                <TableCell>{r.session ? m(r.session.float) : '—'}</TableCell>
                <TableCell>
                  {!r.session ? <Text className={c.muted}>No float yet</Text> : <>
                    {r.session.status_label}
                    {r.session.from_day && <Text className={c.warn}> · from {dayLabel(r.session.from_day)}</Text>}
                    {r.session.recount_asked && <Text className={c.warn}> · recount asked</Text>}
                    {r.session.cashier && <Text block size={200} className={c.muted}>by {r.session.cashier}</Text>}
                  </>}
                </TableCell>
                <TableCell className={c.act}>{action(r)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      {doing?.kind === 'issue' && (
        <Ask title={`Issue the float: ${doing.row.vault}`} go="Issue float" ready={teller !== '' && float !== ''} onClose={() => setDoing(null)}
          run={async () => finish((await api.issueFloat({ vault: doing.row.vault_id, teller, float, note: text })).message)}>
          <div className={c.form}>
            <Text>Count the float into the tray, then choose the teller who takes it. Only they can open the till on this vault.</Text>
            <Field label="Teller" required>
              {tellers === null ? <Spinner size="tiny" /> : (
                <Dropdown placeholder={tellers.length ? 'Choose the teller' : 'Nobody is set up to sell at this till'} value={tellers.find((t) => t.id === teller)?.label ?? ''}
                  selectedOptions={teller ? [teller] : []} onOptionSelect={(_, d) => setTeller(d.optionValue ?? '')}>
                  {tellers.map((t) => <Option key={t.id} value={t.id}>{t.label}</Option>)}
                </Dropdown>
              )}
            </Field>
            <Field label="Float" required><Input type="number" min={0} step="any" value={float} onChange={(_, d) => setFloat(d.value)} /></Field>
            <Field label="Note (optional)"><Input maxLength={255} value={text} onChange={(_, d) => setText(d.value)} /></Field>
          </div>
        </Ask>
      )}
      {doing?.kind === 'cancel' && doing.row.session && (
        <Ask title={`Take back the float: ${doing.row.vault}`} go="Take back" ready={text.trim() !== ''} onClose={() => setDoing(null)}
          run={async () => finish((await api.takeBackFloat(doing.row.session!.id, text)).message)}>
          <div className={c.form}>
            <Text>The teller has not opened the till: the float goes back to the safe.</Text>
            <Field label="Reason" required><Input maxLength={255} value={text} onChange={(_, d) => setText(d.value)} /></Field>
          </div>
        </Ask>
      )}
      {doing?.kind === 'receive' && doing.row.session && (
        <BlindCount title={`Count the vault: ${doing.row.vault}`} currency={data.currency} exponent={data.exponent} denominations={data.denominations}
          recount={doing.row.session.recount_asked}
          intro="Count everything in the tray, float included, before looking at anything else. Your count is compared with the teller's afterwards."
          recountText="Your count does not match the teller's. Count the tray again from the start and enter it once more. The second count stands."
          submit={(body) => api.receiveVault(doing.row.session!.id, body)}
          onDone={() => finish('Vault received.')} onCancel={() => { setDoing(null); setNonce((n) => n + 1) }} />
      )}
      {doing?.kind === 'hand-over' && (
        <Ask title="Hand over to the income controller" go="Hand over" ready={ticked.length > 0} onClose={() => setDoing(null)}
          run={async () => finish((await api.handOver({ branch: data.branch ?? '', sessions: ticked, note: text })).message)}>
          <div className={c.form}>
            <Text>Bundle the trays you received and hand them over. The income controller counts them again, blind.</Text>
            <Field label="Vaults handed over" required>
              {data.mine.map((x) => (
                <Checkbox key={x.id} label={x.label} checked={ticked.includes(x.id)}
                  onChange={(_, d) => setTicked(d.checked ? [...ticked, x.id] : ticked.filter((t) => t !== x.id))} />
              ))}
            </Field>
            <Field label="Note (optional)"><Input maxLength={255} value={text} onChange={(_, d) => setText(d.value)} /></Field>
          </div>
        </Ask>
      )}
    </div>
  )
}
