import { Link, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { useEffect, useState } from 'react'
import { api, type IntegrationsData } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { appPathFor } from '../paths'
import { ToneBadge, useProcStyles } from './ProcurementParts'

const TONE: Record<string, string> = { ok: 'success', attention: 'warning', off: 'subtle' }

const useStyles = makeStyles({
  grid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 300px), 1fr))', gap: '12px' },
  card: { backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusXLarge, padding: '14px 16px',
    display: 'grid', gap: '8px', alignContent: 'start' },
  head: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '8px' },
  dl: { display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '2px 10px', margin: 0, fontSize: tokens.fontSizeBase200 },
  pills: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
})

/**
 * System → Integrations (part 22; the integrations add-on): every connection to the outside world on one card —
 * whether it is set up, when it last worked, what failed this week — with the way to its set-up.
 */
export function IntegrationsPage() {
  const page = usePageStyles()
  const p = useProcStyles()
  const s = useStyles()
  const [d, setD] = useState<IntegrationsData | null>(null)
  const [error, setError] = useState('')
  useEffect(() => { api.integrations().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])
  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening integrations…" /></div>
  return (
    <div className={page.page}>
      <PageHead title="Integrations" hint="Every connection to the outside world: whether it is set up, when it last worked, and what failed this week." classic="/admin/integrations" />
      <div className={s.pills}>
        <ToneBadge tone="success" label={`${d.counts.ok ?? 0} working`} />
        <ToneBadge tone="warning" label={`${d.counts.attention ?? 0} need attention`} />
        <ToneBadge tone="subtle" label={`${d.counts.off ?? 0} not set up`} />
      </div>
      <div className={s.grid}>
        {d.cards.map((c) => (
          <section key={c.id} className={s.card} aria-label={c.title}>
            <div className={s.head}><Text weight="semibold">{c.title}</Text><ToneBadge tone={TONE[c.state] ?? 'informative'} label={c.state_label} /></div>
            <Text size={200} className={p.muted}>{c.what}</Text>
            <dl className={s.dl}>
              <dt>Now</dt><dd style={{ margin: 0 }}>{c.detail}</dd>
              {!c.always && <><dt>Last worked</dt><dd style={{ margin: 0 }}>{c.last_ok ?? 'Not yet'}</dd>
                <dt>Failed this week</dt><dd style={{ margin: 0, color: c.failed > 0 ? 'var(--zx-bad)' : undefined }}>{c.failed}</dd></>}
            </dl>
            {c.url && <Link onClick={() => navigate(appPathFor(c.url!))}>{c.link} →</Link>}
          </section>
        ))}
      </div>
    </div>
  )
}
