import { useEffect, useMemo, useRef, useState, useCallback } from 'react'
import { cameraScanSupported, listenForScans } from '../scanner'
import { CameraScan } from './CameraScan'
import { cachedImage } from '../brand'
import { isDesktop, readScale } from '../hardware'
import { api, ApiError } from '../api'
import { addMarkdown, addToCart, addWeighed, findByBarcode, priceCart, qtyAllowed, resolvePrice, search, setManual, setQty, type CartLine } from '../cart'
import { findMarkdown, parseScaleLabel, productForPlu } from '../scale'
import { db, nextSeq, type CacheRecord } from '../db'
import { exportQueueFile, offlineSignIn, retryRefused } from '../offline'
import { queueMode, signInAgain, submitSale, type RxDetails } from '../till'
import { generics, needsPrescription, nhisCover, saleLevel, warnings as rxWarnings } from '../pharmacy'
import { billLines, groupsFor, kitchenNote } from '../restaurant'
import { BillView, FloorView, ModifierPicker, type Choice } from './Restaurant'
import { RepairsScreen, SerialPrompt } from './Electronics'
import { AlterationsScreen, StylePicker } from './Fashion'
import { styleOf } from '../fashion'
import { AppointmentsScreen, PassesScreen, StaffPrompt } from './Services'
import { isService } from '../services'
import { EmptiesDialog, RouteScreen } from './Van'
import { recordVisit } from '../visits'
import { achieved, withDeposits } from '../distribution'
import { submitRoadEvent } from '../till'
import { v7 as uuidv7 } from 'uuid'
import { staleShiftSince } from '../shift'
import { formatMoney, prettyQty, toMilli } from '../money'
import { isLow, leftMilli, queuedLines, takenMilli, type QueuedLine } from '../stock'
import { expiryNote } from '../expiry'
import type { Appointment, Services, ServicePass, StaffMember, RxLevel } from '../types'
import type { Alteration, Bootstrap, DiningItem, DiningOrder, Fashion, Grocery, Pharmacy, Restaurant, RepairJob, Style, TradeInItem, Payment, PostedSale, Product, PromotionRow, TillCustomer, Unit } from '../types'
import { parseMoney } from '../money'
import { ReturnScreen } from './ReturnScreen'
import { DrawerScreen } from './Drawer'
import { SlipScreen } from './Slip'
import type { DrawerSlip } from '../slip'
import { checkCashLimit } from '../drawer'
import { confirmLeave, useLeaveWarning } from '../unsaved'
import { cachedCustomers, refreshCustomers } from '../customers'
import { CustomerPicker } from './CustomerPicker'
import { Pay } from './Pay'
import { Calculator, detailsMissing, detailsPayload, emptyDetails, SaleDetailsDialog, type SaleDetailsState } from './TillTools'

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
  useLeaveWarning(lines.length > 0) // a sale being rung up is only on screen until it is paid
  // The drawer: pay-out, pay-in, cash drop, open without a sale; the cash-limit warning; a short confirmation.
  const [drawerTab, setDrawerTab] = useState<null | 'payout' | 'payin' | 'drop' | 'no_sale'>(null)
  const [cashHigh, setCashHigh] = useState(false)
  // A shift left open overnight (opened on an earlier day, over 12 hours ago, so a night shift past midnight is fine).
  const staleShift = staleShiftSince(boot.shift?.opened_at, new Date())
  const [drawerNote, setDrawerNote] = useState('')
  const [slip, setSlip] = useState<{ slip: DrawerSlip; message: string } | null>(null)
  // The cash limit is checked again whenever this screen opens (e.g. after a receipt), so the warning stays until a drop.
  useEffect(() => { checkCashLimit(online, boot.drawer?.cash_limit_minor ?? 0).then(setCashHigh).catch(() => undefined) }, []) // eslint-disable-line react-hooks/exhaustive-deps
  const [busy, setBusy] = useState(false)
  const [payError, setPayError] = useState('')
  const [customers, setCustomers] = useState<TillCustomer[]>([])
  const [customer, setCustomer] = useState<TillCustomer | null>(null)
  const [picking, setPicking] = useState(false)
  // seq: a retry of the same sale (after an approval) keeps its receipt number, so numbers have no gaps.
  type Approvals = { credit?: { username: string; pin: string }; discount?: { username: string; pin: string }; offlineApproverId?: string; seq?: number; rx?: Rx; tip?: number
    tradeIns?: TradeInItem[]; tradeInApproval?: { username: string; pin: string } | null }
  const [approval, setApproval] = useState<{ payments: Payment[]; message: string; kind: 'credit' | 'discount' | 'discount_offline' | 'sign_in'; approvals: Approvals } | null>(null)
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
  // Electronics: serial numbers for the item being added; the repairs screen.
  const [electronics, setElectronics] = useState(false)
  const [serialPick, setSerialPick] = useState<Product | null>(null)
  const [repairsOpen, setRepairsOpen] = useState(false)
  // Fashion: the size × colour picker for a style; alterations.
  const [fashion, setFashion] = useState<Fashion | null>(null)
  const [stylePick, setStylePick] = useState<Style | null>(null)
  const [alterationsOpen, setAlterationsOpen] = useState(false)
  // Services: who did the service being added (and the pass session it uses), the appointment book, the customer's passes.
  const [services, setServices] = useState<Services | null>(null)
  const [staffPick, setStaffPick] = useState<{ product: Product; pass?: { pass: ServicePass; item: number } } | null>(null)
  const [appointmentsOpen, setAppointmentsOpen] = useState(false)
  const [passesOpen, setPassesOpen] = useState(false)
  // Distribution: today's route, empties handed back, an order for delivery instead of a sale, and the visit being made.
  const [routeOpen, setRouteOpen] = useState(false)
  const [emptiesOpen, setEmptiesOpen] = useState(false)
  const [orderMode, setOrderMode] = useState(false)
  const [routeCtx, setRouteCtx] = useState<{ customerId: string; routeId: string } | null>(null)
  const containers = useMemo(() => products.filter((p) => p.is_container), [products])
  // Pharmacy: the prescription and the pharmacist's approval for prescription-only medicines in this sale.
  const [rx, setRx] = useState<Rx | null>(null)
  const [rxPrompt, setRxPrompt] = useState<{ message: string; payments?: Payment[]; approvals?: Approvals; level?: RxLevel } | null>(null)
  const [coupon, setCoupon] = useState('')
  const [couponInput, setCouponInput] = useState('')
  const [saleBp, setSaleBp] = useState(0)
  const [editing, setEditing] = useState<CartLine | null>(null)
  const [returning, setReturning] = useState(false)
  const [takingPayment, setTakingPayment] = useState(false)
  // A comment, a walk-in's name and phone, industry details (Details); the calculator (F1).
  const [details, setDetails] = useState<SaleDetailsState>(emptyDetails)
  const [detailsPrompt, setDetailsPrompt] = useState<{ message: string } | null>(null)
  const [calcOpen, setCalcOpen] = useState(false)
  const searchRef = useRef<HTMLInputElement>(null)
  const findForm = useRef<HTMLFormElement>(null)
  const [camera, setCamera] = useState(false)
  /** A code from the camera, or from a scanner while the cursor was elsewhere: treated as if scanned into the box. */
  const scanned = useCallback((code: string) => {
    setQuery(code)
    setTimeout(() => findForm.current?.requestSubmit(), 0) // after the box holds the code
  }, [])
  useEffect(() => listenForScans((code) => { if (!document.querySelector('.modal')) scanned(code) }), [scanned]) // never behind the payment or another window

  useEffect(() => { // promotions come with the cached catalog
    db.cache.get('catalog').then((c) => {
      const cat = c as Extract<CacheRecord, { id: 'catalog' }> | undefined
      setPromotions(cat?.promotions ?? [])
      setUtcOffset(cat?.utc_offset_minutes ?? 0)
      setGrocery(cat?.grocery ?? null)
      setPharmacy(cat?.pharmacy ?? null)
      setRestaurant(cat?.restaurant ?? null)
      setElectronics(!!cat?.electronics)
      setFashion(cat?.fashion ?? null)
      setServices(cat?.services ?? null)
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
  // The price of one unit on the price list in use, to check a price without adding the item.
  const priceOf = (p: Product, u: Unit) => resolvePrice(p, u.id, list.id, 1000, new Date())
  const cart = useMemo(() => priceCart(withDeposits(lines, products), list, new Date(), { promotions, branchId: boot.branch.id, utcOffsetMinutes: utcOffset, coupon, saleDiscountBp: saleBp,
    serviceBp: restaurant?.service_charge?.bp ?? 0 }), [lines, products, list, promotions, utcOffset, coupon, saleBp, boot.branch.id, restaurant])
  const results = useMemo(() => search(products, query), [products, query])
  // Stock left (setting "Show stock left on the till"): the stock last received, less this sale and unsent sales.
  const productMap = useMemo(() => new Map(products.map((p) => [p.id, p])), [products])
  const [queuedSales, setQueuedSales] = useState<{ lines?: QueuedLine[] }[]>([])
  useEffect(() => {
    if (!boot.show_stock) return
    void db.queue.where('status').anyOf('pending', 'review').toArray()
      .then((items) => setQueuedSales(items.filter((i) => i.type === 'sale').map((i) => i.payload as { lines?: QueuedLine[] })))
  }, [boot.show_stock, queue.pending, queue.review])
  const inCart = useMemo(() => takenMilli(productMap, lines.map((l) => ({ productId: l.product.id, unitId: l.unit.id, qtyMilli: l.qtyMilli }))), [productMap, lines])
  const inQueue = useMemo(() => takenMilli(productMap, queuedLines(queuedSales)), [productMap, queuedSales])
  const stockLeft = (p: Product) => {
    const n = boot.show_stock ? leftMilli(p, inCart, inQueue) : null
    if (n === null) return null
    const unit = p.units.find((u) => u.is_base)?.uom_code ?? ''
    if (n <= 0) return { text: n === 0 ? 'None left' : `None left (${prettyQty(n)} ${unit})`, out: true, low: false }
    return isLow(p, n) ? { text: `Low: ${prettyQty(n)} ${unit} left`, out: false, low: true } : { text: `${prettyQty(n)} ${unit} left`, out: false, low: false }
  }

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'F2') { e.preventDefault(); searchRef.current?.focus() }
      if (e.key === 'F1') { e.preventDefault(); setCalcOpen(true) }
      if (e.key === 'F12' && lines.length && !paying) { e.preventDefault(); startPay() }
      if (e.key === 'F4' && !paying) { e.preventDefault(); setPicking(true) }
      if (e.key === 'F8' && !paying && online) { e.preventDefault(); setReturning(true) }
      if (e.key === 'F6' && !paying && online && restaurant) { e.preventDefault(); setFloorOpen(true) }
      if (e.key === 'F7' && !paying && online && electronics) { e.preventDefault(); setRepairsOpen(true) }
      if (e.key === 'F9' && !paying && online && fashion) { e.preventDefault(); setAlterationsOpen(true) }
      if (e.key === 'F10' && !paying && online && services) { e.preventDefault(); setAppointmentsOpen(true) }
      if (e.key === 'F3' && !paying && boot.van) { e.preventDefault(); setRouteOpen(true) }
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
    const style = fashion && !unit ? styleOf(product, fashion.styles) : null
    if (style && !stylePick) {
      setQuery('')
      return setStylePick(style) // choose the size and colour from the grid
    }
    if (services?.staff.length && isService(product) && !unit) {
      setQuery('')
      return setStaffPick({ product }) // who is doing it (their commission)
    }
    if (product.track_serials) {
      setQuery('')
      return setSerialPick(product) // each unit by its serial / IMEI number
    }
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

  /** Serial-numbered units: one line with their numbers (the quantity is the number of units). */
  function addSerials(product: Product, serials: string[]) {
    setLines((l) => [...l, { key: `sn:${uuidv7()}`, product, unit: product.units.find((u) => u.is_base) ?? product.units[0], qtyMilli: serials.length * 1000, serials }])
    setSerialPick(null)
    searchRef.current?.focus()
  }

  /** Distribution: send the cart as an order for delivery (from the van's warehouse), queued if offline. */
  async function sendOrder() {
    if (!customer) return setMessage('An order is for a customer: choose the customer (F4) first.')
    const orderLines = lines.filter((l) => !l.autoDeposit && l.qtyMilli > 0 && !l.product.is_container)
    if (!orderLines.length) return setMessage('Add what the customer is ordering.')
    setBusy(true)
    try {
      const id = uuidv7()
      const r = await submitRoadEvent(online, 'order', { id, customer_id: customer.id, lines: orderLines.map((l) => ({ product_uom_id: l.unit.id, qty: (l.qtyMilli / 1000).toString() })) })
      if (routeCtx?.customerId === customer.id) await recordVisit(online, customer.id, routeCtx.routeId, 'order', { customer_order_id: id }).catch(() => undefined)
      setMessage(r.queued ? `Order for ${customer.name} saved on this phone; it is sent when there is signal.`
        : `Order ${r.number} sent for ${customer.name}${r.status === 'on_hold' ? ' — it is on credit hold until the office releases it' : ''}.`)
      setLines([])
      setOrderMode(false)
      setRouteCtx(null)
      pickCustomer(null)
    } catch (e) {
      setMessage(e instanceof Error ? e.message : String(e))
    } finally {
      setBusy(false)
    }
  }

  /** A service done by a staff member (or nobody in particular); a session from a prepaid pass is a line at no charge. */
  function addService(product: Product, staff: StaffMember | null, pass?: { pass: ServicePass; item: number }, appointmentId?: string) {
    setLines((l) => [...l, { key: `svc:${uuidv7()}`, product, unit: product.units.find((u) => u.is_base) ?? product.units[0], qtyMilli: 1000,
      staff: staff ? { id: staff.id, name: staff.name } : null, pass: pass ? { id: pass.pass.id, item: pass.item, number: pass.pass.number } : null, appointmentId }])
    setStaffPick(null)
    setQuery('')
    searchRef.current?.focus()
  }

  /** Check out an appointment: its service on the till, done by the person booked (the sale marks the appointment done). */
  function checkoutAppointment(a: Appointment) {
    const p = products.find((x) => x.id === a.product_id)
    if (!p) return setMessage('Refresh the till: that service is not in its catalog.')
    if (!customer && a.customer_id) {
      const c = customers.find((x) => x.id === a.customer_id)
      if (c) pickCustomer(c)
    }
    addService(p, { id: a.employee_id, name: a.employee_name ?? '', job_title: null }, undefined, a.id)
    setAppointmentsOpen(false)
  }

  /** Collect an alteration: one line of the alteration product at the agreed charge (the sale marks it collected). */
  function collectAlteration(a: Alteration, productId: string) {
    const p = products.find((x) => x.id === productId)
    if (!p) return setMessage('Refresh the till: the alteration product is not in its catalog.')
    setLines((l) => [...l, { key: `alt:${a.id}`, product: p, unit: p.units.find((u) => u.is_base) ?? p.units[0], qtyMilli: 1000, alteration: { id: a.id, charge_minor: a.charge_minor },
      note: `${a.number} · ${a.garment}` }])
    setAlterationsOpen(false)
  }

  /** Collect a repair: its parts and labour become the cart (the sale marks the job collected). */
  function collectRepair(job: RepairJob) {
    const missing = job.lines.filter((l) => !products.some((p) => p.id === l.product_id))
    if (missing.length) return setMessage(`Refresh the till: ${missing.map((m) => m.name).join(', ')} not in its catalog.`)
    setLines(job.lines.map((l, i) => {
      const product = products.find((p) => p.id === l.product_id)!
      return { key: `rj:${job.id}:${i}`, product, unit: product.units.find((u) => u.id === l.product_uom_id) ?? product.units[0], qtyMilli: Math.round(Number(l.qty) * 1000), repairJobId: job.id }
    }))
    setRepairsOpen(false)
    setPayNow(true)
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
  // Open payment once the bill's lines are in place (after the render that set them), not on every render.
  useEffect(() => {
    if (payNow) {
      setPayNow(false)
      startPay()
    }
  }, [payNow]) // eslint-disable-line react-hooks/exhaustive-deps

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
    if (orderMode) {
      sendOrder()
      return
    }
    if (cart.total < 0 && lines.length) { // only empties came back: pay the deposit out
      if (window.confirm(`Pay out ${money(-cart.total)} in cash for the empties?`)) complete([{ method: 'cash', amount_minor: cart.total, tendered_minor: cart.total }])
      return
    }
    if (lines.some((l) => l.product.is_pack) && !customer) {
      setMessage('A prepaid pack belongs to a customer: choose the customer (F4) first.')
      return
    }
    if (cart.unpriced.length) {
      setMessage(`No ${list.name} price for: ${cart.unpriced.map((l) => l.product.name).join(', ')}`)
      return
    }
    setPayError('')
    const missing = detailsMissing(boot.require_details, details, customer)
    if (missing.length) {
      setDetailsPrompt({ message: `Enter the customer's ${missing.join(' and ')} before payment, or choose a customer.` })
      return
    }
    if (pharmacy && lines.some((l) => needsPrescription(l.product)) && !rx) {
      // Ask only for what this branch requires (Settings → Pharmacy); warnings are always shown.
      const found = rxWarnings([...new Map(lines.map((l) => [l.product.id, l.product])).values()], customer?.allergies ?? [], pharmacy.interactions)
      const level = saleLevel(pharmacy.rules, lines.map((l) => ({ product: l.product, qtyBaseMilli: Math.round(l.qtyMilli * Number(l.unit.factor)) })),
        found.some((w) => w.severity === 'major'))
      if (level !== 'free' || found.length > 0) {
        setRxPrompt({ message: '', level: level ?? 'approve' })
        return
      }
    }
    if (cart.total === 0 && lines.length) {
      complete([], {}) // only sessions from prepaid passes: nothing to pay
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
        diningOrderId: payingBill?.id ?? null, tipMinor: approvals.tip ?? 0, kitchenTickets: !!restaurant,
        tradeIns: approvals.tradeIns ?? [], tradeInApproval: approvals.tradeInApproval ?? null, details: detailsPayload(details, !!customer) })
      setLines([])
      setPaying(false)
      setApproval(null)
      setCoupon('')
      setCouponInput('')
      setSaleBp(0)
      setRx(null)
      setDetails(emptyDetails())
      setPayingBill(null)
      if (routeCtx && customer?.id === routeCtx.customerId) { // a sale on the route is the visit's outcome
        recordVisit(online, routeCtx.customerId, routeCtx.routeId, 'sold', { sale_id: sale.id }).catch(() => undefined)
      }
      setRouteCtx(null)
      pickCustomer(null)
      checkCashLimit(online, boot.drawer?.cash_limit_minor ?? 0).then(setCashHigh).catch(() => undefined)
      onPosted(sale)
    } catch (err) {
      const e = err instanceof ApiError ? err : null
      const retrying = ['approval_failed', 'over_limit', 'own_document', 'pin_locked'].includes(e?.code ?? '')
      if (['prescription_required', 'pharmacist_needed'].includes(e?.code ?? '') || (retrying && approvals.rx?.approval && !approvals.credit && !approvals.discount)) {
        setRxPrompt({ message: e!.message, payments, approvals: { ...approvals, rx: undefined }, level: e?.code === 'pharmacist_needed' ? 'approve' : 'details' })
        return
      }
      if (e?.code === 'details_required' || e?.code === 'details_invalid') {
        setPaying(false)
        setDetailsPrompt({ message: e.message })
        return
      }
      if (e?.code === 'credit_needs_approval' || (retrying && approvals.credit && !approvals.discount)) {
        setApproval({ payments, message: e!.message, kind: 'credit', approvals: { ...approvals, credit: undefined } })
        return
      }
      if (e?.code === 'signed_out') {
        setApproval({ payments, message: e.message, kind: 'sign_in', approvals })
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
    if (kind === 'sign_in') {
      try {
        await signInAgain(boot.user.id, a.username, a.pin)
      } catch (err) {
        return setApproval({ ...approval, message: err instanceof Error ? err.message : String(err) })
      }
      return complete(payments, approvals)
    }
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
        <div><BrandIcon boot={boot} /><strong>{boot.branch.code} · {boot.till?.name}</strong> <span className="muted">{boot.user.name}</span></div>
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
              {online && services && <button type="button" className="link" onClick={() => setPassesOpen(true)}>Passes</button>}
              <button type="button" className="link" onClick={() => pickCustomer(null)} aria-label="Remove customer">✕</button>
            </span>
          ) : <button type="button" onClick={() => setPicking(true)}>Customer (F4)</button>}
          <button type="button" onClick={() => setCalcOpen(true)} title="Calculator">Calculator (F1)</button>
          {online && restaurant && <button type="button" onClick={() => setFloorOpen(true)}>Tables (F6)</button>}
          {online && electronics && <button type="button" onClick={() => setRepairsOpen(true)}>Repairs (F7)</button>}
          {online && fashion && <button type="button" onClick={() => setAlterationsOpen(true)}>Alterations (F9)</button>}
          {online && services && <button type="button" onClick={() => setAppointmentsOpen(true)}>Appointments (F10)</button>}
          {boot.van && <span className="chip" aria-label="Van">Van {boot.van.van.code}{achieved(boot.van) !== null ? ` · ${achieved(boot.van)}% of target` : ''}</span>}
          {boot.van && <button type="button" onClick={() => setRouteOpen(true)}>Route (F3)</button>}
          {containers.length > 0 && <button type="button" onClick={() => setEmptiesOpen(true)}>Empties returned</button>}
          {boot.van && <button type="button" className={orderMode ? 'primary' : ''} aria-pressed={orderMode} onClick={() => setOrderMode((m) => !m)}>
            {orderMode ? 'Taking an order — tap to sell instead' : 'Take order'}</button>}
          {payingBill && <span className="chip" aria-label="Paying bill">Paying {payingBill.label}
            <button type="button" className="link" onClick={() => { setPayingBill(null); setLines([]) }} aria-label="Stop paying this bill">✕</button></span>}
          {online && <button type="button" onClick={() => setReturning(true)}>Return (F8)</button>}
          <button type="button" onClick={() => setDrawerTab('payout')}>Drawer</button>
          <button type="button" onClick={() => { if (confirmLeave(lines.length > 0, 'the sale on screen')) onCloseShift() }}>Close shift</button>
          <button type="button" onClick={() => { if (confirmLeave(lines.length > 0, 'the sale on screen')) onLogout() }}>Sign out</button>
        </div>
      </header>

      {cashHigh && (
        <div className="banner warn" role="status">
          <span><strong>Cash in the drawer is above {formatMoney(boot.drawer?.cash_limit_minor ?? 0, boot.company.currency, boot.company.exponent)}.</strong> Ask a supervisor for a cash drop.</span>
          <button type="button" onClick={() => setDrawerTab('drop')}>Cash drop</button>
        </div>
      )}
      {staleShift && (
        <div className="banner warn" role="status">
          <span><strong>This shift was opened on {new Date(boot.shift!.opened_at).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })} and
            not closed.</strong> Close it (count the cash) and open a new shift, so each day's cash balances on its own.</span>
          <button type="button" onClick={() => { if (confirmLeave(lines.length > 0, 'the sale on screen')) onCloseShift() }}>Close shift</button>
        </div>
      )}
      {drawerNote && <div className="banner ok" role="status"><span>{drawerNote}</span><button type="button" className="link" onClick={() => setDrawerNote('')} aria-label="Dismiss">✕</button></div>}
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
        <form ref={findForm} onSubmit={onScanOrSearch} className="scan">
          <input ref={searchRef} autoFocus className="big" value={query} onChange={(e) => setQuery(e.target.value)}
            placeholder="Scan a barcode or type a name / SKU (F2)" aria-label="Scan or search" autoComplete="off" />
          {cameraScanSupported() && <button type="button" className="camera-btn" onClick={() => setCamera(true)} aria-label="Scan with the camera">📷</button>}
        </form>
        {camera && <CameraScan onScan={(code) => { setCamera(false); scanned(code) }} onClose={() => setCamera(false)} />}
        {message && <p className="error" role="alert">{message}</p>}
        <ul className="results">
          {results.map((p) => (
            <li key={p.id}>
              <button type="button" onClick={() => add(p)}>
                <span>{p.name}<br /><span className="muted small">{p.sku}{p.location ? ` · ${p.location}` : ''}{stockLeft(p) && <> · <span className={stockLeft(p)!.out ? 'stock-out' : stockLeft(p)!.low ? 'stock-low' : ''}>{stockLeft(p)!.text}</span></>}{expiryNote(p) && <> · <span className={expiryNote(p)!.expired ? 'stock-out' : expiryNote(p)!.soon ? 'stock-low' : ''}>{expiryNote(p)!.text}</span></>}</span></span>
                {(() => { const base = p.units.find((u) => u.is_base); const pr = base && priceOf(p, base)
                  return <span className="result-price mono">{pr ? money(pr.amount_minor) : 'no price'}{base && p.units.length > 1 ? <span className="muted small"> /{base.uom_code}</span> : null}</span> })()}
              </button>
              {p.units.filter((u) => !u.is_base).map((u) => {
                const pr = priceOf(p, u)
                return <button key={u.id} type="button" className="unit" onClick={() => add(p, u)}>+ {u.uom_code}{pr ? <span className="small mono"> {money(pr.amount_minor)}</span> : null}</button>
              })}
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
                    {!l.service && stockLeft(l.product) && <div className={`small ${stockLeft(l.product)!.out ? 'stock-out' : stockLeft(l.product)!.low ? 'stock-low' : 'muted'}`}>{stockLeft(l.product)!.text}</div>}
                    {expiryNote(l.product) && <div className={`small ${expiryNote(l.product)!.expired ? 'stock-out' : expiryNote(l.product)!.soon ? 'stock-low' : 'muted'}`}>{expiryNote(l.product)!.text}</div>}
                    {l.note && <div className="small muted">{l.note}</div>}
                    {!l.service && (l.product.drug || l.product.dosage || l.dosage != null) && (
                      <input className="dosage-input small" value={l.dosage ?? ''} maxLength={255} placeholder="Dosage, e.g. 1 tablet 3 times daily"
                        onChange={(e) => { const v = e.target.value; setLines((x) => x.map((y) => (y.key === l.key ? { ...y, dosage: v } : y))) }}
                        aria-label={`Dosage for ${l.product.name}`} />
                    )}
                    {l.serials && <div className="small muted mono">S/N {l.serials.join(', ')}</div>}
                    {l.markdown && <div className="small promo">Marked-down sticker</div>}
                    {l.staff && <div className="small muted">with {l.staff.name}</div>}
                    {l.autoDeposit && <div className="small muted">Deposit for the containers</div>}
                    {l.qtyMilli < 0 && <div className="small promo">Empties returned</div>}
                    {l.pass && <div className="small promo">Prepaid session · {l.pass.number}</div>}
                    {l.promotions.map((p) => <div key={p.id} className="small promo">{p.name} −{money(p.saving_minor)}</div>)}
                    {l.manual && <div className="small promo">Discount −{money(l.discount - l.promotions.reduce((a, p) => a + p.saving_minor, 0))}</div>}
                  </td>
                  <td>{l.diningItemId || l.service || l.serials || l.repairJobId || l.alteration || l.pass || l.appointmentId || l.autoDeposit ? prettyQty(l.qtyMilli) : (
                    <input className="qty mono" inputMode="decimal" defaultValue={prettyQty(l.qtyMilli)} key={l.qtyMilli}
                      onBlur={(e) => changeQty(l, e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()} aria-label={`Quantity of ${l.product.name}`} />)}
                    {isDesktop() && l.unit.decimals > 0 && !l.diningItemId && (
                      <button type="button" className="small" aria-label={`Weigh ${l.product.name} on the scale`}
                        onClick={() => void readScale().then((kg) => (kg ? changeQty(l, kg) : setMessage('No scale is set up on this till.'))).catch((e) => setMessage(String(e)))}>Weigh</button>
                    )}</td>
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
                    {!l.diningItemId && !l.autoDeposit && <button type="button" className="link" onClick={() => setLines((x) => setQty(x, l.key, 0))} aria-label={`Remove ${l.service ? 'service charge' : l.product.name}`}>✕</button>}</td>
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
            <button type="button" onClick={() => setDetailsPrompt({ message: '' })} aria-label="Sale details">
              Details{details.comment || details.name || details.phone || Object.keys(details.extras).length ? ' ✓' : ''}</button>
            <button type="button" className="primary big" onClick={startPay} disabled={!lines.length || busy}>{orderMode ? 'Send order (F12)' : cart.total < 0 ? 'Pay out deposit (F12)' : 'Pay (F12)'}</button>
          </div>
        </div>
      </section>

      {paying && !approval && !rxPrompt && (
        <Pay total={cart.total} currency={boot.company.currency} exponent={boot.company.exponent} busy={busy} error={payError}
          customer={customer} tips={!!restaurant?.tips && online && payingBill?.kind !== 'delivery'} bill={payingBill}
          momoMode={boot.momo_confirm} online={online}
          nhis={pharmacy?.nhis ? nhisCover(cart.lines, customer, new Date().toLocaleDateString('en-CA')) : null}
          tradeInProducts={electronics && online ? products : null}
          onPay={(p, tip, extra) => complete(p, { tip, tradeIns: extra.tradeIns, tradeInApproval: extra.tradeInApproval })} onCancel={() => setPaying(false)} />
      )}
      {calcOpen && <Calculator onClose={() => setCalcOpen(false)} />}
      {detailsPrompt && <SaleDetailsDialog value={details} message={detailsPrompt.message} hasCustomer={!!customer} pharmacy={!!pharmacy} restaurant={!!restaurant} services={!!services}
        onSave={(d) => { setDetails(d); setDetailsPrompt(null) }} onCancel={() => setDetailsPrompt(null)} />}
      {rxPrompt && pharmacy && (
        <PrescriptionForm message={rxPrompt.message} level={rxPrompt.level ?? 'approve'} initial={rx} lines={lines} products={products} customer={customer} pharmacy={pharmacy}
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
      {stylePick && <StylePicker style={stylePick} products={products} onPick={(p) => { setStylePick(null); add(p, p.units.find((u) => u.is_base) ?? p.units[0]) }}
        onCancel={() => setStylePick(null)} />}
      {alterationsOpen && <AlterationsScreen currency={boot.company.currency} exponent={boot.company.exponent} onCollect={collectAlteration} onClose={() => setAlterationsOpen(false)} />}
      {routeOpen && boot.van && <RouteScreen day={boot.van} customers={customers} online={online} onClose={() => setRouteOpen(false)}
        onSell={(c, routeId) => { pickCustomer(c); setRouteCtx({ customerId: c.id, routeId }); setOrderMode(false); setRouteOpen(false) }}
        onOrder={(c, routeId) => { pickCustomer(c); setRouteCtx({ customerId: c.id, routeId }); setOrderMode(true); setRouteOpen(false) }} />}
      {emptiesOpen && <EmptiesDialog containers={containers} onCancel={() => setEmptiesOpen(false)}
        onAdd={(p, q) => { setLines((l) => [...l, { key: `empties:${uuidv7()}`, product: p, unit: p.units.find((u) => u.is_base) ?? p.units[0], qtyMilli: -q }]); setEmptiesOpen(false) }} />}
      {staffPick && services && <StaffPrompt product={staffPick.product} staff={services.staff} onPick={(s) => addService(staffPick.product, s, staffPick.pass)}
        onCancel={() => setStaffPick(null)} />}
      {appointmentsOpen && services && <AppointmentsScreen products={products} services={services} customer={customer} onCheckout={checkoutAppointment}
        onClose={() => setAppointmentsOpen(false)} />}
      {passesOpen && customer && <PassesScreen customer={customer} products={products} lines={lines}
        onUse={(pass, item, product) => { setPassesOpen(false); setStaffPick({ product, pass: { pass, item } }) }} onClose={() => setPassesOpen(false)} />}
      {serialPick && <SerialPrompt product={serialPick} onDone={(s) => addSerials(serialPick, s)} onCancel={() => setSerialPick(null)} />}
      {repairsOpen && <RepairsScreen products={products} currency={boot.company.currency} exponent={boot.company.exponent}
        onCollect={collectRepair} onClose={() => setRepairsOpen(false)} />}
      {drawerTab && <DrawerScreen boot={boot} online={online} initial={drawerTab} onCancel={() => setDrawerTab(null)}
        onDone={(m, s) => { setDrawerTab(null); if (s) setSlip({ slip: s, message: m }); else setDrawerNote(m); checkCashLimit(online, boot.drawer?.cash_limit_minor ?? 0).then(setCashHigh).catch(() => undefined) }} />}
      {slip && <SlipScreen slip={slip.slip} boot={boot} message={slip.message} onDone={() => setSlip(null)} />}
      {picking && <CustomerPicker customers={customers} money={money} onPick={pickCustomer} onCancel={() => setPicking(false)} />}
      {approval && <CreditApproval title={approval.kind === 'sign_in' ? 'Sign in again' : approval.kind === 'credit' ? 'Supervisor approval — credit' : 'Supervisor approval — discount'}
        self={approval.kind === 'sign_in'} message={approval.message} busy={busy} onApprove={approve} onCancel={() => setApproval(null)} />}
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
function PrescriptionForm({ message, level, initial, lines, products, customer, pharmacy, offline, busy, onPickPatient, onSwap, onConfirm, onCancel }: {
  message: string; level: RxLevel; initial: Rx | null; lines: CartLine[]; products: Product[]; customer: TillCustomer | null; pharmacy: Pharmacy; offline: boolean; busy: boolean
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
  const needsDetails = level === 'approve' || level === 'details'
  const found = rxWarnings([...new Map(lines.map((l) => [l.product.id, l.product])).values()], customer?.allergies ?? [], pharmacy.interactions)
  function submit(e: React.FormEvent) {
    e.preventDefault()
    if (level !== 'free' && !customer) return setError('Choose the patient.')
    if (needsDetails && (!d.prescriber_name.trim() || !d.issued_on)) return setError('Enter the prescriber and the date on the prescription.')
    if (d.issued_on > today) return setError('The prescription date cannot be in the future.')
    if ((username && pin.length < 4) || (!username && pin)) return setError('Enter the pharmacist’s username and PIN, or leave both empty if you are the pharmacist.')
    onConfirm({ ...d, prescriber_name: d.prescriber_name.trim() }, username ? { username, pin } : null)
  }
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Prescription" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <form className="card pay rx" onSubmit={submit}>
        <h2>{needsDetails ? 'Prescription' : level === 'patient' ? 'Patient' : 'Medicine warnings'}</h2>
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
        {needsDetails && <>
          <label>Prescriber<input autoFocus value={d.prescriber_name} onChange={set('prescriber_name')} placeholder="Dr. …" /></label>
          <div className="row">
            <label>Reg. no.<input value={d.prescriber_reg_no ?? ''} onChange={set('prescriber_reg_no')} /></label>
            <label>Written on<input type="date" value={d.issued_on} max={today} onChange={set('issued_on')} /></label>
          </div>
          <div className="row">
            <label>Hospital / clinic<input value={d.facility ?? ''} onChange={set('facility')} /></label>
            <label>Prescription no.<input value={d.reference ?? ''} onChange={set('reference')} /></label>
          </div>
        </>}
        {level === 'approve' ? (
          <p className="muted small">Pharmacist approval{offline ? ' (checked on this till while offline)' : ''} — leave empty if you are the pharmacist.</p>
        ) : (
          <p className="muted small">A pharmacist may approve now (optional){offline ? ', checked on this till while offline' : ''}; otherwise the sale {pharmacy.rules?.after === 'signoff' ? 'waits for a pharmacist to sign it off' : 'is recorded as sold without a pharmacist'}.</p>
        )}
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

/** A supervisor approves with their own username and PIN (or, `self`, the cashier signs in again to finish the sale). */
function CreditApproval({ title, message, busy, onApprove, onCancel, self = false }: {
  title: string; message: string; busy: boolean; onApprove: (a: { username: string; pin: string }) => void; onCancel: () => void; self?: boolean
}) {
  const who = self ? 'Your' : 'Supervisor'
  const [username, setUsername] = useState('')
  const [pin, setPin] = useState('')
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={self ? 'Sign in again' : 'Supervisor approval'}>
      <form className="card pay" onSubmit={(e) => { e.preventDefault(); if (username && pin) onApprove({ username, pin }) }}>
        <h2>{title}</h2>
        <p className="error">{message}</p>
        <label>{who} username<input autoFocus value={username} onChange={(e) => setUsername(e.target.value)} autoCapitalize="none" /></label>
        <label>{who} PIN<input type="password" inputMode="numeric" value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 8))} /></label>
        <div className="row">
          <button type="button" onClick={onCancel} disabled={busy}>Back</button>
          <button className="primary big" disabled={busy || !username || pin.length < 4}>{busy ? 'Checking…' : self ? 'Sign in and complete the sale' : 'Approve'}</button>
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
/** The company's icon in the top bar (cached for offline use). */
function BrandIcon({ boot }: { boot: Bootstrap }) {
  const [src, setSrc] = useState<string | null>(null)
  const url = boot.brand?.icon_url ?? null
  useEffect(() => {
    let live = true
    void cachedImage(url).then((s) => { if (live) setSrc(s) })
    return () => { live = false }
  }, [url])
  return src ? <img className="brand-icon" src={src} alt="" /> : null
}
