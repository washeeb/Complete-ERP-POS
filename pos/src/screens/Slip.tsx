import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { printSlip } from '../hardware'
import { formatMoney } from '../money'
import { SLIP_TITLES, slipRows, slipSignatures, type DrawerSlip } from '../slip'
import type { Bootstrap } from '../types'

/** The slip as it prints: also used for the browser's print window. */
function SlipPaper({ slip, boot }: { slip: DrawerSlip; boot: Bootstrap }) {
  return (
    <div className="receipt slip">
      <p className="center"><strong>{boot.company.name}</strong><br />{boot.branch.name}</p>
      <h2 className="center">{SLIP_TITLES[slip.kind]}<br />{formatMoney(slip.amountMinor, boot.company.currency, boot.company.exponent)}</h2>
      <table className="slip-rows"><tbody>
        {slipRows(slip, boot).map(([label, value]) => <tr key={label}><th scope="row">{label}</th><td>{value}</td></tr>)}
      </tbody></table>
      {slip.queued && <p className="small">Saved on the till offline; sent when the server is back.</p>}
      {slipSignatures(slip).map((who) => <p key={who} className="signature">{who}<span className="sign-line" /></p>)}
    </div>
  )
}

/**
 * After a cash drop, pay-out or pay-in: the slip prints by itself once (receipt printer on the desktop till, the print
 * window in a browser); "Print another copy" for the safe bag or the expense file.
 */
export function SlipScreen({ slip, boot, message, onDone }: { slip: DrawerSlip; boot: Bootstrap; message: string; onDone: () => void }) {
  const [printing, setPrinting] = useState(false)
  const [error, setError] = useState('')
  const started = useRef(false)

  async function print() {
    setError('')
    setPrinting(true)
    try {
      if (!(await printSlip(slip, boot))) {
        // Browser: only the slip goes to the print window.
        document.body.classList.add('printing-slip')
        window.addEventListener('afterprint', () => document.body.classList.remove('printing-slip'), { once: true })
        window.print()
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setPrinting(false)
    }
  }

  useEffect(() => {
    if (started.current) return
    started.current = true
    const t = setTimeout(print, 50) // after the slip is on the page
    return () => clearTimeout(t)
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Slip">
      <div className="card pay">
        <p className="banner ok" role="status">{message}</p>
        <SlipPaper slip={slip} boot={boot} />
        {error && <p className="error" role="alert">{error}</p>}
        <div className="row">
          <button type="button" onClick={print} disabled={printing}>{printing ? 'Printing…' : 'Print another copy'}</button>
          <button type="button" className="primary big" onClick={onDone}>Done</button>
        </div>
      </div>
      {createPortal(<div className="slip-print" aria-hidden="true"><SlipPaper slip={slip} boot={boot} /></div>, document.body)}
    </div>
  )
}
