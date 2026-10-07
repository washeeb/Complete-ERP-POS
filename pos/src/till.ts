import { v7 as uuidv7 } from 'uuid'
import { api, ApiError } from './api'
import { db, getLocalShift, getSession, type OfflineGrant } from './db'
import { enqueue, grantFor } from './offline'
import type { Bootstrap, Payment, PostedSale, Shift } from './types'
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

async function currentGrant(): Promise<OfflineGrant> {
  const session = await getSession()
  const g = session ? await grantFor(session.userId) : undefined
  if (!g) throw new Error('No offline sign-in for you on this till. Sign out and sign in again while the server is reachable.')
  return g
}

const isNetwork = (e: unknown) => e instanceof ApiError && e.code === 'network'

export async function openShift(floatMinor: number, online: boolean): Promise<void> {
  const id = uuidv7()
  const openedAt = new Date().toISOString()
  let queued = await queueMode(online)
  if (!queued) {
    try {
      await api.openShift(floatMinor, id)
    } catch (e) {
      if (!isNetwork(e)) throw e
      queued = true
    }
  }
  if (queued) {
    await enqueue({ id, type: 'shift_open', grant: await currentGrant(), payload: { id, opening_float_minor: floatMinor, opened_at: openedAt } })
  }
  await db.kv.put({ id: 'shift', shiftId: id, openedAt, floatMinor, cashMinor: 0, queued })
}

/** Blind close. Offline, the expected cash is worked out on the till and confirmed by the server at sync. */
export async function closeShift(countedMinor: number, note: string, online: boolean): Promise<{ shift: Shift; provisional: boolean }> {
  const local = await getLocalShift()
  let queued = await queueMode(online)
  if (!queued) {
    try {
      const r = await api.closeShift(countedMinor, note)
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
    payload: { shift_id: local.shiftId, counted_cash_minor: countedMinor, note: note || null, closed_at: closedAt } })
  await db.kv.delete('shift')
  const expected = local.floatMinor + local.cashMinor
  return {
    provisional: true,
    shift: { id: local.shiftId, status: 'closed', opened_at: local.openedAt, opened_by: '', opening_float_minor: local.floatMinor,
      expected_cash_minor: expected, counted_cash_minor: countedMinor, over_short_minor: countedMinor - expected },
  }
}

type Cart = ReturnType<typeof priceCart>

/** The paper prescription, as the pharmacist enters it before payment (Pharmacy). */
export type RxDetails = { prescriber_name: string; prescriber_reg_no?: string; facility?: string; reference?: string; issued_on: string }

/** Post the sale now if we can; otherwise queue it and print a receipt marked "not yet sent". */
export async function submitSale(args: {
  boot: Bootstrap; online: boolean; cart: Cart; listId: string; payments: Payment[]; seq: number; catalogVersion: string | null
  customerId?: string | null; creditApproval?: { username: string; pin: string } | null; saleId?: string
  coupon?: string | null; saleDiscountBp?: number; discountApproval?: { username: string; pin: string } | null; offlineApproverId?: string | null
  prescription?: RxDetails | null; pharmacistApproval?: { username: string; pin: string } | null; pharmacistOfflineBy?: string | null
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
    })),
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
      // A supervisor's PIN goes to the server only, never into the offline queue.
      const { sale } = await api.postSale({ ...payload, ...(args.creditApproval ? { credit_approval: args.creditApproval } : {}),
        ...(args.discountApproval ? { discount_approval: args.discountApproval } : {}),
        ...(args.pharmacistApproval ? { pharmacist_approval: args.pharmacistApproval } : {}) })
      await recordCash()
      return sale
    } catch (e) {
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
    cashier: session?.userName ?? '', currency: boot.company.currency, prices_include_tax: list.prices_include_tax,
    net_minor: cart.net, tax_minor: cart.tax, total_minor: cart.total,
    paid_minor: payments.reduce((a, p) => a + p.amount_minor, 0),
    change_minor: payments.reduce((a, p) => a + (p.tendered_minor - p.amount_minor), 0),
    tax_breakdown: cart.taxBreakdown,
    lines: cart.lines.map((l, i) => ({ line_no: i + 1, sku: l.product.sku, name: l.product.name, uom: l.unit.uom_code,
      qty: (l.qtyMilli / 1000).toFixed(3), unit_price_minor: l.price!.amount_minor, line_total_minor: l.total, discount_minor: l.discount, promotions: l.promotions })),
    kind: 'sale', discount_minor: cart.discount, points_earned: 0,
    payments: payments.map((p) => ({ method: p.method, provider: p.provider ?? null, amount_minor: p.amount_minor,
      tendered_minor: p.tendered_minor, reference: p.reference ?? null, status: 'queued' })),
  }
}
