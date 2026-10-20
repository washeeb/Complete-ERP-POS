import { api } from './api'
import { db } from './db'
import { hardware } from './hardware'
import { recentErrors } from './problems'

/**
 * Device health and remote fixes (owner request 2026-10-20). Every few minutes, while the server answers and someone is
 * signed in, the till sends how it is doing (version, unsent work, connection, storage, printer, catalogue age); the
 * answer carries any fix someone queued for it in the back office, which the till runs and reports on. A fix never
 * touches unsent sales: the queue is only ever emptied by the server accepting it.
 */
export type HealthSnapshot = {
  app: string; desktop: boolean; uptime_min: number; queue_pending: number; queue_review: number; oldest_pending_min: number | null
  latency_ms: number | null; connection: string | null; storage_used_mb: number | null; storage_free_mb: number | null
  printer: string | null; catalog_age_min: number | null; errors_recent: number; screen: string
}

export type RemoteCommand = { id: string; command: 'reload' | 'sync_now' | 'refresh_catalog' | 'clear_cache' | 'collect_logs' }

/** Every 5 minutes (20 ticks of the 15-second connection check). */
export const HEALTH_EVERY_TICKS = 20

export async function collectHealth(latencyMs: number | null, screen: string): Promise<HealthSnapshot> {
  const queue = await db.queue.toArray()
  const pending = queue.filter((q) => q.status === 'pending')
  const oldest = pending.map((q) => Date.parse(String(q.payload?.occurred_at ?? q.payload?.closed_at ?? q.payload?.opened_at ?? ''))).filter((t) => !Number.isNaN(t))
  const estimate = await navigator.storage?.estimate?.().catch(() => null) ?? null
  const catalog = (await db.cache.get('catalog')) as { savedAt?: string } | undefined
  const hw = await hardware().catch(() => null)
  const conn = (navigator as Navigator & { connection?: { effectiveType?: string } }).connection
  const mb = (b?: number) => (b === undefined ? null : Math.round(b / 1048576))
  return {
    app: import.meta.env.VITE_APP_VERSION ?? 'dev',
    desktop: typeof window !== 'undefined' && '__TAURI__' in window,
    uptime_min: Math.round(performance.now() / 60000),
    queue_pending: pending.length,
    queue_review: queue.filter((q) => q.status === 'review').length,
    oldest_pending_min: oldest.length ? Math.round((Date.now() - Math.min(...oldest)) / 60000) : null,
    latency_ms: latencyMs,
    connection: conn?.effectiveType ?? null,
    storage_used_mb: mb(estimate?.usage),
    storage_free_mb: estimate?.quota !== undefined && estimate?.usage !== undefined ? mb(estimate.quota - estimate.usage) : null,
    printer: hw ? hw.printer : null,
    catalog_age_min: catalog?.savedAt ? Math.round((Date.now() - Date.parse(catalog.savedAt)) / 60000) : null,
    errors_recent: recentErrors().length,
    screen,
  }
}

/** Time a round trip to the server's /up (ms), or null when it does not answer. */
export async function measureLatency(): Promise<number | null> {
  const t = performance.now()
  try {
    const r = await fetch('/up', { cache: 'no-store' })
    return r.ok ? Math.round(performance.now() - t) : null
  } catch {
    return null
  }
}

export type FixHandlers = { syncNow: () => Promise<void>; refreshCatalog: () => Promise<void> }

/** Run one queued fix; returns what to report. Reload and clear-cache report first, then reload the page. */
export async function runCommand(c: RemoteCommand, h: FixHandlers): Promise<void> {
  const done = (status: 'done' | 'failed', result: string) => api.commandDone(c.id, status, result.slice(0, 20000)).catch(() => undefined)
  try {
    switch (c.command) {
      case 'sync_now':
        await h.syncNow()
        await done('done', `Waiting after sending: ${(await db.queue.where('status').equals('pending').count())}`)
        return
      case 'refresh_catalog':
        await h.refreshCatalog()
        await done('done', 'Catalogue refreshed')
        return
      case 'collect_logs': {
        const queue = (await db.queue.toArray()).map((q) => ({ type: q.type, status: q.status, attempts: q.attempts, error: q.lastError }))
        await done('done', JSON.stringify({ errors: recentErrors(), queue, health: await collectHealth(null, 'remote') }, null, 1))
        return
      }
      case 'clear_cache':
        // Cached catalogue, customers and pictures go; sales waiting to be sent and the till's identity stay.
        await db.cache.clear()
        if ('caches' in window) for (const k of await caches.keys()) await caches.delete(k)
        await done('done', 'Cache cleared; reloading')
        window.location.reload()
        return
      case 'reload':
        await done('done', 'Reloading')
        window.location.reload()
        return
    }
  } catch (e) {
    await done('failed', e instanceof Error ? e.message : String(e))
  }
}

/** Send the health snapshot; run whatever fixes come back. Quiet on failure: it is tried again next time. */
export async function reportHealth(screen: string, h: FixHandlers): Promise<void> {
  const snapshot = await collectHealth(await measureLatency(), screen)
  const r = await api.health(snapshot).catch(() => null)
  for (const c of r?.commands ?? []) await runCommand(c, h)
}
