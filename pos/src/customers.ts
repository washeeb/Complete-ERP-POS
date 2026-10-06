import { api } from './api'
import { db, type CacheRecord } from './db'
import type { TillCustomer } from './types'

/** Customers cached on the till so they can be looked up offline; refreshed when the server answers (ETag). */
export async function cachedCustomers(): Promise<{ customers: TillCustomer[]; version: string | null }> {
  const c = (await db.cache.get('customers')) as Extract<CacheRecord, { id: 'customers' }> | undefined
  return { customers: c?.customers ?? [], version: c?.version ?? null }
}

export async function refreshCustomers(): Promise<TillCustomer[] | null> {
  const { version } = await cachedCustomers()
  const fresh = await api.customers(version).catch(() => null)
  if (!fresh) return null
  await db.cache.put({ id: 'customers', version: fresh.version, customers: fresh.customers, savedAt: new Date().toISOString() })
  return fresh.customers
}

/** Name, code or phone (digits anywhere, so 024… finds +23324…). */
export function searchCustomers(all: TillCustomer[], q: string): TillCustomer[] {
  const t = q.trim().toLowerCase()
  if (!t) return all.slice(0, 20)
  const digits = t.replace(/\D/g, '').replace(/^0/, '')
  return all.filter((c) => c.name.toLowerCase().includes(t) || c.code.toLowerCase().includes(t)
    || (digits.length >= 3 && (c.phone ?? '').replace(/\D/g, '').includes(digits))).slice(0, 20)
}
