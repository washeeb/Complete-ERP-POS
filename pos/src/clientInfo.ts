/**
 * In the desktop till app: which computer this is (name, network card, address on the shop network), sent with each
 * request in the X-SES-Client header so the audit log and the device list show it. A browser cannot tell these, so
 * there the header is left out and only the browser and address are recorded.
 */
type Tauri = { core: { invoke: <T>(cmd: string) => Promise<T> } }

let cached: Promise<string | null> | null = null

export function clientHeader(): Promise<string | null> {
  const t = (globalThis as { __TAURI__?: Tauri }).__TAURI__
  if (!t) return Promise.resolve(null)
  cached ??= t.core.invoke<Record<string, string>>('client_info')
    // Plain ASCII only: a header cannot carry other characters, so a computer named "Kofi’s PC" or "CAISSE-ÉTÉ" would
    // stop every request before it leaves (owner 2026-11-08: a new till could not enrol, "Cannot reach the server").
    // JSON \u escapes keep the name exact for the server.
    .then((info) => JSON.stringify(Object.fromEntries(Object.entries(info).filter(([, v]) => v)))
      .replace(/[^\x20-\x7e]/g, (c) => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0')))
    .catch(() => null) // an older desktop app without the command
  return cached
}
