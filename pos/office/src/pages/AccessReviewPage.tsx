import { Badge, Button, Link, makeStyles, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { ArrowDownloadRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type AccessReviewData } from '../api'
import { download } from '../list/ExportMenu'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginBottom: '12px' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '12px 16px', marginBottom: '12px', overflowX: 'auto' },
  risk: { borderLeft: `4px solid ${tokens.colorPaletteYellowBorderActive}`, padding: '6px 12px', marginTop: '10px' },
  high: { borderLeftColor: tokens.colorPaletteRedBorderActive },
  muted: { color: tokens.colorNeutralForeground3 },
  bad: { color: tokens.colorPaletteRedForeground1 },
  warn: { color: tokens.colorPaletteDarkOrangeForeground1 },
  list: { margin: '4px 0 0 18px' },
})

/**
 * Who can do what (settings and admin): risky combinations first (one person ordering and receiving, adding suppliers
 * and paying them…) with what to do about each, then notes worth a look, then everyone's roles and where they apply.
 * Read only; access is changed on a user's page. Download (CSV) for the auditor.
 */
export function AccessReviewPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [r, setR] = useState<AccessReviewData | null>(null)
  const [error, setError] = useState('')
  useEffect(() => { api.accessReview().then(setR).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])
  if (!r) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Checking everyone's access…" />}</div>
  const person = (id: string, name: string) => (r.can_open ? <Link onClick={() => navigate(`/office/users/${id}`)}>{name}</Link> : <>{name}</>)
  const csv = async () => { const problem = await download('/office-api/who-can-do-what/csv', 'who-can-do-what.csv'); if (problem) setError(problem) }

  return (
    <div className={page.page}>
      <PageHead title="Who can do what" hint="Risky combinations of rights, and everyone's access. To change someone's access, open them under Users." classic="/admin/access-review" />
      <div className={s.bar}><Button icon={<ArrowDownloadRegular />} onClick={() => void csv()}>Download (CSV)</Button></div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <Cards items={[{ label: 'People with access', value: String(r.counts.people) }, { label: 'High-risk combinations', value: String(r.counts.high), tone: r.counts.high ? 'var(--zx-bad)' : undefined },
        { label: 'To keep an eye on', value: String(r.counts.medium) }]} />
      <section className={s.panel} aria-label="Risky combinations">
        <Text weight="semibold" size={400} block>Risky combinations</Text>
        <Text size={200} className={s.muted} block>One person holding both halves can take something and hide it. In a small team some are unavoidable: then check that person's work in the audit log now and then.</Text>
        {r.risks.length === 0 ? <Text block style={{ marginTop: 8 }}>No risky combinations: each of these jobs is split between different people.</Text> : r.risks.map((k) => (
          <div key={k.title} className={`${s.risk} ${k.severity === 'high' ? s.high : ''}`}>
            <Badge appearance={k.severity === 'high' ? 'filled' : 'tint'} color={k.severity === 'high' ? 'danger' : 'warning'}>{k.severity === 'high' ? 'High' : 'Watch'}</Badge> <Text weight="semibold">{k.title}</Text>
            <Text block>{k.why} <b>What to do:</b> {k.fix}</Text>
            <ul className={s.list}>{k.who.map((w) => <li key={w.id}>{person(w.id, w.name)} <Text className={s.muted}>— {w.via}; {w.where}</Text></li>)}</ul>
          </div>
        ))}
      </section>
      {r.notes.length > 0 && <section className={s.panel} aria-label="Worth a look">
        <Text weight="semibold" size={400} block>Worth a look</Text>
        <ul className={s.list}>{r.notes.map((n, i) => <li key={i}>{n.text} {r.can_open && <Link onClick={() => navigate(`/office/users/${n.id}`)}>Open</Link>}</li>)}</ul>
      </section>}
      <section className={s.panel} aria-label="Everyone's access">
        <Text weight="semibold" size={400} block>Everyone's access</Text>
        <Text size={200} className={s.muted} block>Each role and where it applies (a branch, a store, a till or the whole company).</Text>
        <Table size="small" aria-label="Everyone's access" style={{ minWidth: 720 }}>
          <TableHeader><TableRow><TableHeaderCell>Person</TableHeaderCell><TableHeaderCell>Roles and where</TableHeaderCell><TableHeaderCell>Risky combinations</TableHeaderCell></TableRow></TableHeader>
          <TableBody>{r.people.map((p) => (
            <TableRow key={p.id}>
              <TableCell>{person(p.id, p.name)}<Text block size={200} className={s.muted}>{p.username}{p.active ? '' : ' · switched off'}</Text></TableCell>
              <TableCell>{p.roles.map((x, i) => <div key={i}>{x.role} <Text className={s.muted}>({x.where}{x.until ? `, until ${x.until}` : ''})</Text></div>)}</TableCell>
              <TableCell>{p.owner ? <Text className={s.muted}>Owner: everything, by design</Text> : p.risks.length === 0 ? <Text className={s.muted}>None</Text>
                : p.risks.map((k) => <div key={k.title} className={k.severity === 'high' ? s.bad : s.warn}>{k.title}</div>)}</TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </section>
    </div>
  )
}
