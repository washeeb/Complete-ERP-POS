import { v7 as uuidv7 } from 'uuid'
import { shiftClosedOnServer } from './shift'
import { warrantyUntil } from './electronics'
import { api, ApiError, SIGNED_OUT, van as vanApi, type OrderBody, type VisitBody, type ShiftGrant } from './api'
import { db, getLocalShift, getSession, type OfflineGrant } from './db'
import { enqueue, grantFor, saveGrant } from './offline'
import type { Bootstrap, Payment, PostedSale, Shift, TradeInItem } from './types'
import type { priceCart } from './cart'

/**
 * One rule for every till action: talk to the server directly only when it is reachable, this
 * session has a server token, and nothing is waiting in the queue (so work always reaches the
 * server in the order it happened). Otherwise the action goes into the signed offline queue under
 * the person's offline grant.
 */
export async function queueMode(online: boolean): Promise<boolean> {
  const session = await getSession()
  return !online || !session?.token || (await db.queue.count()) > 0
}

/**
 * Distribution: a visit or an order from the road. Sent straight away when the server answers; otherwise (or when other
 * work is already waiting) it joins the offline queue and is sent in order later. Returns whether it was queued.
 */
export async function submitRoadEvent(online: boolean, type: 'visit' | 'order', payload: VisitBody | OrderBody): Promise<{ queued: boolean; number?: string; status?: string }> {
  if (!(await queueMode(online))) {
    try {
      if (type === 'visit') {
        await vanApi.visit(payload as VisitBody)
        return { queued: false }
      }
      const r = await vanApi.order(payload as OrderBody)
      return { queued: false, number: r.order.number, status: r.order.status }
    } catch (e) {
      if (!isNetwork(e)) throw e
    }
  }
  const { id, ...rest } = payload
  await enqueue({ id, type, grant: await currentGrant(), payload: rest })
  return { queued: true }
}

export async function currentGrant(): Promise<OfflineGrant> {
  const session = await getSession()
  // The sign-in ended (it lasts 12 hours from the last use): say so, so the cashier signs in again instead of guessing.
  if (!session) throw new ApiError('signed_out', SIGNED_OUT, 401)
  const g = await grantFor(session.userId)
  if (!g) throw new Error('The server is not answering, and this till cannot keep the sale offline for you yet (sign in once while the server answers to allow that). Try again in a moment.')
  return g
}

/**
 * Sign in again in place (the sign-in ended mid-sale): same person only, since the open shift and the cart are theirs.
 * A fresh session and offline sign-in are saved, so the sale can be completed straight away.
 */
export async function signInAgain(userId: string, username: string, pin: string): Promise<void> {
  const r = await api.login(username.trim(), pin)
  if (r.user.id !== userId) {
    throw new Error('Sign in as the person using this till. To change cashier, go back to the cart and sign out.')
  }
  if (r.offline_grant) await saveGrant(r.offline_grant)
  await db.kv.put({ id: 'session', token: r.token, userId: r.user.id, userName: r.user.name, expiresAt: r.expires_at })
}

const isNetwork = (e: unknown) => e instanceof ApiError && e.code === 'network'

/** Open the till. With cash control, on the vault the cashier issued to this teller (its float is the vault's). */
export async function openShift(floatMinor: number, online: boolean, vaultSessionId: string | null = null, grant: ShiftGrant | null = null,
  own: { id: string; name: string } | null = null): Promise<void> {
  const id = uuidv7()
  const openedAt = new Date().toISOString()
  let queued = await queueMode(online)
  if (!queued) {
    try {
      await api.openShift(floatMinor, id, vaultSessionId, grant, own?.id ?? null)
    } catch (e) {
      if (!isNetwork(e)) throw e
      queued = true
    }
  }
  if (queued) {
    await enqueue({ id, type: 'shift_open', grant: await currentGrant(),
      payload: { id, opening_float_minor: floatMinor, opened_at: openedAt, vault_session_id: vaultSessionId, vault_id: own?.id ?? null } })
  }
  const me = await getSession()
  await db.kv.put({ id: 'shift', shiftId: id, openedAt, floatMinor, cashMinor: 0, queued, vault: vaultSessionId !== null, openedById: me?.userId, openedByName: me?.userName, vaultName: own?.name ?? null })
}

/**
 * Blind close. Offline, the expected cash is worked out on the till and confirmed by the server at sync. On a vault the
 * result stays blind (the cashier counts next): expected and over/short come back empty.
 */
export async function closeShift(countedMinor: number, note: string, online: boolean, denoms: Record<string, number> | null = null,
  other: Record<string, number> | null = null): Promise<{ shift: Shift; provisional: boolean }> {
  const local = await getLocalShift()
  let queued = await queueMode(online)
  if (!queued) {
    try {
      const r = await api.closeShift(countedMinor, note, denoms, other)
      await db.kv.delete('shift')
      return { shift: r.shift, provisional: false }
    } catch (e) {
      if (!isNetwork(e)) throw e
      queued = true
    }
  }
  if (!local) throw new Error('This till does not know which shift is open. Reconnect to the server to close it.')
  const closedAt = new Date().toISOString()
  await enqueue({ id: uuidv7(), type: 'shift_close', grant: await currentGrant(),
    payload: { shift_id: local.shiftId, counted_cash_minor: countedMinor, note: note || null, closed_at: closedAt, denoms, other } })
  await db.kv.delete('shift')
  const expected = local.floatMinor + local.cashMinor
  const blind = local.vault === true
  return {
    provisional: true,
    shift: { id: local.shiftId, status: 'closed', opened_at: local.openedAt, opened_by: '', opening_float_minor: local.floatMinor,
      expected_cash_minor: blind ? null : expected, counted_cash_minor: countedMinor, over_short_minor: blind ? null : countedMinor - expected },
  }
}

type Cart = ReturnType<typeof priceCart>

/** How long a sale waits for the server before the till carries on without it (and prints). */
export const SALE_WAIT_MS = 4000

/** The paper prescription, as the pharmacist enters it before payment (Pharmacy). */
export type RxDetails = { prescriber_name: string; prescriber_reg_no?: string; facility?: string; reference?: string; issued_on: string }

/** Post the sale now if we can; otherwise queue it and print a receipt marked "not yet sent". */
export async function submitSale(args: {
  boot: Bootstrap; online: boolean; cart: Cart; listId: string; payments: Payment[]; seq: number; catalogVersion: string | null
  customerId?: string | null; creditApproval?: { username: string; pin: string } | null; saleId?: string
  coupon?: string | null; saleDiscountBp?: number; discountApproval?: { username: string; pin: string } | null; offlineApproverId?: string | null
  prescription?: RxDetails | null; pharmacistApproval?: { username: string; pin: string } | null; pharmacistOfflineBy?: string | null
  diningOrderId?: string | null; tipMinor?: number; kitchenTickets?: boolean
  tradeIns?: TradeInItem[]; tradeInApproval?: { username: string; pin: string } | null
  details?: { comment?: string; comment_on_receipt?: boolean; walk_in?: { name: string | null; phone: string | null }; extras?: Record<string, unknown> }
}): Promise<PostedSale> {
  const { boot, cart, payments } = args
  const local = await getLocalShift()
  const shiftId = boot.shift?.id ?? local?.shiftId
  if (!shiftId) throw new Error('Open a shift first.')
  const payload = {
    id: args.saleId ?? uuidv7(),
    device_seq: args.seq,
    customer_id: args.customerId ?? null,
    occurred_at: new Date().toISOString(),
    price_list_id: args.listId,
    shift_id: shiftId,
    catalog_version: args.catalogVersion,
    lines: cart.lines.map((l) => ({
      product_id: l.product.id, product_uom_id: l.unit.id, qty: (l.qtyMilli / 1000).toString(), unit_price_minor: l.price!.amount_minor, line_total_minor: l.total,
      ...(l.manual ? { manual: l.manual } : {}),
      ...(l.markdown ? { markdown_id: l.markdown.id } : {}),
      ...(l.diningItemId ? { dining_item_id: l.diningItemId } : {}),
      ...(l.note ? { note: l.note } : {}),
      ...(l.dosage?.trim() ? { dosage: l.dosage.trim() } : {}),
      ...(l.service ? { service_charge: true } : {}),
      ...(l.serials?.length ? { serials: l.serials } : {}),
      ...(l.repairJobId ? { repair_job_id: l.repairJobId } : {}),
      ...(l.alteration ? { alteration_id: l.alteration.id } : {}),
      ...(l.staff ? { staff_id: l.staff.id } : {}),
      ...(l.pass ? { service_pass_id: l.pass.id, pass_item: l.pass.item } : {}),
      ...(l.appointmentId ? { appointment_id: l.appointmentId } : {}),
    })),
    ...(args.diningOrderId ? { dining_order_id: args.diningOrderId } : {}),
    ...(args.tipMinor ? { tip_minor: args.tipMinor } : {}),
    ...(args.tradeIns?.length ? { trade_ins: args.tradeIns.map((t) => ({ product_id: t.product_id, serial: t.serial, condition: t.condition, value_minor: t.value_minor })) } : {}),
    ...(args.details ?? {}), // comment, walk-in name and phone, industry details
    coupon_code: args.coupon || null,
    sale_discount_bp: args.saleDiscountBp ?? 0,
    // Offline, a supervisor's PIN was checked against their offline sign-in on this till; the server checks their limit.
    ...(args.offlineApproverId ? { discount_approved_offline_by: args.offlineApproverId } : {}),
    ...(args.prescription ? { prescription: args.prescription } : {}),
    // Pharmacy offline: the pharmacist's PIN was checked against their offline sign-in here; the server checks their permission.
    ...(args.pharmacistOfflineBy ? { pharmacist_offline_by: args.pharmacistOfflineBy } : {}),
    totals: { net_minor: cart.net, tax_minor: cart.tax, total_minor: cart.total },
    payments,
  }
  const cash = payments.filter((p) => p.method === 'cash').reduce((a, p) => a + p.amount_minor, 0)
  const recordCash = async () => local && db.kv.put({ ...local, cashMinor: local.cashMinor + cash })

  if (!(await queueMode(args.online))) {
    try {
      // A supervisor's PIN goes to the server only, never into the offline queue: then the till waits for the answer.
      // Otherwise it waits a few seconds at most; on a slow network the sale is kept here, the receipt prints at once, and
      // the sale follows from the queue (the server ignores a second copy of the same sale).
      const approvals = !!(args.creditApproval || args.discountApproval || args.pharmacistApproval || args.tradeInApproval)
      const { sale } = await api.postSale({ ...payload, ...(args.creditApproval ? { credit_approval: args.creditApproval } : {}),
        ...(args.discountApproval ? { discount_approval: args.discountApproval } : {}),
        ...(args.pharmacistApproval ? { pharmacist_approval: args.pharmacistApproval } : {}),
        ...(args.tradeInApproval ? { trade_in_approval: args.tradeInApproval } : {}) }, approvals ? undefined : SALE_WAIT_MS)
      await recordCash()
      return sale
    } catch (e) {
      // The shift was closed elsewhere (in the back office, or by mistake): forget it here, so signing in again opens a new one.
      if (e instanceof ApiError && e.code === 'no_shift' && shiftClosedOnServer(true, false, local?.shiftId, (await db.queue.filter((q) => q.type === 'shift_open').toArray()).map((q) => q.id))) {
        await db.kv.delete('shift')
        throw new ApiError('no_shift', 'This till’s shift has been closed. Sign out and sign in again to open a new shift (the sale on screen was not recorded).', e.status)
      }
      if (!isNetwork(e)) throw e // refused by the server: nothing was recorded, the cashier can fix and retry
    }
  }

  // Going into the offline queue with a manual discount: a supervisor must confirm it here (PIN against their offline sign-in).
  if ((cart.lines.some((l) => l.manual) || (args.saleDiscountBp ?? 0) > 0) && !args.offlineApproverId) {
    throw new ApiError('offline_approval_needed', 'The server cannot be reached: a supervisor must approve the discount here.', 0)
  }
  const receiptNo = `${boot.branch.code}-${boot.till!.code}${boot.device.receipt_series ?? ''}-${String(args.seq).padStart(6, '0')}`
  await enqueue({ id: payload.id, type: 'sale', grant: await currentGrant(), payload, receiptNo })
  await recordCash()
  const session = await getSession()
  const list = boot.price_lists.find((l) => l.id === args.listId)!
  return {
    id: payload.id, receipt_no: receiptNo, device_seq: args.seq, status: 'queued', occurred_at: payload.occurred_at,
    comment: args.details?.comment_on_receipt ? args.details.comment ?? null : null, walk_in: args.details?.walk_in ?? null,
    cashier: session?.userName ?? '', currency: boot.company.currency, prices_include_tax: list.prices_include_tax,
    net_minor: cart.net, tax_minor: cart.tax, total_minor: cart.total,
    paid_minor: payments.reduce((a, p) => a + p.amount_minor, 0),
    change_minor: payments.reduce((a, p) => a + (p.tendered_minor - p.amount_minor), 0),
    tax_breakdown: cart.taxBreakdown,
    lines: cart.lines.map((l, i) => ({ line_no: i + 1, sku: l.product.sku, name: l.product.name, uom: l.unit.uom_code,
      qty: (l.qtyMilli / 1000).toFixed(3), unit_price_minor: l.price!.amount_minor, line_total_minor: l.total, discount_minor: l.discount, promotions: l.promotions, note: l.note ?? null, dosage: l.dosage?.trim().toUpperCase() || null, serials: l.serials ?? [],
      warranty_until: l.serials?.length ? warrantyUntil(l.product.warranty_months) : null })),
    tip_minor: args.tipMinor ?? 0, order_number: null,
    // Restaurant counter sale while offline: the kitchen cannot see it yet, so the till prints the kitchen ticket.
    kitchen: args.kitchenTickets && !args.diningOrderId ? cart.lines.filter((l) => l.product.station && !l.service)
      .map((l) => ({ name: l.product.name, qty: (l.qtyMilli / 1000).toString(), note: l.note ?? null, station: l.product.station! })) : [],
    kind: 'sale', discount_minor: cart.discount, points_earned: 0,
    payments: payments.map((p) => ({ method: p.method, provider: p.provider ?? null, amount_minor: p.amount_minor,
      tendered_minor: p.tendered_minor, reference: p.reference ?? null, status: 'queued' })),
  }
}
