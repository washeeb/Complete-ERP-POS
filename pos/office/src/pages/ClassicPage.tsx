import { makeStyles, ProgressBar, tokens } from '@fluentui/react-components'
import { useEffect, useRef, useState } from 'react'
import { followFrame } from '../nav'

const useStyles = makeStyles({
  wrap: { position: 'relative', flex: '1 1 auto', display: 'flex', minHeight: 0 },
  frame: { flex: '1 1 auto', width: '100%', border: 'none', backgroundColor: tokens.colorNeutralBackground2 },
  bar: { position: 'absolute', insetInline: 0, top: 0 },
})

/**
 * A page of the current back office, not yet rebuilt, shown inside the new one: its own menus are hidden (layout.embedded
 * on the server) and it tells this page its title and address, so the address bar and Back follow it.
 */
export function ClassicPage({ admin, onTitle }: { admin: string; onTitle: (title: string) => void }) {
  const s = useStyles()
  const frame = useRef<HTMLIFrameElement>(null)
  const [loading, setLoading] = useState(true)

  // A menu click or Back: show that page, unless the frame is already on it (it may have moved there by itself).
  useEffect(() => {
    const f = frame.current
    if (!f) return
    let here: string | null = null
    try {
      here = f.contentWindow ? f.contentWindow.location.pathname + f.contentWindow.location.search : null
    } catch {
      here = null
    }
    if (here !== admin) {
      setLoading(true)
      f.src = admin
    }
  }, [admin])

  useEffect(() => {
    const onMessage = (e: MessageEvent) => {
      if (e.origin !== location.origin || e.source !== frame.current?.contentWindow) return
      const d = e.data as { type?: string; title?: string; path?: string }
      if (d?.type !== 'zx-page') return
      setLoading(false)
      if (typeof d.title === 'string') onTitle(d.title.replace(/\s+[-–|]\s+[^-–|]+$/, ''))
      if (typeof d.path !== 'string') return
      // Signed out meanwhile, or the page left the back office: the whole window goes there, not the frame.
      if (!d.path.startsWith('/admin') || /^\/admin\/(login|logout)\b/.test(d.path)) location.assign(d.path)
      else followFrame(d.path)
    }
    window.addEventListener('message', onMessage)
    return () => window.removeEventListener('message', onMessage)
  }, [onTitle])

  return (
    <div className={s.wrap}>
      {loading && <ProgressBar className={s.bar} thickness="medium" aria-label="Opening the page" />}
      <iframe ref={frame} className={s.frame} title="Page" onLoad={() => setLoading(false)}
        allow="publickey-credentials-get *; clipboard-write; camera" />
    </div>
  )
}
