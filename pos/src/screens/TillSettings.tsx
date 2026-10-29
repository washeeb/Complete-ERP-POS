import { useEffect, useState } from 'react'
import { api } from '../api'
import { hardware, isDesktop, openDrawer, printersInfo, readScale, saveHardware, scalePorts, testPrint, type Config, type PrinterInfo } from '../hardware'
import { getPrefs, savePrefs, type Prefs } from '../prefs'
import { applyTheme, getTheme, type ThemeChoice } from '../theme'
import type { Bootstrap } from '../types'

type Approval = { username: string; pin: string }

/**
 * The till's own settings: receipt printer and paper, copies and logo, dosage-label printer, cash drawer, scale,
 * scanner check, and the screen (light/dark, text size, scan beep). Anyone can look and try the test buttons that
 * change nothing; saving, and opening the drawer to test it, need a manager's username and PIN (recorded in the
 * audit log). Printer, drawer and scale are kept by the desktop app on this computer; the screen choices too.
 */
export function TillSettings({ boot, online, onClose }: { boot: Bootstrap; online: boolean; onClose: () => void }) {
  const desktop = isDesktop()
  const [saved, setSaved] = useState<Config | null>(null)
  const [hw, setHw] = useState<Config | null>(null)
  const [printers, setPrinters] = useState<PrinterInfo[]>([])
  const [ports, setPorts] = useState<string[]>([])
  const [prefs, setPrefs] = useState<Prefs>(getPrefs())
  const [theme, setTheme] = useState<ThemeChoice>(getTheme())
  const [scan, setScan] = useState('')
  const [note, setNote] = useState<{ text: string; bad?: boolean } | null>(null)
  const [asking, setAsking] = useState<null | 'save' | 'drawer'>(null)
  const [approval, setApproval] = useState<Approval>({ username: '', pin: '' })
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!desktop) return
    void (async () => {
      const [cfg, list, sp] = await Promise.all([hardware(), printersInfo(), scalePorts()])
      setPrinters(list)
      setPorts(sp)
      if (cfg) {
        // Nothing chosen yet: the receipt printer is picked for you (never one that only saves files).
        const pick = cfg.printer ?? list.find((p) => p.receipt)?.name ?? null
        setSaved(cfg)
        setHw({ ...cfg, printer: pick })
      }
    })()
  }, [desktop])

  const set = (patch: Partial<Config>) => setHw((h) => (h ? { ...h, ...patch } : h))
  const chosen = printers.find((p) => p.name === hw?.printer)
  const say = (text: string, bad = false) => setNote({ text, bad })

  /** What changed, as [before, after], for the audit log. */
  function changes(): Record<string, [string, string]> {
    const out: Record<string, [string, string]> = {}
    const add = (k: string, a: unknown, b: unknown) => { if (String(a ?? '') !== String(b ?? '')) out[k] = [String(a ?? ''), String(b ?? '')] }
    if (hw && saved) for (const k of Object.keys(hw) as (keyof Config)[]) add(k, saved[k], hw[k])
    const before = getPrefs()
    add('text_size', before.textScale, prefs.textScale)
    add('scan_beep', before.beep, prefs.beep)
    add('theme', getTheme(), theme)
    return out
  }

  async function confirm(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setNote(null)
    try {
      if (asking === 'drawer') {
        await api.tillSettings(approval, { drawer_test: ['', 'opened'] })
        await openDrawer()
        say('Drawer opened (test).')
      } else {
        const diff = changes()
        if (Object.keys(diff).length === 0) {
          say('Nothing has changed.')
        } else {
          const r = await api.tillSettings(approval, diff)
          if (hw && desktop) await saveHardware(hw)
          savePrefs(prefs)
          applyTheme(theme)
          setSaved(hw)
          say(`Saved, approved by ${r.approved_by}.`)
        }
      }
      setAsking(null)
      setApproval({ username: '', pin: '' })
    } catch (err) {
      say(err instanceof Error ? err.message : String(err), true)
    } finally {
      setBusy(false)
    }
  }

  async function run(what: string, job: () => Promise<unknown>) {
    setNote(null)
    try {
      const r = await job()
      say(typeof r === 'string' ? r : `${what}: done.`)
    } catch (err) {
      say(`${what}: ${err instanceof Error ? err.message : String(err)}`, true)
    }
  }

  return (
    <div className="card settings">
      <div className="row spread"><h1>Till settings</h1><button type="button" onClick={onClose}>Back</button></div>
      <p className="muted small">{boot.till?.name ?? 'This till'} · {boot.branch.name}. Anyone can look and test; saving needs a manager’s username and PIN.</p>

      {desktop && hw ? (
        <>
          <fieldset>
            <legend>Receipt printer</legend>
            <label>Printer
              <select value={hw.printer?.startsWith('tcp://') ? '' : hw.printer ?? ''} onChange={(e) => set({ printer: e.target.value || null })}>
                <option value="">— none: use the print window —</option>
                {printers.map((p) => (
                  <option key={p.name} value={p.name} disabled={p.saves_files}>{p.name}{p.saves_files ? ' (only saves files)' : p.receipt ? ' (receipt printer)' : ''}</option>
                ))}
              </select>
            </label>
            <label>…or a network printer
              <input value={hw.printer?.startsWith('tcp://') ? hw.printer : ''} placeholder="tcp://192.168.1.50:9100"
                onChange={(e) => set({ printer: e.target.value.trim() || null })} />
            </label>
            {chosen && !chosen.receipt && <p className="warn small">This does not look like a receipt printer. Print a test to be sure.</p>}
            <div className="row">
              <label>Paper
                <select value={hw.paper_mm ?? 80} onChange={(e) => set({ paper_mm: Number(e.target.value) })}>
                  <option value={80}>80 mm (48 characters)</option><option value={58}>58 mm (32 characters)</option>
                </select>
              </label>
              <label>Copies of each receipt
                <select value={hw.copies ?? 1} onChange={(e) => set({ copies: Number(e.target.value) })}>
                  <option value={1}>1</option><option value={2}>2 (one for the shop)</option><option value={3}>3</option>
                </select>
              </label>
            </div>
            <label className="check"><input type="checkbox" checked={hw.print_logo !== false} onChange={(e) => set({ print_logo: e.target.checked })} /> Company logo at the top</label>
            <p className="muted small">Receipts print by themselves after each sale {boot.auto_print_receipt === false ? '(switched off for the company: press Print receipt)' : '(company setting)'}.</p>
            <button type="button" disabled={!hw.printer} onClick={() => run('Test print', async () => { await testPrint(hw, boot.company.name); return 'Test receipt sent: check the ruler fits the paper and it cuts.' })}>
              Print a test receipt
            </button>
          </fieldset>

          <fieldset>
            <legend>Dosage labels</legend>
            <label>Label printer
              <select value={hw.label_printer ?? ''} onChange={(e) => set({ label_printer: e.target.value || null })}>
                <option value="">Same as the receipt printer</option>
                {printers.filter((p) => !p.saves_files).map((p) => <option key={p.name} value={p.name}>{p.name}</option>)}
              </select>
            </label>
          </fieldset>

          <fieldset>
            <legend>Cash drawer</legend>
            <label className="check"><input type="checkbox" checked={hw.drawer} onChange={(e) => set({ drawer: e.target.checked })} /> Open the drawer (plugged into the receipt printer) after cash sales</label>
            <button type="button" disabled={!saved?.printer || !online} onClick={() => setAsking('drawer')}>Open the drawer (test, manager’s PIN)</button>
          </fieldset>

          <fieldset>
            <legend>Weighing scale</legend>
            <div className="row">
              <label>Port
                <select value={hw.scale_port ?? ''} onChange={(e) => set({ scale_port: e.target.value || null })}>
                  <option value="">No scale</option>
                  {ports.map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
              </label>
              <label>Speed (baud)
                <select value={hw.scale_baud ?? 9600} onChange={(e) => set({ scale_baud: Number(e.target.value) })}>
                  {[2400, 4800, 9600, 19200].map((b) => <option key={b} value={b}>{b}</option>)}
                </select>
              </label>
            </div>
            <button type="button" disabled={!saved?.scale_port} onClick={() => run('Scale', async () => `Scale reads ${await readScale()} kg.`)}>Read the scale (saved port)</button>
          </fieldset>
        </>
      ) : (
        <p className="muted">{desktop ? 'Loading…' : 'Printer, drawer and scale are set on the desktop till app. In a browser, receipts use the print window.'}</p>
      )}

      <fieldset>
        <legend>Barcode scanner</legend>
        <label>Scan any barcode here to check the scanner
          <input value={scan} onChange={(e) => setScan(e.target.value)} placeholder="Scan now…" autoComplete="off" />
        </label>
        {scan && <p className="small">The scanner sent <strong className="mono">{scan}</strong> ({scan.length} characters). <button type="button" className="link" onClick={() => setScan('')}>Clear</button></p>}
        <label className="check"><input type="checkbox" checked={prefs.beep} onChange={(e) => setPrefs({ ...prefs, beep: e.target.checked })} /> Beep after each scan (high: found, low: not found)</label>
      </fieldset>

      <fieldset>
        <legend>Screen</legend>
        <div className="row">
          <label>Colours
            <select value={theme} onChange={(e) => setTheme(e.target.value as ThemeChoice)}>
              <option value="system">Like this computer</option><option value="light">Light</option><option value="dark">Dark</option>
            </select>
          </label>
          <label>Text size
            <select value={prefs.textScale} onChange={(e) => setPrefs({ ...prefs, textScale: Number(e.target.value) as Prefs['textScale'] })}>
              <option value={100}>Normal</option><option value={112}>Larger</option><option value={125}>Largest</option>
            </select>
          </label>
        </div>
      </fieldset>

      {note && <p className={note.bad ? 'error' : 'ok'} role="status">{note.text}</p>}
      <div className="row">
        <button type="button" onClick={onClose}>Back</button>
        <button type="button" className="primary big" disabled={!online} title={online ? '' : 'Needs the server'} onClick={() => setAsking('save')}>Save (manager’s PIN)</button>
      </div>
      {!online && <p className="muted small">Saving needs the server, so that the change is approved and recorded.</p>}

      {asking && (
        <div className="modal" role="dialog" aria-modal="true" aria-label="Manager approval">
          <form className="card pay" onSubmit={confirm}>
            <h2>{asking === 'drawer' ? 'Open the drawer (test)' : 'Save the till settings'}</h2>
            <p className="muted small">A manager approves with their username and PIN. It is recorded in the audit log.</p>
            <label>Manager’s username<input autoFocus autoCapitalize="none" value={approval.username} onChange={(e) => setApproval({ ...approval, username: e.target.value })} required /></label>
            <label>Manager’s PIN<input type="password" inputMode="numeric" value={approval.pin} onChange={(e) => setApproval({ ...approval, pin: e.target.value.replace(/\D/g, '').slice(0, 8) })} required /></label>
            <div className="row">
              <button type="button" onClick={() => { setAsking(null); setApproval({ username: '', pin: '' }) }} disabled={busy}>Cancel</button>
              <button className="primary" disabled={busy || !approval.username || approval.pin.length < 4}>{busy ? 'Checking…' : 'Approve'}</button>
            </div>
          </form>
        </div>
      )}
    </div>
  )
}
