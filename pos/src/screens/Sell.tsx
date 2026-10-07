import { useEffect, useMemo, useRef, useState } from 'react'
import { api, ApiError } from '../api'
import { addMarkdown, addToCart, addWeighed, findByBarcode, priceCart, qtyAllowed, search, setManual, setQty, type CartLine } from '../cart'
import { findMarkdown, parseScaleLabel, productForPlu } from '../scale'
import { db, nextSeq, type CacheRecord } from '../db'
import { exportQueueFile, offlineSignIn, retryRefused } from '../offline'
import { queueMode, submitSale, type RxDetails } from '../till'
import { generics, needsPrescription, warnings as rxWarnings } from '../pharmacy'
import { billLines, groupsFor, kitchenNote } from '../restaurant'
import { BillView, FloorView, ModifierPicker, type Choice } from './Restaurant'
import { v7 as uuidv7 } from 'uuid'
import { formatMoney, prettyQty, toMilli } from '../money'
import type { Bootstrap, DiningItem, DiningOrder, Grocery, Pharmacy, Restaurant, Payment, PostedSale, Product, PromotionRow, TillCustomer, Unit } from '../types'
import { parseMoney } from '../money'
import { ReturnScreen } from './ReturnScreen'
import { cachedCustomers, refreshCustomers } from '../customers'
import { CustomerPicker } from './CustomerPicker'
import { Pay } from './Pay'

export function Sell({ boot, products, online, catalogVersion, queue, onPosted, onRefresh, onSendNow, onCloseShift, onLogout }: {
  boot: Bootstrap; products: Product[]; online: boolean; catalogVersion: string | null
  queue: { pending: number; review: number; sending: boolean; lastError: string | null }
  onPosted: (s: PostedSale) => void; onRefresh: () => Promise<void>; onSendNow: () => void
  onCloseShift: () => void; onLogout: () => void
}) {
  const defaultList = boot.price_lists.find((l) => l.is_default) ?? boot.price_lists[0]
  const [listId, setListId] = useState(defaultList?.id ?? '')
  const [lines, setLines] = useState<CartLine[]>([])
  const [query, setQuery] = useState('')
  const [message, setMessage] = useState('')
  const [paying, setPaying] = useState(false)
  const [busy, setBusy] = useState(false)
  const [payError, setPayError] = useState('')
  const [customers, setCustomers] = useState<TillCustomer[]>([])
  const [customer, setCustomer] = useState<TillCustomer | null>(null)
  const [picking, setPicking] = useState(false)
  // seq: a retry of the same sale (after an approval) keeps its receipt number, so numbers have no gaps.
  type Approvals = { credit?: { username: string; pin: string }; discount?: { username: string; pin: string }; offlineApproverId?: string; seq?: number; rx?: Rx; tip?: number }
  const [approval, setApproval] = useState<{ payments: Payment[]; message: string; kind: 'credit' | 'discount' | 'discount_offline'; approvals: Approvals } | null>(null)
  const [promotions, setPromotions] = useState<PromotionRow[]>([])
  const [utcOffset, setUtcOffset] = useState(0)
  const [grocery, setGrocery] = useState<Grocery | null>(null)
  const [pharmacy, setPharmacy] = useState<Pharmacy | null>(null)
  // Restaurant: tables and bills (server), the bill being paid, and choices for a dish sold at the counter.
  const [restaurant, setRestaurant] = useState<Restaurant | null>(null)
  const [floorOpen, setFloorOpen] = useState(false)
  const [billOpen, setBillOpen] = useState<DiningOrder | null>(null)
  const [payingBill, setPayingBill] = useState<DiningOrder | null>(null)
  const [payNow, setPayNow] = useState(false)
  const [counterPick, setCounterPick] = useState<Product | null>(null)
  // Pharmacy: the prescription and the pharmacist's approval for prescription-only medicines in this sale.
  const [rx, setRx] = useState<Rx | null>(null)
  const [rxPrompt, setRxPrompt] = useState<{ message: string; payments?: Payment[]; approvals?: Approvals } | null>(null)
  const [coupon, setCoupon] = useState('')
  const [couponInput, setCouponInput] = useState('')
  const [saleBp, setSaleBp] = useState(0)
  const [editing, setEditing] = useState<CartLine | null>(null)
  const [returning, setReturning] = useState(false)
  const [takingPayment, setTakingPayment] = useState(false)
  const searchRef = useRef<HTMLInputElement>(null)

  useEffect(() => { // promotions come with the cached catalog
    db.cache.get('catalog').then((c) => {
      const cat = c as Extract<CacheRecord, { id: 'catalog' }> | undefined
      setPromotions(cat?.promotions ?? [])
      setUtcOffset(cat?.utc_offset_minutes ?? 0)
      setGrocery(cat?.grocery ?? null)
      setPharmacy(cat?.pharmacy ?? null)
      setRestaurant(cat?.restaurant ?? null)
    })
  }, [catalogVersion])

  useEffect(() => {
    let alive = true
    cachedCustomers().then((c) => alive && setCustomers(c.customers))
    if (online) refreshCustomers().then((c) => alive && c && setCustomers(c))
    return () => { alive = false }
  }, [online])

  function pickCustomer(c: TillCustomer | null) {
    setCustomer(c)
    setPicking(false)
    // The customer's own price list (e.g. wholesale) applies to their sale.
    if (c?.price_list_id && boot.price_lists.some((l) => l.id === c.price_list_id)) setListId(c.price_list_id)
    else if (!c) setListId(defaultList?.id ?? '')
    searchRef.current?.focus()
  }
  const list = boot.price_lists.find((l) => l.id === listId) ?? defaultList
  const money = (m: number) => formatMoney(m, boot.company.currency, boot.company.exponent)
  const cart = useMemo(() => priceCart(lines, list, new Date(), { promotions, branchId: boot.branch.id, utcOffsetMinutes: utcOffset, coupon, saleDiscountBp: saleBp,
    serviceBp: restaurant?.service_charge?.bp ?? 0 }), [lines, list, promotions, utcOffset, coupon, saleBp, boot.branch.id, restaurant])
  const results = useMemo(() => search(products, query), [products, query])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'F2') { e.preventDefault(); searchRef.current?.focus() }
      if (e.key === 'F12' && lines.length && !paying) { e.preventDefault(); startPay() }
      if (e.key === 'F4' && !paying) { e.preventDefault(); setPicking(true) }
      if (e.key === 'F8' && !paying && online) { e.preventDefault(); setReturning(true) }
      if (e.key === 'F6' && !paying && online && restaurant) { e.preventDefault(); setFloorOpen(true) }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  })

  async function exportUnsent() {
    const url = URL.createObjectURL(await exportQueueFile())
    const a = Object.assign(document.createElement('a'), { href: url, download: `till-${boot.branch.code}-${boot.till?.code}${boot.device.receipt_series ?? ''}-unsent-${Date.now()}.json` })
    a.click()
    URL.revokeObjectURL(url)
  }

  function add(product: Product, unit?: Unit) {
    if (restaurant && !unit && groupsFor(product, restaurant.modifier_groups).length) {
      setQuery('')
      return setCounterPick(product) // a dish with choices: ask them first
    }
    const u = unit ?? product.units.find((x) => x.is_base) ?? product.units[0]
    setLines((l) => addToCart(l, product, u))
    setQuery('')
    setMessage('')
    searchRef.current?.focus()
  }

  /** Counter sale of a dish with choices: its own line (the choices go to the kitchen as its note), plus priced extras. */
  function addWithChoices(product: Product, c: Choice) {
    const key = `mod:${uuidv7()}`
    const base = (p: Product) => p.units.find((x) => x.is_base) ?? p.units[0]
    const extras = c.chargedProductIds.map((id) => products.find((p) => p.id === id)).filter((p): p is Product => !!p)
    setLines((l) => [...l, { key, product, unit: base(product), qtyMilli: c.qtyMilli, note: kitchenNote(c.names, c.note) },
      ...extras.map((x, n) => ({ key: `${key}:${n}`, product: x, unit: base(x), qtyMilli: c.qtyMilli, note: `with ${product.name}` }))])
    setCounterPick(null)
    searchRef.current?.focus()
  }

  /** Pay a bill (all of it or the chosen items): its items become the cart, with the service charge for table service. */
  function payBill(order: DiningOrder, items: DiningItem[]) {
    const { lines: bill, missing } = billLines(items, products)
    if (missing.length) return setMessage(`Refresh the till: ${missing.map((m) => m.name).join(', ')} not in its menu.`)
    const svc = restaurant?.service_charge && (order.kind === 'table' || order.kind === 'tab') ? products.find((p) => p.id === restaurant.service_charge!.product_id) : null
    setLines(svc ? [...bill, { key: 'service', product: svc, unit: svc.units.find((u) => u.is_base) ?? svc.units[0], qtyMilli: 1000, service: true }] : bill)
    setPayingBill(order)
    setBillOpen(null)
    setFloorOpen(false)
    const c = order.customer_id ? customers.find((x) => x.id === order.customer_id) : null
    if (c) pickCustomer(c)
    setPayNow(true)
  }
  useEffect(() => {
    if (payNow) {
      setPayNow(false)
      startPay()
    }
  })

  function onScanOrSearch(e: React.FormEvent) {
    e.preventDefault()
    // Grocery: a markdown sticker, or a scale label with the weight in it.
    const md = findMarkdown(grocery, query, products)
    if (md) {
      setLines((l) => addMarkdown(l, md.product, md.unit, md.markdown))
      setQuery('')
      return setMessage(`Marked down: ${md.product.name}`)
    }
    const weighed = grocery ? parseScaleLabel(query, grocery.scale_prefixes) : null
    if (weighed) {
      const p = productForPlu(products, weighed.plu)
      if (!p) return setMessage(`No product has scale code ${weighed.plu}.`)
      setLines((l) => addWeighed(l, p.product, p.unit, weighed.grams))
      setQuery('')
      return setMessage('')
    }
    const hit = findByBarcode(products, query)
    if (hit) return add(hit.product, hit.unit)
    if (results.length === 1) return add(results[0])
    if (query.trim() && !results.length) setMessage(`Nothing matches “${query.trim()}”.`)
  }

  function changeQty(line: CartLine, value: string) {
    try {
      const milli = toMilli(value || '0')
      if (milli > 0 && !qtyAllowed(line.unit, milli)) {
        setMessage(`${line.unit.uom_name} allows ${line.unit.decimals} decimal place(s).`)
        return
      }
      setLines((l) => setQty(l, line.key, milli))
    } catch {
      setMessage('Enter a quantity like 2 or 1.5')
    }
  }

  function changeUnit(line: CartLine, unitId: string) {
    const unit = line.product.units.find((u) => u.id === unitId)!
    setLines((l) => addToCart(setQty(l, line.key, 0), line.product, unit, line.qtyMilli))
  }

  function startPay() {
    if (cart.unpriced.length) {
      setMessage(`No ${list.name} price for: ${cart.unpriced.map((l) => l.product.name).join(', ')}`)
      return
    }
    setPayError('')
    if (pharmacy && lines.some((l) => needsPrescription(l.product)) && !rx) {
      setRxPrompt({ message: '' })
      return
    }
    setPaying(true)
  }

  async function confirmRx(details: RxDetails, pharmacist: { username: string; pin: string } | null) {
    const pending = rxPrompt
    try {
      let next: Rx = { details }
      if (pharmacist && (await queueMode(online))) {
        // Offline: the pharmacist's PIN is checked against their offline sign-in on this till.
        const g = await offlineSignIn(pharmacist.username, pharmacist.pin)
        next = { details, offlineBy: g.user_id }
      } else if (pharmacist) {
        next = { details, approval: pharmacist }
      }
      setRx(next)
      setRxPrompt(null)
      if (pending?.payments) return complete(pending.payments, { ...pending.approvals, rx: next })
      setPaying(true)
    } catch (err) {
      setRxPrompt({ ...(pending ?? {}), message: err instanceof Error ? err.message : String(err) })
    }
  }

  async function complete(payments: Payment[], given: Approvals = {}) {
    setBusy(true)
    setPayError('')
    const approvals: Approvals = { ...given, seq: given.seq ?? (await nextSeq(boot.last_device_seq)), rx: given.rx ?? rx ?? undefined }
    try {
      const hasManual = saleBp > 0 || lines.some((l) => l.manual)
      if (hasManual && !approvals.offlineApproverId && !approvals.discount && (await queueMode(online))) {
        // Offline: a supervisor confirms with their PIN on this till (their offline sign-in); the server checks their limit later.
        setApproval({ payments, message: 'A supervisor must approve the discount.', kind: 'discount_offline', approvals })
        return
      }
      const sale = await submitSale({ boot, online, cart, listId: list.id, payments, seq: approvals.seq!, catalogVersion,
        customerId: customer?.id ?? null, creditApproval: approvals.credit ?? null, discountApproval: approvals.discount ?? null,
        offlineApproverId: approvals.offlineApproverId ?? null, coupon: cart.couponApplied ? coupon : null, saleDiscountBp: saleBp,
        prescription: approvals.rx?.details ?? null, pharmacistApproval: approvals.rx?.approval ?? null, pharmacistOfflineBy: approvals.rx?.offlineBy ?? null,
        diningOrderId: payingBill?.id ?? null, tipMinor: approvals.tip ?? 0, kitchenTickets: !!restaurant })
      setLines([])
      setPaying(false)
      setApproval(null)
      setCoupon('')
      setCouponInput('')
      setSaleBp(0)
      setRx(null)
      setPayingBill(null)
      pickCustomer(null)
      onPosted(sale)
    } catch (err) {
      const e = err instanceof ApiError ? err : null
      const retrying = ['approval_failed', 'over_limit', 'own_document', 'pin_locked'].includes(e?.code ?? '')
      if (['prescription_required', 'pharmacist_needed'].includes(e?.code ?? '') || (retrying && approvals.rx?.approval && !approvals.credit && !approvals.discount)) {
        setRxPrompt({ message: e!.message, payments, approvals: { ...approvals, rx: undefined } })
        return
      }
      if (e?.code === 'credit_needs_approval' || (retrying && approvals.credit && !approvals.discount)) {
        setApproval({ payments, message: e!.message, kind: 'credit', approvals: { ...approvals, credit: undefined } })
        return
      }
      if (e?.code === 'offline_approval_needed') {
        setApproval({ payments, message: e.message, kind: 'discount_offline', approvals })
        return
      }
      if (e?.code === 'discount_needs_approval' || (retrying && approvals.discount)) {
        setApproval({ payments, message: e!.message, kind: 'discount', approvals: { ...approvals, discount: undefined } })
        return
      }
      setPayError(err instanceof Error ? err.message : String(err))
      if (e && ['price_changed', 'totals_mismatch', 'product_unavailable'].includes(e.code)) await onRefresh()
    } finally {
      setBusy(false)
    }
  }

  async function approve(a: { username: string; pin: string }) {
    if (!approval) return
    const { payments, kind, approvals } = approval
    if (kind === 'credit') return complete(payments, { ...approvals, credit: a })
    if (kind === 'discount') return complete(payments, { ...approvals, discount: a })
    try {
      const g = await offlineSignIn(a.username, a.pin)
      if (g.user_id === boot.user.id) throw new Error('Another person must approve this.')
      return complete(payments, { ...approvals, offlineApproverId: g.user_id })
    } catch (err) {
      setApproval({ ...approval, message: err instanceof Error ? err.message : String(err) })
    }
  }

  function applyCoupon(e: React.FormEvent) {
    e.preventDefault()
    setCoupon(couponInput.trim().toUpperCase())
  }
  return (
    <div className="sell">
      <header className="topbar">
        <div><strong>{boot.branch.code} · {boot.till?.name}</strong> <span className="muted">{boot.user.name}</span></div>
        <label className="inline">
          Price list
          <select value={listId} onChange={(e) => setListId(e.target.value)} aria-label="Price list">
            {boot.price_lists.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
          </select>
        </label>
        <div className="row">
          {customer ? (
            <span className="chip" aria-label="Customer">
              {customer.name}{customer.credit_limit_minor !== null && ` · credit ${money(customer.available_minor ?? 0)}`}
              {online && <button type="button" className="link" onClick={() => setTakingPayment(true)}>Take payment</button>}
              <button type="button" className="link" onClick={() => pickCustomer(null)} aria-label="Remove customer">✕</button>
            </span>
          ) : <button type="button" onClick={() => setPicking(true)}>Customer (F4)</button>}
          {online && restaurant && <button type="button" onClick={() => setFloorOpen(true)}>Tables (F6)</button>}
          {payingBill && <span className="chip" aria-label="Paying bill">Paying {payingBill.label}
            <button type="button" className="link" onClick={() => { setPayingBill(null); setLines([]) }} aria-label="Stop paying this bill">✕</button></span>}
          {online && <button type="button" onClick={() => setReturning(true)}>Return (F8)</button>}
          <button type="button" onClick={onCloseShift}>Close shift</button>
          <button type="button" onClick={onLogout}>Sign out</button>
        </div>
      </header>

      {(queue.pending > 0 || queue.review > 0 || !online) && (
        <div className={`banner ${queue.review ? 'bad' : 'warn'}`} role="status">
          <span>
            {!online && <strong>Working offline. </strong>}
            {queue.pending > 0 && `${queue.pending} item(s) saved on this till, waiting to reach the server${queue.sending ? ' — sending…' : '.'} `}
            {queue.review > 0 && `${queue.review} item(s) were refused by the server — show a supervisor (they are in the back-office Review queue). `}
            {queue.lastError && !queue.sending && <span className="small">({queue.lastError})</span>}
          </span>
          <span className="row">
            {queue.pending > 0 && <button type="button" onClick={onSendNow} disabled={queue.sending}>Send now</button>}
            {queue.review > 0 && <button type="button" onClick={async () => { await retryRefused(); onSendNow() }}>Retry refused</button>}
            <button type="button" onClick={exportUnsent}>Export unsent sales</button>
          </span>
        </div>
      )}
      <section className="find">
        <form onSubmit={onScanOrSearch}>
          <input ref={searchRef} autoFocus className="big" value={query} onChange={(e) => setQuery(e.target.value)}
            placeholder="Scan a barcode or type a name / SKU (F2)" aria-label="Scan or search" autoComplete="off" />
        </form>
        {message && <p className="error" role="alert">{message}</p>}
        <ul className="results">
          {results.map((p) => (
            <li key={p.id}>
              <button type="button" onClick={() => add(p)}>
                <span>{p.name}<br /><span className="muted small">{p.sku}{p.on_hand !== null ? ` · ${prettyQty(toMilli(p.on_hand))} in stock` : ''}</span></span>
              </button>
              {p.units.filter((u) => !u.is_base).map((u) => (
                <button key={u.id} type="button" className="unit" onClick={() => add(p, u)}>+ {u.uom_code}</button>
              ))}
            </li>
          ))}
        </ul>
      </section>

      <section className="cart" aria-label="Cart">
        {cart.lines.length === 0 ? <p className="muted center">Scan or search to start a sale.</p> : (
          <table>
            <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th className="num">Price</th><th className="num">Total</th><th /></tr></thead>
            <tbody>
              {cart.lines.map((l) => (
                <tr key={l.key} className={l.price ? '' : 'unpriced'}>
                  <td>{l.service ? `Service charge (${(restaurant?.service_charge?.bp ?? 0) / 100} %)` : l.product.name}
                    {l.note && <div className="small muted">{l.note}</div>}
                    {l.markdown && <div className="small promo">Marked-down sticker</div>}
                    {l.promotions.map((p) => <div key={p.id} className="small promo">{p.name} −{money(p.saving_minor)}</div>)}
                    {l.manual && <div className="small promo">Discount −{money(l.discount - l.promotions.reduce((a, p) => a + p.saving_minor, 0))}</div>}
                  </td>
                  <td>{l.diningItemId || l.service ? prettyQty(l.qtyMilli) : (
                    <input className="qty mono" inputMode="decimal" defaultValue={prettyQty(l.qtyMilli)} key={l.qtyMilli}
                      onBlur={(e) => changeQty(l, e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()} aria-label={`Quantity of ${l.product.name}`} />)}</td>
                  <td>
                    {l.product.units.length > 1 ? (
                      <select value={l.unit.id} onChange={(e) => changeUnit(l, e.target.value)} aria-label="Unit">
                        {l.product.units.map((u) => <option key={u.id} value={u.id}>{u.uom_code}</option>)}
                      </select>
                    ) : l.unit.uom_code}
                  </td>
                  <td className="num">{l.price ? money(l.price.amount_minor) : 'no price'}</td>
                  <td className="num">{money(l.total)}</td>
                  <td>{!l.service && <button type="button" className="link" onClick={() => setEditing(l)} aria-label={`Discount ${l.product.name}`}>%</button>}
                    {!l.diningItemId && <button type="button" className="link" onClick={() => setLines((x) => setQty(x, l.key, 0))} aria-label={`Remove ${l.service ? 'service charge' : l.product.name}`}>✕</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <div className="cart-foot">
          <dl className="totals">
            {cart.discount > 0 && <><dt>You save</dt><dd>{money(cart.discount)}</dd></>}
            <dt>{list.prices_include_tax ? 'Tax included' : 'Tax'}</dt><dd>{money(cart.tax)}</dd>
            <dt className="strong">Total</dt><dd className="strong grand">{money(cart.total)}</dd>
          </dl>
          <form className="row wrap" onSubmit={applyCoupon}>
            <input aria-label="Coupon code" placeholder="Coupon" value={couponInput} onChange={(e) => setCouponInput(e.target.value)} className="mono" />
            <button type="submit">Apply coupon</button>
            {coupon && (cart.couponError ? <span className="error small">{cart.couponError}</span> : <span className="small">Coupon {coupon} applied</span>)}
            <label className="inline small">Sale discount %
              <input aria-label="Sale discount percent" inputMode="decimal" style={{ width: '4em' }} value={saleBp ? saleBp / 100 : ''}
                onChange={(e) => setSaleBp(Math.max(0, Math.min(10000, Math.round(Number(e.target.value || 0) * 100))))} />
            </label>
          </form>
          <div className="row">
            <button type="button" onClick={() => setLines([])} disabled={!lines.length}>Clear</button>
            <button type="button" className="primary big" onClick={startPay} disabled={!lines.length}>Pay (F12)</button>
          </div>
        </div>
      </section>

      {paying && !approval && !rxPrompt && (
        <Pay total={cart.total} currency={boot.company.currency} exponent={boot.company.exponent} busy={busy} error={payError}
          customer={customer} tips={!!restaurant?.tips && online && payingBill?.kind !== 'delivery'} bill={payingBill}
          onPay={(p, tip) => complete(p, { tip })} onCancel={() => setPaying(false)} />
      )}
      {rxPrompt && pharmacy && (
        <PrescriptionForm message={rxPrompt.message} initial={rx} lines={lines} products={products} customer={customer} pharmacy={pharmacy}
          offline={!online} busy={busy} onPickPatient={() => setPicking(true)}
          onSwap={(line, alt) => setLines((l) => addToCart(setQty(l, line.key, 0), alt, alt.units.find((u) => u.is_base) ?? alt.units[0], line.qtyMilli))}
          onConfirm={confirmRx} onCancel={() => setRxPrompt(null)} />
      )}
      {floorOpen && <FloorView products={products} money={{ currency: boot.company.currency, exponent: boot.company.exponent }}
        onOpenBill={(o) => { setFloorOpen(false); setBillOpen(o) }} onClose={() => setFloorOpen(false)} />}
      {billOpen && restaurant && <BillView initial={billOpen} products={products} restaurant={restaurant} onPay={payBill}
        onBack={() => { setBillOpen(null); setFloorOpen(true) }} />}
      {counterPick && restaurant && <ModifierPicker product={counterPick} restaurant={restaurant} tableService={false}
        onDone={(c) => addWithChoices(counterPick, c)} onCancel={() => setCounterPick(null)} />}
      {picking && <CustomerPicker customers={customers} money={money} onPick={pickCustomer} onCancel={() => setPicking(false)} />}
      {approval && <CreditApproval title={approval.kind === 'credit' ? 'Supervisor approval — credit' : 'Supervisor approval — discount'} message={approval.message} busy={busy}
        onApprove={approve} onCancel={() => setApproval(null)} />}
      {editing && <LineDiscount line={editing} money={money} onSave={(m) => { setLines((x) => setManual(x, editing.key, m)); setEditing(null) }} onCancel={() => setEditing(null)} />}
      {takingPayment && customer && <AccountPayment customer={customer} money={money} exponent={boot.company.exponent}
        onDone={(balance) => { setTakingPayment(false); if (balance !== null) setCustomer({ ...customer, balance_minor: balance }) }} />}
      {returning && <ReturnScreen boot={boot} money={money} onDone={(s) => { setReturning(false); if (s) onPosted(s) }} />}
    </div>
  )
}

type Rx = { details: RxDetails; approval?: { username: string; pin: string }; offlineBy?: string }

/**
 * Pharmacy: before payment for prescription-only or controlled medicines — the patient, the prescription, the allergy and
 * interaction warnings, generic substitutes, and the pharmacist's username and PIN (empty when the pharmacist is signed in here).
 */
function PrescriptionForm({ message, initial, lines, products, customer, pharmacy, offline, busy, onPickPatient, onSwap, onConfirm, onCancel }: {
  message: string; initial: Rx | null; lines: CartLine[]; products: Product[]; customer: TillCustomer | null; pharmacy: Pharmacy; offline: boolean; busy: boolean
  onPickPatient: () => void; onSwap: (line: CartLine, alt: Product) => void
  onConfirm: (d: RxDetails, pharmacist: { username: string; pin: string } | null) => void; onCancel: () => void
}) {
  const today = new Date().toLocaleDateString('en-CA')
  const [d, setD] = useState<RxDetails>(initial?.details ?? { prescriber_name: '', prescriber_reg_no: '', facility: '', reference: '', issued_on: today })
  const [username, setUsername] = useState('')
  const [pin, setPin] = useState('')
  const [error, setError] = useState('')
  const set = (k: keyof RxDetails) => (e: React.ChangeEvent<HTMLInputElement>) => setD({ ...d, [k]: e.target.value })
  const medicines = lines.filter((l) => needsPrescription(l.product))
  const found = rxWarnings([...new Map(lines.map((l) => [l.product.id, l.product])).values()], customer?.allergies ?? [], pharmacy.interactions)
  function submit(e: React.FormEvent) {
    e.preventDefault()
    if (!customer) return setError('Choose the patient.')
    if (!d.prescriber_name.trim() || !d.issued_on) return setError('Enter the prescriber and the date on the prescription.')
    if (d.issued_on > today) return setError('The prescription date cannot be in the future.')
    if ((username && pin.length < 4) || (!username && pin)) return setError('Enter the pharmacist’s username and PIN, or leave both empty if you are the pharmacist.')
    onConfirm({ ...d, prescriber_name: d.prescriber_name.trim() }, username ? { username, pin } : null)
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Prescription" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay rx" onSubmit={submit}>
        <h2>Prescription</h2>
        {(message || error) && <p className="error">{error || message}</p>}
        <ul className="rx-meds">
          {medicines.map((l) => {
            const alts = generics(products, l.product)
            return (
              <li key={l.key}><strong>{l.product.name}</strong> {l.product.drug?.schedule === 'controlled' && <span className="chip">Controlled</span>}
                {alts.length > 0 && (
                  <select aria-label={`Generic for ${l.product.name}`} value="" onChange={(e) => { const a = alts.find((x) => x.id === e.target.value); if (a) onSwap(l, a) }}>
                    <option value="">Swap for generic…</option>
                    {alts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                  </select>
                )}
              </li>
            )
          })}
        </ul>
        <div className="row">
          <span>Patient: <strong>{customer?.name ?? 'none chosen'}</strong>{customer?.nhis_no ? ` · NHIS ${customer.nhis_no}` : ''}</span>
          <button type="button" onClick={onPickPatient}>{customer ? 'Change patient' : 'Choose patient'}</button>
        </div>
        {found.length > 0 && (
          <ul className="rx-warnings" role="alert" aria-label="Warnings">
            {found.map((w, i) => <li key={i} className={w.severity}><strong>{w.severity === 'major' ? 'Major' : w.severity === 'moderate' ? 'Moderate' : 'Minor'}</strong> · {w.message}</li>)}
          </ul>
        )}
        <label>Prescriber<input autoFocus value={d.prescriber_name} onChange={set('prescriber_name')} placeholder="Dr. …" /></label>
        <div className="row">
          <label>Reg. no.<input value={d.prescriber_reg_no ?? ''} onChange={set('prescriber_reg_no')} /></label>
          <label>Written on<input type="date" value={d.issued_on} max={today} onChange={set('issued_on')} /></label>
        </div>
        <div className="row">
          <label>Hospital / clinic<input value={d.facility ?? ''} onChange={set('facility')} /></label>
          <label>Prescription no.<input value={d.reference ?? ''} onChange={set('reference')} /></label>
        </div>
        <p className="muted small">Pharmacist approval{offline ? ' (checked on this till while offline)' : ''} — leave empty if you are the pharmacist.</p>
        <div className="row">
          <label>Pharmacist username<input value={username} onChange={(e) => setUsername(e.target.value)} autoCapitalize="none" /></label>
          <label>Pharmacist PIN<input type="password" inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 8))} /></label>
        </div>
        <div className="row">
          <button type="button" onClick={onCancel} disabled={busy}>Back</button>
          <button className="primary big" disabled={busy}>{found.some((w) => w.severity === 'major') ? 'Dispense despite warnings' : 'Continue to payment'}</button>
        </div>
      </form>
    </div>
  )
}

/** A supervisor approves a credit sale over the customer's limit with their own username and PIN. */
function CreditApproval({ title, message, busy, onApprove, onCancel }: {
  title: string; message: string; busy: boolean; onApprove: (a: { username: string; pin: string }) => void; onCancel: () => void
}) {
  const [username, setUsername] = useState('')
  const [pin, setPin] = useState('')
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Supervisor approval">
      <form className="card pay" onSubmit={(e) => { e.preventDefault(); if (username && pin) onApprove({ username, pin }) }}>
        <h2>{title}</h2>
        <p className="error">{message}</p>
        <label>Supervisor username<input autoFocus value={username} onChange={(e) => setUsername(e.target.value)} autoCapitalize="none" /></label>
        <label>Supervisor PIN<input type="password" inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 8))} /></label>
        <div className="row">
          <button type="button" onClick={onCancel} disabled={busy}>Back</button>
          <button className="primary big" disabled={busy || !username || pin.length < 4}>{busy ? 'Checking…' : 'Approve'}</button>
        </div>
      </form>
    </div>
  )
}
/** A discount on one line: a percentage off, or a new unit price (within limits; above the cashier's, a supervisor approves). */
function LineDiscount({ line, money, onSave, onCancel }: {
  line: CartLine; money: (m: number) => string; onSave: (m: CartLine['manual']) => void; onCancel: () => void
}) {
  const [mode, setMode] = useState<'pct' | 'price'>(line.manual?.price_minor !== undefined ? 'price' : 'pct')
  const [value, setValue] = useState(line.manual?.bp ? String(line.manual.bp / 100) : '')
  const [error, setError] = useState('')
  function save(e: React.FormEvent) {
    e.preventDefault()
    try {
      if (!value.trim()) return onSave(null)
      if (mode === 'pct') {
        const bp = Math.round(Number(value) * 100)
        if (!(bp > 0 && bp <= 10000)) throw new Error('Enter a percentage between 0.01 and 100.')
        onSave({ bp })
      } else {
        onSave({ price_minor: parseMoney(value, 2) })
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Line discount" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay" onSubmit={save}>
        <h2>Discount — {line.product.name}</h2>
        <div className="segmented" role="tablist">
          <button type="button" role="tab" aria-selected={mode === 'pct'} className={mode === 'pct' ? 'active' : ''} onClick={() => setMode('pct')}>% off</button>
          <button type="button" role="tab" aria-selected={mode === 'price'} className={mode === 'price' ? 'active' : ''} onClick={() => setMode('price')}>New price</button>
        </div>
        <label>{mode === 'pct' ? 'Percentage off' : `New price per ${line.unit.uom_code}`}
          <input autoFocus className="big mono" inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)} placeholder={mode === 'pct' ? '10' : money(0)} />
        </label>
        <p className="muted small">Leave empty to remove the discount. A supervisor approves anything above your limit.</p>
        {error && <p className="error">{error}</p>}
        <div className="row"><button type="button" onClick={onCancel}>Back</button><button className="primary big">Apply</button></div>
      </form>
    </div>
  )
}
/** Money a customer brings in against their account, taken at the till (counts in this shift's cash). */
function AccountPayment({ customer, money, exponent, onDone }: {
  customer: TillCustomer; money: (m: number) => string; exponent: number; onDone: (balance: number | null) => void
}) {
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<'cash' | 'momo' | 'card'>('cash')
  const [provider, setProvider] = useState('mtn')
  const [reference, setReference] = useState('')
  const [error, setError] = useState('')
  const [done, setDone] = useState<{ number: string; balance: number } | null>(null)
  async function save(e: React.FormEvent) {
    e.preventDefault()
    setError('')
    try {
      const r = await api.accountPayment({ customer_id: customer.id, amount_minor: parseMoney(amount, exponent), method,
        ...(method === 'momo' ? { provider } : {}), ...(reference ? { reference } : {}) })
      setDone({ number: r.number, balance: r.balance_minor })
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Take payment" onKeyDown={(e) => e.key === 'Escape' && onDone(null)}>
      {done ? (
        <div className="card pay">
          <h2>Payment {done.number}</h2>
          <p>{customer.name} now owes {money(done.balance)}.</p>
          <div className="row"><button className="primary big" onClick={() => onDone(done.balance)}>Done</button></div>
        </div>
      ) : (
        <form className="card pay" onSubmit={save}>
          <h2>Take payment — {customer.name}</h2>
          <p className="muted">Owes {money(customer.balance_minor)}{customer.overdue_minor > 0 ? ` · ${money(customer.overdue_minor)} overdue` : ''}</p>
          <div className="segmented" role="tablist">
            {(['cash', 'momo', 'card'] as const).map((m) => (
              <button key={m} type="button" role="tab" aria-selected={method === m} className={method === m ? 'active' : ''} onClick={() => setMethod(m)}>
                {m === 'cash' ? 'Cash' : m === 'momo' ? 'Mobile money' : 'Card'}</button>
            ))}
          </div>
          <label>Amount<input autoFocus className="big mono" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></label>
          {method === 'momo' && (
            <select aria-label="Network" value={provider} onChange={(e) => setProvider(e.target.value)}>
              <option value="mtn">MTN MoMo</option><option value="telecel">Telecel Cash</option><option value="at">AT Money</option>
            </select>
          )}
          {method !== 'cash' && <label>Reference<input value={reference} onChange={(e) => setReference(e.target.value)} /></label>}
          {error && <p className="error">{error}</p>}
          <div className="row"><button type="button" onClick={() => onDone(null)}>Back</button><button className="primary big">Record payment</button></div>
        </form>
      )}
    </div>
  )
}