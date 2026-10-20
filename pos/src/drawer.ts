import { v7 as uuidv7 } from 'uuid'
import { api, ApiError } from './api'
import { db, getLocalShift } from './db'
import { enqueue } from './offline'
import { currentGrant, queueMode } from './till'
import type { DrawerKind } from './types'

/**
 * The drawer besides sales: an expense paid out, cash paid in, a cash drop to the safe, the drawer opened without a
 * sale, and the cash-limit warning. Sent straight away when the server answers; otherwise queued (signed, in order)
 * like a sale. An approval given offline is the supervisor's PIN checked here against their offline sign-in.
 */
export type DrawerBody = {
  kind: DrawerKind; amount_minor?: number; account_id?: string | null; payee?: string | null; reason?: string | null
  reference?: string | null; photo?: string | null; approval?: { username: string; pin: string } | null; approved_by?: string | null
}

/** How the cash in the drawer changes: pay-ins add, pay-outs and drops take away. */
export function cashEffect(kind: DrawerKind, amountMinor: number): number {
  return kind === 'payin' ? amountMinor : kind === 'payout' || kind === 'drop' ? -amountMinor : 0
}

/** Cash the till believes is in the drawer: float + cash taken + pay-ins − pay-outs − drops. */
export async function cashInDrawer(): Promise<number | null> {
  const s = await getLocalShift()
  return s ? s.floatMinor + s.cashMinor : null
}

export async function recordDrawer(online: boolean, body: DrawerBody): Promise<{ queued: boolean; approvedBy: string | null }> {
  const local = await getLocalShift()
  if (!local) throw new Error('Open a shift first.')
  const id = uuidv7()
  const occurredAt = new Date().toISOString()
  let result: { queued: boolean; approvedBy: string | null } | null = null
  if (!(await queueMode(online))) {
    try {
      const { approved_by: _offlineOnly, ...sent } = body
      void _offlineOnly
      const r = await api.drawer({ id, ...sent })
      result = { queued: false, approvedBy: r.event.approved_by }
    } catch (e) {
      if (!(e instanceof ApiError && e.code === 'network')) throw e // refused: nothing recorded, the cashier can fix and retry
    }
  }
  if (!result) {
    if ((body.kind === 'drop' || body.approval) && !body.approved_by) {
      throw new ApiError('offline_approval_needed', 'The server cannot be reached: the supervisor approves here with their PIN.', 0)
    }
    const { approval: _pinStaysHere, ...payload } = body // a supervisor's PIN never goes into the queue
    void _pinStaysHere
    await enqueue({ id, type: 'drawer', grant: await currentGrant(), payload: { ...payload, shift_id: local.shiftId, occurred_at: occurredAt } })
    result = { queued: true, approvedBy: null }
  }
  const effect = cashEffect(body.kind, body.amount_minor ?? 0)
  if (effect !== 0) {
    const s = await getLocalShift()
    // A drop or pay-in starts the cash-limit warning afresh.
    if (s) await db.kv.put({ ...s, cashMinor: s.cashMinor + effect, limitAlerted: body.kind === 'drop' ? false : s.limitAlerted })
  }
  return result
}

/**
 * The cash limit (0 = off): true when the drawer is above it. The first time in a shift (until the next drop) the
 * supervisors are told; the till shows its own banner every time.
 */
export async function checkCashLimit(online: boolean, limitMinor: number): Promise<boolean> {
  if (!limitMinor) return false
  const s = await getLocalShift()
  if (!s) return false
  const inDrawer = s.floatMinor + s.cashMinor
  if (inDrawer <= limitMinor) return false
  if (!s.limitAlerted) {
    await db.kv.put({ ...s, limitAlerted: true })
    await recordDrawer(online, { kind: 'limit_alert', amount_minor: inDrawer }).catch(() => undefined)
  }
  return true
}

/** A receipt photo, made smaller on the till (longest side 1280 px, JPEG) so it travels quickly and fits the queue. */
export async function shrinkPhoto(file: File): Promise<string> {
  const url = URL.createObjectURL(file)
  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const i = new Image()
      i.onload = () => resolve(i)
      i.onerror = () => reject(new Error('That file is not a photo.'))
      i.src = url
    })
    const scale = Math.min(1, 1280 / Math.max(img.width, img.height))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(img.width * scale)
    canvas.height = Math.round(img.height * scale)
    canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height)
    return canvas.toDataURL('image/jpeg', 0.7)
  } finally {
    URL.revokeObjectURL(url)
  }
}
