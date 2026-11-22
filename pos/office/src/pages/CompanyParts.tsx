import { Badge, Dropdown, Option, Text } from '@fluentui/react-components'
import { useState } from 'react'
import { api, type Archivable } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'

export type ArchiveKind = 'branch' | 'store' | 'till' | 'industry' | 'legal_entity'
const WHAT: Record<ArchiveKind, string> = { branch: 'branch', store: 'store', till: 'till', industry: 'industry', legal_entity: 'legal entity' }

/** In use (the default), archived only, or both, as the current lists' filter. */
export const SHOW: [string, string][] = [['', 'In use'], ['archived', 'Archived only'], ['both', 'Both']]

export function ShowFilter({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <Dropdown aria-label="Show" value={SHOW.find(([k]) => k === value)?.[1] ?? 'In use'} selectedOptions={[value]} onOptionSelect={(_, d) => onChange(d.optionValue ?? '')} style={{ minWidth: 150 }}>
      {SHOW.map(([k, l]) => <Option key={k || 'use'} value={k}>{l}</Option>)}
    </Dropdown>
  )
}

export const Status = ({ r }: { r: Pick<Archivable, 'archived'> & { archived_note?: string | null } }) => (
  <Badge appearance="tint" color={r.archived ? 'subtle' : 'success'} title={r.archived_note ?? undefined}>{r.archived ? 'Archived' : 'In use'}</Badge>
)

/**
 * Archive (with the reason; what must happen first when it cannot be archived yet) or Restore a branch, store, till,
 * industry or legal entity (part 20), as the current pages' buttons, through ArchiveService.
 */
export function ArchiveAsk({ kind, row, name, restore, onClose, onDone }: {
  kind: ArchiveKind; row: Archivable & { id: string }; name: string; restore: boolean; onClose: () => void; onDone: (message: string) => void
}) {
  const [why, setWhy] = useState('')
  const blocked = !restore && row.archive_blockers.length > 0
  return (
    <Ask title={`${restore ? 'Restore' : 'Archive'} ${name}?`} go={restore ? 'Restore' : 'Archive'} ready={!blocked && (restore || why.trim().length >= 3)} onClose={onClose}
      run={() => api.companyArchive(kind, row.id, restore, why).then((x) => onDone(x.message))}>
      {restore
        ? <Text>It comes back into the lists people choose from.{kind === 'branch' ? ' The stores and tills archived with it come back too; enrol its devices again to trade.' : ''}</Text>
        : blocked
          ? <Text>It cannot be archived yet. {row.archive_blockers.join(' ')}</Text>
          : <>
            <Text block style={{ marginBottom: 10 }}>It leaves every list people choose from, so nothing new is given this {WHAT[kind]}. Its history stays: past sales, stock, books and the audit log keep
              showing it. You can restore it at any time.</Text>
            <TextField label="Why" required maxLength={255} placeholder="e.g. created by mistake, no longer used" value={why} onChange={setWhy} />
          </>}
    </Ask>
  )
}
