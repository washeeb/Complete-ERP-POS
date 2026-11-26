import {
  Badge, Button, Dropdown, Field, Input, Link, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowDownloadRegular, ArrowLeftRegular, MoneyRegular, SendRegular, DismissCircleRegular } from '@fluentui/react-icons'
import { useEffect, useState, type ReactNode } from 'react'
import { api, type NhisClaimDetail } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { Pager } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { navigate } from '../nav'
import { CLAIM_COLOUR, day } from './NhisClaimsPage'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '12px 14px', marginBottom: '12px' },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', gap: '12px 16px',
    '@media (max-width: 900px)': { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' }, '@media (max-width: 520px)': { gridTemplateColumns: '1fr' } },
  scroll: { overflowX: 'auto' },
  muted: { color: tokens.colorNeutralForeground3 },
  end: { textAlign: 'right' },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

type Step = 'submit' | 'settle' | 'withdraw'

/** One NHIS claim: what was claimed and paid, who did what, the sales on it, and the next step (send, record the payment, withdraw). */
export function NhisClaimPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useStyles()
  const [c, setC] = useState<NhisClaimDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [step, setStep] = useState<Step | null>(null)
  const [lines, setLines] = useState({ page: 1, per: 25 })
  const [nonce, setNonce] = useState(0)
  useEffect(() => {
    api.nhisClaim(id, `page=${lines.page}&per=${lines.per}`).then((x) => { setC(x); setError(''); onTitle(`NHIS claim ${x.number}`) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, lines, nonce, onTitle])
  if (!c) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Reading the claim…" />}</div>
  const m = (v: number | null) => (v === null ? '—' : money(v, c.currency, c.exponent))
  const fact = (label: string, value: ReactNode) => <div><Text block size={200} className={s.muted}>{label}</Text><Text block>{value || '—'}</Text></div>
  const finish = (msg: string) => { setStep(null); setDone(msg); setNonce((n) => n + 1) }
  return (
    <div className={page.page}>
      <PageHead title={`NHIS claim ${c.number}`} hint="What was claimed from the NHIA, what it paid, and the sales on the claim." />
      <div className={s.bar}>
        <Button icon={<ArrowLeftRegular />} onClick={() => navigate('/office/nhis-claims')}>NHIS claims</Button>
        <span className={s.grow} />
        {c.download && <Button icon={<ArrowDownloadRegular />} onClick={() => { window.location.href = c.download! }}>Download the list (CSV)</Button>}
        {c.status === 'open' && <Button icon={<DismissCircleRegular />} onClick={() => { setDone(''); setStep('withdraw') }}>Withdraw</Button>}
        {c.status === 'open' && <Button appearance="primary" icon={<SendRegular />} onClick={() => { setDone(''); setStep('submit') }}>Mark as sent to the NHIA</Button>}
        {c.status === 'submitted' && <Button appearance="primary" icon={<MoneyRegular />} onClick={() => { setDone(''); setStep('settle') }}>Record what the NHIA paid</Button>}
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <section className={s.panel} aria-label="Claim">
        <div className={s.facts}>
          {fact('Status', <Badge appearance="tint" color={CLAIM_COLOUR[c.status] ?? 'subtle'}>{c.status_label}</Badge>)}
          {fact('Branch', c.branch)}
          {fact('Sales', `${day(c.period_from)} to ${day(c.period_to)}`)}
          {fact('Claimed', <Text weight="semibold">{m(c.claimed)}</Text>)}
          {fact('Paid by the NHIA', m(c.paid))}
          {fact('Not paid (written off)', m(c.rejected))}
          {fact('NHIA reference', c.reference)}
          {fact('Prepared by', c.prepared_by)}
          {fact('Sent by', c.submitted_by && `${c.submitted_by}${c.submitted_at ? `, ${day(c.submitted_at)}` : ''}`)}
          {fact('Payment recorded by', c.settled_by && `${c.settled_by}${c.paid_on ? `, paid ${day(c.paid_on)}${c.paid_method ? ` (${c.paid_method})` : ''}` : ''}`)}
          {fact('Note', c.note)}
        </div>
      </section>
      <section className={s.panel} aria-label="Sales on this claim">
        <Text block weight="semibold" size={400} style={{ marginBottom: 6 }}>Sales on this claim</Text>
        {c.lines.total === 0 ? <Text className={s.muted}>{c.status === 'withdrawn' ? 'It was withdrawn: its sales went back to "not claimed yet".' : 'No sales on this claim.'}</Text> : <>
          <div className={s.scroll}>
            <Table size="small" aria-label="Sales on this claim" style={{ minWidth: 640 }}>
              <TableHeader><TableRow><TableHeaderCell>Date</TableHeaderCell><TableHeaderCell>Receipt</TableHeaderCell><TableHeaderCell>Patient</TableHeaderCell>
                <TableHeaderCell>NHIS no.</TableHeaderCell><TableHeaderCell className={s.end}>NHIS part</TableHeaderCell></TableRow></TableHeader>
              <TableBody>{c.lines.rows.map((l) => (
                <TableRow key={l.id}>
                  <TableCell>{when(l.at)}</TableCell>
                  <TableCell>{l.sale_id ? <Link onClick={() => navigate(`/office/sales/${l.sale_id}`)}>{l.receipt}</Link> : l.receipt}</TableCell>
                  <TableCell>{l.patient ?? '—'}</TableCell><TableCell>{l.nhis_no ?? '—'}</TableCell>
                  <TableCell className={s.end}>{m(l.amount)}</TableCell>
                </TableRow>
              ))}</TableBody>
            </Table>
          </div>
          <Pager page={c.lines.page} per={c.lines.per} total={c.lines.total} options={[25, 50, 100]} onPage={(p) => setLines({ ...lines, page: p })} onPer={(n) => setLines({ page: 1, per: n })} />
        </>}
      </section>
      {step && <StepDialog claim={c} step={step} onClose={() => setStep(null)} onDone={finish} />}
    </div>
  )
}

function StepDialog({ claim, step, onClose, onDone }: { claim: NhisClaimDetail; step: Step; onClose: () => void; onDone: (m: string) => void }) {
  const s = useStyles()
  const major = (claim.claimed / 10 ** claim.exponent).toFixed(claim.exponent)
  const [reference, setReference] = useState('')
  const [paid, setPaid] = useState(major)
  const [paidOn, setPaidOn] = useState(claim.today)
  const [method, setMethod] = useState('bank')
  const [note, setNote] = useState('')
  const [reason, setReason] = useState('')
  const run = async (body: Record<string, string>) => onDone((await api.nhisClaimStep(claim.id, step, body)).message)
  if (step === 'submit') return (
    <Ask title="Mark as sent to the NHIA?" go="Mark as sent" ready onClose={onClose} run={() => run({ reference })}>
      <Field label="NHIA claim reference (optional)"><Input value={reference} maxLength={80} onChange={(_, d) => setReference(d.value)} /></Field>
    </Ask>
  )
  if (step === 'withdraw') return (
    <Ask title="Withdraw this claim?" go="Withdraw" ready={reason.trim().length >= 3} onClose={onClose} run={() => run({ reason })}>
      <div className={s.form}>
        <Text>Its sales go back to "not claimed yet", for the next claim.</Text>
        <Field label="Why" required><Input value={reason} maxLength={255} onChange={(_, d) => setReason(d.value)} /></Field>
      </div>
    </Ask>
  )
  return (
    <Ask title="Record what the NHIA paid" go="Record it" ready={paid.trim() !== '' && Number(paid) >= 0 && paidOn !== '' && method !== ''} onClose={onClose}
      run={() => run({ paid, paid_on: paidOn, method, note })}>
      <div className={s.form}>
        <Text>Claimed {money(claim.claimed, claim.currency, claim.exponent)}. What the NHIA did not pay is written off as "NHIS claims not paid".</Text>
        <Field label="Amount paid" required><Input type="number" min={0} step="0.01" value={paid} onChange={(_, d) => setPaid(d.value)} /></Field>
        <Field label="Paid on" required><Input type="date" value={paidOn} max={claim.today} onChange={(_, d) => setPaidOn(d.value)} /></Field>
        <Field label="Paid by" required>
          <Dropdown value={claim.methods.find((x) => x.id === method)?.label ?? ''} selectedOptions={[method]} onOptionSelect={(_, d) => setMethod(d.optionValue ?? '')}>
            {claim.methods.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
          </Dropdown>
        </Field>
        <Field label="Note (e.g. why some was not paid)"><Input value={note} maxLength={255} onChange={(_, d) => setNote(d.value)} /></Field>
      </div>
    </Ask>
  )
}
