/**
 * Barcode scanners on handhelds and tills (2026-10-23). Every common handheld — Zebra, Honeywell, Sunmi, Urovo, Newland,
 * Datalogic — and every USB / Bluetooth scanner can "type" the code like a keyboard and end it with Enter (or Tab). The
 * scan box takes that directly; this file also catches a scan that arrives while the cursor is somewhere else (a scanner
 * types far faster than a person), and gives the beep and buzz people expect from a handheld.
 */

/** A scanner sends each character within this many milliseconds of the last; a person types slower. */
export const SCAN_GAP_MS = 35
/** Shorter than this is a key someone pressed, not a barcode. */
export const MIN_SCAN_LENGTH = 4

export type KeyStroke = { key: string; at: number }

/**
 * Turns keystrokes into a scanned code: characters that came fast (each within SCAN_GAP_MS) and ended with Enter or Tab.
 * Returns the code, or null when it was a person typing.
 */
export function scannedCode(keys: KeyStroke[]): string | null {
  const end = keys[keys.length - 1]
  if (!end || (end.key !== 'Enter' && end.key !== 'Tab')) return null
  const chars = keys.slice(0, -1)
  if (chars.length < MIN_SCAN_LENGTH || chars.some((k) => k.key.length !== 1)) return null
  for (let i = 1; i < keys.length; i++) {
    if (keys[i].at - keys[i - 1].at > SCAN_GAP_MS) return null
  }
  return chars.map((k) => k.key).join('')
}

/**
 * Listen for scans anywhere on the page while the cursor is not in a text box (that box takes its own input).
 * Returns the function that stops listening.
 */
export function listenForScans(onScan: (code: string) => void): () => void {
  let keys: KeyStroke[] = []
  const handler = (e: KeyboardEvent) => {
    const t = e.target as HTMLElement | null
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return
    if (e.key === 'Shift' || e.key === 'Control' || e.key === 'Alt' || e.key === 'AltGraph') return // capitals: Shift comes first
    const now = performance.now()
    if (keys.length && now - keys[keys.length - 1].at > SCAN_GAP_MS) keys = []
    keys.push({ key: e.key, at: now })
    const code = scannedCode(keys)
    if (code) {
      e.preventDefault()
      keys = []
      onScan(code)
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      keys = []
    }
  }
  document.addEventListener('keydown', handler, true)
  return () => document.removeEventListener('keydown', handler, true)
}

let audio: AudioContext | null = null

/** A short beep (high when found, low when not) and a buzz on phones — so the person scanning need not look. */
export function scanFeedback(ok: boolean): void {
  try {
    navigator.vibrate?.(ok ? 40 : [80, 60, 80])
    audio ??= new AudioContext()
    const osc = audio.createOscillator()
    const gain = audio.createGain()
    osc.frequency.value = ok ? 1800 : 400
    gain.gain.value = 0.08
    osc.connect(gain).connect(audio.destination)
    osc.start()
    osc.stop(audio.currentTime + (ok ? 0.08 : 0.25))
  } catch {
    // no sound on this device: the screen still shows the result
  }
}

/** The camera can read barcodes in this browser (Chrome and Edge on Android, and most desktop Chrome). */
export const cameraScanSupported = (): boolean => typeof window !== 'undefined' && 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia

/** Formats read with the camera: shop barcodes (EAN, UPC), Code 128 / 39 / ITF on cartons and shelves, QR and Data Matrix. */
export const CAMERA_FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar', 'qr_code', 'data_matrix']
