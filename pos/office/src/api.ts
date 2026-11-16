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

export class ApiError extends Error {
  status: number
  code: string

  constructor(message: string, status: number, code = 'error') {
    super(message)
    this.status = status
    this.code = code
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
  if (!res.ok) throw new ApiError(body.detail ?? body.message ?? 'Something went wrong. Try again.', res.status, body.error ?? 'error')
  return body as T
}

export const api = {
  me: () => call<Me>('/office-api/me'),
  menu: () => call<Menu>('/office-api/menu'),
  search: (q: string, signal?: AbortSignal) => call<{ results: SearchResult[] }>(`/office-api/search?q=${encodeURIComponent(q)}`, { signal }),
  setLook: (look: Prefs['office_look']) => call<{ prefs: Prefs }>('/office-api/prefs', { method: 'POST', body: JSON.stringify({ office_look: look }) }),
  /** Sign out of the back office (the same sign-in as the current one), then the sign-in page. */
  signOut: async () => {
    await fetch('/admin/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'text/html' } }).catch(() => undefined)
    location.assign('/admin/login')
  },
}
