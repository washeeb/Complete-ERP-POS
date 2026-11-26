import { makeStyles, Select, Tooltip } from '@fluentui/react-components'
import { useState } from 'react'
import { api, type Me } from '../api'
import { confirmLeave } from '../unsaved'

const useStyles = makeStyles({
  select: { width: '190px', '@media (max-width: 760px)': { width: '100%' } }, // on a phone: inside the Show panel
})

/**
 * The branch switch beside the industry one (part 45): "All my branches" or one branch, within the chosen industry.
 * Every list, report, the Business Portal and To-do then show only that branch, and new documents start with it, so
 * the page is opened again with the new choice (after asking, when the page has unsaved changes). Shown only to
 * people with more than one branch there.
 */
export function BranchSwitch({ me }: { me: Me }) {
  const s = useStyles()
  const [busy, setBusy] = useState(false)
  if (!me.branch) return null
  const choose = (id: string) => confirmLeave(() => {
    setBusy(true)
    api.chooseBranch(id).then(() => window.location.reload()).catch(() => setBusy(false))
  })
  return (
    <Tooltip content="Show one branch, or all your branches" relationship="description">
      <Select size="small" className={s.select} aria-label="Branch shown" value={me.branch.selected} disabled={busy}
        onChange={(_, d) => choose(d.value)}>
        <option value="">All my branches</option>
        {me.branch.options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
      </Select>
    </Tooltip>
  )
}
