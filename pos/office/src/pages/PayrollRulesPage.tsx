import { Button, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, CheckmarkStarburstRegular, DeleteRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type PayrollRules, type PayrollRuleValues } from '../api'
import { Ask } from '../form/Ask'
import { TextField, useFieldStyles } from '../form/Fields'
import { ExportMenu } from '../list/ExportMenu'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { END, Panel, useProcStyles } from './ProcurementParts'

/**
 * Payroll → Tax and SSNIT rules (part 38), as the current page: the monthly PAYE bands, SSNIT and pension rates, Tier 3
 * relief, junior staff overtime and the standard day payroll uses. Check and confirm records the accountant who compared
 * them with the current GRA and SSNIT rules; export to Excel, CSV or PDF.
 */
export function PayrollRulesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<PayrollRules | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [v, setV] = useState<PayrollRuleValues | null>(null)
  const load = useCallback(() => { api.payrollRules().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])
  useEffect(load, [load])
  const p = d?.profile
  const put = (k: Exclude<keyof PayrollRuleValues, 'bands'>) => (x: string) => setV((o) => (o ? { ...o, [k]: x } : o))
  const band = (i: number, k: 'width' | 'rate', x: string) => setV((o) => (o ? { ...o, bands: o.bands.map((b, j) => (j === i ? { ...b, [k]: x } : b)) } : o))
  const ready = !!v && v.bands.length > 0 && v.bands.every((b, i) => b.rate !== '' && (i === v.bands.length - 1 || Number(b.width) > 0))
    && [v.employee, v.employer, v.tier1, v.tier3_relief, v.ot_threshold, v.ot_low, v.ot_high, v.hours].every((x) => x !== '')
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={() => navigate('/office/payroll')}>Payroll</Button>
      <PageHead title="Tax and SSNIT rules" hint="The PAYE bands and SSNIT rates payroll uses. An accountant checks and confirms them." />
      <div className={s.bar}>
        <span className={s.grow} />
        {p && <ExportMenu url="/office-api/payroll-rules/export" query="" total={p.bands.length} />}
        {d?.can_verify && p && <Button appearance="primary" icon={<CheckmarkStarburstRegular />} onClick={() => { setDone(''); setV(p.values) }}>Check and confirm</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {!d ? <Spinner label="Opening the rules…" /> : !p ? <Panel title="Rules"><Text>No payroll profile is set up.</Text></Panel> : (
        <Panel title={p.name}>
          {p.verified ? <Text block className={s.muted} style={{ marginBottom: 10 }}>{p.verified}</Text>
            : <MessageBar intent="warning" style={{ marginBottom: 10 }}><MessageBarBody>NOT YET CHECKED — an accountant must confirm these against current GRA and SSNIT rules before the first real payroll.</MessageBarBody></MessageBar>}
          <Table size="small" aria-label="PAYE bands" style={{ maxWidth: 480 }}>
            <TableHeader><TableRow><TableHeaderCell>Monthly taxable income</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Rate</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{p.bands.map((b, i) => <TableRow key={i}><TableCell>{b.band}</TableCell><TableCell className={s.end}>{b.rate}</TableCell></TableRow>)}</TableBody>
          </Table>
          <Text block style={{ marginTop: 10 }}>{p.note}</Text>
        </Panel>
      )}

      {v && (
        <Ask title="Check and confirm" go="Confirm" width={760} ready={ready} onClose={() => setV(null)}
          run={() => api.verifyPayrollRules(v).then((x) => { setV(null); setDone(x.message); load() })}>
          <div className={s.one}>
            <Text>Compare with the current GRA PAYE bands and SSNIT rates. Saving records you as the person who checked them.</Text>
            <Text weight="semibold">Monthly PAYE bands (leave the last band's width empty)</Text>
            {v.bands.map((b, i) => (
              <div key={i} className={s.grid} role="group" aria-label={`Band ${i + 1}`}>
                <TextField label="Band width (GHS)" type="number" min={0.01} step="any" value={b.width} onChange={(x) => band(i, 'width', x)} />
                <TextField label="Rate %" required type="number" min={0} max={100} step="any" value={b.rate} onChange={(x) => band(i, 'rate', x)} />
                <div style={{ display: 'flex', alignItems: 'flex-end', height: '100%' }}>
                  {v.bands.length > 1 && <Button appearance="subtle" icon={<DeleteRegular />} aria-label={`Remove band ${i + 1}`} onClick={() => setV({ ...v, bands: v.bands.filter((_, j) => j !== i) })} />}
                </div>
              </div>
            ))}
            <div><Button icon={<AddRegular />} onClick={() => setV({ ...v, bands: [...v.bands, { width: '', rate: '' }] })}>Add band</Button></div>
            <Text weight="semibold">SSNIT and pensions (% of basic)</Text>
            <div className={f.grid}>
              <TextField label="Employee" required type="number" step="any" value={v.employee} onChange={put('employee')} />
              <TextField label="Employer" required type="number" step="any" value={v.employer} onChange={put('employer')} />
              <TextField label="Of which Tier 1" required type="number" step="any" value={v.tier1} onChange={put('tier1')} />
              <TextField label="Tier 3 tax relief up to" required type="number" step="any" value={v.tier3_relief} onChange={put('tier3_relief')} />
            </div>
            <Text weight="semibold">Overtime of qualifying junior staff</Text>
            <div className={f.grid}>
              <TextField label="Lower rate up to (% of basic)" required type="number" step="any" value={v.ot_threshold} onChange={put('ot_threshold')} />
              <TextField label="Lower rate %" required type="number" step="any" value={v.ot_low} onChange={put('ot_low')} />
              <TextField label="Higher rate %" required type="number" step="any" value={v.ot_high} onChange={put('ot_high')} />
              <TextField label="Standard day (hours)" required type="number" min={1} max={24} step="1" value={v.hours} onChange={put('hours')} />
            </div>
          </div>
        </Ask>
      )}
    </div>
  )
}
