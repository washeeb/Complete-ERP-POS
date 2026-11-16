import { useEffect, useState } from 'react'
import { appPathFor, classicPathFor, routeOf, type Route } from './paths'

/** Moving between pages without reloading the app: the address bar, Back and Forward work as for any site. */
const EVENT = 'zx-navigate'

export function navigate(to: string, replace = false) {
  const path = to.startsWith('/admin') ? appPathFor(to) : to
  if (path === location.pathname + location.search) return
  if (replace) history.replaceState(null, '', path)
  else history.pushState(null, '', path)
  window.dispatchEvent(new Event(EVENT))
}

/** The address follows a page that moved by itself inside the frame (a link or a save there), without reloading it. */
export function followFrame(adminPath: string) {
  // The frame shows the old version of the page, so the address says so (a rebuilt page's new address would differ).
  const path = classicPathFor(adminPath.split('?')[0]) + (adminPath.includes('?') ? `?${adminPath.split('?').slice(1).join('?')}` : '')
  if (path !== location.pathname + location.search) history.replaceState(null, '', path)
}

export function useRoute(): Route {
  const [route, setRoute] = useState(() => routeOf(location.pathname, location.search))
  useEffect(() => {
    const update = () => setRoute(routeOf(location.pathname, location.search))
    window.addEventListener('popstate', update)
    window.addEventListener(EVENT, update)
    return () => {
      window.removeEventListener('popstate', update)
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
