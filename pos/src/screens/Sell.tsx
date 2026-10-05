import { useEffect, useMemo, useRef, useState } from 'react'
import { v7 as uuidv7 } from 'uuid'
import { api, ApiError } from '../api'
import { addToCart, findByBarcode, priceCart, qtyAllowed, search, setQty, type CartLine } from '../cart'
import { db, nextSeq } from '../db'
import { formatMoney, prettyQty, toMilli } from '../money'
import type { Bootstrap, Payment, PostedSale, Product, Unit } from '../types'
import { Pay } from './Pay'

export function Sell({ boot, products, onPosted, onRefresh, onCloseShift, onLogout }: {
  boot: Bootstrap; products: Product[]; onPosted: (s: PostedSale) => void; onRefresh: () => Promise<void>
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
  const [pending, setPending] = useState(0)
  const searchRef = useRef<HTMLInputElement>(null)
  const list = boot.price_lists.find((l) => l.id === listId) ?? defaultList
  const money = (m: number) => formatMoney(m, boot.company.currency, boot.company.exponent)
  const cart = useMemo(() => priceCart(lines, list, new Date()), [lines, list])
  const results = useMemo(() => search(products, query), [products, query])

  useEffect(() => {
    db.pendingSales.count().then(setPending)
  }, [paying])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'F2') { e.preventDefault(); searchRef.current?.focus() }
      if (e.key === 'F12' && lines.length && !paying) { e.preventDefault(); startPay() }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  })

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
    const payload = {
      id: uuidv7(),
      device_seq: await nextSeq(boot.last_device_seq),
      occurred_at: new Date().toISOString(),
      price_list_id: list.id,
      shift_id: boot.shift!.id,
      lines: cart.lines.map((l) => ({
        product_id: l.product.id, product_uom_id: l.unit.id, qty: prettyQty(l.qtyMilli), unit_price_minor: l.price!.amount_minor, line_total_minor: l.total,
      })),
      totals: { net_minor: cart.net, tax_minor: cart.tax, total_minor: cart.total },
      payments,
    }
    // Kept locally until the server acknowledges it; resending the same id is safe (idempotent).
    await db.pendingSales.put({ id: payload.id, payload, createdAt: payload.occurred_at, lastError: null })
    try {
      const { sale } = await api.postSale(payload)
      await db.pendingSales.delete(payload.id)
      setLines([])
      setPaying(false)
      onPosted(sale)
    } catch (err) {
      const e = err instanceof ApiError ? err : new ApiError('server', String(err), 0)
      if (e.code === 'network') {
        await db.pendingSales.update(payload.id, { lastError: e.message })
        setPayError(`${e.message} The sale is saved on this till and will be sent when you press “Send now”. Do not ring it up again.`)
      } else {
        await db.pendingSales.delete(payload.id) // refused by the server: nothing was recorded
        setPayError(e.message)
        if (['price_changed', 'totals_mismatch', 'product_unavailable'].includes(e.code)) await onRefresh()
      }
    } finally {
      setBusy(false)
    }
  }

  async function resendPending() {
    for (const p of await db.pendingSales.toArray()) {
      try {
        await api.postSale(p.payload)
        await db.pendingSales.delete(p.id)
      } catch (err) {
        await db.pendingSales.update(p.id, { lastError: err instanceof Error ? err.message : String(err) })
        setMessage(err instanceof Error ? err.message : String(err))
        break
      }
    }
    setPending(await db.pendingSales.count())
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

      {pending > 0 && (
        <div className="banner warn" role="status">
          {pending} sale(s) saved on this till but not yet received by the server.
          <button type="button" onClick={resendPending}>Send now</button>
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
