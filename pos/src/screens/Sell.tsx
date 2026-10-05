import { useEffect, useMemo, useRef, useState } from 'react'
import { ApiError } from '../api'
import { addToCart, findByBarcode, priceCart, qtyAllowed, search, setQty, type CartLine } from '../cart'
import { nextSeq } from '../db'
import { exportQueueFile, retryRefused } from '../offline'
import { submitSale } from '../till'
import { formatMoney, prettyQty, toMilli } from '../money'
import type { Bootstrap, Payment, PostedSale, Product, Unit } from '../types'
import { Pay } from './Pay'

export function Sell({ boot, products, online, catalogVersion, queue, onPosted, onRefresh, onSendNow, onCloseShift, onLogout }: {
  boot: Bootstrap; products: Product[]; online: boolean; catalogVersion: string | null
  queue: { pending: number; review: number; sending: boolean; lastError: string | null }
  onPosted: (s: PostedSale) => void; onRefresh: () => Promise<void>; onSendNow: () => void
  onCloseShift: () => void; onLogout: () => void
}) {
  const defaultList = boot.price_lists.find((l) => l.is_default) ?? boot.price_lists[0]
  const [listId, setListId] = useState(defaultList?.id ?? '')
  const [lines, setLines] = useState<CartLine[]>([])
  const [query, setQuery] = useState('')
  const [message, setMessage] = useState('')
  const [paying, setPaying] = useState(false)
  const [busy, setBusy] = useState(false)
  const [payError, setPayError] = useState('')
  const searchRef = useRef<HTMLInputElement>(null)
  const list = boot.price_lists.find((l) => l.id === listId) ?? defaultList
  const money = (m: number) => formatMoney(m, boot.company.currency, boot.company.exponent)
  const cart = useMemo(() => priceCart(lines, list, new Date()), [lines, list])
  const results = useMemo(() => search(products, query), [products, query])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'F2') { e.preventDefault(); searchRef.current?.focus() }
      if (e.key === 'F12' && lines.length && !paying) { e.preventDefault(); startPay() }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  })

  async function exportUnsent() {
    const url = URL.createObjectURL(await exportQueueFile())
    const a = Object.assign(document.createElement('a'), { href: url, download: `till-${boot.branch.code}-${boot.till?.code}${boot.device.receipt_series ?? ''}-unsent-${Date.now()}.json` })
    a.click()
    URL.revokeObjectURL(url)
  }

  function add(product: Product, unit?: Unit) {
    const u = unit ?? product.units.find((x) => x.is_base) ?? product.units[0]
    setLines((l) => addToCart(l, product, u))
    setQuery('')
    setMessage('')
    searchRef.current?.focus()
  }

  function onScanOrSearch(e: React.FormEvent) {
    e.preventDefault()
    const hit = findByBarcode(products, query)
    if (hit) return add(hit.product, hit.unit)
    if (results.length === 1) return add(results[0])
    if (query.trim() && !results.length) setMessage(`Nothing matches “${query.trim()}”.`)
  }

  function changeQty(line: CartLine, value: string) {
    try {
      const milli = toMilli(value || '0')
      if (milli > 0 && !qtyAllowed(line.unit, milli)) {
        setMessage(`${line.unit.uom_name} allows ${line.unit.decimals} decimal place(s).`)
        return
      }
      setLines((l) => setQty(l, line.key, milli))
    } catch {
      setMessage('Enter a quantity like 2 or 1.5')
    }
  }

  function changeUnit(line: CartLine, unitId: string) {
    const unit = line.product.units.find((u) => u.id === unitId)!
    setLines((l) => addToCart(setQty(l, line.key, 0), line.product, unit, line.qtyMilli))
  }

  function startPay() {
    if (cart.unpriced.length) {
      setMessage(`No ${list.name} price for: ${cart.unpriced.map((l) => l.product.name).join(', ')}`)
      return
    }
    setPayError('')
    setPaying(true)
  }

  async function complete(payments: Payment[]) {
    setBusy(true)
    setPayError('')
    try {
      const sale = await submitSale({ boot, online, cart, listId: list.id, payments, seq: await nextSeq(boot.last_device_seq), catalogVersion })
      setLines([])
      setPaying(false)
      onPosted(sale)
    } catch (err) {
      const e = err instanceof ApiError ? err : null
      setPayError(err instanceof Error ? err.message : String(err))
      if (e && ['price_changed', 'totals_mismatch', 'product_unavailable'].includes(e.code)) await onRefresh()
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className="sell">
      <header className="topbar">
        <div><strong>{boot.branch.code} · {boot.till?.name}</strong> <span className="muted">{boot.user.name}</span></div>
        <label className="inline">
          Price list
          <select value={listId} onChange={(e) => setListId(e.target.value)} aria-label="Price list">
            {boot.price_lists.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
          </select>
        </label>
        <div className="row">
          <button type="button" onClick={onCloseShift}>Close shift</button>
          <button type="button" onClick={onLogout}>Sign out</button>
        </div>
      </header>

      {(queue.pending > 0 || queue.review > 0 || !online) && (
        <div className={`banner ${queue.review ? 'bad' : 'warn'}`} role="status">
          <span>
            {!online && <strong>Working offline. </strong>}
            {queue.pending > 0 && `${queue.pending} item(s) saved on this till, waiting to reach the server${queue.sending ? ' — sending…' : '.'} `}
            {queue.review > 0 && `${queue.review} item(s) were refused by the server — show a supervisor (they are in the back-office Review queue). `}
            {queue.lastError && !queue.sending && <span className="small">({queue.lastError})</span>}
          </span>
          <span className="row">
            {queue.pending > 0 && <button type="button" onClick={onSendNow} disabled={queue.sending}>Send now</button>}
            {queue.review > 0 && <button type="button" onClick={async () => { await retryRefused(); onSendNow() }}>Retry refused</button>}
            <button type="button" onClick={exportUnsent}>Export unsent sales</button>
          </span>
        </div>
      )}
      <section className="find">
        <form onSubmit={onScanOrSearch}>
          <input ref={searchRef} autoFocus className="big" value={query} onChange={(e) => setQuery(e.target.value)}
            placeholder="Scan a barcode or type a name / SKU (F2)" aria-label="Scan or search" autoComplete="off" />
        </form>
        {message && <p className="error" role="alert">{message}</p>}
        <ul className="results">
          {results.map((p) => (
            <li key={p.id}>
              <button type="button" onClick={() => add(p)}>
                <span>{p.name}<br /><span className="muted small">{p.sku}{p.on_hand !== null ? ` · ${prettyQty(toMilli(p.on_hand))} in stock` : ''}</span></span>
              </button>
              {p.units.filter((u) => !u.is_base).map((u) => (
                <button key={u.id} type="button" className="unit" onClick={() => add(p, u)}>+ {u.uom_code}</button>
              ))}
            </li>
          ))}
        </ul>
      </section>

      <section className="cart" aria-label="Cart">
        {cart.lines.length === 0 ? <p className="muted center">Scan or search to start a sale.</p> : (
          <table>
            <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th className="num">Price</th><th className="num">Total</th><th /></tr></thead>
            <tbody>
              {cart.lines.map((l) => (
                <tr key={l.key} className={l.price ? '' : 'unpriced'}>
                  <td>{l.product.name}</td>
                  <td><input className="qty mono" inputMode="decimal" defaultValue={prettyQty(l.qtyMilli)} key={l.qtyMilli}
                    onBlur={(e) => changeQty(l, e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()} aria-label={`Quantity of ${l.product.name}`} /></td>
                  <td>
                    {l.product.units.length > 1 ? (
                      <select value={l.unit.id} onChange={(e) => changeUnit(l, e.target.value)} aria-label="Unit">
                        {l.product.units.map((u) => <option key={u.id} value={u.id}>{u.uom_code}</option>)}
                      </select>
                    ) : l.unit.uom_code}
                  </td>
                  <td className="num">{l.price ? money(l.price.amount_minor) : 'no price'}</td>
                  <td className="num">{money(l.total)}</td>
                  <td><button type="button" className="link" onClick={() => setLines((x) => setQty(x, l.key, 0))} aria-label={`Remove ${l.product.name}`}>✕</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <div className="cart-foot">
          <dl className="totals">
            <dt>{list.prices_include_tax ? 'Tax included' : 'Tax'}</dt><dd>{money(cart.tax)}</dd>
            <dt className="strong">Total</dt><dd className="strong grand">{money(cart.total)}</dd>
          </dl>
          <div className="row">
            <button type="button" onClick={() => setLines([])} disabled={!lines.length}>Clear</button>
            <button type="button" className="primary big" onClick={startPay} disabled={!lines.length}>Pay (F12)</button>
          </div>
        </div>
      </section>

      {paying && (
        <Pay total={cart.total} currency={boot.company.currency} exponent={boot.company.exponent} busy={busy} error={payError}
          onPay={complete} onCancel={() => setPaying(false)} />
      )}
    </div>
  )
}
