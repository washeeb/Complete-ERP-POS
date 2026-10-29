/**
 * This till's screen preferences (till Settings): text size and the scan beep. Kept on this computer only, like the
 * light/dark choice (theme.ts); the printer and drawer are kept by the desktop app (hardware.ts).
 */
export type Prefs = { textScale: 100 | 112 | 125; beep: boolean }

const KEY = 'till-prefs'
const DEFAULTS: Prefs = { textScale: 100, beep: true }

export function getPrefs(): Prefs {
  try {
    return { ...DEFAULTS, ...(JSON.parse(localStorage.getItem(KEY) ?? '{}') as Partial<Prefs>) }
  } catch {
    return DEFAULTS
  }
}

export function savePrefs(p: Prefs): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(p))
  } catch {
    // private window or blocked storage: the choice lasts until the till reloads
  }
  applyPrefs(p)
}

/** Bigger text for a screen read from a distance or by older eyes (everything sized in rem grows with it). */
export function applyPrefs(p: Prefs = getPrefs()): void {
  document.documentElement.style.fontSize = p.textScale === 100 ? '' : `${p.textScale}%`
}

let audio: AudioContext | null = null

/** A short beep after a scan: high when the item was found, low when nothing matched (if switched on). */
export function beep(found: boolean): void {
  if (!getPrefs().beep) return
  try {
    audio ??= new AudioContext()
    const osc = audio.createOscillator()
    const gain = audio.createGain()
    osc.frequency.value = found ? 1800 : 300
    gain.gain.value = 0.08
    osc.connect(gain).connect(audio.destination)
    osc.start()
    osc.stop(audio.currentTime + (found ? 0.07 : 0.25))
  } catch {
    // no sound device: nothing to do
  }
}
