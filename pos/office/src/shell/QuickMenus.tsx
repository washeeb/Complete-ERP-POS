import { Button, makeStyles, Popover, PopoverSurface, PopoverTrigger, Text, tokens, Tooltip } from '@fluentui/react-components'
import { StarAddRegular, StarDismissRegular, StarOffRegular } from '@fluentui/react-icons'
import { useState, type ReactElement } from 'react'
import { api } from '../api'
import { linkClick } from '../nav'
import { appPathFor } from '../paths'
import { recentPages, type Page } from '../recent'

const useStyles = makeStyles({
  surface: { width: '320px', maxHeight: '70vh', overflowY: 'auto', padding: '10px' },
  title: { display: 'block', padding: '2px 8px 8px', fontWeight: tokens.fontWeightSemibold, fontSize: tokens.fontSizeBase400 },
  row: { display: 'flex', alignItems: 'center', gap: '4px', borderRadius: tokens.borderRadiusMedium, ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover } },
  link: {
    flex: '1 1 auto', minWidth: 0, display: 'block', padding: '7px 8px', color: tokens.colorNeutralForeground1, textDecorationLine: 'none',
    overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', ':focus-visible': { outline: `2px solid ${tokens.colorStrokeFocus2}` },
  },
  empty: { display: 'block', padding: '4px 8px 8px', color: tokens.colorNeutralForeground3 },
  foot: { borderTop: `1px solid ${tokens.colorNeutralStroke2}`, marginTop: '6px', paddingTop: '6px' },
  error: { display: 'block', padding: '4px 8px', color: tokens.colorPaletteRedForeground1 },
})

/** "Recent" (the clock): the pages opened lately in this browser, newest first. */
export function RecentMenu({ trigger, beside = false }: { trigger: ReactElement; beside?: boolean }) {
  const s = useStyles()
  const [open, setOpen] = useState(false)
  const [pages, setPages] = useState<Page[]>([])
  return (
    <Popover open={open} onOpenChange={(_, d) => { setOpen(d.open); if (d.open) setPages(recentPages()) }} positioning={beside ? 'after-top' : 'below-start'} trapFocus>
      <PopoverTrigger disableButtonEnhancement>{trigger}</PopoverTrigger>
      <PopoverSurface className={s.surface} aria-label="Recent pages">
        <Text className={s.title}>Recent</Text>
        {pages.length === 0 && <Text className={s.empty}>Pages you open appear here.</Text>}
        {pages.map((p) => (
          <div key={p.url} className={s.row}>
            <a className={s.link} href={appPathFor(p.url)} onClick={(e) => { linkClick(e, p.url); setOpen(false) }}>{p.label}</a>
          </div>
        ))}
      </PopoverSurface>
    </Popover>
  )
}

/**
 * "Favourites" (the star): the person's favourite pages (the same list as the classic back office's star, on every
 * computer), with a button to add the page on screen, or take it off.
 */
export function FavouritesMenu({ trigger, favourites, current, onChange, beside = false }: {
  trigger: ReactElement; favourites: Page[]; current: Page | null; onChange: (list: Page[]) => void; beside?: boolean
}) {
  const s = useStyles()
  const [open, setOpen] = useState(false)
  const [error, setError] = useState('')
  const here = current ? favourites.find((f) => f.url.split('?')[0] === current.url.split('?')[0]) : undefined
  const toggle = (p: Page) => {
    setError('')
    api.toggleFavourite(p.url, p.label).then((r) => onChange(r.favourites)).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }
  return (
    <Popover open={open} onOpenChange={(_, d) => setOpen(d.open)} positioning={beside ? 'after-top' : 'below-start'} trapFocus>
      <PopoverTrigger disableButtonEnhancement>{trigger}</PopoverTrigger>
      <PopoverSurface className={s.surface} aria-label="Favourite pages">
        <Text className={s.title}>Favourites</Text>
        {favourites.length === 0 && <Text className={s.empty}>No favourites yet. Open a page, then add it here.</Text>}
        {favourites.map((f) => (
          <div key={f.url} className={s.row}>
            <a className={s.link} href={appPathFor(f.url)} onClick={(e) => { linkClick(e, f.url); setOpen(false) }}>{f.label}</a>
            <Tooltip content={`Take “${f.label}” off`} relationship="label">
              <Button appearance="subtle" size="small" icon={<StarDismissRegular />} onClick={() => toggle(f)} />
            </Tooltip>
          </div>
        ))}
        {error && <Text className={s.error}>{error}</Text>}
        {current && (
          <div className={s.foot}>
            <Button appearance="subtle" icon={here ? <StarOffRegular /> : <StarAddRegular />} onClick={() => toggle(here ?? current)}>
              {here ? `Take “${current.label}” off` : `Add “${current.label}”`}
            </Button>
          </div>
        )}
      </PopoverSurface>
    </Popover>
  )
}
