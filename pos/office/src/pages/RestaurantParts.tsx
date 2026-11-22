import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, Text } from '@fluentui/react-components'
import { MoreHorizontalRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type Archivable } from '../api'
import { Ask } from '../form/Ask'
import { TextField } from '../form/Fields'
import { useProcStyles } from './ProcurementParts'

type Kind = 'table' | 'station' | 'modifier'

/** Archive and Restore on a floor-plan table, kitchen station or modifier group (part 30), as the current pages' buttons. */
export function ArchiveMenu({ kind, row, name, onDone }: { kind: Kind; row: Archivable & { id: string }; name: string; onDone: (message: string) => void }) {
  const s = useProcStyles()
  const [ask, setAsk] = useState<'archive' | 'restore' | null>(null)
  const [why, setWhy] = useState('')
  if (!row.can_archive && !row.can_restore) return null
  return (
    <>
      <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
        <MenuPopover><MenuList>
          {row.can_archive && <MenuItem onClick={() => { setWhy(''); setAsk('archive') }}>Archive</MenuItem>}
          {row.can_restore && <MenuItem onClick={() => setAsk('restore')}>Restore</MenuItem>}
        </MenuList></MenuPopover></Menu>
      {ask && (
        <Ask title={`${ask === 'restore' ? 'Restore' : 'Archive'} ${name}?`} go={ask === 'restore' ? 'Restore' : 'Archive'}
          ready={ask === 'restore' || (row.archive_blockers.length === 0 && why.trim().length >= 3)} onClose={() => setAsk(null)}
          run={() => api.restArchive(kind, row.id, ask === 'restore', why).then((x) => { setAsk(null); onDone(x.message) })}>
          {ask === 'restore' ? <Text>It comes back into the lists people choose from.</Text>
            : row.archive_blockers.length ? <Text>It cannot be archived yet. {row.archive_blockers.join(' ')}</Text>
              : <div className={s.one}><Text>It leaves every list people choose from. Its history stays: past bills keep showing it. You can restore it at any time.</Text>
                <TextField label="Why" required maxLength={255} value={why} onChange={setWhy} /></div>}
        </Ask>
      )}
    </>
  )
}
