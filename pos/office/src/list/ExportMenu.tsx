import {
  Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, Spinner, Tooltip,
} from '@fluentui/react-components'
import { ArrowDownloadRegular, DocumentPdfRegular, DocumentTextRegular, TableRegular } from '@fluentui/react-icons'
import { useState } from 'react'

/** A PDF is laid out row by row in the server's memory: beyond this it does not fit (the server says so too). */
export const PDF_ROWS = 800

export type ExportList = 'sales' | 'shifts' | 'drawer' | 'products' | 'stock' | 'movements' | 'adjustments' | 'counts' | 'transfers' | 'requests' | 'customers' | 'invoices' | 'suppliers' | 'purchase-orders' | 'goods-receipts' | 'direct-purchases'

/** The file name the server gave (Content-Disposition), or a plain one. */
export function fileName(disposition: string | null, fallback: string): string {
  const m = disposition?.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)
  return m ? decodeURIComponent(m[1]) : fallback
}

/**
 * Fetch a file the server makes (an export, a voucher) and save it under the name the server gave. A refusal (a licence
 * that does not allow output, too many rows…) comes back as its message instead of a broken download; null = saved.
 */
export async function download(url: string, fallback: string): Promise<string | null> {
  try {
    const res = await fetch(url, { credentials: 'same-origin' })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      return body.detail ?? body.message ?? 'The file could not be made. Try again.'
    }
    const blob = await res.blob()
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = fileName(res.headers.get('Content-Disposition'), fallback)
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(a.href), 10000)
    return null
  } catch {
    return 'The file could not be made. Check the connection and try again.'
  }
}

/**
 * Export, as on every list of the current back office: what the list shows — its search, filters and order, all pages —
 * as Excel, CSV or PDF. A PDF holds about 800 rows; beyond that it is offered as Excel or CSV. A refusal (a licence that
 * does not allow output, too many rows) comes back as a message, not as a broken download.
 */
export function ExportMenu({ list, query, total }: { list: ExportList; query: string; total: number | null }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const onError = setError
  const tooMany = total !== null && total > PDF_ROWS
  const go = async (format: 'xlsx' | 'csv' | 'pdf') => {
    setBusy(true)
    const problem = await download(`/office-api/export/${list}?${query}${query ? '&' : ''}format=${format}`, `${list}.${format}`)
    if (problem) onError(problem)
    setBusy(false)
  }
  return (
    <>
    <Menu>
      <MenuTrigger disableButtonEnhancement>
        <Button icon={busy ? <Spinner size="tiny" /> : <ArrowDownloadRegular />} disabled={busy || total === 0}>{busy ? 'Preparing…' : 'Export'}</Button>
      </MenuTrigger>
      <MenuPopover>
        <MenuList>
          <MenuItem icon={<TableRegular />} onClick={() => go('xlsx')}>Export to Excel (.xlsx)</MenuItem>
          <MenuItem icon={<DocumentTextRegular />} onClick={() => go('csv')}>Export to CSV</MenuItem>
          {tooMany ? (
            <Tooltip content={`${total?.toLocaleString('en-GB')} rows are too many for a PDF (about ${PDF_ROWS} fit). Narrow the list, or use Excel or CSV.`} relationship="description">
              <MenuItem icon={<DocumentPdfRegular />} disabled>Export to PDF</MenuItem>
            </Tooltip>
          ) : <MenuItem icon={<DocumentPdfRegular />} onClick={() => go('pdf')}>Export to PDF</MenuItem>}
        </MenuList>
      </MenuPopover>
    </Menu>
    {error && (
      <Dialog open onOpenChange={(_, d) => !d.open && setError('')}>
        <DialogSurface style={{ maxWidth: 460 }}>
          <DialogBody>
            <DialogTitle>Export not made</DialogTitle>
            <DialogContent>{error}</DialogContent>
            <DialogActions><Button appearance="primary" onClick={() => setError('')}>OK</Button></DialogActions>
          </DialogBody>
        </DialogSurface>
      </Dialog>
    )}
    </>
  )
}
