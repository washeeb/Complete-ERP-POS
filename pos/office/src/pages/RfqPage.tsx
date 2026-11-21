import {
  Button, Input, Link, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens, Field,
} from '@fluentui/react-components'
import { ArrowLeftRegular, DismissRegular, EditRegular, LinkRegular, LockOpenRegular, PersonAddRegular, SendRegular, TrophyRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type RfqDetail } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField } from '../form/Fields'
import { money } from '../home/format'
import { usePageStyles, when } from '../list/ListParts'
import { navigate } from '../nav'
import { day, END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Dialog = 'send' | 'links' | 'invite' | 'quote' | 'close' | 'award' | 'cancel' | null

/**
 * One request for quotation (part 18): what is asked, of whom, and their answers; once unsealed (the closing time
 * passed, or Close and compare), the totals and the side-by-side comparison (★ cheapest for the line, score out of 100:
 * price 50, delivery 20, credit 10, past performance 20). Send to a supplier (each their own link), Supplier links, Ask
 * another supplier, Enter a quote (one that came by paper, phone or e-mail), Close and compare, Award (each line to a
 * supplier: draft purchase orders; not the cheapest needs the reason), Cancel.
 */
export function RfqPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [r, setR] = useState<RfqDetail | null>(null)
  const [error, setError] = useState('')
  const [dialog, setDialog] = useState<Dialog>(null)
  const [v, setV] = useState({ invitation: '', channel: 'email', to: '', note: '', supplier_id: '', from: '', delivery_days: '', payment_days: '', valid_until: '', reason: '' })
  const [prices, setPrices] = useState<Record<string, { price: string; qty: string }>>({})
  const [awards, setAwards] = useState<Record<string, string>>({})
  const [done, setDone] = useState<{ text: string; open?: string | null } | null>(null)
  const load = useCallback(() => {
    api.rfq(id).then((x) => { setR(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !r) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!r) return <div className={page.page}><Spinner label="Opening the request…" /></div>
  const m = (x: number) => money(x, r.currency, r.exponent)
  const a = r.actions
  const contact = (inv: string, channel: string) => {
    const i = r.invitations.find((x) => x.id === inv)
    return (channel === 'email' ? i?.email : i?.phone) ?? ''
  }
  const open = (d: Dialog) => {
    setDone(null)
    setV({ invitation: '', channel: a.send?.channels[0]?.id ?? 'email', to: '', note: '', supplier_id: '', from: '', delivery_days: '', payment_days: '', valid_until: '', reason: '' })
    setPrices(Object.fromEntries(r.lines.map((l) => [l.id, { price: '', qty: l.plain_qty }])))
    setAwards(Object.fromEntries((a.award?.lines ?? []).map((l) => [l.id, l.suggested ?? ''])))
    setDialog(d)
  }
  const run = (action: 'send' | 'invite' | 'quote' | 'close' | 'award' | 'cancel', body: Record<string, unknown> = {}) => api.rfqAction(r.id, action, body).then((x) => {
    if (action === 'award' && x.open) { navigate(x.open); return }
    setR(x.rfq)
    setDialog(null)
    setDone({ text: x.message, open: action === 'send' ? x.open : null })
  })
  const notCheapest = (a.award?.lines ?? []).some((l) => awards[l.id] && l.options[0] && !l.options.find((o) => o.id === awards[l.id])?.label.includes('★'))

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/rfqs'))}>Requests for quotation</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{r.number}</span><ToneBadge tone={r.tone} label={r.status_label} /></h1>
        <div className={s.actions}>
          {a.send && <Button appearance="primary" icon={<SendRegular />} onClick={() => open('send')}>Send to a supplier</Button>}
          {a.links && <Button icon={<LinkRegular />} onClick={() => open('links')}>Supplier links</Button>}
          {a.invite && <Button icon={<PersonAddRegular />} onClick={() => open('invite')}>Ask another supplier</Button>}
          {a.enter_quote && <Button icon={<EditRegular />} onClick={() => open('quote')}>Enter a quote</Button>}
          {a.close && <Button icon={<LockOpenRegular />} onClick={() => open('close')}>Close and compare</Button>}
          {a.award && <Button appearance="primary" icon={<TrophyRegular />} onClick={() => open('award')}>Award</Button>}
          {a.cancel && <Button icon={<DismissRegular />} onClick={() => open('cancel')}>Cancel</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done.text} {done.open && <Link href={done.open} target="_blank" rel="noopener">Open WhatsApp</Link>}</MessageBarBody></MessageBar>}

      <Panel title="Details">
        <div className={s.facts}>
          <Fact label="Title">{r.title}</Fact>
          <Fact label="Deliver to">{r.deliver_to ?? '—'}</Fact>
          <Fact label="Quotes close">{when(r.closes_at)}</Fact>
          <Fact label="Needed by">{day(r.needed_by)}</Fact>
          <Fact label="Made by">{r.made_by ?? '—'}</Fact>
          <Fact label="From purchase request">{r.request ? <Link onClick={() => navigate(r.request!.open)}>{r.request.number}</Link> : '—'}</Fact>
          {r.awarded && <Fact label="Awarded by">{r.awarded}</Fact>}
          {r.award_note && <Fact label="Why (award)">{r.award_note}</Fact>}
          {r.cancelled_reason && <Fact label="Cancelled because">{r.cancelled_reason}</Fact>}
        </div>
        {r.terms && <Text block style={{ marginTop: 10, whiteSpace: 'pre-wrap' }}><Text weight="semibold">Told to suppliers: </Text>{r.terms}</Text>}
      </Panel>

      <Panel title="Items">
        <Table size="small" aria-label="Items" style={{ minWidth: 480 }}>
          <TableHeader><TableRow><TableHeaderCell>#</TableHeaderCell><TableHeaderCell>Item</TableHeaderCell><TableHeaderCell button={END}>Quantity</TableHeaderCell><TableHeaderCell>Note</TableHeaderCell></TableRow></TableHeader>
          <TableBody>{r.lines.map((l) => <TableRow key={l.id}><TableCell>{l.no}</TableCell><TableCell>{l.item}</TableCell><TableCell className={s.end}>{l.qty}</TableCell><TableCell>{l.note ?? ''}</TableCell></TableRow>)}</TableBody>
        </Table>
      </Panel>

      <Panel title="Suppliers asked" about={r.unsealed ? undefined : 'Prices stay sealed until the closing time, unless you close the request earlier.'}>
        <Table size="small" aria-label="Suppliers asked" style={{ minWidth: 640 }}>
          <TableHeader><TableRow>
            <TableHeaderCell>Supplier</TableHeaderCell><TableHeaderCell>Sent</TableHeaderCell><TableHeaderCell>Seen</TableHeaderCell><TableHeaderCell>Answer</TableHeaderCell>
            {r.unsealed && <><TableHeaderCell button={END}>Total before VAT</TableHeaderCell><TableHeaderCell>Order</TableHeaderCell></>}
          </TableRow></TableHeader>
          <TableBody>{r.invitations.map((i) => (
            <TableRow key={i.id}><TableCell>{i.supplier}</TableCell><TableCell>{i.sent ?? 'Not sent'}</TableCell><TableCell>{i.seen ?? '—'}</TableCell><TableCell>{i.answer ?? '—'}</TableCell>
              {r.unsealed && <><TableCell className={s.end}>{i.total !== null ? m(i.total) : ''}</TableCell>
                <TableCell>{i.order ? <Link onClick={() => navigate(i.order!.open)}>{i.order.number}</Link> : ''}</TableCell></>}</TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      {r.comparison && (
        <Panel title="Comparison" about="Score out of 100: price 50, delivery time 20, credit terms 10, past performance 20 (a new supplier gets half). ★ = cheapest for the line, ✔ = awarded.">
          <Table size="small" aria-label="Comparison" style={{ minWidth: 640 }}>
            <TableHeader><TableRow><TableHeaderCell>Item</TableHeaderCell>{r.comparison.suppliers.map((x) => <TableHeaderCell key={x.id} button={END}>{x.name}{x.best ? ' · best' : ''}</TableHeaderCell>)}</TableRow></TableHeader>
            <TableBody>
              {r.comparison.lines.map((l, i) => <TableRow key={`l${i}`}><TableCell>{l.item}</TableCell>{l.cells.map((c, j) => <TableCell key={j} className={s.end}>{c}</TableCell>)}</TableRow>)}
              {r.comparison.rows.map((row, i) => <TableRow key={`r${i}`}><TableCell><Text weight="semibold">{row.label}</Text></TableCell>{row.cells.map((c, j) => <TableCell key={j} className={s.end}>{c}</TableCell>)}</TableRow>)}
            </TableBody>
          </Table>
        </Panel>
      )}

      {dialog === 'send' && a.send && (
        <Ask title="Send to a supplier" go="Send" ready={!!v.invitation && !!v.to.trim()} onClose={() => setDialog(null)}
          run={() => run('send', { invitation: v.invitation, channel: v.channel, to: v.to, note: v.note })}>
          <div className={s.one}>
            <Text>The supplier gets a link to their own page for this request, where they enter prices. Send each supplier their own link.</Text>
            <Choose label="Supplier" required options={r.invitations.filter((i) => i.status !== 'declined').map((i) => ({ id: i.id, label: `${i.supplier} · ${i.status_label}${i.sent ? '' : ' · not sent'}` }))}
              value={v.invitation} onChange={(x) => setV({ ...v, invitation: x, to: contact(x, v.channel) })} />
            <Field label="How" required>
              <RadioGroup value={v.channel} onChange={(_, d) => setV({ ...v, channel: d.value, to: contact(v.invitation, d.value) })}>
                {a.send.channels.map((c) => <Radio key={c.id} value={c.id} label={c.label} />)}
              </RadioGroup>
            </Field>
            <TextField label={v.channel === 'email' ? 'E-mail address' : 'WhatsApp number'} required maxLength={160} value={v.to} onChange={(x) => setV({ ...v, to: x })} />
            <LongText label="Message (optional)" maxLength={500} value={v.note} onChange={(x) => setV({ ...v, note: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'links' && (
        <Ask title="Each supplier's own link" go="Close" ready onClose={() => setDialog(null)} run={async () => setDialog(null)} width={680}>
          <Text block style={{ marginBottom: 8 }}>To send another way (SMS, your own e-mail). Each link shows only that supplier's page; do not send one supplier another's link.</Text>
          <Table size="small" aria-label="Supplier links"><TableBody>{r.invitations.map((i) => (
            <TableRow key={i.id}><TableCell>{i.supplier}</TableCell><TableCell><Input readOnly value={i.link ?? ''} style={{ width: '100%' }} onFocus={(e) => e.target.select()} aria-label={`Link for ${i.supplier}`} /></TableCell></TableRow>
          ))}</TableBody></Table>
        </Ask>
      )}
      {dialog === 'invite' && a.invite && (
        <Ask title="Ask another supplier" go="Add" ready={!!v.supplier_id} onClose={() => setDialog(null)} run={() => run('invite', { supplier_id: v.supplier_id })}>
          <Choose label="Supplier" required options={a.invite.suppliers} value={v.supplier_id} onChange={(x) => setV({ ...v, supplier_id: x })} />
        </Ask>
      )}
      {dialog === 'quote' && (
        <Ask title="Enter a quote" go="Save the quote" width={720} ready={!!v.invitation && Object.values(prices).some((p) => Number(p.price) > 0)} onClose={() => setDialog(null)}
          run={() => run('quote', { invitation: v.invitation, from: v.from, lines: prices, delivery_days: v.delivery_days, payment_days: v.payment_days, valid_until: v.valid_until, note: v.note })}>
          <div className={s.one}>
            <Text>For a quote that came by paper, phone or e-mail; keep the paper. It replaces any earlier quote from that supplier. Leave a price empty when it is not offered.</Text>
            <Choose label="Supplier" required options={r.invitations.map((i) => ({ id: i.id, label: `${i.supplier} · ${i.status_label}` }))} value={v.invitation} onChange={(x) => setV({ ...v, invitation: x })} />
            <TextField label="Their person who gave the quote (optional)" maxLength={100} value={v.from} onChange={(x) => setV({ ...v, from: x })} />
            {r.lines.map((l) => (
              <div key={l.id} className={s.grid}>
                <Text style={{ alignSelf: 'end', paddingBottom: 8 }}>{l.item} ({l.qty})</Text>
                <TextField label="Unit price before VAT" type="number" min={0} step="any" value={prices[l.id]?.price ?? ''} onChange={(x) => setPrices({ ...prices, [l.id]: { ...prices[l.id], price: x } })} />
                <TextField label="They can supply" type="number" min={0} step="any" value={prices[l.id]?.qty ?? ''} onChange={(x) => setPrices({ ...prices, [l.id]: { ...prices[l.id], qty: x } })} />
              </div>
            ))}
            <TextField label="Delivery (days)" type="number" min={0} max={365} step="1" value={v.delivery_days} onChange={(x) => setV({ ...v, delivery_days: x })} />
            <TextField label="Credit (days)" type="number" min={0} max={365} step="1" value={v.payment_days} onChange={(x) => setV({ ...v, payment_days: x })} />
            <TextField label="Prices hold until" type="date" value={v.valid_until} onChange={(x) => setV({ ...v, valid_until: x })} />
            <TextField label="Note" maxLength={500} value={v.note} onChange={(x) => setV({ ...v, note: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'close' && a.close && (
        <Ask title="Close and compare?" go="Close and compare" ready onClose={() => setDialog(null)} run={() => run('close')}>
          <Text>{a.close.early ? `Suppliers can no longer send or change prices, and the prices are opened now, before the closing time of ${when(r.closes_at)}. This is recorded.` : 'Suppliers can no longer send prices.'}</Text>
        </Ask>
      )}
      {dialog === 'award' && a.award && (
        <Ask title="Award" go="Award" width={720} ready={!notCheapest || v.note.trim() !== ''} onClose={() => setDialog(null)}
          run={() => run('award', { awards: Object.fromEntries(Object.entries(awards).map(([k, x]) => [k, x || null])), note: v.note })}>
          <div className={s.one}>
            <Text>Choose who supplies each item. Each supplier chosen gets a draft purchase order at their prices, which goes for approval as usual. Giving an item to anyone but the cheapest needs the reason.</Text>
            {a.award.lines.map((l) => (
              <Choose key={l.id} label={l.item} options={l.options} placeholder="Do not buy now" value={awards[l.id] ?? ''} onChange={(x) => setAwards({ ...awards, [l.id]: x })} />
            ))}
            <LongText label={notCheapest ? 'Why (an item is not going to the cheapest)' : 'Why (needed when an item is not going to the cheapest, or prices have expired)'} maxLength={500}
              value={v.note} onChange={(x) => setV({ ...v, note: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'cancel' && (
        <Ask title="Cancel the request?" go="Cancel the request" ready={v.reason.trim() !== ''} onClose={() => setDialog(null)} run={() => run('cancel', { reason: v.reason })}>
          <TextField label="Reason" required maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
        </Ask>
      )}
    </div>
  )
}
