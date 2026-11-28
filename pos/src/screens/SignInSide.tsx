import { useEffect, useState, type ReactNode } from 'react'
import { cachedImage } from '../brand'
import { db, type CacheRecord } from '../db'
import type { Bootstrap } from '../types'

/**
 * The sign-in screen in the new look (part 5a): the company on one side, in its colour (logo, branch and till, the
 * date and time), the sign-in card on the other. Read from what the till kept from its last start, so it shows offline
 * too; a till that never started shows the sign-in card alone.
 */
export function SignInSide({ children }: { children: ReactNode }) {
  const [boot, setBoot] = useState<Bootstrap | null>(null)
  const [logo, setLogo] = useState<string | null>(null)
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    let live = true
    db.cache.get('bootstrap').then(async (c) => {
      const b = (c as Extract<CacheRecord, { id: 'bootstrap' }> | undefined)?.data ?? null
      if (!live || !b) return
      setBoot(b)
      const src = await cachedImage(b.brand?.logo_dark_url ?? b.brand?.logo_url)
      if (live) setLogo(src)
    }).catch(() => undefined)
    const t = setInterval(() => setNow(new Date()), 30_000)
    return () => { live = false; clearInterval(t) }
  }, [])
  if (!boot) return <>{children}</>
  const tz = boot.company.timezone
  return (
    <div className="signin-layout">
      <aside className="signin-brand" aria-label={boot.company.name}>
        {logo ? <img className="signin-logo" src={logo} alt="" /> : null}
        <p className="signin-company">{boot.company.name}</p>
        <p className="signin-where">{boot.branch.name}{boot.till ? ` · ${boot.till.name}` : ''}</p>
        <p className="signin-time" aria-live="off">{now.toLocaleTimeString('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit' })}</p>
        <p className="signin-date">{now.toLocaleDateString('en-GB', { timeZone: tz, weekday: 'long', day: 'numeric', month: 'long' })}</p>
        {boot.brand?.powered_by && <p className="signin-powered">{boot.brand.powered_by}</p>}
      </aside>
      <div className="signin-card">{children}</div>
    </div>
  )
}
