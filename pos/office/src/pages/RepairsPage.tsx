import { Button, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api } from '../api'
import type { RepairList } from '../api'
import { Ask } from '../form/Ask'
import { Choose, LongText, TextField, useFieldStyles } from '../form/Fields'
import { DataTable, Pager } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'
import { Pick } from './CrmLeadsPage'
import { day, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = RepairList['rows'][number]
const BLANK = { branch_id: '', customer_name: '', phone: '', serial: '', device: '', accessories: '', fault: '', promised_on: '' }

/**
 * Electronics → Repairs (part 36): devices in for repair and where each job stands. New repair job (a serial number we
 * sold shows its warranty); a row opens the job.
 */
export function RepairsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [params, set] = useQueryParams()
  const { data, loading, error } = useList(api.repairs, serialize(params), 0)
  const [q, setQ] = useState(str(params, 'q'))
  const [add, setAdd] = useState<typeof BLANK | null>(null)
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const put = (k: keyof typeof BLANK) => (x: string) => setAdd((o) => (o ? { ...o, [k]: x } : o))
  return (
    <div className={page.page}>
      <PageHead title="Repairs" hint="Devices in for repair. Jobs with charges are collected by paying at the till." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, customer, phone, device or serial" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search repairs" />
        <Pick label="Status" any="Any status" options={data?.statuses ?? []} value={str(params, 'status')} onChange={(v) => set({ status: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />}
          onClick={() => setAdd({ ...BLANK, branch_id: data.branches.length === 1 ? data.branches[0].id : '' })}>New repair job</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable<Row> label="Repairs" rows={data?.rows ?? []} sort="received" dir="desc" loading={loading} empty="No repair jobs yet."
        onSort={() => undefined} onOpen={(r) => navigate(`/office/electronics/repairs/${r.id}`)}
        columns={[
          { key: 'number', label: 'No.', render: (r) => <Text font="monospace" size={200}>{r.number}</Text> },
          { key: 'received', label: 'In', render: (r) => r.received ?? '—' },
          { key: 'customer', label: 'Customer', render: (r) => <><Text weight="semibold">{r.customer}</Text>{r.phone && <Text size={200} block className={s.muted}>{r.phone}</Text>}</> },
          { key: 'device', label: 'Device', render: (r) => <>{r.device}{r.serial && <Text size={200} block className={s.muted}>{r.serial}</Text>}</> },
          { key: 'status', label: 'Status', render: (r) => <ToneBadge tone={r.tone} label={r.status_label} /> },
          { key: 'warranty', label: 'Warranty', render: (r) => (r.warranty ? 'Yes' : '—') },
          { key: 'promised', label: 'Promised', render: (r) => day(r.promised) },
        ]} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}

      {add && data && (
        <Ask title="New repair job" go="Open job" width={680} onClose={() => setAdd(null)}
          ready={!!add.branch_id && add.customer_name.trim() !== '' && add.fault.trim() !== '' && (add.device.trim() !== '' || add.serial.trim() !== '')}
          run={() => api.newRepair(add).then((x) => navigate(`/office/electronics/repairs/${x.id}`))}>
          <div className={f.grid}>
            <Choose label="Branch" required options={data.branches} value={add.branch_id} onChange={put('branch_id')} />
            <TextField label="Customer" required maxLength={120} value={add.customer_name} onChange={put('customer_name')} />
            <TextField label="Phone" maxLength={20} value={add.phone} onChange={put('phone')} />
            <TextField label="Serial / IMEI (if known)" hint="A number we sold shows its warranty." maxLength={80} value={add.serial} onChange={put('serial')} />
            <TextField label="Device" maxLength={160} placeholder="e.g. Samsung A12, black" value={add.device} onChange={put('device')} />
            <TextField label="Left with it" maxLength={255} placeholder="charger, case…" value={add.accessories} onChange={put('accessories')} />
            <LongText label="What is wrong" className={f.spanAll} maxLength={2000} value={add.fault} onChange={put('fault')} />
            <TextField label="Promised for" type="date" value={add.promised_on} onChange={put('promised_on')} />
          </div>
        </Ask>
      )}
    </div>
  )
}
