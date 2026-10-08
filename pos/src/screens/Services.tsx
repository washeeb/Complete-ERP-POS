import { useCallback, useEffect, useState } from 'react'
import { services as api } from '../api'
import { byStaff, coveringItems, hhmm, isService, slots } from '../services'
import type { CartLine } from '../cart'
import type { Appointment, Product, Services, ServicePass, StaffMember, TillCustomer } from '../types'

const errorText = (e: unknown) => (e instanceof Error ? e.message : String(e))
const today = () => new Date().toLocaleDateString('en-CA')

/** Who did this service? Their commission is worked out from the sale. */
export function StaffPrompt({ product, staff, onPick, onCancel }: { product: Product; staff: StaffMember[]; onPick: (s: StaffMember | null) => void; onCancel: () => void }) {
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label={`Who did ${product.name}?`} onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <div className="card">
        <h2>Who is doing {product.name}?</h2>
        <div className="row wrap">
          {staff.map((s) => <button key={s.id} type="button" className="big" onClick={() => onPick(s)}>{s.name}</button>)}
        </div>
        <div className="row"><button type="button" onClick={() => onPick(null)}>Nobody in particular</button><button type="button" onClick={onCancel}>Back</button></div>
      </div>
    </div>
  )
}

/**
 * The appointment book at the till: today's (or another day's) appointments by staff member, booking a new one, arrivals,
 * no-shows, cancellations, and checking out — the service goes on the till with the staff member who did it.
 */
export function AppointmentsScreen({ products, services, customer, onCheckout, onClose }: {
  products: Product[]; services: Services; customer: TillCustomer | null; onCheckout: (a: Appointment) => void; onClose: () => void
}) {
  const [date, setDate] = useState(today())
  const [list, setList] = useState<Appointment[]>([])
  const [staff, setStaff] = useState<StaffMember[]>(services.staff)
  const [error, setError] = useState('')
  const bookable = products.filter(isService)
  const [form, setForm] = useState({ product_id: bookable[0]?.id ?? '', employee_id: services.staff[0]?.id ?? '', time: '', customer_name: customer?.name ?? '', phone: customer?.phone ?? '', note: '' })
  const load = useCallback(() => api.day(date).then((r) => { setList(r.appointments); setStaff(r.staff) }).catch((e) => setError(errorText(e))), [date])
  useEffect(() => { load() }, [load])
  async function act(f: () => Promise<unknown>) {
    setError('')
    try { await f(); await load() } catch (e) { setError(errorText(e)) }
  }
  const times = slots(services.day_start, services.day_end, services.slot_minutes)
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value })
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Appointments">
      <div className="card wide">
        <div className="row spread">
          <h2>Appointments</h2>
          <span className="row">
            <input type="date" aria-label="Day" value={date} onChange={(e) => setDate(e.target.value || today())} />
            <button type="button" onClick={onClose}>Back to till</button>
          </span>
        </div>
        {error && <p className="error" role="alert">{error}</p>}
        <div className="bill-cols">
          <section aria-label="Appointments by staff member">
            {byStaff(staff, list).map(({ staff: s, appointments }) => (
              <div key={s.id}>
                <h3>{s.name}</h3>
                <ul className="bill-items">
                  {appointments.map((a) => (
                    <li key={a.id}>
                      <span><strong>{hhmm(a.starts_at)}–{hhmm(a.ends_at)}</strong> {a.customer_name} · {a.service}
                        {a.note && <span className="small muted"><br />{a.note}</span>}</span>
                      <span className="row wrap">
                        <span className="chip small">{a.status_label}</span>
                        {a.status === 'booked' && <button type="button" onClick={() => act(() => api.status(a.id, 'arrived'))}>Arrived</button>}
                        {(a.status === 'booked' || a.status === 'arrived') && <button type="button" className="primary" onClick={() => onCheckout(a)}>Check out</button>}
                        {a.status === 'booked' && new Date(a.starts_at) < new Date() && <button type="button" onClick={() => act(() => api.status(a.id, 'no_show'))}>No-show</button>}
                        {(a.status === 'booked' || a.status === 'arrived') && <button type="button" className="link" onClick={() => {
                          const reason = window.prompt('Why is it cancelled?')
                          if (reason) act(() => api.status(a.id, 'cancelled', reason))
                        }}>Cancel</button>}
                      </span>
                    </li>
                  ))}
                  {appointments.length === 0 && <li className="muted">Free all day.</li>}
                </ul>
              </div>
            ))}
            {staff.length === 0 && <p className="muted">Nobody here takes appointments yet (back office: HR → Employees → Takes appointments).</p>}
          </section>
          <form aria-label="New appointment" onSubmit={(e) => {
            e.preventDefault()
            act(async () => {
              await api.book({ product_id: form.product_id, employee_id: form.employee_id, starts_at: new Date(`${date}T${form.time}`).toISOString(),
                customer_id: customer?.id ?? null, customer_name: form.customer_name, phone: form.phone || null, note: form.note || null })
              setForm({ ...form, time: '', note: '' })
            })
          }}>
            <h3>Book</h3>
            <label>Service<select value={form.product_id} onChange={set('product_id')} required>
              {bookable.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.service_minutes} min)</option>)}
            </select></label>
            <label>With<select value={form.employee_id} onChange={set('employee_id')} required>
              {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select></label>
            <label>Time<select value={form.time} onChange={set('time')} required>
              <option value="">Choose…</option>
              {times.map((t) => <option key={t} value={t}>{t}</option>)}
            </select></label>
            <label>Customer<input value={form.customer_name} onChange={set('customer_name')} required maxLength={120} /></label>
            <label>Phone<input inputMode="tel" value={form.phone} onChange={set('phone')} maxLength={20} /></label>
            <label>Note<input value={form.note} onChange={set('note')} maxLength={255} /></label>
            <button className="primary big" disabled={!bookable.length || !staff.length}>Book</button>
          </form>
        </div>
      </div>
    </div>
  )
}

/** The customer's prepaid passes: use a session for one of the services it covers (a line at no charge). */
export function PassesScreen({ customer, products, lines, onUse, onClose }: {
  customer: TillCustomer; products: Product[]; lines: CartLine[]; onUse: (pass: ServicePass, item: number, product: Product) => void; onClose: () => void
}) {
  const [passes, setPasses] = useState<ServicePass[] | null>(null)
  const [error, setError] = useState('')
  useEffect(() => { api.passes(customer.id).then((r) => setPasses(r.passes)).catch((e) => setError(errorText(e))) }, [customer.id])
  const services = products.filter(isService)
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Prepaid passes" onKeyDown={(e) => e.key === 'Escape' && onClose()}>
      <div className="card wide">
        <div className="row spread"><h2>{customer.name}: prepaid passes</h2><button type="button" onClick={onClose}>Back</button></div>
        {error && <p className="error" role="alert">{error}</p>}
        {passes === null && !error && <p className="muted">Loading…</p>}
        {passes?.length === 0 && <p className="muted">No passes with sessions left.</p>}
        {passes?.map((p) => (
          <section key={p.id} aria-label={p.name}>
            <h3>{p.name} <span className="muted small">{p.number}{p.expires_on ? ` · until ${p.expires_on}` : ''}</span></h3>
            <ul className="bill-items">
              {p.items.map((it, i) => (
                <li key={i}>
                  <span>{it.label} · <strong>{it.remaining === null ? 'unlimited' : `${it.remaining} left`}</strong></span>
                  <span className="row wrap">
                    {services.filter((s) => coveringItems([p], s.id, lines).some((x) => x.item === i)).map((s) => (
                      <button key={s.id} type="button" onClick={() => onUse(p, i, s)}>Use for {s.name}</button>
                    ))}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </div>
  )
}
