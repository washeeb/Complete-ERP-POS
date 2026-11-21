import {
  Badge, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Dropdown, Link, makeStyles, MessageBar, MessageBarBody, Option, SearchBox, Spinner, Text, tokens,
} from '@fluentui/react-components'
import { useEffect, useState, type ReactNode } from 'react'
import { api, type PrescriptionDetail, type PrescriptionRow } from '../api'
import { DataTable, Pager, type Column } from '../list/DataTable'
import { PageHead, usePageStyles, when } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { navigate } from '../nav'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  search: { flex: '1 1 200px', maxWidth: '300px' },
  muted: { color: tokens.colorNeutralForeground3 },
  facts: { display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: '10px 16px', '@media (max-width: 560px)': { gridTemplateColumns: '1fr' } },
  head: { marginTop: '16px', marginBottom: '4px' },
  list: { margin: 0, paddingLeft: '20px' },
})

const OFFLINE = [['', 'Online and offline'], ['yes', 'Approved offline'], ['no', 'Approved online']]

/**
 * Prescriptions (pharmacy): each prescription dispensed at a till, made with the sale and never changed. A row opens
 * it: the prescriber, the paper prescription, the pharmacist, the medicines and the warnings the pharmacist saw.
 */
export function PrescriptionsPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [params, set] = useQueryParams()
  const { open, ...rest } = params
  const query = serialize(rest)
  const { data, loading, error } = useList(api.prescriptions, query)
  const [q, setQ] = useState(str(params, 'q'))
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const offline = str(params, 'offline')
  const columns: Column<PrescriptionRow>[] = [
    { key: 'number', label: 'Number', render: (r) => <Text weight="semibold">{r.number}</Text> },
    { key: 'dispensed', label: 'Dispensed', sort: 'created_at', render: (r) => when(r.dispensed_at) },
    { key: 'patient', label: 'Patient', render: (r) => r.patient ?? '—' },
    { key: 'prescriber', label: 'Prescriber', render: (r) => r.prescriber },
    { key: 'pharmacist', label: 'Pharmacist', render: (r) => r.pharmacist ?? '—' },
    { key: 'warnings', label: 'Warnings', end: true, render: (r) => (r.warnings ? <Badge appearance="tint" color="warning">{r.warnings}</Badge> : '—') },
    { key: 'offline', label: 'Offline', render: (r) => (r.offline ? <Badge appearance="tint" color="informative">Offline</Badge> : '—') },
  ]
  return (
    <div className={page.page}>
      <PageHead title="Prescriptions" hint="Every prescription dispensed at the tills, made with the sale and never changed. Open one to see the medicines and the warnings the pharmacist saw." classic="/admin/prescriptions" />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Number, patient, prescriber or reference" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search prescriptions" />
        <Dropdown aria-label="Approved" style={{ minWidth: 170 }} value={OFFLINE.find((x) => x[0] === offline)?.[1] ?? ''} selectedOptions={[offline]} onOptionSelect={(_, d) => set({ offline: d.optionValue ?? '' })}>
          {OFFLINE.map(([k, l]) => <Option key={k} value={k}>{l}</Option>)}
        </Dropdown>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      <DataTable label="Prescriptions" columns={columns} rows={data?.rows ?? []} sort="created_at" dir={data?.dir ?? 'desc'} loading={loading} empty="No prescriptions dispensed yet."
        onSort={(_, dir) => set({ dir: dir === 'asc' ? 'asc' : '' })} onOpen={(r) => set({ open: r.id })} />
      {data && <Pager page={data.page} per={data.per} total={data.total} options={[10, 25, 50, 100]} onPage={(p) => set({ page: String(p) })} onPer={(n) => set({ per: String(n) })} />}
      {typeof open === 'string' && open !== '' && <PrescriptionDialog id={open} onClose={() => set({ open: '' })} />}
    </div>
  )
}

function PrescriptionDialog({ id, onClose }: { id: string; onClose: () => void }) {
  const s = useStyles()
  const [rx, setRx] = useState<PrescriptionDetail | null>(null)
  const [error, setError] = useState('')
  useEffect(() => { api.prescription(id).then(setRx).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [id])
  const fact = (label: string, value: ReactNode) => <div><Text block size={200} className={s.muted}>{label}</Text><Text block>{value || '—'}</Text></div>
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: 680 }}>
        <DialogBody>
          <DialogTitle>{rx ? `Prescription ${rx.number}` : 'Prescription'}</DialogTitle>
          <DialogContent>
            {error ? <Text style={{ color: tokens.colorPaletteRedForeground1 }}>{error}</Text> : !rx ? <Spinner label="Reading the prescription…" /> : <>
              <div className={s.facts}>
                {fact('Dispensed', when(rx.dispensed_at))}
                {fact('Patient', rx.patient)}
                {fact('Receipt', rx.receipt && (rx.sale_id ? <Link onClick={() => navigate(`/office/sales/${rx.sale_id}`)}>{rx.receipt}</Link> : rx.receipt))}
                {fact('Prescriber', rx.prescriber)}
                {fact('Prescriber reg. no.', rx.prescriber_reg_no)}
                {fact('Facility', rx.facility)}
                {fact('Prescription ref.', rx.reference)}
                {fact('Written on', rx.issued_on && new Date(rx.issued_on).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }))}
                {fact('Pharmacist', <>{rx.pharmacist}{rx.offline && <Text block size={200} className={s.muted}>Approved on the till while offline</Text>}</>)}
              </div>
              <Text block weight="semibold" className={s.head}>Medicines</Text>
              {rx.medicines.length ? <ul className={s.list}>{rx.medicines.map((m, i) => <li key={i}>{m}</li>)}</ul> : <Text className={s.muted}>None recorded</Text>}
              <Text block weight="semibold" className={s.head}>Warnings shown to the pharmacist</Text>
              {rx.warnings.length ? <ul className={s.list}>{rx.warnings.map((w, i) => <li key={i}><b>{w.severity}</b> · {w.message}</li>)}</ul> : <Text className={s.muted}>None</Text>}
            </>}
          </DialogContent>
          <DialogActions><Button onClick={onClose}>Close</Button></DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
