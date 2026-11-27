import { Button, makeStyles, mergeClasses, Popover, PopoverSurface, PopoverTrigger, SearchBox, Spinner, Text, tokens, Tooltip } from '@fluentui/react-components'
import { BuildingRegular, CheckmarkRegular, ChevronDownRegular, ChevronRightRegular, HistoryRegular } from '@fluentui/react-icons'
import { useEffect, useMemo, useState } from 'react'
import { api, type Me, type Scope } from '../api'
import { confirmLeave } from '../unsaved'

const useStyles = makeStyles({
  trigger: { maxWidth: '300px', minWidth: 0, '@media (max-width: 1280px)': { maxWidth: '220px' } },
  label: { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },
  surface: { width: 'min(560px, calc(100vw - 24px))', padding: 0, display: 'flex', flexDirection: 'column', maxHeight: 'min(70vh, 560px)' },
  head: { padding: '10px 12px', borderBottom: `1px solid ${tokens.colorNeutralStroke2}`, display: 'grid', gap: '8px' },
  cols: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1.2fr)', minHeight: 0, overflow: 'hidden', flex: '1 1 auto',
    '@media (max-width: 560px)': { gridTemplateColumns: '1fr', overflowY: 'auto' } },
  col: { overflowY: 'auto', padding: '6px', display: 'grid', alignContent: 'start', gap: '2px' },
  left: { borderRight: `1px solid ${tokens.colorNeutralStroke2}`, '@media (max-width: 560px)': { borderRight: 'none', borderBottom: `1px solid ${tokens.colorNeutralStroke2}` } },
  group: { padding: '6px 8px 2px', color: tokens.colorNeutralForeground3, fontSize: tokens.fontSizeBase200 },
  item: {
    display: 'flex', alignItems: 'center', gap: '8px', width: '100%', textAlign: 'left', padding: '7px 8px', border: 'none', borderRadius: tokens.borderRadiusMedium,
    backgroundColor: 'transparent', color: 'inherit', cursor: 'pointer', font: 'inherit',
    ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover }, ':focus-visible': { outline: `2px solid ${tokens.colorStrokeFocus2}` },
  },
  open: { backgroundColor: tokens.colorNeutralBackground1Selected },
  grow: { flex: '1 1 auto', minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' },
  muted: { color: tokens.colorNeutralForeground3, padding: '8px' },
  recent: { display: 'flex', flexWrap: 'wrap', gap: '6px' },
})

const PHONE = '(max-width: 760px)'

function usePhone(): boolean {
  const [phone, setPhone] = useState(() => typeof matchMedia === 'function' && matchMedia(PHONE).matches)
  useEffect(() => {
    if (typeof matchMedia !== 'function') return
    const m = matchMedia(PHONE)
    const on = () => setPhone(m.matches)
    m.addEventListener('change', on)
    return () => m.removeEventListener('change', on)
  }, [])
  return phone
}

/** Where the back office is looking, in words: "Pharmacy › Branch C", "All industries › All branches". */
export function scopeLabel(sc: Scope): string {
  const ind = sc.industries.find((i) => i.id === sc.industry) ?? sc.industries[0]
  const br = ind?.branches.find((b) => b.id === sc.branch)
  return `${ind?.label ?? 'All industries'} › ${br ? br.label : ind && ind.branches.length === 1 ? ind.branches[0].label : 'All branches'}`
}

/**
 * The one picker for which industry and branch the back office shows (owner 2026-11-27, after Dynamics 365's company
 * switcher and Fiori's context selector): industries on the left, the chosen one's branches on the right, a search, and
 * the places used lately. An industry with only one of the person's branches has no branch list: choosing the industry
 * is enough. Every list, report, the Business Portal and To-do follow the choice, so the page opens again with it
 * (after asking, when there is unsaved work). Kept for the person on any computer.
 */
export function ScopePicker({ me }: { me: Me }) {
  const s = useStyles()
  const compact = usePhone() // on a phone: an icon, so the top row still fits
  const sc = me.scope
  const [open, setOpen] = useState(false)
  const [at, setAt] = useState(sc?.industry ?? '')
  const [q, setQ] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (open && sc) { setAt(sc.industry); setQ('') } }, [open, sc])

  const words = q.trim().toLowerCase()
  const industries = useMemo(() => (sc?.industries ?? []).filter((i) => !words || i.label.toLowerCase().includes(words)
    || i.branches.some((b) => b.label.toLowerCase().includes(words))), [sc, words])
  if (!sc) return null
  const shown = sc.industries.find((i) => i.id === at) ?? industries[0]
  const branches = (shown?.branches ?? []).filter((b) => !words || b.label.toLowerCase().includes(words) || shown.label.toLowerCase().includes(words))

  const go = (industry: string, branch: string) => {
    setOpen(false)
    if (industry === sc.industry && branch === sc.branch) return
    confirmLeave(() => {
      setBusy(true)
      api.chooseScope(industry, branch).then(() => window.location.reload()).catch(() => setBusy(false))
    })
  }
  const label = scopeLabel(sc)

  return (
    <Popover open={open} onOpenChange={(_, d) => setOpen(d.open)} positioning="below-end" trapFocus>
      <PopoverTrigger disableButtonEnhancement>
        {compact
          ? <Tooltip content={`Showing: ${label}`} relationship="label"><Button appearance="subtle" icon={busy ? <Spinner size="tiny" /> : <BuildingRegular />} /></Tooltip>
          : <Button appearance="outline" size="small" className={s.trigger} icon={busy ? <Spinner size="tiny" /> : <BuildingRegular />} aria-label={`Showing: ${label}. Change the industry or branch`}>
              <span className={s.label}>{label}</span><ChevronDownRegular />
            </Button>}
      </PopoverTrigger>
      <PopoverSurface className={s.surface} aria-label="Industry and branch shown">
        <div className={s.head}>
          <SearchBox size="small" placeholder="Find an industry or branch" value={q} onChange={(_, d) => setQ(d.value)} autoFocus />
          {!words && sc.recent.length > 1 && (
            <div>
              <Text size={200} className={s.muted} style={{ padding: 0 }}>Lately</Text>
              <div className={s.recent}>
                {sc.recent.slice(0, 5).map((r) => (
                  <Button key={`${r.industry}|${r.branch}`} size="small" appearance="subtle" icon={<HistoryRegular />} onClick={() => go(r.industry, r.branch)}>{r.label}</Button>
                ))}
              </div>
            </div>
          )}
        </div>
        <div className={s.cols}>
          <div className={mergeClasses(s.col, s.left)} role="listbox" aria-label="Industries">
            <span className={s.group}>Industry</span>
            {industries.length === 0 && <span className={s.muted}>Nothing matches.</span>}
            {industries.map((i) => {
              const one = i.branches.length === 1 // no branch to choose: the industry is enough
              return (
                <button key={i.id} type="button" role="option" aria-selected={i.id === sc.industry} className={mergeClasses(s.item, i.id === shown?.id && s.open)}
                  onClick={() => (one ? go(i.id, '') : setAt(i.id))} onMouseEnter={() => !one && setAt(i.id)} onFocus={() => !one && setAt(i.id)}>
                  <span className={s.grow}>{i.label}</span>
                  {i.id === sc.industry && <CheckmarkRegular />}
                  {!one && <ChevronRightRegular />}
                </button>
              )
            })}
          </div>
          <div className={s.col} role="listbox" aria-label={`Branches of ${shown?.label ?? ''}`}>
            {shown && shown.branches.length > 1 && (<>
              <span className={s.group}>Branch</span>
              <button type="button" role="option" aria-selected={shown.id === sc.industry && sc.branch === ''} className={s.item} onClick={() => go(shown.id, '')}>
                <span className={s.grow}><b>All {shown.id === '' ? 'my' : shown.label} branches</b> ({shown.branches.length})</span>
                {shown.id === sc.industry && sc.branch === '' && <CheckmarkRegular />}
              </button>
              {branches.map((b) => (
                <button key={b.id} type="button" role="option" aria-selected={b.id === sc.branch} className={s.item} onClick={() => go(shown.id, b.id)}>
                  <span className={s.grow}>{b.label}</span>
                  {b.id === sc.branch && shown.id === sc.industry && <CheckmarkRegular />}
                </button>
              ))}
            </>)}
            {shown && shown.branches.length === 1 && <span className={s.muted}>{shown.label} has one branch: {shown.branches[0].label}.</span>}
          </div>
        </div>
      </PopoverSurface>
    </Popover>
  )
}
