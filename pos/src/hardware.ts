import { receiptRaster } from './brand'
import { receiptEscPos } from './escpos'
import { dosageEscPos, dosageLabels } from './dosage'
import { slipEscPos, type DrawerSlip } from './slip'
import type { Bootstrap, PostedSale } from './types'

/**
 * The desktop till app (Tauri) adds receipt printer, cash drawer and scale. In a browser none of this exists and the
 * till keeps using the print window; nothing else changes.
 */
type Tauri = { core: { invoke: <T>(cmd: string, args?: Record<string, unknown>) => Promise<T> } }
const tauri = (): Tauri | null => ((globalThis as { __TAURI__?: Tauri }).__TAURI__ ?? null)

export const isDesktop = (): boolean => tauri() !== null

type Config = { printer: string | null; drawer: boolean; scale_port: string | null }

/** The desktop till keeps its own copy of unsent sales on the PC (Documents\SES Labs Till\Unsent sales); null in a browser. */
export async function saveUnsentBackup(file: Blob): Promise<string | null> {
  const t = tauri()
  return t ? t.core.invoke<string>('save_unsent', { data: await file.text() }) : null
}

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
  const logo = await receiptRaster(boot).catch(() => null) // the company logo, from the till's cache when offline
  await t.core.invoke('print_raw', { data: receiptEscPos(sale, boot, logo).toBase64(), openDrawer: cash })
  return true
}

/** Print a drawer slip silently on the receipt printer; false when there is none (use the print window instead). */
export async function printSlip(slip: DrawerSlip, boot: Bootstrap): Promise<boolean> {
  const t = tauri()
  const cfg = await hardware()
  if (!t || !cfg?.printer) return false
  await t.core.invoke('print_raw', { data: slipEscPos(slip, boot).toBase64(), openDrawer: false })
  return true
}

/** Print the dosage labels silently on the receipt printer (each cut); false when there is none or no label. */
export async function printDosageLabels(sale: PostedSale, boot: Bootstrap): Promise<boolean> {
  const t = tauri()
  const cfg = await hardware()
  if (!t || !cfg?.printer || dosageLabels(sale).length === 0) return false
  await t.core.invoke('print_raw', { data: dosageEscPos(sale, boot).toBase64(), openDrawer: false })
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
