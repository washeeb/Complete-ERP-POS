import { Button, MessageBar, MessageBarBody } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type TargetList, type TargetValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, useFieldStyles } from '../form/Fields'
import { money } from '../home/format'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useList } from '../list/useList'
import { useProcStyles } from './ProcurementParts'

type Row = TargetList['rows'][number]

/**
 * Distribution → Rep targets (part 34): each rep's sales target (before tax) and visits target per month. Set a target
 * (setting one for a month that has one changes it); a row opens it to change. Rep performance measures against these.
 */
export function RepTargetsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.repTargets, '', refresh)
  const [edit, setEdit] = useState<TargetValues | null>(null)
  const [done, setDone] = useState('')
  const m = (x: number) => (data ? money(x, data.currency, data.exponent) : '')
  const put = <K extends keyof TargetValues>(k: K) => (x: TargetValues[K]) => setEdit((o) => (o ? { ...o, [k]: x } : o))
  return (
    <div className={page.page}>
      <PageHead title="Rep targets" hint="Each rep's sales and visits target per month. Rep performance measures against these." />
      <div className={s.bar}>
        <span className={s.grow} />
        {data && <Button appearance="primary" icon={<AddRegular />}
          onClick={() => { setDone(''); setEdit({ user_id: '', year: String(data.year), month: String(data.month), target: '', visit_target: '' }) }}>Set a target</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Rep targets" rows={data?.rows ?? []} sort="period" dir="desc" loading={loading} empty="No targets yet." onSort={() => undefined}
        onOpen={(r) => { setDone(''); setEdit({ ...r.values }) }}
        columns={[
          { key: 'rep', label: 'Rep', render: (r) => r.rep },
          { key: 'period', label: 'Month', render: (r) => r.period },
          { key: 'target', label: 'Sales target', end: true, render: (r) => m(r.target) },
          { key: 'visits', label: 'Visits target', end: true, render: (r) => r.visits ?? '—' },
        ]} />
      {edit && data && (
        <Ask title="Set a target" go="Save" width={600} onClose={() => setEdit(null)} ready={!!edit.user_id && !!edit.month && edit.target !== '' && Number(edit.target) >= 0}
          run={() => api.saveRepTarget(edit).then((x) => { setEdit(null); setDone(x.message); setRefresh((n) => n + 1) })}>
          <div className={f.grid}>
            <Choose label="Rep" required options={data.reps} value={edit.user_id} onChange={put('user_id')} />
            <TextField label="Year" required type="number" min={2000} max={2100} value={edit.year} onChange={put('year')} />
            <Choose label="Month" required options={data.months} value={edit.month} onChange={put('month')} />
            <TextField label="Sales target (before tax)" required type="number" min={0} step="any" prefix={data.currency} value={edit.target} onChange={put('target')} />
            <TextField label="Visits target (optional)" type="number" min={0} value={edit.visit_target} onChange={put('visit_target')} />
          </div>
        </Ask>
      )}
    </div>
  )
}
