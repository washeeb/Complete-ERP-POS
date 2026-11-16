import { makeStyles, mergeClasses, SearchBox, Spinner, Text, tokens } from '@fluentui/react-components'
import { useEffect, useMemo, useRef, useState } from 'react'
import { api, type Menu, type SearchResult } from '../api'
import { navigate } from '../nav'
import { findPages } from '../paths'

const useStyles = makeStyles({
  root: { position: 'relative', width: '100%', maxWidth: '560px' },
  box: { width: '100%', maxWidth: 'none' },
  list: {
    position: 'absolute', top: 'calc(100% + 4px)', insetInline: 0, zIndex: 100, maxHeight: '60vh', overflowY: 'auto', padding: '6px 0',
    backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusLarge, boxShadow: tokens.shadow16,
    border: `1px solid ${tokens.colorNeutralStroke2}`,
  },
  head: { display: 'block', padding: '6px 12px 2px', color: tokens.colorNeutralForeground3, fontSize: tokens.fontSizeBase200, fontWeight: tokens.fontWeightSemibold },
  item: {
    display: 'flex', flexDirection: 'column', gap: '1px', padding: '6px 12px', cursor: 'pointer', color: tokens.colorNeutralForeground1,
    ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover },
  },
  active: { backgroundColor: tokens.colorNeutralBackground1Selected },
  sub: { color: tokens.colorNeutralForeground3, fontSize: tokens.fontSizeBase200 },
  empty: { padding: '8px 12px', color: tokens.colorNeutralForeground3 },
})

type Hit = { kind: 'page' | 'record'; title: string; sub: string; url: string; group: string }

/**
 * One box for everything (Ctrl+K or /): pages of the menu as you type, then records (customers, products, invoices…)
 * from the back office's own search. Arrow keys and Enter, or the mouse.
 */
export function Search({ menu, compact = false }: { menu: Menu; compact?: boolean }) {
  const s = useStyles()
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [records, setRecords] = useState<SearchResult[]>([])
  const [busy, setBusy] = useState(false)
  const [at, setAt] = useState(0)
  const input = useRef<HTMLInputElement>(null)

  useEffect(() => { // Ctrl+K or "/" (outside a text box) jumps to search
    const key = (e: KeyboardEvent) => {
      const typing = e.target instanceof HTMLElement && (e.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName))
      if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !typing)) {
        e.preventDefault()
        input.current?.focus()
      }
    }
    window.addEventListener('keydown', key)
    return () => window.removeEventListener('keydown', key)
  }, [])

  useEffect(() => {
    if (q.trim().length < 2) {
      setRecords([])
      return
    }
    const ctl = new AbortController()
    const t = setTimeout(() => {
      setBusy(true)
      api.search(q.trim(), ctl.signal).then((r) => setRecords(r.results)).catch(() => undefined).finally(() => setBusy(false))
    }, 250)
    return () => { clearTimeout(t); ctl.abort() }
  }, [q])

  const hits: Hit[] = useMemo(() => [
    ...findPages(menu, q, 6).map((p) => ({ kind: 'page' as const, title: p.label, sub: p.where, url: p.url, group: 'Pages' })),
    ...records.map((r) => ({ kind: 'record' as const, title: r.title, sub: r.details, url: r.url, group: r.group })),
  ], [menu, q, records])

  function go(h: Hit) {
    setOpen(false)
    setQ('')
    input.current?.blur()
    navigate(h.url)
  }

  function onKey(e: React.KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); setAt((a) => Math.min(a + 1, hits.length - 1)) }
    if (e.key === 'ArrowUp') { e.preventDefault(); setAt((a) => Math.max(a - 1, 0)) }
    if (e.key === 'Enter' && hits[at]) { e.preventDefault(); go(hits[at]) }
    if (e.key === 'Escape') { setOpen(false); input.current?.blur() }
  }

  let lastGroup = ''
  return (
    <div className={s.root} onBlur={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node)) setOpen(false) }}>
      <SearchBox ref={input} className={s.box} size={compact ? 'small' : 'medium'} value={q} placeholder="Search pages, customers, products… (Ctrl+K)"
        aria-label="Search" aria-expanded={open && q.trim() !== ''} aria-controls="zx-search-list" role="combobox"
        onChange={(_, d) => { setQ(d.value); setOpen(true); setAt(0) }} onFocus={() => setOpen(true)} onKeyDown={onKey} />
      {open && q.trim() !== '' && (
        <div id="zx-search-list" className={s.list} role="listbox" aria-label="Results">
          {hits.map((h, i) => {
            const head = h.group !== lastGroup ? <Text className={s.head}>{h.group}</Text> : null
            lastGroup = h.group
            return (
              <div key={`${h.kind}-${h.url}-${i}`}>
                {head}
                <div role="option" tabIndex={-1} aria-selected={i === at} className={mergeClasses(s.item, i === at && s.active)}
                  onMouseDown={(e) => e.preventDefault()} onClick={() => go(h)} onMouseEnter={() => setAt(i)}>
                  <Text weight="semibold">{h.title}</Text>
                  {h.sub && <Text className={s.sub}>{h.sub}</Text>}
                </div>
              </div>
            )
          })}
          {busy && <div className={s.empty}><Spinner size="tiny" label="Searching records…" /></div>}
          {!busy && hits.length === 0 && <div className={s.empty}>Nothing found for “{q.trim()}”.</div>}
        </div>
      )}
    </div>
  )
}
