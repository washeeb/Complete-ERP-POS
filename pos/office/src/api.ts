/**
 * The new back office talks to the server it is served from, with the person's back-office sign-in (the session cookie).
 * Signed out: back to the sign-in page, which returns here afterwards.
 */

export type MenuItem = { label: string; url: string; icon: string | null; badge: string | null }
export type MenuGroup = { label: string | null; items: MenuItem[] }
export type MenuSection = { label: string; groups: MenuGroup[] }
export type MenuTab = { label: string; icon: string; sections: MenuSection[] }
export type Menu = { home: string; tabs: MenuTab[]; create: { label: string; items: { label: string; url: string }[] }[] }
export type Prefs = { home: string; rows: number; theme: 'system' | 'light' | 'dark'; date: string; density: string; office_look: 'top' | 'side' }
export type Me = {
  user: { id: string; name: string; role: string; photo: string | null; initials: string }
  company: { name: string; color: string; on_color: string; menu_color: string | null; logo: string | null; logo_dark: string | null; icon: string | null }
  prefs: Prefs
  favourites: { url: string; label: string }[]
  classic: string
}
export type SearchResult = { group: string; title: string; url: string; details: string }
export type TodoItem = {
  id: string; title: string; detail: string; who: string | null; where: string | null; amount: number | null; percent: number | null
  at: string; expires_at: string | null; lines: string[]; url: string
}
export type TodoGroup = { key: string; label: string; hint: string; count: number; url: string | null; inline: boolean; items: TodoItem[] }
export type Todo = { currency: string; exponent: number; groups: TodoGroup[]; total: number }
export type SaleRow = { id: string; receipt_no: string; occurred_at: string; kind: string; branch: string | null; till: string | null; cashier: string | null; total: number; paid_by: string[] }
export type SalesList = {
  currency: string; exponent: number; total: number; sum: number; mix: { method: string; amount: number }[]
  page: number; per: number; sort: string; dir: 'asc' | 'desc'; rows: SaleRow[]
}
export type Option = { id: string; label: string }
export type SalesOptions = { branches: Option[]; tellers: Option[]; methods: Option[]; per: number[] }
export type SaleDetail = {
  currency: string; exponent: number; id: string; receipt_no: string; occurred_at: string; kind: string; return_reason: string | null
  cashier: string | null; where: string; net: number; tax: number; total: number; change: number; discount: number; discount_note: string | null; points: number
  customer: { id: string | null; walk_in_name: string | null; walk_in_phone: string | null; details: { label: string; value: string }[]; comment: string | null; comment_on_receipt: boolean }
  lines: { name: string; qty: string; unit_price: number; tax: number; total: number; dosage: string | null }[]
  tax_breakdown: { name: string; amount: number }[]
  payments: { method: string; amount: number; reference: string | null }[]
  actions: { print: string; preview: string; save_customer: boolean }
}
export type Period = 'today' | 'week' | 'month' | 'quarter' | 'year'
export type Kpi = { key: string; label: string; format: 'money' | 'count'; current: number; previous: number | null; change: number | null; spark: number[]; url: string }
export type HomeData = {
  currency: string; exponent: number
  period: { key: Period; label: string; previous: string; options: Record<Period, string> }
  reminders: { label: string; count: number; url: string; urgent: boolean }[]
  kpis: Kpi[]
  top_items: { id: string; name: string; qty: number; total: number }[]
  trend: { months: string[]; series: { key: string; label: string; values: number[] }[] }
  layout: import('./home/layout').HomeLayout | null
}

export class ApiError extends Error {
  status: number
  code: string
  /** Where to go to fix it, when the server says (e.g. "Confirm it's you"). */
  url: string | null

  constructor(message: string, status: number, code = 'error', url: string | null = null) {
    super(message)
    this.status = status
    this.code = code
    this.url = url
  }
}

function xsrf(): string {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : ''
}

async function call<T>(path: string, init: RequestInit = {}): Promise<T> {
  const res = await fetch(path, {
    ...init,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf(), ...(init.headers ?? {}) },
  })
  if (res.status === 401 || res.status === 419) {
    location.assign(`/office-signin?to=${encodeURIComponent(location.pathname + location.search)}`) // signs in, then back here
    throw new ApiError('Signed out', res.status, 'signed_out')
  }
  const body = await res.json().catch(() => ({}))
  if (res.status === 429) throw new ApiError('Too many changes in a minute. Wait a moment, then try again.', 429, 'too_many')
  if (!res.ok) throw new ApiError(body.detail ?? body.message ?? 'Something went wrong. Try again.', res.status, body.error ?? 'error', typeof body.url === 'string' ? body.url : null)
  return body as T
}

export const api = {
  me: () => call<Me>('/office-api/me'),
  menu: () => call<Menu>('/office-api/menu'),
  search: (q: string, signal?: AbortSignal) => call<{ results: SearchResult[] }>(`/office-api/search?q=${encodeURIComponent(q)}`, { signal }),
  home: (period: Period, top: Period) => call<HomeData>(`/office-api/home?period=${period}&top=${top}`),
  saveHome: (layout: import('./home/layout').HomeLayout | null) =>
    call<{ layout: import('./home/layout').HomeLayout | null }>('/office-api/home-layout', { method: 'POST', body: JSON.stringify({ layout }) }),
  sales: (query: string, signal?: AbortSignal) => call<SalesList>(`/office-api/sales?${query}`, { signal }),
  salesOptions: () => call<SalesOptions>('/office-api/sales/options'),
  sale: (id: string) => call<SaleDetail>(`/office-api/sales/${id}`),
  saveSaleCustomer: (id: string, name: string, phone: string) =>
    call<{ customer: { id: string; name: string } }>(`/office-api/sales/${id}/save-customer`, { method: 'POST', body: JSON.stringify({ name, phone }) }),
  todo: () => call<Todo>('/office-api/todo'),
  todoCount: () => call<{ count: number }>('/office-api/todo-count'),
  /** Approve or decline a till request; ApiError code "reconfirm" (with its url) = confirm it is you first. */
  decideTill: (id: string, yes: boolean, note?: string) =>
    call<{ status: string; decided_by: string | null }>(`/office-api/till-requests/${id}/decide`, { method: 'POST', body: JSON.stringify({ yes, note: note ?? null }) }),
  /** Add a page to the person's favourites, or take it off (the same list as the classic back office's star). */
  toggleFavourite: (url: string, label: string) =>
    call<{ favourites: { url: string; label: string }[] }>('/admin-ui/favourite', { method: 'POST', body: JSON.stringify({ url, label }) }),
  setLook: (look: Prefs['office_look']) => call<{ prefs: Prefs }>('/office-api/prefs', { method: 'POST', body: JSON.stringify({ office_look: look }) }),
  /** Sign out of the back office (the same sign-in as the current one), then the sign-in page. */
  signOut: async () => {
    await fetch('/admin/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'text/html' } }).catch(() => undefined)
    location.assign('/admin/login')
  },
}
