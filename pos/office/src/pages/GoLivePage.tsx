import { Button, Checkbox, Field, Link, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { ArrowSyncRegular, ArrowUndoRegular, CalculatorRegular, DatabaseRegular, EditRegular, EyeRegular, HatGraduationRegular, RocketRegular, SendRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type GoLiveData, type GoLiveStep } from '../api'
import { Ask } from '../form/Ask'
import { Choose, ChooseMany, LongText, TextField, Toggle } from '../form/Fields'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { appPathFor } from '../paths'
import { END, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Dialog = 'plan' | 'old' | 'day' | 'practice' | 'archive' | 'snapshot' | 'parallel' | 'golive' | 'rollback' | { step: GoLiveStep; do: 'done' | 'skip' | 'undo' } | null
const TONE: Record<string, string> = { live: 'success', parallel: 'informative', rolled_back: 'danger', planning: 'warning' }
const ABOUT: Record<string, string> = {
  parallel: 'Both systems run side by side for one to two weeks; the daily totals are compared before the old one is switched off.',
  big_bang: 'Load the data on a quiet day and use only the new system the next morning. The go-live backup is the way back.',
  one_till: 'Start with one till or branch; the rest join after a week of clean days.',
  one_shop: 'One real shop goes first, with support on call; a report is e-mailed every evening.',
  several: 'Two or three shops with different set-ups start together; same support tools.',
  dry_run: 'A full simulated week in the practice company before any real shop.',
}

/**
 * System → Go-live (part 24), as the current page: the plan (where the data comes from, how you switch over, the pilot),
 * the checklist (found done in the data, or ticked by hand), the figures against the old system, the parallel run's
 * days, the practice company, the evening pilot report, and the go-live backup with Go live and Go back.
 */
export function GoLivePage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<GoLiveData | null>(null)
  const [error, setError] = useState('')
  const [note, setNote] = useState<{ good: boolean; text: string } | null>(null)
  const [dialog, setDialog] = useState<Dialog>(null)
  const [v, setV] = useState<Record<string, unknown>>({})
  const [busy, setBusy] = useState(false)
  useEffect(() => { api.goLive().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the go-live plan…" /></div>
  const m = (x: number | null) => (x === null ? '—' : money(x, d.currency, d.exponent))
  const num = (x: number | null, isMoney: boolean) => (x === null ? '—' : isMoney ? m(x) : x.toLocaleString('en-GB'))
  const t = (k: string) => String(v[k] ?? '')
  const put = (k: string) => (x: unknown) => setV((o) => ({ ...o, [k]: x }))
  const act = (action: string, body: Record<string, unknown> = {}) => api.goLiveAction(action, body).then((x) => {
    setD(x.golive)
    setDialog(null)
    if (x.message) setNote({ good: !/not sent|to look at/i.test(x.message), text: x.message })
  })
  const now = (action: string) => {
    setBusy(true)
    setNote(null)
    act(action).catch((e: unknown) => setNote({ good: false, text: e instanceof Error ? e.message : String(e) })).finally(() => setBusy(false))
  }
  const open = (x: Dialog) => {
    setNote(null)
    setV(x === 'plan' ? { ...d.plan } : x === 'old' ? Object.fromEntries(d.old_totals.map((o) => [o.key, o.value])) : x === 'day' ? { branch_id: d.choices.branches[0]?.id ?? '', day: new Date().toISOString().slice(0, 10) }
      : x === 'practice' ? { users: [], copy_catalogue: true } : {})
    setDialog(x)
  }
  return (
    <div className={page.page}>
      <PageHead title="Going live" hint="The plan, the checklist, the figures against the old system, and the decision to go live — or go back." />
      <div className={s.bar}><span className={s.grow} /><Button appearance="primary" icon={<EditRegular />} onClick={() => open('plan')}>Plan</Button></div>
      {note && <MessageBar intent={note.good ? 'success' : 'warning'} style={{ marginBottom: 12 }}><MessageBarBody>{note.text}</MessageBarBody></MessageBar>}
      <Panel title="Status">
        <div className={s.facts}>
          <Fact label="Status"><ToneBadge tone={TONE[d.status] ?? 'informative'} label={d.status_label} />{d.live_since && <Text size={200} className={s.muted}> since {d.live_since}</Text>}</Fact>
          <Fact label="Data from">{d.summary.sources}</Fact>
          <Fact label="Switch-over">{d.summary.cutover}</Fact>
          <Fact label="Pilot">{d.summary.pilot}</Fact>
          <Fact label="Go-live date">{d.summary.date}</Fact>
          <Fact label="Progress">{d.summary.progress}</Fact>
        </div>
        {!d.exists && <MessageBar intent="info" style={{ marginTop: 10 }}><MessageBarBody>Start with <b>Plan</b>: choose where the data comes from, how you switch over and how the pilot runs. The recommended choices are already selected.</MessageBarBody></MessageBar>}
        {d.status === 'rolled_back' && <Text block style={{ color: 'var(--zx-bad)', marginTop: 10 }}>Rolled back: {d.rollback_reason}</Text>}
      </Panel>

      <Panel title="1. Checklist" about="In this order. Green steps were found done in the data; others are ticked by hand. Steps marked optional can be left.">
        <Table size="small" aria-label="Checklist">
          <TableBody>{d.steps.map((st, i) => (
            <TableRow key={st.key} style={st.done ? { opacity: 0.8 } : undefined}>
              <TableCell style={{ width: 36, color: st.done ? 'var(--zx-good)' : undefined, fontWeight: 600 }}>{st.done ? (st.skipped ? '–' : '✓') : i + 1}</TableCell>
              <TableCell>
                <Text weight="semibold">{st.title}</Text>{st.optional && <Text className={s.muted}> · optional</Text>}
                <Text size={200} block className={s.muted}>{st.why}{st.by ? ` — ${st.skipped ? 'not needed' : 'ticked'} by ${st.by}${st.note ? `: ${st.note}` : ''}` : ''}</Text>
              </TableCell>
              <TableCell style={{ whiteSpace: 'nowrap' }}><div className={s.actions}>
                {st.link && <Button size="small" appearance="subtle" onClick={() => navigate(appPathFor(st.link!))}>Open</Button>}
                {st.manual && (st.by
                  ? <Button size="small" appearance="subtle" onClick={() => { setV({}); setDialog({ step: st, do: 'undo' }) }}>Undo</Button>
                  : <>
                    <Button size="small" appearance="subtle" onClick={() => { setV({ note: '' }); setDialog({ step: st, do: 'done' }) }}>Done</Button>
                    {st.optional && <Button size="small" appearance="subtle" onClick={() => { setV({ note: '' }); setDialog({ step: st, do: 'skip' }) }}>Not needed</Button>}
                  </>)}
              </div></TableCell>
            </TableRow>
          ))}</TableBody>
        </Table>
      </Panel>

      <Panel title="2. Check the figures" about="What was loaded, next to the old system's figures on the go-live date. The accounts must also tie: every sub-ledger equals its control account, and “Opening balances (to clear)” comes to zero."
        actions={<div className={s.actions}>
          <Button icon={<CalculatorRegular />} onClick={() => open('old')}>Enter the old system's figures</Button>
          <Button icon={<ArrowSyncRegular />} disabled={busy} onClick={() => now('check')}>{busy ? 'Checking…' : 'Post and check again'}</Button>
        </div>}>
        <Table size="small" aria-label="Figures" style={{ minWidth: 560 }}>
          <TableHeader><TableRow><TableHeaderCell>Figure</TableHeaderCell><TableHeaderCell button={END}>This system</TableHeaderCell><TableHeaderCell button={END}>Old system</TableHeaderCell>
            <TableHeaderCell button={END}>Difference</TableHeaderCell></TableRow></TableHeader>
          <TableBody>
            {d.figures.rows.map((r) => (
              <TableRow key={r.label}><TableCell>{r.label}</TableCell><TableCell className={s.end}>{num(r.ours, r.money)}</TableCell><TableCell className={s.end}>{num(r.old, r.money)}</TableCell>
                <TableCell className={s.end} style={{ color: r.difference ? 'var(--zx-bad)' : 'var(--zx-good)' }}>{r.difference === null ? '' : r.difference === 0 ? '✓' : num(r.difference, r.money)}</TableCell></TableRow>
            ))}
            <TableRow><TableCell>Opening balances (to clear)</TableCell><TableCell className={s.end}>{m(d.figures.opening_left)}</TableCell><TableCell />
              <TableCell className={s.end} style={{ color: d.figures.opening_left ? 'var(--zx-bad)' : 'var(--zx-good)' }}>{d.figures.opening_left ? 'should be 0' : '✓'}</TableCell></TableRow>
          </TableBody>
        </Table>
        {d.figures.problems.length ? <ul style={{ margin: '8px 0 0 18px' }}>{d.figures.problems.map((p, i) => <li key={i}>{p}</li>)}</ul>
          : <Text block style={{ color: 'var(--zx-good)', marginTop: 8 }}>Everything matches.</Text>}
      </Panel>

      {d.parallel && (
        <Panel title="3. Parallel run" about="Each evening, the old system's totals for each branch. A difference above 1.00 needs an explanation (a sale rung up in only one system, a price that differs…)."
          actions={<Button onClick={() => open('day')}>Enter a day of the old system</Button>}>
          <Table size="small" aria-label="Parallel run" style={{ minWidth: 640 }}>
            <TableHeader><TableRow><TableHeaderCell>Day</TableHeaderCell><TableHeaderCell>Branch</TableHeaderCell><TableHeaderCell button={END}>Old system</TableHeaderCell>
              <TableHeaderCell button={END}>This system</TableHeaderCell><TableHeaderCell button={END}>Difference</TableHeaderCell><TableHeaderCell button={END}>Cash old / new</TableHeaderCell><TableHeaderCell>Note</TableHeaderCell></TableRow></TableHeader>
            <TableBody>
              {d.parallel.length === 0 && <TableRow><TableCell colSpan={7}><Text className={s.muted}>No day entered yet.</Text></TableCell></TableRow>}
              {d.parallel.map((x) => (
                <TableRow key={x.id}><TableCell>{x.day}</TableCell><TableCell>{x.branch}</TableCell><TableCell className={s.end}>{m(x.old)}</TableCell><TableCell className={s.end}>{m(x.new)}</TableCell>
                  <TableCell className={s.end} style={{ color: x.bad ? 'var(--zx-bad)' : 'var(--zx-good)' }}>{x.difference === 0 ? '✓' : m(x.difference)}</TableCell>
                  <TableCell className={s.end}>{m(x.old_cash)} / {m(x.new_cash)}</TableCell><TableCell>{x.note ?? ''}</TableCell></TableRow>
              ))}
            </TableBody>
          </Table>
        </Panel>
      )}

      <Panel title="Practice company" about="Staff practise on a copy with made-up customers. Nothing done there is real: no GRA reporting, no SMS or e-mail, no online-store updates, and its books never mix with yours.">
        {d.practice
          ? <><Text block><b>{d.practice.name}</b> — staff sign in to the back office with their usual password at the e-mail address with “+practice” (e.g. kofi+practice@yourshop.com); at a till enrolled to the practice company they use their usual username and PIN.</Text>
            <Link onClick={() => open('archive')} style={{ color: 'var(--zx-bad)' }}>Archive the practice company</Link></>
          : <Button icon={<HatGraduationRegular />} onClick={() => open('practice')}>Create the practice company</Button>}
        {d.practice_result && (
          <div style={{ marginTop: 10 }}>
            <Text block><b>{d.practice_result.name}</b>: {d.practice_result.copied.products} products, {d.practice_result.copied.prices} prices, {d.practice_result.copied.stock} stock lines,
              {' '}{d.practice_result.copied.customers} made-up customers.</Text>
            <Table size="small" aria-label="Practice logins"><TableHeader><TableRow><TableHeaderCell>Name</TableHeaderCell><TableHeaderCell>Back-office sign-in</TableHeaderCell><TableHeaderCell>Till username</TableHeaderCell></TableRow></TableHeader>
              <TableBody>{d.practice_result.logins.map((l) => <TableRow key={l.email}><TableCell>{l.name}</TableCell><TableCell>{l.email}</TableCell><TableCell>{l.username}</TableCell></TableRow>)}</TableBody></Table>
            {d.practice_result.problems.length > 0 && <details><summary>{d.practice_result.problems.length} item(s) were not copied</summary><ul>{d.practice_result.problems.map((p, i) => <li key={i}>{p}</li>)}</ul></details>}
          </div>
        )}
      </Panel>

      <Panel title="Pilot report" about="E-mailed to the support address every evening at the plan's time during the parallel run and the first 60 days live: sales per pilot branch, cash over/short, tills that were offline, the parallel-run difference, problems staff reported, and health warnings."
        actions={<div className={s.actions}>
          <Button icon={<EyeRegular />} disabled={busy} onClick={() => now('report')}>Show today's report</Button>
          <Button icon={<SendRegular />} disabled={busy} onClick={() => now('send_report')}>Send it now</Button>
        </div>}>
        {d.report ? <>
          <Text weight="semibold" block>{d.report.title}</Text>
          <ul style={{ margin: '4px 0 0 18px' }}>{d.report.lines.map((l, i) => <li key={i}>{l}</li>)}</ul>
          {d.report.warnings.length ? <><Text block style={{ color: 'var(--zx-bad)', marginTop: 6 }}>Needs attention:</Text><ul style={{ margin: '4px 0 0 18px' }}>{d.report.warnings.map((w, i) => <li key={i}>{w}</li>)}</ul></>
            : <Text block style={{ color: 'var(--zx-good)', marginTop: 6 }}>Nothing needs attention.</Text>}
        </> : <Text className={s.muted}>Press “Show today's report” to see it here.</Text>}
      </Panel>

      <Panel title="Go live — or go back" about="The go-live backup is the point you can return to. The rollback plan (installation guide, chapter 17.8) says who does what if you have to go back.">
        <Text block style={{ marginBottom: 10 }}>Go-live backup: {d.snapshot ? <><b>{d.snapshot.file}</b> ({d.snapshot.ago}, {d.snapshot.status})</> : <Text className={s.muted}>not taken yet</Text>}</Text>
        <div className={s.actions}>
          {d.actions.snapshot && <Button icon={<DatabaseRegular />} onClick={() => open('snapshot')}>Take the go-live backup</Button>}
          {d.actions.start_parallel && <Button onClick={() => open('parallel')}>Start the parallel run</Button>}
          {d.actions.go_live && <Button appearance="primary" icon={<RocketRegular />} onClick={() => open('golive')}>Go live</Button>}
          {d.actions.roll_back && <Button icon={<ArrowUndoRegular />} onClick={() => open('rollback')}>Go back to the old system</Button>}
        </div>
      </Panel>

      {dialog === 'plan' && (
        <Ask title="The go-live plan" go="Save" width={680} ready={((v.sources as string[]) ?? []).length > 0} onClose={() => setDialog(null)}
          run={() => act('plan', { ...v, go_live_on: t('go_live_on') || null, parallel_until: v.cutover_mode === 'parallel' ? t('parallel_until') || null : null,
            support_email: t('support_email') || null, pilot_report_time: t('pilot_report_time') || null })}>
          <div className={s.one}>
            <Field label="Where does the data come from?" required hint="Tick every source you will import from. “Starting fresh” = nothing is brought over.">
              {d.choices.sources.map((o) => <Checkbox key={o.id} label={o.label} checked={((v.sources as string[]) ?? []).includes(o.id)}
                onChange={(_, e) => put('sources')(e.checked ? [...((v.sources as string[]) ?? []), o.id] : ((v.sources as string[]) ?? []).filter((x) => x !== o.id))} />)}
            </Field>
            <Field label="How do you switch over?" required>
              <RadioGroup value={t('cutover_mode')} onChange={(_, e) => put('cutover_mode')(e.value)}>{d.choices.cutover.map((o) => <Radio key={o.id} value={o.id} label={<>{o.label}{ABOUT[o.id] && <Text size={200} block className={s.muted}>{ABOUT[o.id]}</Text>}</>} />)}</RadioGroup>
            </Field>
            <Field label="How is the pilot run?" required>
              <RadioGroup value={t('pilot_mode')} onChange={(_, e) => put('pilot_mode')(e.value)}>{d.choices.pilot.map((o) => <Radio key={o.id} value={o.id} label={<>{o.label}{ABOUT[o.id] && <Text size={200} block className={s.muted}>{ABOUT[o.id]}</Text>}</>} />)}</RadioGroup>
            </Field>
            <TextField label="Go-live date (opening balances are “as of” this date)" type="date" value={t('go_live_on')} onChange={put('go_live_on')} />
            {v.cutover_mode === 'parallel' && <TextField label="Parallel run until" type="date" value={t('parallel_until')} onChange={put('parallel_until')} />}
            <ChooseMany label="Pilot branch(es)" hint="Empty = all branches." options={d.choices.branches} value={(v.pilot_branch_ids as string[]) ?? []} onChange={put('pilot_branch_ids')} />
            <TextField label="Support e-mail (problem reports and the evening pilot report)" maxLength={255} value={t('support_email')} onChange={put('support_email')} />
            <TextField label="Pilot report time (HH:MM)" maxLength={5} value={t('pilot_report_time')} onChange={put('pilot_report_time')} />
          </div>
        </Ask>
      )}
      {dialog && typeof dialog === 'object' && 'step' in dialog && (
        <Ask title={`${dialog.step.title}: ${{ done: 'done', skip: 'not needed', undo: 'undo' }[dialog.do]}`} go={{ done: 'Done', skip: 'Not needed', undo: 'Undo' }[dialog.do]} ready onClose={() => setDialog(null)}
          run={() => act('step', { key: dialog.step.key, do: dialog.do, note: t('note') || null })}>
          {dialog.do === 'undo' ? <Text>The step goes back to not done.</Text> : <TextField label="Note (optional)" maxLength={255} value={t('note')} onChange={put('note')} />}
        </Ask>
      )}
      {dialog === 'old' && (
        <Ask title="Enter the old system's figures" go="Save" width={560} ready onClose={() => setDialog(null)}
          run={() => act('old_totals', { figures: Object.fromEntries(d.old_totals.map((o) => [o.key, t(o.key) === '' ? null : t(o.key)])) })}>
          <div className={s.one}>
            <Text>From the old system on the go-live date (its stock valuation, aged debtors and creditors reports). Leave a box empty to skip that check.</Text>
            {d.old_totals.map((o) => <TextField key={o.key} label={o.label} type="number" step="any" value={t(o.key)} onChange={put(o.key)} />)}
          </div>
        </Ask>
      )}
      {dialog === 'day' && (
        <Ask title="Enter a day of the old system" go="Record the day" width={560} ready={!!t('branch_id') && !!t('day') && t('old_sales') !== ''} onClose={() => setDialog(null)}
          run={() => act('parallel_day', { branch_id: t('branch_id'), day: t('day'), old_sales: t('old_sales'), old_cash: t('old_cash') || null, old_receipts: t('old_receipts') || null, note: t('note') || null })}>
          <div className={s.one}>
            <Text>The old system's end-of-day totals for one branch. This system's totals for the same day are filled in for you.</Text>
            <Choose label="Branch" required options={d.choices.branches} value={t('branch_id')} onChange={put('branch_id')} />
            <TextField label="Day" required type="date" value={t('day')} onChange={put('day')} />
            <TextField label="Old system: total sales" required type="number" step="any" prefix={d.currency} value={t('old_sales')} onChange={put('old_sales')} />
            <TextField label="Old system: cash" type="number" step="any" prefix={d.currency} value={t('old_cash')} onChange={put('old_cash')} />
            <TextField label="Old system: number of receipts" type="number" min={0} value={t('old_receipts')} onChange={put('old_receipts')} />
            <LongText label="Explanation of any difference" maxLength={255} value={t('note')} onChange={put('note')} />
          </div>
        </Ask>
      )}
      {dialog === 'practice' && (
        <Ask title="Create the practice company" go="Create" width={600} ready onClose={() => setDialog(null)}
          run={() => act('practice', { users: (v.users as string[]) ?? [], copy_catalogue: !!v.copy_catalogue })}>
          <div className={s.one}>
            <Text>A copy of your branches, tills, settings, products, prices and stock, with made-up customers, for staff to practise on. Nothing done there is real or sent anywhere. It can take a few minutes.</Text>
            <ChooseMany label="Staff who get a practice login (you always do)" options={d.choices.users} value={(v.users as string[]) ?? []} onChange={put('users')} />
            <Toggle label="Copy products, prices and stock" hint="Off, or nothing loaded yet = a small sample range instead." value={!!v.copy_catalogue} onChange={put('copy_catalogue')} />
          </div>
        </Ask>
      )}
      {dialog === 'archive' && (
        <Ask title="Archive the practice company?" go="Archive" ready onClose={() => setDialog(null)} run={() => act('archive_practice')}>
          <Text>Practice logins stop working. You can then create a fresh practice company.</Text>
        </Ask>
      )}
      {dialog === 'snapshot' && (
        <Ask title="Take the go-live backup?" go="Take the backup" ready onClose={() => setDialog(null)} run={() => act('snapshot')}>
          <Text>An encrypted backup of everything as it is now, copied to every backup destination. If going live goes wrong, this is what the technician restores. It can take a few minutes.</Text>
        </Ask>
      )}
      {dialog === 'parallel' && (
        <Ask title="Start the parallel run?" go="Start" ready onClose={() => setDialog(null)} run={() => act('start_parallel')}>
          <Text>From today both systems are used. Each evening, enter the old system's totals; the go-live step turns green after several clean days.</Text>
        </Ask>
      )}
      {dialog === 'golive' && (
        <Ask title="Go live?" go="Go live" ready={d.open_steps.length === 0 || t('reason').trim() !== ''} onClose={() => setDialog(null)} run={() => act('go_live', { reason: t('reason') || null })}>
          <div className={s.one}>
            <Text>From now on this system holds the books and the old one is only for looking things up.</Text>
            {d.open_steps.length > 0 && <>
              <Text>Not finished: {d.open_steps.join(', ')}.</Text>
              <LongText label="Some steps are not finished: why go live anyway?" maxLength={500} value={t('reason')} onChange={put('reason')} />
            </>}
          </div>
        </Ask>
      )}
      {dialog === 'rollback' && (
        <Ask title="Go back to the old system?" go="Record it" ready={t('reason').trim() !== ''} onClose={() => setDialog(null)} run={() => act('roll_back', { reason: t('reason') })}>
          <div className={s.one}>
            <Text>Records the decision. Then follow the rollback plan: the old system takes over selling, and the technician restores the go-live backup if the books must be put back.</Text>
            <LongText label="Why?" maxLength={500} value={t('reason')} onChange={put('reason')} />
          </div>
        </Ask>
      )}
    </div>
  )
}
