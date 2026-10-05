import { formatMoney, prettyQty, toMilli } from '../money'
import type { Bootstrap, PostedSale } from '../types'

const METHOD: Record<string, string> = { cash: 'Cash', momo: 'Mobile money', card: 'Card' }
const NETWORK: Record<string, string> = { mtn: 'MTN', telecel: 'Telecel', at: 'AT' }

/** 80 mm thermal layout; the same markup prints on A4. Only .receipt is printed (see App.css @media print). */
export function Receipt({ sale, boot, onNew }: { sale: PostedSale; boot: Bootstrap; onNew: () => void }) {
  const m = (v: number) => formatMoney(v, sale.currency, boot.company.exponent)
  const when = new Date(sale.occurred_at).toLocaleString('en-GB', { timeZone: boot.company.timezone, dateStyle: 'medium', timeStyle: 'short' })

  return (
    <div className="receipt-screen">
      <div className="receipt" aria-label={`Receipt ${sale.receipt_no}`}>
        <h2>{boot.company.name}</h2>
        {boot.company.legal_name && boot.company.legal_name !== boot.company.name && <p>{boot.company.legal_name}</p>}
        <p>{boot.branch.name}{boot.branch.address ? ` · ${boot.branch.address}` : ''}</p>
        {boot.branch.phone && <p>Tel {boot.branch.phone}</p>}
        {(boot.company.tin || boot.company.vat_no) && <p>{boot.company.tin && `TIN ${boot.company.tin}`} {boot.company.vat_no && `VAT ${boot.company.vat_no}`}</p>}
        <hr />
        <p><strong>Receipt {sale.receipt_no}</strong></p>
        {sale.status === 'queued' && <p className="small">Recorded on this till while offline — sent to the server automatically.</p>}
        <p>{when} · {sale.cashier}</p>
        <hr />
        <table>
          <tbody>
            {sale.lines.map((l) => (
              <tr key={l.line_no}>
                <td>{l.name}<br /><span className="small">{prettyQty(toMilli(l.qty))} {l.uom} × {m(l.unit_price_minor)}</span></td>
                <td className="num">{m(l.line_total_minor)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <hr />
        <table>
          <tbody>
            <tr className="strong"><td>TOTAL</td><td className="num">{m(sale.total_minor)}</td></tr>
            {sale.payments.map((p, i) => (
              <tr key={i}><td>{METHOD[p.method]}{p.provider ? ` ${NETWORK[p.provider] ?? p.provider}` : ''}{p.reference ? ` ${p.reference}` : ''}</td><td className="num">{m(p.tendered_minor)}</td></tr>
            ))}
            {sale.change_minor > 0 && <tr><td>Change</td><td className="num">{m(sale.change_minor)}</td></tr>}
          </tbody>
        </table>
        <hr />
        <table className="small">
          <tbody>
            <tr><td>{sale.prices_include_tax ? 'Prices include tax.' : 'Prices exclude tax.'} Before tax</td><td className="num">{m(sale.net_minor)}</td></tr>
            {sale.tax_breakdown.map((t) => (
              <tr key={t.code}><td>{t.name} {(t.rate_bp / 100).toFixed(2)}%</td><td className="num">{m(t.amount_minor)}</td></tr>
            ))}
          </tbody>
        </table>
        <hr />
        <p className="center">{boot.receipt_footer}</p>
      </div>
      <div className="row no-print">
        <button type="button" onClick={() => window.print()}>Print receipt</button>
        <button type="button" className="primary big" autoFocus onClick={onNew}>New sale</button>
      </div>
    </div>
  )
}
