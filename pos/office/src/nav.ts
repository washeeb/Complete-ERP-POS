import { useEffect, useState } from 'react'
import { appPathFor, routeOf, type Route } from './paths'
import { confirmLeave, hasUnsaved } from './unsaved'

/** Moving between pages without reloading the app: the address bar, Back and Forward work as for any site. */
const EVENT = 'zx-navigate'

/** Open a page of the back office. With unsaved changes on this page, the person is asked first (unsaved.ts). */
export function navigate(to: string, replace = false) {
  const path = to.startsWith('/admin') ? appPathFor(to) : to
  if (path === location.pathname + location.search) return
  confirmLeave(() => go(path, replace))
}

function go(path: string, replace: boolean) {
  if (replace) history.replaceState(null, '', path)
  else history.pushState(null, '', path)
  window.dispatchEvent(new Event(EVENT))
}

/** Where the person is, for Back with unsaved changes: the page is put back, then they are asked. */
let shown = typeof location === 'undefined' ? '' : location.pathname + location.search

export function useRoute(): Route {
  const [route, setRoute] = useState(() => routeOf(location.pathname, location.search))
  useEffect(() => {
    const update = () => {
      shown = location.pathname + location.search
      setRoute(routeOf(location.pathname, location.search))
    }
    const back = () => {
      const to = location.pathname + location.search
      if (to === shown || !hasUnsaved()) { update(); return }
      history.pushState(null, '', shown) // stay on the page with the changes while the person decides
      confirmLeave(() => go(to, false))
    }
    window.addEventListener('popstate', back)
    window.addEventListener(EVENT, update)
    return () => {
      window.removeEventListener('popstate', back)
      window.removeEventListener(EVENT, update)
    }
  }, [])
  return route
}

/** A plain click on a link inside the app: handled here (no reload). A middle or ctrl click opens a new tab as usual. */
export function linkClick(e: React.MouseEvent, to: string) {
  if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return
  e.preventDefault()
  navigate(to)
}
