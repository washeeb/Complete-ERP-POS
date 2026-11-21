import { Button, Dropdown, Link, MessageBar, MessageBarBody, Option, SearchBox, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular, ArrowSwapRegular, ArrowSyncRegular, ArrowUndoRegular, CheckmarkCircleRegular, DismissRegular, SendRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type CostRunDetail, type CostRunLine } from '../api'
import { Ask } from '../form/Ask'
import { LongText } from '../form/Fields'
import { money } from '../home/format'
import { DataTable, Pager } from '../list/DataTable'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { day, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Dialog = 'replace' | 'submit' | 'post' | 'cancel' | 'undo' | null
type Action = 'recalculate' | 'replace' | 'submit' | 'post' | 'cancel' | 'undo'

/**
 * One cost run (part 19), as the current page: the preview (or what it did) with its figures, and its products a page
 * at a time — what happens to each, why one was skipped, the price used, the old and new cost, the stock value added
 * and the cost of past sales. Work it out again, Replace their costs too, Send for approval (when two people are
 * needed), Approve and post, Cancel, and Undo this run (an equal and opposite run).
 */
export function CostRunPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [r, setR] = useState<CostRunDetail | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [dialog, setDialog] = useState<Dialog>(null)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [p, setP] = useState({ kind: '', q: '', page: 1, per: 25 })
  const [q, setQ] = useState('')
  const query = new URLSearchParams({ ...(p.kind ? { kind: p.kind } : {}), ...(p.q ? { q: p.q } : {}), page: String(p.page), per: String(p.per) }).toString()
  const load = useCallback(() => {
    api.costRun(id, query).then((x) => { setR(x); onTitle(x.number) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, query, onTitle])
  useEffect(load, [load])
  useEffect(() => {
    const t = setTimeout(() => { if (q !== p.q) setP((o) => ({ ...o, q, page: 1 })) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

  if (error && !r) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!r) return <div className={page.page}><Spinner label="Opening the cost run…" /></div>
  const m = (x: number | null) => (x === null ? '—' : money(x, r.currency, r.exponent))
  const act = r.actions
  const open = (d: Dialog) => { setDone(''); setError(''); setReason(''); setDialog(d) }
  const run = (action: Action, body: Record<string, unknown> = {}) => api.costRunAction(r.id, action, body).then((x) => {
    setDialog(null)
    setDone(x.message)
    load() // the products list too, with its filters
  })
  const now = (action: Action) => {
    setBusy(true)
    setDone('')
    setError('')
    run(action).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
  }

  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => (history.length > 1 ? history.back() : navigate('/office/cost-runs'))}>Cost runs</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{r.number}</span><ToneBadge tone={r.tone} label={r.status_label} /></h1>
        <div className={s.actions}>
          {act.recalculate && <Button icon={<ArrowSyncRegular />} disabled={busy} onClick={() => now('recalculate')}>{busy ? 'Working…' : 'Work it out again'}</Button>}
          {act.replace && <Button icon={<ArrowSwapRegular />} onClick={() => open('replace')}>Replace their costs too</Button>}
          {act.submit && <Button appearance="primary" icon={<SendRegular />} onClick={() => open('submit')}>Send for approval</Button>}
          {act.post && <Button appearance="primary" icon={<CheckmarkCircleRegular />} onClick={() => open('post')}>Approve and post</Button>}
          {act.cancel && <Button icon={<DismissRegular />} onClick={() => open('cancel')}>Cancel</Button>}
          {act.undo && <Button icon={<ArrowUndoRegular />} onClick={() => open('undo')}>Undo this run</Button>}
        </div>
      </div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {r.nothing !== null && (
        <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>
          Nothing to do: the {r.nothing} product line(s) chosen already have a cost, and this run only fills in missing costs. To give them a new cost, press "Replace their costs too".
        </MessageBarBody></MessageBar>
      )}

      <Panel title={r.done ? 'What it did' : 'Preview: nothing has changed yet'}>
        <div className={s.facts}>
          <Fact label="Added to stock value"><b>{m(r.stock_value)}</b></Fact>
          <Fact label="Cost of past sales">{m(r.catch_up)}</Fact>
          <Fact label="Lines (a product in each store)">{r.lines_count.toLocaleString('en-GB')}</Fact>
          <Fact label="Skipped (listed below, with the reason)">{r.skipped.toLocaleString('en-GB')}</Fact>
          <Fact label="Already had a cost (left as they are)">{r.already_costed.toLocaleString('en-GB')}</Fact>
          <Fact label="Factor">{r.factor}</Fact>
          <Fact label="VAT and levies">{r.tax_mode}</Fact>
          <Fact label="Prices from">{r.price_list ?? '—'}</Fact>
          <Fact label="Date in the books">{day(r.effective_on)}</Fact>
          <Fact label="Against account">{r.account ?? 'The usual accounts'}</Fact>
          <Fact label="Prepared by">{r.prepared_by ?? '—'}</Fact>
          <Fact label="Posted by">{r.posted_by ?? '—'}</Fact>
          {r.reason && <Fact label="Reason">{r.reason}</Fact>}
          {r.note && <Fact label="Note">{r.note}</Fact>}
          {r.reverses && <Fact label="Undoes"><Link onClick={() => navigate(r.reverses!.open)}>{r.reverses.number}</Link></Fact>}
          {r.reversed_by && <Fact label="Undone by"><Link onClick={() => navigate(r.reversed_by!.open)}>{r.reversed_by.number}</Link></Fact>}
        </div>
      </Panel>

      <Panel title="Products" about="What happens to each product in each store. A skipped one says why.">
        <div className={s.bar}>
          <SearchBox className={s.search} placeholder="Product or SKU" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search the run's products" />
          <Dropdown aria-label="What happens" value={p.kind ? r.kinds.find((k) => k.id === p.kind)?.label ?? '' : 'Anything'} selectedOptions={[p.kind]}
            onOptionSelect={(_, d) => setP({ ...p, kind: d.optionValue ?? '', page: 1 })} style={{ minWidth: 200 }}>
            <Option value="">Anything</Option>{r.kinds.map((k) => <Option key={k.id} value={k.id}>{k.label}</Option>)}
          </Dropdown>
        </div>
        <DataTable<CostRunLine> label="Products" rows={r.products.rows} sort="value" dir="desc" loading={false} onSort={() => undefined} onOpen={() => undefined}
          empty={p.kind || p.q ? 'No products match.' : 'Nothing to do: every product chosen already has a cost.'}
          columns={[
            { key: 'product', label: 'Product', render: (l) => <>{l.product}{l.sku && <Text size={200} block className={s.muted}>{l.sku}</Text>}</> },
            { key: 'store', label: 'Store', render: (l) => l.store ?? '—' },
            { key: 'kind', label: 'What happens', render: (l) => <ToneBadge tone={l.tone} label={l.kind_label} /> },
            { key: 'why', label: 'Why', render: (l) => l.why ?? '—' },
            { key: 'on_hand', label: 'On hand', end: true, render: (l) => l.on_hand },
            { key: 'price', label: 'Price used', end: true, render: (l) => m(l.price) },
            { key: 'factor', label: 'Factor', render: (l) => l.factor ?? '—' },
            { key: 'old', label: 'Old cost', end: true, render: (l) => m(l.old_cost) },
            { key: 'new', label: 'New cost', end: true, render: (l) => m(l.new_cost) },
            { key: 'value', label: 'Stock value +', end: true, render: (l) => m(l.stock_value) },
            { key: 'sold', label: 'Sold at no cost', end: true, render: (l) => l.sold },
            { key: 'past', label: 'Cost of past sales', end: true, render: (l) => m(l.catch_up) },
          ]} />
        <Pager page={r.products.page} per={r.products.per} total={r.products.total} options={[10, 25, 50, 100]}
          onPage={(n) => setP({ ...p, page: n })} onPer={(n) => setP({ ...p, per: n, page: 1 })} />
      </Panel>

      {dialog === 'replace' && (
        <Ask title="Replace the costs these products already have?" go="Replace their costs too" ready onClose={() => setDialog(null)} run={() => run('replace')}>
          <Text>The {r.already_costed} product line(s) that already had a cost get the cost worked out from the price. Their real cost from deliveries is lost from the average,
            and the difference goes to Stock losses when posted. Nothing changes until it is posted.</Text>
        </Ask>
      )}
      {dialog === 'submit' && (
        <Ask title="Send for approval?" go="Send for approval" ready onClose={() => setDialog(null)} run={() => run('submit')}>
          <Text>Someone else approves and posts it: nobody approves their own run.</Text>
        </Ask>
      )}
      {dialog === 'post' && (
        <Ask title="Post this cost run?" go="Approve and post" ready onClose={() => setDialog(null)} run={() => run('post')}>
          <Text>Adds {m(r.stock_value)} to the stock value and {m(r.catch_up)} to cost of past sales, as worked out again now. Best done when the tills are quiet: it can take a few
            minutes. Selling prices do not change.</Text>
        </Ask>
      )}
      {dialog === 'cancel' && (
        <Ask title="Cancel this cost run?" go="Cancel the run" ready={reason.trim() !== ''} onClose={() => setDialog(null)} run={() => run('cancel', { reason })}>
          <LongText label="Reason" maxLength={255} value={reason} onChange={setReason} />
        </Ask>
      )}
      {dialog === 'undo' && (
        <Ask title="Undo this run?" go="Undo this run" ready={reason.trim() !== ''} onClose={() => setDialog(null)} run={() => run('undo', { reason })}>
          <div className={s.one}>
            <Text>Puts the earlier costs back with an equal and opposite run. Products that had a delivery since keep their cost, and sales made since keep the cost they were sold at.</Text>
            <LongText label="Reason" maxLength={255} value={reason} onChange={setReason} />
          </div>
        </Ask>
      )}
    </div>
  )
}
