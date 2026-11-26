import { makeStyles, Select, Tooltip } from '@fluentui/react-components'
import { useState } from 'react'
import { api, type Me } from '../api'

const useStyles = makeStyles({
  select: { width: '190px', '@media (max-width: 760px)': { width: '130px' } },
})

/**
 * The industry switch at the top (part 43, as the current back office's): "All industries", "Pharmacy", "Grocery" …
 * Every screen then shows only that industry's branches, stock, prices, purchasing, sales and reports, so the page is
 * opened again with the new choice. Shown only to people who cover several industries or the whole company.
 */
export function IndustrySwitch({ me }: { me: Me }) {
  const s = useStyles()
  const [busy, setBusy] = useState(false)
  if (!me.industry) return null
  const choose = (id: string) => {
    setBusy(true)
    api.industry(id).then(() => window.location.reload()).catch(() => setBusy(false))
  }
  return (
    <Tooltip content="Show one industry, or all" relationship="description">
      <Select size="small" className={s.select} aria-label="Industry shown" value={me.industry.selected} disabled={busy}
        onChange={(_, d) => choose(d.value)}>
        <option value="">All industries</option>
        {me.industry.options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
      </Select>
    </Tooltip>
  )
}
