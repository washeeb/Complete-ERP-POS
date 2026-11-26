import { Link, makeStyles, MessageBar, MessageBarBody, Spinner, Tab, TabList, Text, tokens } from '@fluentui/react-components'
import { ChevronRightRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type TodayData } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { str, useQueryParams } from '../list/query'
import { navigate } from '../nav'

const useStyles = makeStyles({
  wrap: { display: 'grid', gap: '12px', maxWidth: '640px', margin: '0 auto', width: '100%' },
  tabs: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' },
  date: { marginLeft: 'auto', color: tokens.colorNeutralForeground3 },
  card: { borderRadius: tokens.borderRadiusXLarge, padding: '16px', border: `1px solid ${tokens.colorNeutralStroke2}`, backgroundColor: tokens.colorNeutralBackground1,
    boxShadow: tokens.shadow4, display: 'grid', gap: '4px' },
  label: { fontSize: tokens.fontSizeBase200, textTransform: 'uppercase', letterSpacing: '0.04em', color: tokens.colorNeutralForeground3 },
  big: { fontSize: '40px', fontWeight: tokens.fontWeightBold, lineHeight: '1.1' },
  mid: { fontSize: tokens.fontSizeBase500, fontWeight: tokens.fontWeightSemibold },
  row: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: '12px' },
  up: { color: tokens.colorPaletteGreenForeground1 },
  down: { color: tokens.colorPaletteRedForeground1 },
  warn: { color: tokens.colorPaletteMarigoldForeground1 },
  h2: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 6px' },
  alert: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '8px', padding: '10px 12px', borderRadius: tokens.borderRadiusLarge, marginTop: '6px' },
  danger: { backgroundColor: tokens.colorPaletteRedBackground2 },
  warning: { backgroundColor: tokens.colorPaletteYellowBackground2 },
  branch: { padding: '10px 0', borderTop: `1px solid ${tokens.colorNeutralStroke2}`, display: 'grid', gap: '2px', ':first-of-type': { borderTop: 'none' } },
  branchHead: { display: 'flex', justifyContent: 'space-between', gap: '8px', fontSize: tokens.fontSizeBase400 },
  foot: { fontSize: tokens.fontSizeBase200, color: tokens.colorNeutralForeground3, textAlign: 'center' },
})

/**
 * Insights → Today (part 37), as the current page: the owner's phone view of the business today — sales against last
 * week, how it was paid, till closes and cash, what needs attention (each linked to where it is dealt with) and each
 * branch — refreshing itself every minute. Yesterday is one tap away. "Add to Home screen" opens it like an app.
 */
export function TodayPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const yesterday = str(params, 'yesterday') === '1'
  const [d, setD] = useState<TodayData | null>(null)
  const [error, setError] = useState('')
  const load = useCallback(() => {
    api.today(yesterday).then((x) => { setD(x); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [yesterday])
  useEffect(() => {
    load()
    const t = setInterval(load, 60000)
    return () => clearInterval(t)
  }, [load])
  const shown = d && d.yesterday === yesterday ? d : null
  return (
    <div className={page.page}>
      <div className={s.wrap}>
        <PageHead title="Today" hint="The business today on one screen. It refreshes itself every minute." />
        <div className={s.tabs}>
          <TabList selectedValue={yesterday ? 'yesterday' : 'today'} onTabSelect={(_, x) => set({ yesterday: x.value === 'yesterday' ? '1' : '' })} aria-label="Which day">
            <Tab value="today">Today</Tab>
            <Tab value="yesterday">Yesterday</Tab>
          </TabList>
          {shown && <Text className={s.date}>{shown.date}</Text>}
        </div>
        {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
        {!shown ? <Spinner label="Adding up the day…" /> : (
          <>
            <section className={s.card} aria-label="Sales">
              <span className={s.label}>Sales</span>
              <span className={s.big}>{shown.sales}</span>
              <Text>{shown.count} sale(s)
                {shown.vs_last_week !== null && <> · <b className={shown.vs_last_week >= 0 ? s.up : s.down}>{shown.vs_last_week >= 0 ? '+' : ''}{shown.vs_last_week} %</b> on last week ({shown.last_week})</>}
              </Text>
            </section>
            <section className={s.row} aria-label="Payments">
              {shown.payments.map((p) => <div key={p.label} className={s.card}><span className={s.label}>{p.label}</span><span className={s.mid}>{p.value}</span></div>)}
            </section>
            <section className={s.row} aria-label="Till closes and cash">
              <div className={s.card}><span className={s.label}>Till closes</span><span className={s.mid}>{shown.closes}</span></div>
              <div className={s.card}><span className={s.label}>Cash</span><span className={`${s.mid} ${shown.over_short < 0 ? s.down : ''}`}>{shown.over_short_text}</span></div>
            </section>
            {shown.attention.length > 0 && (
              <section className={s.card} aria-label="Needs attention">
                <h2 className={s.h2}>Needs attention</h2>
                {shown.attention.map((a, i) => (
                  <div key={i} className={`${s.alert} ${a.tone === 'danger' ? s.danger : s.warning}`}>
                    {a.open ? <Link onClick={() => navigate(a.open!)}>{a.text}</Link> : <Text>{a.text}</Text>}
                    {a.open && <ChevronRightRegular aria-hidden />}
                  </div>
                ))}
              </section>
            )}
            <section className={s.card} aria-label="Branches">
              <h2 className={s.h2}>Branches</h2>
              {shown.branches.map((b) => (
                <div key={b.name} className={s.branch}>
                  <div className={s.branchHead}><strong>{b.name}</strong><span>{b.sales}</span></div>
                  <Text size={300}>{b.count} sale(s){b.open.length > 0 && <> · <span className={s.warn}>open: {b.open.join(', ')}</span></>}</Text>
                  <Text size={300}>{b.closed ? `✓ ${b.closed}` : <span className={s.warn}>Day not closed yet</span>}</Text>
                  {b.deposit && <Text size={300}>{b.deposit}</Text>}
                </div>
              ))}
            </section>
            <p className={s.foot}>Updates every minute. On your phone: browser menu → <b>Add to Home screen</b> to open it like an app.</p>
          </>
        )}
      </div>
    </div>
  )
}
