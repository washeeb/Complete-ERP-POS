import {
  Button, Checkbox, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, makeStyles, Menu, MenuDivider, MenuGroup,
  MenuGroupHeader, MenuItem, MenuItemCheckbox, MenuItemRadio, MenuList, MenuPopover, MenuTrigger, mergeClasses, Option, SearchBox, Spinner, Text, tokens,
} from '@fluentui/react-components'
import {
  ArrowDownRegular, ArrowLeftRegular, ArrowResetRegular, ArrowRightRegular, ArrowUpRegular, CalendarRegular, ChevronDownRegular, EyeOffRegular,
  LayoutColumnThreeRegular, MoreHorizontalRegular, PersonEditRegular,
} from '@fluentui/react-icons'
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { api, type HomeData, type Me, type Menu as MenuData, type Period } from '../api'
import { column, move, normalize, nudge, PANEL_NAMES, PANELS, standardLayout, withCols, type HomeLayout, type PanelId, type Tile } from '../home/layout'
import { KpisPanel, MeterPanel, RemindersPanel, ShortcutsPanel, TilesPanel, TopItemsPanel, TrendPanel, tilesFor } from '../home/Panels'
import { flatMenu } from '../paths'
import { recentPages } from '../recent'

const useStyles = makeStyles({
  page: { flex: '1 1 auto', overflowY: 'auto', padding: '16px 20px 32px', '--zx-pad-t': '16px', '--zx-pad-x': '20px', '--zx-pad-b': '32px',
    '@media (max-width: 760px)': { padding: '12px 10px 24px', '--zx-pad-t': '12px', '--zx-pad-x': '10px', '--zx-pad-b': '24px' } },
  head: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px', flexWrap: 'wrap', marginBottom: '14px' },
  title: { fontSize: '22px', fontWeight: tokens.fontWeightSemibold, margin: 0 },
  greeting: { color: tokens.colorNeutralForeground3, display: 'block' },
  tools: { display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' },
  grid: { display: 'grid', gap: '16px', alignItems: 'start', '@media (max-width: 1100px)': { gridTemplateColumns: '1fr !important' } },
  col: { display: 'grid', gap: '16px', minWidth: 0, minHeight: '80px', alignContent: 'start' },
  panel: {
    backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, boxShadow: tokens.shadow4,
    border: `1px solid ${tokens.colorNeutralStroke2}`, minWidth: 0, overflow: 'hidden',
  },
  grab: {
    display: 'flex', justifyContent: 'center', height: '10px', cursor: 'grab', color: tokens.colorNeutralForeground4,
    ':hover': { color: tokens.colorNeutralForeground2 }, ':active': { cursor: 'grabbing' },
  },
  grabBar: { width: '28px', height: '3px', borderRadius: '2px', backgroundColor: 'currentColor', marginTop: '4px' },
  panelHead: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', padding: '2px 8px 0 16px' },
  panelTitle: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, padding: '6px 0' },
  panelBody: { padding: '6px 16px 16px' },
  tilePick: { display: 'grid', gap: '4px', maxHeight: '50vh', overflowY: 'auto', marginTop: '8px' },
})

/**
 * Home (owner request 2026-11-16, after the NetSuite-style template): reminders, tiles, key performance indicators with
 * the period before, a KPI meter, top items, the monthly trend and shortcuts. *Viewing* sets the period for the figures;
 * *Personalize* chooses the panels and tiles; *Layout* two or three columns. Panels are dragged by their handle (or moved
 * with the keyboard from their ⋯ menu); everything is kept for the person.
 */
export function HomePage({ me, menu }: { me: Me; menu: MenuData }) {
  const s = useStyles()
  const [period, setPeriod] = useState<Period>('month')
  const [top, setTop] = useState<Period>('month')
  const [data, setData] = useState<HomeData | null>(null)
  const [error, setError] = useState('')
  const [layout, setLayout] = useState<HomeLayout | null>(null)
  const [dragging, setDragging] = useState<PanelId | null>(null)
  const [dropAt, setDropAt] = useState<{ col: number; before: PanelId | null } | null>(null)
  const [picking, setPicking] = useState(false)
  const saveTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  useEffect(() => {
    let live = true
    api.home(period, top).then((d) => {
      if (!live) return
      setData(d)
      setLayout((l) => l ?? normalize(d.layout))
    }).catch((e: unknown) => live && setError(e instanceof Error ? e.message : String(e)))
    return () => { live = false }
  }, [period, top])

  const change = useCallback((next: HomeLayout | null) => {
    setLayout(next ?? standardLayout())
    clearTimeout(saveTimer.current)
    saveTimer.current = setTimeout(() => { void api.saveHome(next).catch(() => undefined) }, 400) // kept for the person, quietly
  }, [])

  const hour = new Date().getHours()
  const hello = `${hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'}, ${me.user.name.split(' ')[0]}`

  if (error) return <div className={s.page}><Text>{error}</Text></div>
  if (!data || !layout) return <div className={s.page}><Spinner label="Gathering your figures…" /></div>

  const cols = layout.cols
  const template = cols === 3 ? 'minmax(240px, 1fr) minmax(0, 2fr) minmax(240px, 1fr)' : 'minmax(260px, 1fr) minmax(0, 2fr)'
  const body = (id: PanelId): ReactNode => {
    switch (id) {
      case 'reminders': return <RemindersPanel data={data} />
      case 'tiles': return <TilesPanel menu={menu} tiles={layout.tiles} onChoose={() => setPicking(true)} />
      case 'kpis': return <KpisPanel data={data} />
      case 'meter': return <MeterPanel data={data} choice={layout.meter} onChoose={(k) => change({ ...layout, meter: k })} />
      case 'top_items': return <TopItemsPanel data={data} top={top} onTop={setTop} />
      case 'trend': return <TrendPanel data={data} />
      case 'shortcuts': return <ShortcutsPanel me={me} menu={menu} recent={recentPages()} />
    }
  }
  const title = (id: PanelId) => (id === 'top_items' ? 'Top 15 items by quantity sold' : PANEL_NAMES[id])

  const drop = (col: number) => {
    if (dragging && dropAt) change(move(layout, dragging, col, dropAt.before === dragging ? null : dropAt.before))
    setDragging(null)
    setDropAt(null)
  }

  return (
    <div className={s.page}>
      <div className={s.head}>
        <div>
          <h1 className={s.title}>Business Portal</h1>
          <Text size={300} className={s.greeting}>{hello}. {data.period.label} so far, against {data.period.previous.toLowerCase()}.</Text>
        </div>
        <div className={s.tools}>
          <Text size={300}>Viewing:</Text>
          <Dropdown aria-label="Period for the figures" value={data.period.options[period]} selectedOptions={[period]} style={{ minWidth: 150 }}
            onOptionSelect={(_, d) => d.optionValue && setPeriod(d.optionValue as Period)} expandIcon={<CalendarRegular />}>
            {Object.entries(data.period.options).map(([k, v]) => <Option key={k} value={k}>{v}</Option>)}
          </Dropdown>
          <Menu checkedValues={{ panels: layout.panels.filter((p) => !p.hidden).map((p) => p.id) }}
            onCheckedValueChange={(_, d) => d.name === 'panels' && change({ ...layout, panels: layout.panels.map((p) => ({ ...p, hidden: !d.checkedItems.includes(p.id) })) })}>
            <MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<PersonEditRegular />}>Personalize <ChevronDownRegular /></Button></MenuTrigger>
            <MenuPopover>
              <MenuList>
                <MenuGroup>
                  <MenuGroupHeader>Panels on your Business Portal</MenuGroupHeader>
                  {PANELS.map((id) => <MenuItemCheckbox key={id} name="panels" value={id}>{PANEL_NAMES[id]}</MenuItemCheckbox>)}
                </MenuGroup>
                <MenuDivider />
                <MenuItem onClick={() => setPicking(true)}>Choose tiles…</MenuItem>
              </MenuList>
            </MenuPopover>
          </Menu>
          <Menu checkedValues={{ cols: [String(cols)] }} onCheckedValueChange={(_, d) => d.name === 'cols' && change(withCols(layout, d.checkedItems[0] === '2' ? 2 : 3))}>
            <MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<LayoutColumnThreeRegular />}>Layout <ChevronDownRegular /></Button></MenuTrigger>
            <MenuPopover>
              <MenuList>
                <MenuGroup>
                  <MenuGroupHeader>Columns</MenuGroupHeader>
                  <MenuItemRadio name="cols" value="3">Three columns</MenuItemRadio>
                  <MenuItemRadio name="cols" value="2">Two columns</MenuItemRadio>
                </MenuGroup>
                <MenuDivider />
                <MenuItem icon={<ArrowResetRegular />} onClick={() => change(null)}>Back to the standard Business Portal</MenuItem>
              </MenuList>
            </MenuPopover>
          </Menu>
        </div>
      </div>

      <div className={s.grid} style={{ gridTemplateColumns: template }}>
        {Array.from({ length: cols }, (_, c) => (
          <div key={c} className={mergeClasses(s.col, !!dragging && dropAt?.col === c && dropAt.before === null && 'zx-drop')} aria-label={`Column ${c + 1}`}
            onDragOver={(e) => { if (!dragging) return; e.preventDefault(); if (e.target === e.currentTarget) setDropAt({ col: c, before: null }) }}
            onDrop={(e) => { e.preventDefault(); drop(c) }}>
            {column(layout, c).map((p, i) => (
              <section key={p.id} className={mergeClasses(s.panel, 'zx-rise', dragging === p.id && 'zx-dragging', dropAt?.before === p.id && dragging !== p.id && 'zx-drop')}
                style={{ animationDelay: `${(c * 3 + i) * 40}ms` }} aria-labelledby={`panel-${p.id}`}
                onDragOver={(e) => {
                  if (!dragging) return
                  e.preventDefault()
                  e.stopPropagation()
                  const r = e.currentTarget.getBoundingClientRect()
                  const below = e.clientY > r.top + r.height / 2
                  const col = column(layout, c)
                  setDropAt({ col: c, before: below ? (col[i + 1]?.id ?? null) : p.id })
                }}>
                <div className={s.grab} draggable onDragStart={(e) => { setDragging(p.id); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', p.id) }}
                  onDragEnd={() => { setDragging(null); setDropAt(null) }} title="Drag to move this panel" aria-hidden><span className={s.grabBar} /></div>
                <div className={s.panelHead}>
                  <h2 id={`panel-${p.id}`} className={s.panelTitle}>{title(p.id)}</h2>
                  <Menu>
                    <MenuTrigger disableButtonEnhancement><Button appearance="subtle" size="small" icon={<MoreHorizontalRegular />} aria-label={`${title(p.id)}: move or hide`} /></MenuTrigger>
                    <MenuPopover>
                      <MenuList>
                        <MenuItem icon={<ArrowUpRegular />} onClick={() => change(nudge(layout, p.id, 'up'))}>Move up</MenuItem>
                        <MenuItem icon={<ArrowDownRegular />} onClick={() => change(nudge(layout, p.id, 'down'))}>Move down</MenuItem>
                        {c > 0 && <MenuItem icon={<ArrowLeftRegular />} onClick={() => change(nudge(layout, p.id, 'left'))}>Move to the left column</MenuItem>}
                        {c < cols - 1 && <MenuItem icon={<ArrowRightRegular />} onClick={() => change(nudge(layout, p.id, 'right'))}>Move to the right column</MenuItem>}
                        <MenuDivider />
                        <MenuItem icon={<EyeOffRegular />} onClick={() => change({ ...layout, panels: layout.panels.map((x) => (x.id === p.id ? { ...x, hidden: true } : x)) })}>Hide (Personalize brings it back)</MenuItem>
                      </MenuList>
                    </MenuPopover>
                  </Menu>
                </div>
                <div className={s.panelBody}>{body(p.id)}</div>
              </section>
            ))}
          </div>
        ))}
      </div>

      {picking && <TilePicker menu={menu} chosen={tilesFor(menu, layout.tiles)} onSave={(tiles) => { change({ ...layout, tiles }); setPicking(false) }} onCancel={() => setPicking(false)} />}
    </div>
  )
}

/** Personalize → Choose tiles: up to 8 pages of the menu, in the order ticked. */
function TilePicker({ menu, chosen, onSave, onCancel }: { menu: MenuData; chosen: Tile[]; onSave: (t: Tile[]) => void; onCancel: () => void }) {
  const s = useStyles()
  const [picked, setPicked] = useState<Tile[]>(chosen)
  const [q, setQ] = useState('')
  const pages = useMemo(() => flatMenu(menu).filter((p) => `${p.label} ${p.where}`.toLowerCase().includes(q.toLowerCase())), [menu, q])
  const has = (url: string) => picked.some((t) => t.url === url)
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onCancel()}>
      <DialogSurface>
        <DialogBody>
          <DialogTitle>Choose your tiles</DialogTitle>
          <DialogContent>
            <Text>Up to 8 pages you open often. {picked.length} chosen.</Text>
            <SearchBox placeholder="Find a page" value={q} onChange={(_, d) => setQ(d.value)} style={{ width: '100%', maxWidth: 'none', marginTop: 8 }} />
            <div className={s.tilePick}>
              {pages.map((p) => (
                <Checkbox key={p.url} label={`${p.label} · ${p.where}`} checked={has(p.url)} disabled={!has(p.url) && picked.length >= 8}
                  onChange={(_, d) => setPicked(d.checked ? [...picked, { url: p.url, label: p.label }] : picked.filter((t) => t.url !== p.url))} />
              ))}
            </div>
          </DialogContent>
          <DialogActions>
            <Button appearance="secondary" onClick={() => onSave([])}>Use the standard tiles</Button>
            <Button appearance="secondary" onClick={onCancel}>Cancel</Button>
            <Button appearance="primary" onClick={() => onSave(picked)}>Save</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
