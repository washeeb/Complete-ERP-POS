import { makeStyles, mergeClasses, Text, tokens } from '@fluentui/react-components'
import { CircleSmallFilled } from '@fluentui/react-icons'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { saveAsked, setLeaveGuard } from '../unsaved'

const useStyles = makeStyles({
  // Fixed to the bottom of the window while the form scrolls, edge to edge: the page's own padding (its --zx-pad-*
  // values) is taken back, so nothing of the form shows below or beside it (owner report 2026-11-26).
  bar: {
    position: 'sticky', bottom: 'calc(-1 * var(--zx-pad-b, 0px))', zIndex: 3, display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap',
    marginTop: '12px', marginInline: 'calc(-1 * var(--zx-pad-x, 0px))', marginBottom: 'calc(-1 * var(--zx-pad-b, 0px))',
    padding: '10px var(--zx-pad-x, 12px)', backgroundColor: tokens.colorNeutralBackground1,
    borderTop: `1px solid ${tokens.colorNeutralStroke2}`, boxShadow: '0 -4px 12px rgba(0,0,0,.06)',
    ':not(:last-child)': { marginBottom: '16px' }, // more of the page follows (e.g. My profile): keep its distance
  },
  note: { display: 'inline-flex', alignItems: 'center', gap: '4px', color: tokens.colorPaletteDarkOrangeForeground1 },
  saved: { color: tokens.colorNeutralForeground3 },
  grow: { flex: '1 1 auto' },
  buttons: { display: 'flex', gap: '8px', flexWrap: 'wrap', justifyContent: 'flex-end' },
})

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b)

/**
 * The save bar at the foot of every form (Cancel, Save…; the last button saves). Given the form's values, it also:
 * - says "Unsaved changes" while there are any;
 * - saves with Ctrl+S (or ⌘S) by pressing that button, so a save not allowed yet (button greyed out) or already
 *   running is not sent twice;
 * - asks before the person moves away with unsaved changes (unsaved.ts).
 * A change counts only after the person has typed or clicked in the page, so values filled in while it loads do not.
 */
export function SaveBar({ value, saveKey = true, children }: { value?: unknown; saveKey?: boolean; children: ReactNode }) {
  const s = useStyles()
  const base = useRef<string>(JSON.stringify(value))
  const now = useRef(value)
  const touched = useRef(false)
  const bar = useRef<HTMLDivElement>(null)
  const [dirty, setDirty] = useState(false)
  const [justSaved, setJustSaved] = useState(false)
  now.current = value

  useEffect(() => {
    if (!touched.current) base.current = JSON.stringify(value) // still loading: what is shown is the starting point
    setDirty(touched.current && !same(JSON.parse(base.current ?? 'null'), value))
  }, [value])

  useEffect(() => {
    // The person's own input anywhere in the page this bar belongs to (not the menus or the top bar).
    const press = () => {
      const buttons = bar.current?.querySelectorAll('button')
      const last = buttons?.[buttons.length - 1]
      if (last && !last.disabled && last.getAttribute('aria-disabled') !== 'true') last.click()
    }
    const page = bar.current?.closest('main') ?? bar.current?.parentElement
    const touch = (e: Event) => {
      if (page && e.target instanceof Node && page.contains(e.target) && !bar.current?.contains(e.target)) touched.current = true
    }
    const keys = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 's' && saveKey) {
        e.preventDefault() // never the browser's "save this web page"
        saveAsked()
        press()
      }
    }
    document.addEventListener('pointerdown', touch, true)
    document.addEventListener('keydown', touch, true)
    document.addEventListener('input', touch, true) // pasted or filled in by the browser
    document.addEventListener('keydown', keys)
    const off = setLeaveGuard({
      dirty: () => touched.current && base.current !== JSON.stringify(now.current),
      save: press,
      saved: () => {
        base.current = JSON.stringify(now.current)
        touched.current = false
        setDirty(false)
        setJustSaved(true)
      },
    })
    return () => {
      off()
      document.removeEventListener('pointerdown', touch, true)
      document.removeEventListener('keydown', touch, true)
      document.removeEventListener('input', touch, true)
      document.removeEventListener('keydown', keys)
    }
  }, [])

  useEffect(() => { if (dirty) setJustSaved(false) }, [dirty])

  return (
    <div ref={bar} className={s.bar} role="region" aria-label="Save" onClickCapture={(e) => { if ((e.target as HTMLElement).closest('button')) saveAsked() }}>
      {value !== undefined && (dirty
        ? <Text className={s.note} role="status"><CircleSmallFilled />Unsaved changes{saveKey ? ' · Ctrl+S saves' : ''}</Text>
        : justSaved ? <Text className={s.saved} role="status">Saved</Text> : null)}
      <span className={s.grow} />
      <div className={mergeClasses(s.buttons)}>{children}</div>
    </div>
  )
}
