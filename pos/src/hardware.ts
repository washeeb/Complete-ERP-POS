import { receiptRaster } from './brand'
import { EscPos, receiptEscPos } from './escpos'
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

/** What the desktop till keeps about its own hardware (till Settings): printers, paper, copies, drawer and scale. */
export type Config = {
  printer: string | null; drawer: boolean; scale_port: string | null; scale_baud: number | null
  paper_mm: number | null; copies: number | null; print_logo: boolean | null; label_printer: string | null
}
export type PrinterInfo = { name: string; receipt: boolean; saves_files: boolean }

/** The desktop till keeps its own copy of unsent sales on the PC (Documents\SES Labs Till\Unsent sales); null in a browser. */
export async function saveUnsentBackup(file: Blob): Promise<string | null> {
  const t = tauri()
  return t ? t.core.invoke<string>('save_unsent', { data: await file.text() }) : null
}

export async function hardware(): Promise<Config | null> {
  const t = tauri()
  return t ? t.core.invoke<Config>('get_config') : null
}

const width = (cfg: Config): number => (cfg.paper_mm === 58 ? 32 : 48)

/** Print silently on the receipt printer; false when there is none (use the print window instead). */
export async function printReceipt(sale: PostedSale, boot: Bootstrap): Promise<boolean> {
  const t = tauri()
  const cfg = await hardware()
  if (!t || !cfg?.printer) return false
  const cash = sale.payments.some((p) => p.method === 'cash')
  // The company logo (from the till's cache when offline), at most the paper's printable width: 384 dots on 58 mm too.
  const logo = cfg.print_logo === false ? null : await receiptRaster(boot).catch(() => null)
  await t.core.invoke('print_raw', { data: receiptEscPos(sale, boot, logo, width(cfg)).toBase64(), openDrawer: cash, kind: 'receipt' })
  return true
}

export async function printersInfo(): Promise<PrinterInfo[]> {
  const t = tauri()
  return t ? t.core.invoke<PrinterInfo[]>('printers_info') : []
}

export async function scalePorts(): Promise<string[]> {
  const t = tauri()
  return t ? t.core.invoke<string[]>('list_scale_ports') : []
}

/** Save this computer's hardware settings (a printer that only saves files is refused). */
export async function saveHardware(hw: Omit<Config, never>): Promise<void> {
  await tauri()?.core.invoke('set_settings', { hw })
}

/** A short test receipt with a ruler across the paper: shows the printer, the width and the cut work. */
export async function testPrint(cfg: Config, companyName: string): Promise<void> {
  const t = tauri()
  if (!t) return
  const w = width(cfg)
  const p = new EscPos(w).align('center').bold(true).line(companyName).bold(false).line('Test receipt').rule()
    .align('left').columns(`Paper ${cfg.paper_mm ?? 80} mm`, `${w} characters`).columns('Copies', String(cfg.copies ?? 1))
    .line('1234567890'.repeat(5).slice(0, w)).rule().align('center').line(new Date().toLocaleString('en-GB')).cut()
  await t.core.invoke('print_raw', { data: p.toBase64(), openDrawer: false, kind: 'slip', printer: cfg.printer })
}

/** Print a drawer slip silently on the receipt printer; false when there is none (use the print window instead). */
export async function printSlip(slip: DrawerSlip, boot: Bootstrap): Promise<boolean> {
  const t = tauri()
  const cfg = await hardware()
  if (!t || !cfg?.printer) return false
  await t.core.invoke('print_raw', { data: slipEscPos(slip, boot, width(cfg)).toBase64(), openDrawer: false, kind: 'slip' })
  return true
}

/** Print the dosage labels silently on the receipt printer (each cut); false when there is none or no label. */
export async function printDosageLabels(sale: PostedSale, boot: Bootstrap): Promise<boolean> {
  const t = tauri()
  const cfg = await hardware()
  if (!t || !cfg?.printer || dosageLabels(sale).length === 0) return false
  await t.core.invoke('print_raw', { data: dosageEscPos(sale, boot).toBase64(), openDrawer: false, kind: 'label' })
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
