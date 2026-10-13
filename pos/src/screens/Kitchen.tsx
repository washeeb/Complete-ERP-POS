import { useCallback, useEffect, useRef, useState } from 'react'
import { kds, type WasteProduct } from '../api'
import { WasteDialog } from './RestaurantExtras'
import { prettyQty, toMilli } from '../money'
import { kitchenNote, minutesSince } from '../restaurant'
import type { DiningItem, Floor, KitchenTicket } from '../types'

const STATION_KEY = 'kds-station'
const PRINTED_KEY = 'kds-printed'
const read = (k: string) => { try { return localStorage.getItem(k) } catch { return null } }
const write = (k: string, v: string) => { try { localStorage.setItem(k, v) } catch { /* private window: fine */ } }

/**
 * Kitchen screen (a "Kitchen display" device; nobody signs in). Shows dishes sent to its station, oldest first.
 * Tap a dish when it is ready; "Collected" when it leaves the pass. A station set to print tickets prints each new
 * dish instead of relying on the screen (printer fallback).
 */
export function Kitchen() {
  const [station, setStation] = useState<string>(read(STATION_KEY) ?? '')
  const [stations, setStations] = useState<Floor['stations']>([])
  const [tickets, setTickets] = useState<KitchenTicket[]>([])
  const [error, setError] = useState('')
  const [now, setNow] = useState(() => Date.now())
  const [toPrint, setToPrint] = useState<KitchenTicket[]>([])
  const [waste, setWaste] = useState<WasteProduct[] | null>(null)
  const printed = useRef(new Set<string>(JSON.parse(read(PRINTED_KEY) ?? '[]') as string[]))

  const load = useCallback(async () => {
    try {
      const r = await kds.tickets(station || null)
      setStations(r.stations)
      setTickets(r.tickets)
      setNow(Date.now())
      setError('')
      const st = r.stations.find((s) => s.code === station)
      if (st?.auto_print) {
        const fresh = r.tickets.map((t) => ({ ...t, items: t.items.filter((i) => i.status === 'fired' && !printed.current.has(i.id)) })).filter((t) => t.items.length)
        if (fresh.length) {
          fresh.forEach((t) => t.items.forEach((i) => printed.current.add(i.id)))
          write(PRINTED_KEY, JSON.stringify([...printed.current].slice(-500)))
          setToPrint(fresh)
        }
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    }
  }, [station])

  useEffect(() => {
    load()
    const id = setInterval(load, 4000)
    return () => clearInterval(id)
  }, [load])
  useEffect(() => {
    if (!toPrint.length) return
    const t = setTimeout(() => { window.print(); setToPrint([]) }, 50) // after the ticket is on the page
    return () => clearTimeout(t)
  }, [toPrint])

  async function bump(items: DiningItem[], status: 'ready' | 'fired' | 'served') {
    if (!items.length) return
    try {
      await kds.bump(items.map((i) => i.id), status)
      await load()
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    }
  }

  return (
    <div className="kitchen">
      <header className="topbar no-print">
        <strong>Kitchen</strong>
        <label className="inline">Station
          <select value={station} onChange={(e) => { setStation(e.target.value); write(STATION_KEY, e.target.value) }} aria-label="Station">
            <option value="">All stations</option>
            {stations.map((s) => <option key={s.code} value={s.code}>{s.name}{s.auto_print ? ' (prints)' : ''}</option>)}
          </select>
        </label>
        <span className="muted small">{tickets.reduce((a, t) => a + t.items.filter((i) => i.status === 'fired').length, 0)} to cook</span>
        <button type="button" onClick={() => kds.products().then((r) => setWaste(r.products)).catch((e) => setError(e instanceof Error ? e.message : String(e)))}>Log waste</button>
      </header>
      {error && <p className="error no-print" role="alert">{error}</p>}
      <div className="tickets no-print">
        {tickets.length === 0 && <p className="muted center">No orders. New ones appear here by themselves.</p>}
        {tickets.map((t) => {
          const oldest = Math.max(...t.items.map((i) => minutesSince(i.fired_at, now)))
          const ready = t.items.filter((i) => i.status === 'ready')
          return (
            <article key={t.order_id} className={`ticket ${oldest >= 20 ? 'late' : oldest >= 10 ? 'slow' : ''}`} aria-label={`Ticket ${t.label}`}>
              <header><strong>{t.label}</strong><span className="small">{t.waiter} · {oldest}′</span></header>
              <ul>
                {t.items.map((i) => (
                  <li key={i.id}>
                    <button type="button" className={`dish ${i.status}`} onClick={() => bump([i], i.status === 'fired' ? 'ready' : 'fired')}
                      aria-label={`${i.name} ${i.status === 'ready' ? 'ready — tap to recall' : 'tap when ready'}`}>
                      <span><strong>{prettyQty(toMilli(i.qty))} × {i.name}</strong>{i.seat ? ` · seat ${i.seat}` : ''}
                        {kitchenNote(i.modifiers.map((m) => m.name), i.note) && <><br /><span className="note">{kitchenNote(i.modifiers.map((m) => m.name), i.note)}</span></>}</span>
                      <span className="small">{i.status === 'ready' ? 'Ready' : `${minutesSince(i.fired_at, now)}′`}</span>
                    </button>
                  </li>
                ))}
              </ul>
              <div className="row">
                <button type="button" onClick={() => bump(t.items.filter((i) => i.status === 'fired'), 'ready')} disabled={!t.items.some((i) => i.status === 'fired')}>All ready</button>
                <button type="button" className="primary" onClick={() => bump(ready, 'served')} disabled={!ready.length}>Collected</button>
              </div>
            </article>
          )
        })}
      </div>
      {waste && <WasteDialog products={waste} identify money={{ currency: 'GHS', exponent: 2 }}
        onSave={(b) => kds.waste({ ...b, username: b.username ?? '', pin: b.pin ?? '' })} onCancel={() => setWaste(null)} />}
      {toPrint.map((t) => (
        <div key={t.order_id} className="receipt kitchen-ticket print-only" aria-hidden="true">
          <header className="center"><h2>{station || 'KITCHEN'}</h2><p><strong>{t.label}</strong> · {t.waiter}</p></header>
          <hr />
          {t.items.map((i) => (
            <p key={i.id}><strong>{prettyQty(toMilli(i.qty))} × {i.name}</strong>{i.seat ? ` · seat ${i.seat}` : ''}
              {kitchenNote(i.modifiers.map((m) => m.name), i.note) && <><br />{kitchenNote(i.modifiers.map((m) => m.name), i.note)}</>}</p>
          ))}
        </div>
      ))}
    </div>
  )
}
