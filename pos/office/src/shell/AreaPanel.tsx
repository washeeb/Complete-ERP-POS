import { CounterBadge, makeStyles, mergeClasses, Text, tokens } from '@fluentui/react-components'
import type { MenuTab } from '../api'
import { appPathFor } from '../paths'
import { linkClick } from '../nav'

const useStyles = makeStyles({
  cols: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '6px 20px', padding: '4px' },
  narrow: { gridTemplateColumns: '1fr' },
  section: { display: 'flex', flexDirection: 'column', gap: '2px', minWidth: 0 },
  h: { fontSize: tokens.fontSizeBase300, fontWeight: tokens.fontWeightSemibold, color: tokens.colorBrandForeground1, padding: '6px 8px 2px' },
  g: { fontSize: tokens.fontSizeBase200, fontWeight: tokens.fontWeightSemibold, color: tokens.colorNeutralForeground3, padding: '6px 8px 0', textTransform: 'uppercase', letterSpacing: '.04em' },
  a: {
    display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', padding: '5px 8px', borderRadius: tokens.borderRadiusMedium,
    color: tokens.colorNeutralForeground1, textDecorationLine: 'none', fontSize: tokens.fontSizeBase300,
    ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover, color: tokens.colorNeutralForeground1Hover },
    ':focus-visible': { outline: `2px solid ${tokens.colorStrokeFocus2}` },
  },
})

/** The pages of one menu area, in its sections and headed groups, as columns (a drop-down at the top, a fly-out at the side). */
export function AreaPanel({ tab, onPick, narrow = false }: { tab: MenuTab; onPick: () => void; narrow?: boolean }) {
  const s = useStyles()
  return (
    <nav className={mergeClasses(s.cols, narrow && s.narrow)} aria-label={tab.label}>
      {tab.sections.map((sec) => (
        <div key={sec.label} className={s.section}>
          {tab.sections.length > 1 && <Text className={s.h}>{sec.label}</Text>}
          {sec.groups.map((g, gi) => (
            <div key={`${g.label}-${gi}`} className={s.section}>
              {g.label && <Text className={s.g}>{g.label}</Text>}
              {g.items.map((i) => (
                <a key={i.url} className={s.a} href={appPathFor(i.url)} onClick={(e) => { linkClick(e, i.url); if (!e.ctrlKey && !e.metaKey) onPick() }}>
                  <span>{i.label}</span>
                  {i.badge && <CounterBadge count={Number(i.badge) || 0} overflowCount={99} size="small" appearance="filled" color="danger" />}
                </a>
              ))}
            </div>
          ))}
        </div>
      ))}
    </nav>
  )
}
