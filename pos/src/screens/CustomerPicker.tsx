import { useState } from 'react'
import { searchCustomers } from '../customers'
import type { TillCustomer } from '../types'

/** Find the customer for this sale by name, code or phone (works offline from the cached list). */
export function CustomerPicker({ customers, money, onPick, onCancel }: {
  customers: TillCustomer[]; money: (m: number) => string; onPick: (c: TillCustomer) => void; onCancel: () => void
}) {
  const [q, setQ] = useState('')
  const found = searchCustomers(customers, q)
  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Choose customer" onKeyDown={(e) => e.key === 'Escape' && onCancel()}>
      <div className="card pay">
        <h2>Customer</h2>
        <input autoFocus className="big" placeholder="Name, code or phone" aria-label="Find customer" value={q} onChange={(e) => setQ(e.target.value)} />
        <ul className="results">
          {found.map((c) => (
            <li key={c.id}>
              <button type="button" onClick={() => onPick(c)}>
                <span>{c.name} <span className="muted small">{c.code}{c.phone ? ` · ${c.phone}` : ''}</span><br />
                  <span className="small">
                    {c.credit_limit_minor === null ? 'Cash customer' : c.credit_hold ? 'Credit on hold' : `Credit available ${money(c.available_minor ?? 0)}`}
                    {c.overdue_minor > 0 && <strong className="error"> · {money(c.overdue_minor)} overdue</strong>}
                  </span>
                </span>
              </button>
            </li>
          ))}
          {found.length === 0 && <li className="muted">No customer found. New customers are added in the back office.</li>}
        </ul>
        <div className="row"><button type="button" onClick={onCancel}>Back (Esc)</button></div>
      </div>
    </div>
  )
}
