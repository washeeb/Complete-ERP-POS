import { receiptEscPos } from './escpos'
import type { Bootstrap, PostedSale } from './types'

/**
 * The desktop till app (Tauri) adds receipt printer, cash drawer and scale. In a browser none of this exists and the
 * till keeps using the print window; nothing else changes.
 */
type Tauri = { core: { invoke: <T>(cmd: string, args?: Record<string, unknown>) => Promise<T> } }
const tauri = (): Tauri | null => ((globalThis as { __TAURI__?: Tauri }).__TAURI__ ?? null)

export const isDesktop = (): boolean => tauri() !== null

type Config = { printer: string | null; drawer: boolean; scale_port: string | null }

export async function hardware(): Promise<Config | null> {
  const t = tauri()
  return t ? t.core.invoke<Config>('get_config') : null
}

/** Print silently on the receipt printer; false when there is none (use the print window instead). */
export async function printReceipt(sale: PostedSale, boot: Bootstrap): Promise<boolean> {
  const t = tauri()
  const cfg = await hardware()
  if (!t || !cfg?.printer) return false
  const cash = sale.payments.some((p) => p.method === 'cash')
  await t.core.invoke('print_raw', { data: receiptEscPos(sale, boot).toBase64(), openDrawer: cash })
  return true
}

export async function openDrawer(): Promise<void> {
  await tauri()?.core.invoke('open_drawer')
}

/** Weight on the scale in kilograms as text, e.g. "1.235"; null without a scale. */
export async function readScale(): Promise<string | null> {
  const t = tauri()
  const cfg = await hardware()
  if (!t || !cfg?.scale_port) return null
  return t.core.invoke<string>('read_scale')
}

export async function openHardwareSetup(): Promise<void> {
  await tauri()?.core.invoke('open_setup')
}
