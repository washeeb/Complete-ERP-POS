import { Button, CounterBadge, makeStyles, mergeClasses, Popover, PopoverSurface, PopoverTrigger, Text, tokens, Tooltip } from '@fluentui/react-components'
import { ChevronDownRegular, HistoryRegular, HomeFilled, StarFilled, StarRegular, TaskListSquareLtrRegular } from '@fluentui/react-icons'
import { useState, type ReactNode } from 'react'
import type { Me, Menu, Prefs } from '../api'
import { navigate } from '../nav'
import { isFavourite, type Page } from '../recent'
import { FavouritesMenu, RecentMenu } from './QuickMenus'
import { menuShade, onColor } from '../theme'
import { AreaPanel } from './AreaPanel'
import { CreateMenu } from './CreateMenu'
import { Search } from './Search'
import { Bell } from './Bell'
import { ScopePicker } from './ScopePicker'
import { UserMenu } from './UserMenu'

const useStyles = makeStyles({
  root: { display: 'flex', flexDirection: 'column', height: '100vh', backgroundColor: tokens.colorNeutralBackground2 },
  top: {
    display: 'grid', gridTemplateColumns: 'minmax(120px, 1fr) minmax(200px, 560px) auto', alignItems: 'center', gap: '16px',
    flex: '0 0 52px', height: '52px', padding: '0 16px', backgroundColor: tokens.colorNeutralBackground1, borderBottom: `1px solid ${tokens.colorNeutralStroke2}`,
    '@media (max-width: 760px)': { gridTemplateColumns: 'auto 1fr auto', gap: '8px', padding: '0 8px' },
  },
  brand: { display: 'flex', alignItems: 'center', gap: '10px', minWidth: 0, cursor: 'pointer', background: 'none', border: 'none', padding: 0, color: 'inherit', font: 'inherit' },
  logo: { height: '30px', maxWidth: '140px', objectFit: 'contain' },
  right: { display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '8px' },
  bar: { display: 'flex', alignItems: 'stretch', flex: '0 0 40px', height: '40px', padding: '0 8px', overflowX: 'auto', scrollbarWidth: 'none' },
  tab: {
    height: '40px', borderRadius: 0, color: 'inherit', fontWeight: tokens.fontWeightSemibold, whiteSpace: 'nowrap', minWidth: 'auto',
    ':hover': { color: 'inherit', backgroundColor: 'rgba(255,255,255,.12)' }, ':hover:active': { color: 'inherit', backgroundColor: 'rgba(255,255,255,.18)' },
  },
  current: { boxShadow: 'inset 0 -3px 0 currentColor', backgroundColor: 'rgba(255,255,255,.10)' },
  badged: { position: 'relative', display: 'inline-flex' },
  counter: { position: 'absolute', top: '-8px', right: '-12px' },
  sep: { width: '1px', margin: '10px 6px', backgroundColor: 'currentColor', opacity: 0.3, flex: '0 0 1px' },
  panel: { width: 'min(880px, calc(100vw - 32px))', maxHeight: '70vh', overflowY: 'auto', padding: '12px' },
  main: { flex: '1 1 auto', display: 'flex', flexDirection: 'column', minHeight: 0 },
  hideSmall: { '@media (max-width: 760px)': { display: 'none' } },
})

/**
 * Look 1 (the default, owner 2026-11-16): the company and search on a white band, the menu areas on a coloured band
 * below; each area drops down its pages in columns. Like the desktop business programs people know.
 */
export function TopLook({ me, menu, current, page, todo, atHome, atTodo, onLook, onFavourites, children }: {
  me: Me; menu: Menu; current: string | null; page: Page | null; todo: number; atHome: boolean; atTodo: boolean
  onLook: (l: Prefs['office_look']) => void; onFavourites: (list: Page[]) => void; children: ReactNode
}) {
  const s = useStyles()
  const [open, setOpen] = useState<string | null>(null)
  const band = me.company.menu_color ?? menuShade(me.company.color)
  const ink = onColor(band)

  return (
    <div className={s.root}>
      <header className={s.top}>
        <button type="button" className={s.brand} onClick={() => navigate('/office/')} aria-label={`${me.company.name}: home`}>
          {me.company.logo ? <img className={s.logo} src={me.company.logo} alt="" /> : null}
          <Text weight="semibold" size={400} truncate wrap={false} className={me.company.logo ? s.hideSmall : undefined}>{me.company.name}</Text>
        </button>
        <Search menu={menu} />
        <div className={s.right}>
          <span className={s.hideSmall}><CreateMenu menu={menu} /></span>
          <ScopePicker me={me} />
          <Bell />
          <UserMenu me={me} onLook={onLook} />
        </div>
      </header>
      <nav className={s.bar} style={{ backgroundColor: band, color: ink }} aria-label="Menu">
        <Tooltip content="Business Portal" relationship="label">
          <Button appearance="transparent" className={mergeClasses(s.tab, atHome && s.current)} icon={<HomeFilled />} onClick={() => navigate('/office/')}
            aria-current={atHome ? 'page' : undefined} />
        </Tooltip>
        <Tooltip content={todo ? `To-do: ${todo} waiting for you` : 'To-do'} relationship="label">
          <Button appearance="transparent" className={mergeClasses(s.tab, atTodo && s.current)} aria-current={atTodo ? 'page' : undefined} onClick={() => navigate('/office/todo')}
            icon={<span className={s.badged}><TaskListSquareLtrRegular />{todo > 0 && <CounterBadge className={s.counter} count={todo} overflowCount={99} size="small" color="danger" />}</span>} />
        </Tooltip>
        <RecentMenu trigger={
          <Tooltip content="Recent pages" relationship="label"><Button appearance="transparent" className={s.tab} icon={<HistoryRegular />} /></Tooltip>} />
        <FavouritesMenu favourites={me.favourites} current={page} onChange={onFavourites} trigger={
          <Tooltip content="Favourites" relationship="label">
            <Button appearance="transparent" className={s.tab} icon={isFavourite(me.favourites, page) ? <StarFilled /> : <StarRegular />} />
          </Tooltip>} />
        <span className={s.sep} aria-hidden />
        {menu.tabs.map((t) => (
          <Popover key={t.label} open={open === t.label} onOpenChange={(_, d) => setOpen(d.open ? t.label : null)} positioning="below-start" trapFocus>
            <PopoverTrigger disableButtonEnhancement>
              <Button appearance="transparent" className={mergeClasses(s.tab, current === t.label && s.current)} icon={<ChevronDownRegular />} iconPosition="after"
                aria-current={current === t.label ? 'page' : undefined}>{t.label}</Button>
            </PopoverTrigger>
            <PopoverSurface className={s.panel}>
              <AreaPanel tab={t} onPick={() => setOpen(null)} />
            </PopoverSurface>
          </Popover>
        ))}
      </nav>
      <main className={s.main}>{children}</main>
    </div>
  )
}
