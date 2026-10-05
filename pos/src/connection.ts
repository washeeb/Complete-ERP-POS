export type ConnectionState = 'online' | 'server-down' | 'offline'

/**
 * Two different failures, two different states: the device has no network at all,
 * or it has a network but the branch/HQ server is not answering. The till keeps
 * selling in both (Phase 3); staff need to know which one to report.
 */
export function connectionState(browserOnline: boolean, serverReachable: boolean): ConnectionState {
  if (!browserOnline) return 'offline'
  return serverReachable ? 'online' : 'server-down'
}

export async function pingServer(fetcher: typeof fetch = fetch, timeoutMs = 3000): Promise<boolean> {
  const ctrl = new AbortController()
  const t = setTimeout(() => ctrl.abort(), timeoutMs)
  try {
    const res = await fetcher('/up', { signal: ctrl.signal, cache: 'no-store' })
    return res.ok
  } catch {
    return false
  } finally {
    clearTimeout(t)
  }
}
