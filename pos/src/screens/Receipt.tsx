import qrcode from 'qrcode-generator'
import { useEffect, useState } from 'react'
import { api, ApiError } from '../api'
import { cachedImage } from '../brand'
import { isDesktop, openHardwareSetup, printReceipt } from '../hardware'
import { formatMoney, prettyQty, toMilli } from '../money'
import type { Bootstrap, PostedSale } from '../types'

const METHOD: Record<string, string> = { cash: 'Cash', momo: 'Mobile money', card: 'Card', account: 'On account', points: 'Points', gift_card: 'Gift card', store_credit: 'Store credit' }
const NETWORK: Record<string, string> = { mtn: 'MTN', telecel: 'Telecel', at: 'AT' }
// Units shown after the quantity only when they say something (2 CTN, 1.5 KG — not 3 PC).
const PLAIN_UNITS = new Set(['PC', 'EA', 'PCS'])

/** The receipt number as a QR code, drawn as SVG (works offline; a 2D scanner on the Return screen reads it straight in). */
function ReceiptQr({ text }: { text: string }) {
  const qr = qrcode(0, 'M')
  qr.addData(text)
  qr.make()
  const n = qr.getModuleCount()
  let d = ''
  for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) d += `M${c + 2} ${r + 2}h1v1h-1z`
  return (
    <svg className="receipt-qr" viewBox={`0 0 ${n + 4} ${n + 4}`} role="img" aria-label={`QR code ${text}`} shapeRendering="crispEdges">
      <rect width={n + 4} height={n + 4} fill="#fff" /><path d={d} fill="#000" />
    </svg>
  )
}

/** The company logo at the top of the receipt (from the till's cache when offline); nothing when there is none. */
function ReceiptLogo({ boot }: { boot: Bootstrap }) {
  const [src, setSrc] = useState<string | null>(null)
  const url = boot.brand?.receipt_logo ? boot.brand.logo_url : null
  useEffect(() => {
    let live = true
    void cachedImage(url).then((s) => { if (live) setSrc(s) })
    return () => { live = false }
  }, [url])
  return src ? <img className="receipt-logo" src={src} alt={boot.company.name} /> : null
}

const CHANNEL: Record<string, string> = { sms: 'SMS', whatsapp: 'WhatsApp', email: 'E-mail' }

/** Send the receipt by SMS, WhatsApp or e-mail when the customer asks (needs the server: offline receipts are sent later). */
export function SendReceipt({ sale, channels }: { sale: PostedSale; channels: ('sms' | 'whatsapp' | 'email')[] }) {
  const [channel, setChannel] = useState(channels[0])
  const [to, setTo] = useState('')
  const [note, setNote] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  if (!channels.length) return null
  if (sale.status === 'queued') return <p className="small no-print">Digital receipt: available once this sale reaches the server.</p>
  const send = async () => {
    setBusy(true)
    setNote(null)
    try {
      const r = await api.sendReceipt(sale.id, channel, to.trim())
      setNote(r.detail)
    } catch (e) {
      setNote(e instanceof ApiError ? e.message : 'Could not send.')
    } finally {
      setBusy(false)
    }
  }
  return (
    <form className="row no-print send-receipt" onSubmit={(e) => { e.preventDefault(); void send() }}>
      <select aria-label="Send by" value={channel} onChange={(e) => setChannel(e.target.value as typeof channel)}>
        {channels.map((c) => <option key={c} value={c}>{CHANNEL[c]}</option>)}
      </select>
      <input aria-label="Send to" placeholder={channel === 'email' ? 'customer@example.com' : '024 123 4567'} type={channel === 'email' ? 'email' : 'tel'}
        value={to} onChange={(e) => setTo(e.target.value)} required />
      <button type="submit" disabled={busy || !to.trim()}>{busy ? 'Sending…' : 'Send receipt'}</button>
      {note && <span className="small" role="status">{note}</span>}
    </form>
  )
}

/**
 * Print receipt: straight to the receipt printer in the desktop till (and open the drawer after cash). A browser cannot
 * print without its print window, so there it opens (unless Chrome runs with --kiosk-printing, manual 9.7).
 * Show print preview: always the print window, to look at the receipt first.
 */
function PrintButton({ sale, boot }: { sale: PostedSale; boot: Bootstrap }) {
  const [note, setNote] = useState<string | null>(null)
  const print = async () => {
    setNote(null)
    try {
      if (!(await printReceipt(sale, boot))) {
        if (isDesktop()) setNote('No receipt printer is chosen on this till: Printer and scale set-up.')
        window.print()
      }
    } catch (e) {
      setNote(`Printer: ${String(e)} — use the print window instead.`)
      window.print()
    }
  }
  return <>
    <button type="button" className="primary" onClick={() => void print()}>Print receipt</button>
    <button type="button" onClick={() => window.print()}>Show print preview</button>
    {note && <span className="small" role="alert">{note}</span>}
  </>
}

/**
 * 80 mm thermal layout; the same markup prints on A4. Only .receipt is printed (see App.css @media print).
 * Header, sub-header and footer are centred; items, totals and tax run margin to margin.
 */
export function Receipt({ sale, boot, onNew }: { sale: PostedSale; boot: Bootstrap; onNew: () => void }) {
  const m = (v: number) => formatMoney(v, sale.currency, boot.company.exponent)
  // Item columns carry plain amounts; the currency is stated once in the column header.
  const n = (v: number) => m(v).replace(/^(-?)\S+ /, '$1')
  const when = new Date(sale.occurred_at).toLocaleString('en-GB', { timeZone: boot.company.timezone, dateStyle: 'medium', timeStyle: 'short' })
  const symbol = m(0).split(' ')[0]

  return (
    <div className="receipt-screen">
      <div className="receipt" aria-label={`Receipt ${sale.receipt_no}`}>
        <header className="center">
          <ReceiptLogo boot={boot} />
          <h2>{boot.company.name}</h2>
          {boot.company.legal_name && boot.company.legal_name !== boot.company.name && <p>{boot.company.legal_name}</p>}
          <p>{boot.branch.name}{boot.branch.address ? ` · ${boot.branch.address}` : ''}</p>
          {boot.branch.phone && <p>Tel {boot.branch.phone}</p>}
          {(boot.company.tin || boot.company.vat_no) && <p>{boot.company.tin && `TIN ${boot.company.tin}`} {boot.company.vat_no && `VAT ${boot.company.vat_no}`}</p>}
          <hr />
          <p><strong>{sale.kind === 'return' ? 'RETURN ' : 'Receipt '}{sale.receipt_no}</strong></p>
          <p>{when} · {sale.cashier}</p>
          {sale.order_number && <p className="strong">Order {sale.order_number.slice(-3)}</p>}
        </header>
        {sale.status === 'queued' && <p className="small">Recorded on this till while offline — sent to the server automatically.</p>}
        <hr />
        <table className="receipt-items">
          <thead>
            <tr><th className="sn">SN</th><th>Item</th><th className="num">Qty</th><th className="num">Price</th><th className="num">Total</th></tr>
            <tr><th colSpan={5} className="small">Amounts in {symbol}</th></tr>
          </thead>
          {sale.lines.map((l, i) => (
            <tbody key={l.line_no}>
              <tr>
                <td className="sn">{i + 1}</td>
                <td className="item">{l.name}</td>
                <td className="num">{prettyQty(toMilli(l.qty))}{PLAIN_UNITS.has(l.uom) ? '' : ` ${l.uom}`}</td>
                <td className="num">{n(l.unit_price_minor)}</td>
                <td className="num">{n(l.line_total_minor)}</td>
              </tr>
              {l.note && <tr className="small"><td /><td colSpan={4}>{l.note}</td></tr>}
              {(l.serials ?? []).length > 0 && <tr className="small"><td /><td colSpan={4}>S/N {l.serials!.join(', ')}{l.warranty_until ? ` · warranty to ${l.warranty_until}` : ''}</td></tr>}
              {(sale.licence_keys ?? []).filter((k) => k.sale_line_id === l.id).map((k) => (
                <tr key={k.key} className="small"><td /><td colSpan={4} className="mono">Licence key: {k.key}</td></tr>
              ))}
              {(l.promotions ?? []).map((p, j) => (
                <tr key={j} className="small promo-line"><td /><td colSpan={3}>{p.name}</td><td className="num">−{n(p.saving_minor)}</td></tr>
              ))}
            </tbody>
          ))}
        </table>
        <hr />
        <table>
          <tbody>
            {(sale.discount_minor ?? 0) > 0 && <tr><td>You saved</td><td className="num">{m(sale.discount_minor ?? 0)}</td></tr>}
            <tr className="strong"><td>{sale.kind === 'return' ? 'REFUND' : 'TOTAL'}</td><td className="num">{m(sale.total_minor)}</td></tr>
            {(sale.tip_minor ?? 0) > 0 && <><tr><td>Tip — thank you</td><td className="num">{m(sale.tip_minor ?? 0)}</td></tr>
              <tr className="strong"><td>PAID</td><td className="num">{m(sale.total_minor + (sale.tip_minor ?? 0))}</td></tr></>}
            {sale.payments.map((p, i) => (
              <tr key={i}><td>{METHOD[p.method]}{p.provider ? ` ${NETWORK[p.provider] ?? p.provider}` : ''}{p.reference ? ` ${p.reference}` : ''}</td><td className="num">{m(p.tendered_minor)}</td></tr>
            ))}
            {sale.change_minor > 0 && <tr><td>Change</td><td className="num">{m(sale.change_minor)}</td></tr>}
            {(sale.points_earned ?? 0) > 0 && <tr><td>Points earned</td><td className="num">{sale.points_earned}</td></tr>}
          </tbody>
        </table>
        <hr />
        <table className="small">
          <tbody>
            <tr><td colSpan={2}>{sale.prices_include_tax ? 'Prices include tax.' : 'Prices exclude tax.'}</td></tr>
            <tr><td>Before tax</td><td className="num">{m(sale.net_minor)}</td></tr>
            {sale.tax_breakdown.map((t) => (
              <tr key={t.code}><td>{t.name} {(t.rate_bp / 100).toFixed(2)}%</td><td className="num">{m(t.amount_minor)}</td></tr>
            ))}
          </tbody>
        </table>
        <hr />
        {sale.fiscal?.code && (
          <div className="center gra">
            <p className="small">GRA E-VAT {sale.fiscal.code}</p>
            {sale.fiscal.qr && <ReceiptQr text={sale.fiscal.qr} />}
          </div>
        )}
        {sale.fiscal && !sale.fiscal.code && sale.fiscal.mode === 'realtime' && <p className="small center">GRA E-VAT: being reported.</p>}
        <footer className="center">
          <ReceiptQr text={sale.receipt_no} />
          <p>{boot.receipt_footer}</p>
          {boot.license?.ref && <p className="receipt-ref">Ref {boot.license.ref}</p>}
          {boot.brand?.powered_by && <p className="receipt-ref">{boot.brand.powered_by}</p>}
          {(boot.brand?.credit ?? []).map((line) => <p key={line} className="receipt-ref">{line}</p>)}
        </footer>
      </div>
      {sale.status === 'queued' && (sale.kitchen?.length ?? 0) > 0 && (
        // Offline counter sale: the kitchen screens cannot see it yet — this ticket goes to the kitchen instead.
        <div className="receipt kitchen-ticket" aria-label="Kitchen ticket">
          <header className="center"><h2>KITCHEN</h2><p><strong>{sale.receipt_no}</strong> · {when}</p></header>
          <hr />
          {[...new Set(sale.kitchen!.map((k) => k.station))].map((st) => (
            <div key={st}>
              <p className="strong">{st}</p>
              {sale.kitchen!.filter((k) => k.station === st).map((k, i) => (
                <p key={i}>{k.qty} × {k.name}{k.note ? <><br /><span className="small">  {k.note}</span></> : null}</p>
              ))}
            </div>
          ))}
        </div>
      )}
      <SendReceipt sale={sale} channels={boot.send_receipt ?? []} />
      <div className="row no-print">
        <PrintButton sale={sale} boot={boot} />
        {isDesktop() && <button type="button" onClick={() => void openHardwareSetup()}>Printer and scale set-up</button>}
        <button type="button" className="primary big" autoFocus onClick={onNew}>New sale</button>
      </div>
    </div>
  )
}
