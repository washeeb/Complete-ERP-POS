import { Button, Link, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { ArrowLeftRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SerialDetail } from '../api'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { day, Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

/**
 * One serial / IMEI number (part 36), as the current page: the product, where it is, when and to whom it was sold, the
 * receipt, its warranty, and its history read from the stock ledger.
 */
export function SerialPage({ id, onTitle }: { id: string; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<SerialDetail | null>(null)
  const [error, setError] = useState('')
  useEffect(() => {
    api.serial(id).then((x) => { setD(x); onTitle(x.serial) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the serial number…" /></div>
  const back = () => (history.length > 1 ? history.back() : navigate('/office/electronics/serials'))
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Serial numbers</Button>
      <div className={s.head}>
        <h1 className={s.title}><span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.serial}</span><ToneBadge tone={d.tone} label={d.status_label} /></h1>
      </div>
      <Panel title="Serial number">
        <div className={s.facts}>
          <Fact label="Product">{d.product ?? '—'}{d.sku && <Text size={200} block className={s.muted}>{d.sku}</Text>}</Fact>
          <Fact label="Where">{d.where ?? '—'}</Fact>
          <Fact label="Sold">{d.sold ?? '—'}</Fact>
          <Fact label="Receipt">{d.receipt ? (d.sale_open ? <Link onClick={() => navigate(d.sale_open!)}>{d.receipt}</Link> : d.receipt) : '—'}</Fact>
          <Fact label="Customer">{d.customer ?? '—'}</Fact>
          <Fact label="Warranty until">{day(d.warranty)}{d.in_warranty !== null && <Text size={200} block className={s.muted}>{d.in_warranty ? 'In warranty' : 'Expired'}</Text>}</Fact>
        </div>
      </Panel>
      <Panel title="History">
        {d.history.length === 0 ? <Text className={s.muted}>No stock movements yet.</Text> : (
          <Table size="small" aria-label="History">
            <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>What</TableHeaderCell><TableHeaderCell>Where</TableHeaderCell>
              <TableHeaderCell>Reason</TableHeaderCell><TableHeaderCell>By</TableHeaderCell></TableRow></TableHeader>
            <TableBody>{d.history.map((h) => (
              <TableRow key={h.id}><TableCell>{h.when}</TableCell><TableCell>{h.what}</TableCell><TableCell>{h.where}</TableCell><TableCell>{h.reason ?? ''}</TableCell><TableCell>{h.by ?? ''}</TableCell></TableRow>
            ))}</TableBody>
          </Table>
        )}
      </Panel>
    </div>
  )
}
