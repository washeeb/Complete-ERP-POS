import { useCallback, useEffect, useState } from 'react'
import { alterations as api } from '../api'
import { styleGrid } from '../fashion'
import { formatMoney, parseMoney, prettyQty, toMilli } from '../money'
import type { Alteration, Product, Style } from '../types'

const errorText = (e: unknown) => (e instanceof Error ? e.message : String(e))

/** Pick a size and colour of a style: the grid shows what is in stock at this till's warehouse. */
export function StylePicker({ style, products, onPick, onCancel }: { style: Style; products: Product[]; onPick: (p: Product) => void; onCancel: () => void }) {
  const g = styleGrid(style, products)
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={`Sizes and colours of ${style.name}`} onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <div className="card wide">
        <div className="row spread"><h2>{style.name} <span className="muted small">{style.code}{style.season ? ` · ${style.season}` : ''}</span></h2><button type="button" onClick={onCancel}>Back</button></div>
        <table className="style-grid">
          <thead><tr><th />{g.sizes.map((s) => <th key={s}>{s}</th>)}</tr></thead>
          <tbody>
            {g.colours.map((c, r) => (
              <tr key={c}><th>{c}</th>
                {g.cells[r].map((cell, i) => (
                  <td key={g.sizes[i]}>{cell ? (
                    <button type="button" className={cell.onHand !== null && cell.onHand <= 0 ? 'out' : ''} aria-label={`${c} ${g.sizes[i]}`} onClick={() => onPick(cell.product)}>
                      {cell.onHand === null ? '·' : prettyQty(toMilli(String(cell.onHand)))}</button>
                  ) : <span className="muted">—</span>}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
        <p className="muted small">Numbers are stock here. Out-of-stock sizes can still be sold if the shop allows it.</p>
      </div>
    </div>
  )
}

const NEXT: Record<string, string[]> = { received: ['in_progress', 'ready'], in_progress: ['ready'], ready: ['in_progress'] }
const LABEL: Record<string, string> = { in_progress: 'In progress', ready: 'Ready to collect' }

/** Alterations at the till: open jobs, a new one, progress, and collection (paid at the agreed charge, or handed over free). */
export function AlterationsScreen({ currency, exponent, onCollect, onClose }: {
  currency: string; exponent: number; onCollect: (a: Alteration, productId: string) => void; onClose: () => void
}) {
  const [list, setList] = useState<Alteration[]>([])
  const [productId, setProductId] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [form, setForm] = useState({ customer_name: '', phone: '', garment: '', work: '', charge: '', due_on: '' })
  const money = (m: number) => formatMoney(m, currency, exponent)
  const load = useCallback(() => api.list().then((r) => { setList(r.alterations); setProductId(r.product_id) }).catch((e) => setError(errorText(e))), [])
  useEffect(() => { load() }, [load])
  async function act(f: () => Promise<unknown>) {
    setError('')
    try { await f(); await load() } catch (e) { setError(errorText(e)) }
  }
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Alterations">
      <div className="card wide">
        <div className="row spread"><h2>Alterations</h2><button type="button" onClick={onClose}>Back to till</button></div>
        {error && <p className="error" role="alert">{error}</p>}
        <div className="bill-cols">
          <section aria-label="Open alterations">
            <ul className="bill-items">
              {list.map((a) => (
                <li key={a.id}>
                  <span><strong>{a.number}</strong> · {a.customer_name} · {a.garment}<span className="small muted"><br />{a.work}{a.due_on ? ` · by ${a.due_on}` : ''} · {a.charge_minor ? money(a.charge_minor) : 'free'}</span></span>
                  <span className="row wrap">
                    <span className="chip small">{a.status_label}</span>
                    {(NEXT[a.status] ?? []).map((s) => <button key={s} type="button" onClick={() => act(() => api.status(a.id, s))}>{LABEL[s]}</button>)}
                    {a.status === 'ready' && (a.charge_minor > 0
                      ? <button type="button" className="primary" disabled={!productId} onClick={() => productId && onCollect(a, productId)}>Collect and pay</button>
                      : <button type="button" className="primary" onClick={() => act(() => api.handOver(a.id))}>Hand over</button>)}
                  </span>
                </li>
              ))}
              {list.length === 0 && <li className="muted">No open alterations.</li>}
            </ul>
          </section>
          <form aria-label="New alteration" onSubmit={(e) => {
            e.preventDefault()
            act(async () => {
              await api.open({ customer_name: form.customer_name, phone: form.phone || null, garment: form.garment, work: form.work,
                charge_minor: form.charge ? parseMoney(form.charge, exponent) : 0, due_on: form.due_on || null })
              setForm({ customer_name: '', phone: '', garment: '', work: '', charge: '', due_on: '' })
            })
          }}>
            <h3>New alteration</h3>
            <label>Customer<input value={form.customer_name} onChange={set('customer_name')} required maxLength={120} /></label>
            <label>Phone<input inputMode="tel" value={form.phone} onChange={set('phone')} maxLength={20} /></label>
            <label>Garment<input value={form.garment} onChange={set('garment')} required maxLength={160} /></label>
            <label>Work to do<input value={form.work} onChange={set('work')} required maxLength={2000} placeholder="e.g. hem 2 cm" /></label>
            <div className="row">
              <label>Charge<input inputMode="decimal" value={form.charge} onChange={set('charge')} placeholder="0 = free" /></label>
              <label>Ready by<input type="date" value={form.due_on} onChange={set('due_on')} /></label>
            </div>
            <button className="primary big">Take in</button>
          </form>
        </div>
      </div>
    </div>
  )
}
