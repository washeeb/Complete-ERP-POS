import { v7 as uuidv7 } from 'uuid'
import { currentPosition, rememberVisit } from './distribution'
import { submitRoadEvent } from './till'
import type { VisitOutcome } from './types'

/** Record a visit (with the phone's position when allowed); queued if the server cannot be reached. */
export async function recordVisit(online: boolean, customerId: string, routeId: string | null, outcome: VisitOutcome, extra: { note?: string | null; sale_id?: string | null; customer_order_id?: string | null } = {}) {
  const pos = await currentPosition()
  await submitRoadEvent(online, 'visit', { id: uuidv7(), customer_id: customerId, route_id: routeId, outcome, occurred_at: new Date().toISOString(), ...pos, ...extra })
  return rememberVisit(customerId, outcome)
}
