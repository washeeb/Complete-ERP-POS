import {
  Accordion, AccordionHeader, AccordionItem, AccordionPanel, Button, Combobox, Dropdown, Field, Input, Link, makeStyles, MessageBar, MessageBarBody, Option, Radio, RadioGroup,
  Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { AddCircleRegular, EditRegular, PrintRegular, TableRegular } from '@fluentui/react-icons'
import { useEffect, useState, type ReactNode } from 'react'
import { api, type BatchesView, type BatchRow } from '../api'
import { Ask } from '../form/Ask'
import { PageHead, usePageStyles } from '../list/ListParts'
import { str, useQueryParams } from '../list/query'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'flex-end', gap: '10px', flexWrap: 'wrap', marginBottom: '12px' },
  grow: { flex: '1 1 auto' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', overflowX: 'auto', marginBottom: '12px' },
  head: { display: 'block' },
  hint: { display: 'block', marginBottom: '8px', color: tokens.colorNeutralForeground3 },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
  muted: { color: tokens.colorNeutralForeground3 },
  pct: { width: '76px' },
  mono: { fontFamily: tokens.fontFamilyMonospace },
  form: { display: 'flex', flexDirection: 'column', gap: '10px' },
})

const END = { style: { justifyContent: 'flex-end' } }

/**
 * Batches & expiry (release 7): for one store — batches expiring soon with the suggested markdown (Apply, then print
 * the stickers: the till charges the sticker price and takes the stock from that batch), the markdowns on the shelf
 * now (Withdraw), what has expired (take it off the shelf; dispose or return it from Expiry & returns), and every
 * batch on hand. Correct expiry fixes a date typed wrongly; Add or move a batch makes a draft stock adjustment.
 */
const TITLES = { grocery: 'Markdowns', pharmacy: 'Near-expiry markdowns' }

/** With an industry: that industry's Near-expiry markdowns page (Grocery → Markdowns, Pharmacy → Near-expiry markdowns), without the full batch list. */
export function BatchesPage({ industry = '' }: { industry?: '' | 'grocery' | 'pharmacy' }) {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const [data, setData] = useState<BatchesView | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [nonce, setNonce] = useState(0)
  const [percent, setPercent] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState('')
  const [correcting, setCorrecting] = useState<BatchRow | null>(null)
  const [adding, setAdding] = useState(false)
  const warehouse = str(params, 'warehouse')
  useEffect(() => {
    api.batches(warehouse, industry).then((d) => { setData(d); setError(''); setPercent(Object.fromEntries(d.suggestions.map((x) => [x.batch_id, x.percent]))) })
      .catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [warehouse, nonce, industry])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Reading the batches…" /></div>
  const where = data.warehouses.find((w) => w.id === data.warehouse)?.label ?? ''
  const run = async (key: string, call: () => Promise<{ message: string }>) => {
    setBusy(key); setDone(''); setError('')
    try { setDone((await call()).message); setNonce((n) => n + 1) } catch (e) { setError(e instanceof Error ? e.message : String(e)) }
    setBusy('')
  }
  const correct = (b: BatchRow) => data.can_correct
    ? <Button size="small" appearance="subtle" icon={<EditRegular />} onClick={() => { setDone(''); setCorrecting(b) }}>Correct expiry</Button> : null
  const section = (title: string, hint: string | null, body: ReactNode) => (
    <section className={s.panel} aria-label={title}>
      <Text weight="semibold" size={400} className={s.head}>{title}</Text>
      {hint && <Text size={200} className={s.hint}>{hint}</Text>}
      {body}
    </section>
  )
  const batchTable = (rows: BatchRow[], label: string, empty: string, expired: boolean) => rows.length === 0 ? <Text className={s.muted}>{empty}</Text> : (
    <Table size="small" aria-label={label} style={{ minWidth: 560 }}>
      <TableHeader><TableRow><TableHeaderCell>Product</TableHeaderCell><TableHeaderCell>Batch</TableHeaderCell><TableHeaderCell>{expired ? 'Expired' : 'Expires'}</TableHeaderCell>
        <TableHeaderCell className={s.end} button={END}>On hand</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
      <TableBody>{rows.map((b) => (
        <TableRow key={b.batch_id}>
          <TableCell>{b.product}<Text block size={200} className={s.muted}>{b.sku}</Text></TableCell><TableCell>{b.batch_no}</TableCell>
          <TableCell>{b.expires ?? 'no date'}</TableCell><TableCell className={s.end}>{b.on_hand}</TableCell><TableCell>{correct(b)}</TableCell>
        </TableRow>
      ))}</TableBody>
    </Table>
  )

  return (
    <div className={page.page}>
      {industry ? <PageHead title={TITLES[industry]} hint={`Near-expiry markdowns and their stickers, and expired stock to take off the shelf, for the ${industry === 'grocery' ? 'grocery stores' : 'pharmacies'}.`}
        />
        : <PageHead title="Batches & expiry" hint="Batches by expiry date for one store: mark down what expires soon, take expired stock off the shelf, and correct a batch typed wrongly."
          />}
      <div className={s.bar}>
        <Field label="Warehouse">
          <Dropdown aria-label="Warehouse" value={where} selectedOptions={[data.warehouse]} style={{ minWidth: 240 }} onOptionSelect={(_, d) => d.optionValue && set({ warehouse: d.optionValue })}>
            {data.warehouses.map((w) => <Option key={w.id} value={w.id}>{w.label}</Option>)}
          </Dropdown>
        </Field>
        <span className={s.grow} />
        <Button icon={<TableRegular />} title="Stock with no batch or expiry date: fill it in on a sheet" onClick={() => navigate(data.sheet)}>Expiry sheet</Button>
        {data.can_add && <Button appearance="primary" icon={<AddCircleRegular />} onClick={() => { setDone(''); setAdding(true) }}>Add or move a batch</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      {data.can_mark_down && <>
        {section('Expiring soon — suggested markdowns', 'Apply, then print the stickers and put them on the packs. The till charges the sticker price and takes the stock from that batch.',
          data.suggestions.length === 0 ? <Text className={s.muted}>Nothing expiring soon.</Text> : (
            <Table size="small" aria-label="Expiring soon" style={{ minWidth: 620 }}>
              <TableHeader><TableRow><TableHeaderCell>Product</TableHeaderCell><TableHeaderCell>Batch</TableHeaderCell><TableHeaderCell>Expires</TableHeaderCell>
                <TableHeaderCell className={s.end} button={END}>On hand</TableHeaderCell><TableHeaderCell>% off</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
              <TableBody>{data.suggestions.map((x) => (
                <TableRow key={x.batch_id}>
                  <TableCell>{x.product}<Text block size={200} className={s.muted}>{x.sku}</Text></TableCell><TableCell>{x.batch_no}</TableCell>
                  <TableCell>{x.expires} <Text size={200} className={s.muted}>({x.days_left} d)</Text></TableCell><TableCell className={s.end}>{x.on_hand}</TableCell>
                  <TableCell><Input className={s.pct} inputMode="decimal" aria-label={`Percent off ${x.product}`} value={percent[x.batch_id] ?? ''}
                    onChange={(_, d) => setPercent({ ...percent, [x.batch_id]: d.value })} contentAfter="%" /></TableCell>
                  <TableCell><Button size="small" disabled={busy !== ''} onClick={() => run(x.batch_id, () => api.applyMarkdown(data.warehouse, x.batch_id, percent[x.batch_id] ?? '', industry))}>
                    {busy === x.batch_id ? 'Applying…' : 'Apply'}</Button></TableCell>
                </TableRow>
              ))}</TableBody>
            </Table>
          ))}
        {section('Marked down now', null, <>
          {data.labels && <Button icon={<PrintRegular />} style={{ marginBottom: 8 }} onClick={() => window.open(data.labels!, '_blank', 'noopener')}>Print all stickers</Button>}
          {data.markdowns.length === 0 ? <Text block className={s.muted}>No markdowns.</Text> : (
            <Table size="small" aria-label="Marked down now" style={{ minWidth: 620 }}>
              <TableBody>{data.markdowns.map((m) => (
                <TableRow key={m.id}>
                  <TableCell>{m.product}</TableCell><TableCell>{m.batch_no}</TableCell><TableCell>{m.percent} % off · {m.price}</TableCell>
                  <TableCell>until {m.until}</TableCell><TableCell className={s.mono}>{m.code}</TableCell>
                  <TableCell><Button size="small" appearance="subtle" disabled={busy !== ''} style={{ color: 'var(--zx-bad)' }}
                    onClick={() => run(m.id, () => api.withdrawMarkdown(m.id, industry))}>{busy === m.id ? 'Withdrawing…' : 'Withdraw'}</Button></TableCell>
                </TableRow>
              ))}</TableBody>
            </Table>
          )}
        </>)}
      </>}

      {section('Expired — take off the shelf', 'Dispose of them or return them to the supplier from Stock → Expiry & returns (approved by a second person).', <>
        {data.expiry_returns && <Link style={{ display: 'block', marginBottom: 8 }} onClick={() => navigate(data.expiry_returns!)}>Open Expiry &amp; returns</Link>}
        {batchTable(data.expired, 'Expired batches', 'Nothing expired in stock.', true)}
      </>)}

      {!industry && <Accordion collapsible>
        <AccordionItem value="all">
          <AccordionHeader>All batches on hand ({data.batches.length >= 500 ? 'the first 500, earliest expiry first' : data.batches.length.toLocaleString('en-GB')})</AccordionHeader>
          <AccordionPanel>
            {section('All batches on hand', 'Correct an expiry date typed wrongly, or add a missing one. For a wrong batch number, use Add or move a batch.',
              batchTable(data.batches, 'All batches on hand', 'No batches in this store.', false))}
          </AccordionPanel>
        </AccordionItem>
      </Accordion>}

      {correcting && <CorrectExpiry industry={industry} batch={correcting} onClose={() => setCorrecting(null)} onDone={(m) => { setCorrecting(null); setDone(m); setNonce((n) => n + 1) }} />}
      {adding && <AddBatch industry={industry} warehouse={data.warehouse} onClose={() => setAdding(false)} onDone={(open) => navigate(open)} />}
    </div>
  )
}

/** Correct a batch's expiry date (or add a missing one); the stock stays in the batch. */
function CorrectExpiry({ industry, batch, onClose, onDone }: { industry: string; batch: BatchRow; onClose: () => void; onDone: (message: string) => void }) {
  const s = useStyles()
  const [date, setDate] = useState(batch.expires_on ?? '')
  const [reason, setReason] = useState('')
  return (
    <Ask title={`Expiry of batch ${batch.batch_no}`} go="Correct" ready={date !== '' && reason.trim() !== ''} onClose={onClose}
      run={async () => onDone((await api.correctExpiry(batch.batch_id, date, reason, industry)).message)}>
      <div className={s.form}>
        <Text>For an expiry date typed wrongly, or missing. The stock stays in this batch. A wrong batch number: use Add or move a batch to move the stock to the right batch.</Text>
        <Field label="Expiry date" required><Input type="date" value={date} onChange={(_, d) => setDate(d.value)} /></Field>
        <Field label="Why" required><Input value={reason} maxLength={255} placeholder="Date mistyped at receiving" onChange={(_, d) => setReason(d.value)} /></Field>
      </div>
    </Ask>
  )
}

/** Add or move a batch: a draft stock adjustment for the store, opened next to check and post (approved above the limit). */
function AddBatch({ industry, warehouse, onClose, onDone }: { industry: string; warehouse: string; onClose: () => void; onDone: (open: string) => void }) {
  const s = useStyles()
  const [kind, setKind] = useState<'found' | 'move'>('found')
  const [text, setText] = useState('')
  const [found, setFound] = useState<{ id: string; label: string }[]>([])
  const [product, setProduct] = useState<{ id: string; label: string } | null>(null)
  const [fromBatches, setFromBatches] = useState<{ id: string; label: string }[]>([])
  const [from, setFrom] = useState('')
  const [batchNo, setBatchNo] = useState('')
  const [expires, setExpires] = useState('')
  const [qty, setQty] = useState('')
  const [cost, setCost] = useState('')
  const [note, setNote] = useState('')
  useEffect(() => {
    if (text.trim().length < 2 || text === product?.label) return
    const t = setTimeout(() => { api.batchProducts(text, industry).then((d) => setFound(d.products)).catch(() => setFound([])) }, 250)
    return () => clearTimeout(t)
  }, [text, product])
  useEffect(() => {
    setFrom('')
    if (!product || kind !== 'move') { setFromBatches([]); return }
    api.productBatches(warehouse, product.id, industry).then((d) => setFromBatches(d.batches)).catch(() => setFromBatches([]))
  }, [product, kind, warehouse])
  const ready = !!product && batchNo.trim() !== '' && expires !== '' && Number(qty) > 0 && (kind === 'found' || from !== '')
  return (
    <Ask title="Add or move a batch" go="Make the draft adjustment" ready={ready} onClose={onClose} width={560}
      run={async () => onDone((await api.addBatch({ warehouse, kind, product_id: product!.id, from_batch_id: kind === 'move' ? from : null, batch_no: batchNo,
        expires_on: expires, qty, unit_cost: kind === 'found' && cost !== '' ? cost : null, note: note || null }, industry)).adjustment.open)}>
      <div className={s.form}>
        <Text>Makes a draft stock adjustment for this store. Check it and post it as usual (a manager approves it above the limit).</Text>
        <Field label="What happened">
          <RadioGroup value={kind} onChange={(_, d) => setKind(d.value as 'found' | 'move')}>
            <Radio value="found" label="Stock of a batch the system does not have (found, or never entered)" />
            <Radio value="move" label="Stock recorded under the wrong batch number: move it to the right one" />
          </RadioGroup>
        </Field>
        <Field label="Product" required>
          <Combobox value={text} selectedOptions={product ? [product.id] : []} placeholder="Name or SKU" onChange={(e) => setText(e.target.value)}
            onBlur={() => setText(product?.label ?? '')} onOptionSelect={(_, d) => { const p = found.find((x) => x.id === d.optionValue) ?? null; setProduct(p); setText(p?.label ?? '') }}>
            {found.map((p) => <Option key={p.id} value={p.id}>{p.label}</Option>)}
            {found.length === 0 && <Option disabled value="">{text.trim().length < 2 ? 'Type at least two letters' : 'Nothing matches'}</Option>}
          </Combobox>
        </Field>
        {kind === 'move' && <Field label="Take it from batch" required>
          <Dropdown value={fromBatches.find((b) => b.id === from)?.label ?? ''} selectedOptions={[from]} placeholder={product ? 'Choose the batch' : 'Choose the product first'}
            onOptionSelect={(_, d) => setFrom(d.optionValue ?? '')}>
            {fromBatches.map((b) => <Option key={b.id} value={b.id}>{b.label}</Option>)}
          </Dropdown>
        </Field>}
        <Field label={kind === 'move' ? 'Right batch number' : 'Batch number'} required><Input value={batchNo} maxLength={60} onChange={(_, d) => setBatchNo(d.value)} /></Field>
        <Field label="Expiry date" required hint="For a batch the product already has, its date stays as it is; correct it with Correct expiry on the list.">
          <Input type="date" value={expires} onChange={(_, d) => setExpires(d.value)} />
        </Field>
        <Field label="Quantity (base unit)" required><Input type="number" min={0} value={qty} onChange={(_, d) => setQty(d.value)} /></Field>
        {kind === 'found' && <Field label="Cost per unit (optional)" hint="Leave empty to use the average cost."><Input type="number" min={0} value={cost} onChange={(_, d) => setCost(d.value)} /></Field>}
        <Field label="Note"><Input value={note} maxLength={255} onChange={(_, d) => setNote(d.value)} /></Field>
      </div>
    </Ask>
  )
}
