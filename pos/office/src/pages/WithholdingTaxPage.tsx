import {
  Button, Dropdown, Link, makeStyles, MessageBar, MessageBarBody, Option, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens,
} from '@fluentui/react-components'
import { ArrowDownloadRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type WhtMonth } from '../api'
import { money } from '../home/format'
import { Cards, PageHead, usePageStyles } from '../list/ListParts'

const useStyles = makeStyles({
  bar: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '10px' },
  panel: { backgroundColor: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, border: `1px solid ${tokens.colorNeutralStroke2}`,
    boxShadow: tokens.shadow4, padding: '14px 16px', overflowX: 'auto' },
  end: { textAlign: 'right', fontVariantNumeric: 'tabular-nums' },
  muted: { color: tokens.colorNeutralForeground3 },
})

const END = { style: { justifyContent: 'flex-end' } }
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')

/**
 * Withholding tax (release 6): the month's payments that kept tax back — the certificate, the supplier and their TIN
 * (missing ones in red), the nature, the base before VAT, the rate and the tax — the total to pay GRA by the 15th of the
 * next month, and Download for GRA (CSV) for the schedule and the accountant. Last month is shown first: its return is
 * the one due now.
 */
export function WithholdingTaxPage() {
  const page = usePageStyles()
  const s = useStyles()
  const [month, setMonth] = useState('')
  const [data, setData] = useState<WhtMonth | null>(null)
  const [error, setError] = useState('')
  useEffect(() => {
    api.withholdingTax(month).then((d) => { setData(d); setError('') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [month])

  if (error && !data) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!data) return <div className={page.page}><Spinner label="Adding up the month…" /></div>
  const m = (v: number) => money(v, data.currency, data.exponent)
  const noTin = data.rows.filter((r) => !r.tin).length

  return (
    <div className={page.page}>
      <PageHead title="Withholding tax" hint="Tax kept back from supplier payments in a month, to pay GRA by the 15th of the next month (from Bank, against the Withholding tax payable account)." classic="/admin/withholding-tax-return" />
      <div className={s.bar}>
        <Dropdown aria-label="Month" value={data.months.find((x) => x.id === data.month)?.label ?? data.month} selectedOptions={[data.month]}
          onOptionSelect={(_, d) => d.optionValue && setMonth(d.optionValue)} style={{ minWidth: 170 }}>
          {data.months.map((o) => <Option key={o.id} value={o.id}>{o.label}</Option>)}
        </Dropdown>
        <Button icon={<ArrowDownloadRegular />} onClick={() => { window.location.href = api.withholdingCsvUrl(data.month) }}>Download for GRA (CSV)</Button>
      </div>
      {!data.enabled && <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>Withholding tax is off: switch it on under Settings → Withholding tax; each supplier payment then keeps it back.</MessageBarBody></MessageBar>}
      {noTin > 0 && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>{noTin} payment(s) to suppliers with no TIN: add it on the supplier before filing.</MessageBarBody></MessageBar>}
      <Cards items={[
        { label: 'Kept back this month', value: m(data.total) },
        { label: 'Pay GRA by', value: day(data.due) },
        { label: 'Payments', value: data.rows.length.toLocaleString('en-GB') },
      ]} />
      <section className={s.panel} aria-label="Payments that kept tax back">
        {data.rows.length === 0 ? <Text className={s.muted}>No withholding tax kept back this month.</Text> : (
          <Table size="small" aria-label="Payments that kept tax back">
            <TableHeader><TableRow>
              <TableHeaderCell>Date</TableHeaderCell><TableHeaderCell>Certificate</TableHeaderCell><TableHeaderCell>Supplier</TableHeaderCell><TableHeaderCell>Nature</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Before VAT</TableHeaderCell><TableHeaderCell className={s.end} button={END}>Rate</TableHeaderCell>
              <TableHeaderCell className={s.end} button={END}>Withholding tax</TableHeaderCell>
            </TableRow></TableHeader>
            <TableBody>{data.rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell>{day(r.date)}</TableCell>
                <TableCell><Link onClick={() => window.open(r.certificate, '_blank', 'noopener')}>{r.certificate_no ?? 'Certificate'}</Link><Text block size={200} className={s.muted}>{r.number}</Text></TableCell>
                <TableCell>{r.supplier ?? '—'}<Text block size={200} style={{ color: r.tin ? tokens.colorNeutralForeground3 : 'var(--zx-bad)' }}>{r.tin ?? 'no TIN'}</Text></TableCell>
                <TableCell>{r.nature}</TableCell>
                <TableCell className={s.end}>{m(r.base)}</TableCell><TableCell className={s.end}>{r.rate} %</TableCell>
                <TableCell className={s.end}><Text weight="semibold">{m(r.wht)}</Text></TableCell>
              </TableRow>
            ))}</TableBody>
          </Table>
        )}
      </section>
    </div>
  )
}
