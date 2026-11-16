import { CounterBadge, makeStyles, mergeClasses, Popover, PopoverSurface, PopoverTrigger, Text, tokens } from '@fluentui/react-components'
import { useState, type ReactNode } from 'react'
import { HistoryRegular, StarFilled, StarRegular } from '@fluentui/react-icons'
import type { Me, Menu, Prefs } from '../api'
import { AreaIcon } from '../icons'
import { navigate } from '../nav'
import { isFavourite, type Page } from '../recent'
import { FavouritesMenu, RecentMenu } from './QuickMenus'
import { menuShade, onColor } from '../theme'
import { AreaPanel } from './AreaPanel'
import { CreateMenu } from './CreateMenu'
import { Search } from './Search'
import { UserMenu } from './UserMenu'

const useStyles = makeStyles({
  root: { display: 'flex', height: '100vh', backgroundColor: tokens.colorNeutralBackground2 },
  rail: { flex: '0 0 84px', display: 'flex', flexDirection: 'column', overflowY: 'auto', scrollbarWidth: 'none' },
  item: {
    display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: '4px', minHeight: '64px', width: '100%',
    padding: '8px 4px', border: 'none', background: 'none', color: 'inherit', cursor: 'pointer', font: 'inherit', fontSize: tokens.fontSizeBase200,
    textAlign: 'center', lineHeight: '1.15', ':hover': { backgroundColor: 'rgba(255,255,255,.12)' },
    ':focus-visible': { outline: '2px solid currentColor', outlineOffset: '-4px' },
  },
  create: { minHeight: '72px' },
  badged: { position: 'relative', display: 'inline-flex' },
  counter: { position: 'absolute', top: '-6px', right: '-14px' },
  sep: { display: 'block', height: '1px', margin: '4px 14px', backgroundColor: 'currentColor', opacity: 0.25, flex: '0 0 1px' },
  current: { backgroundColor: 'rgba(255,255,255,.16)', boxShadow: 'inset 3px 0 0 currentColor' },
  icon: { fontSize: '24px', display: 'block' },
  col: { flex: '1 1 auto', display: 'flex', flexDirection: 'column', minWidth: 0 },
  top: {
    display: 'flex', alignItems: 'center', gap: '12px', flex: '0 0 52px', height: '52px', padding: '0 16px', backgroundColor: tokens.colorNeutralBackground1,
    borderBottom: `1px solid ${tokens.colorNeutralStroke2}`,
  },
  name: { flex: '0 1 auto', minWidth: 0, '@media (max-width: 760px)': { display: 'none' } },
  grow: { flex: '1 1 auto', display: 'flex', justifyContent: 'center' },
  panel: { width: '300px', maxHeight: '80vh', overflowY: 'auto', padding: '10px' },
  panelTitle: { display: 'block', padding: '4px 8px 6px', fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold },
  main: { flex: '1 1 auto', display: 'flex', flexDirection: 'column', minHeight: 0 },
})

/**
 * Look 2 (the owner's side-menu template): a slim coloured rail of areas, each an icon with its name below, Create at the
 * top. An area opens its pages in a fly-out beside the rail; the page keeps the whole width.
 */
export function SideLook({ me, menu, current, page, todo, atTodo, onLook, onFavourites, children }: {
  me: Me; menu: Menu; current: string | null; page: Page | null; todo: number; atTodo: boolean
  onLook: (l: Prefs['office_look']) => void; onFavourites: (list: Page[]) => void; children: ReactNode
}) {
  const s = useStyles()
  const atHome = current === null && !page && !atTodo
  const [open, setOpen] = useState<string | null>(null)
  const band = me.company.menu_color ?? menuShade(me.company.color)
  const ink = onColor(band)

  return (
    <div className={s.root}>
      <nav className={s.rail} style={{ backgroundColor: band, color: ink }} aria-label="Menu">
        <CreateMenu menu={menu} trigger={
          <button type="button" className={mergeClasses(s.item, s.create)} style={{ backgroundColor: me.company.color, color: me.company.on_color }}>
            <AreaIcon label="Create" className={s.icon} />Create
          </button>} />
        <button type="button" className={mergeClasses(s.item, atTodo && s.current)} onClick={() => navigate('/office/todo')} aria-current={atTodo ? 'page' : undefined}
          aria-label={todo ? `To-do, ${todo} waiting for you` : 'To-do'}>
          <span className={s.badged}><AreaIcon label="To-do" className={s.icon} />{todo > 0 && <CounterBadge className={s.counter} count={todo} overflowCount={99} size="small" color="danger" />}</span>To-do
        </button>
        <button type="button" className={mergeClasses(s.item, atHome && s.current)} onClick={() => navigate('/office/')} aria-current={atHome ? 'page' : undefined}>
          <AreaIcon label="Home" className={s.icon} />Home
        </button>
        <RecentMenu beside trigger={<button type="button" className={s.item}><HistoryRegular className={s.icon} aria-hidden />Recent</button>} />
        <FavouritesMenu beside favourites={me.favourites} current={page} onChange={onFavourites} trigger={
          <button type="button" className={s.item}>
            {isFavourite(me.favourites, page) ? <StarFilled className={s.icon} aria-hidden /> : <StarRegular className={s.icon} aria-hidden />}Favourites
          </button>} />
        <span className={s.sep} aria-hidden />
        {menu.tabs.map((t) => {
          return (
            <Popover key={t.label} open={open === t.label} onOpenChange={(_, d) => setOpen(d.open ? t.label : null)} positioning="after-top" trapFocus>
              <PopoverTrigger disableButtonEnhancement>
                <button type="button" className={mergeClasses(s.item, current === t.label && s.current)} aria-current={current === t.label ? 'page' : undefined}>
                  <AreaIcon label={t.label} className={s.icon} />{t.label}
                </button>
              </PopoverTrigger>
              <PopoverSurface className={s.panel}>
                <Text className={s.panelTitle}>{t.label}</Text>
                <AreaPanel tab={t} narrow onPick={() => setOpen(null)} />
              </PopoverSurface>
            </Popover>
          )
        })}
      </nav>
      <div className={s.col}>
        <header className={s.top}>
          <Text weight="semibold" size={400} truncate wrap={false} className={s.name}>{me.company.name}</Text>
          <div className={s.grow}><Search menu={menu} /></div>
          <UserMenu me={me} onLook={onLook} />
        </header>
        <main className={s.main}>{children}</main>
      </div>
    </div>
  )
}
