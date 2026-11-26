import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Text } from '@fluentui/react-components'
import { AddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import { api, type IndustryRow } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ArchiveAsk, ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'

type Editing = { row: IndustryRow | null; name: string; module_key: string; legal_entity_id: string; sort: string } | null

/**
 * Company → Industries (part 20): Pharmacy, Grocery, … each with the industry module its branches run, its branches and
 * its books (the head office company, or its own legal entity). The code starts its new products' codes and never
 * changes. Add, change, archive and restore, through IndustryService and ArchiveService.
 */
export function IndustriesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.industries, serialize(params), refresh)
  const [editing, setEditing] = useState<Editing>(null)
  const [archiving, setArchiving] = useState<{ row: IndustryRow; restore: boolean } | null>(null)
  const [done, setDone] = useState('')
  const finish = (m: string) => { setEditing(null); setArchiving(null); setDone(m); setRefresh((n) => n + 1) }
  const open = (r: IndustryRow | null) => setEditing({ row: r, name: r?.name ?? '', module_key: r?.module_key ?? data?.modules[0]?.id ?? '', legal_entity_id: r?.legal_entity_id ?? '', sort: String(r?.sort ?? 0) })
  return (
    <div className={page.page}>
      <PageHead title="Industries" hint="Each line of business, the module its branches run, and whose books it keeps." />
      <div className={s.bar}>
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); open(null) }}>New industry</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<IndustryRow> label="Industries" rows={data?.rows ?? []} sort="sort" dir="asc" loading={loading} empty="No industries." onSort={() => undefined}
        onOpen={(r) => data?.can_create && open(r)}
        columns={[
          { key: 'code', label: 'Code', render: (r) => <Text font="monospace" weight="semibold">{r.code}</Text> },
          { key: 'name', label: 'Name', render: (r) => r.name },
          { key: 'module', label: 'Module', render: (r) => r.module },
          { key: 'branches', label: 'Branches', end: true, render: (r) => r.branches },
          { key: 'books', label: 'Books', render: (r) => r.books ?? <Text className={s.muted}>The company</Text> },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'more', label: '', render: (r) => (r.can_archive || r.can_restore) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${r.name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_archive && <MenuItem onClick={() => setArchiving({ row: r, restore: false })}>Archive</MenuItem>}
                {r.can_restore && <MenuItem onClick={() => setArchiving({ row: r, restore: true })}>Restore</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />

      {editing && data && (
        <Ask title={editing.row ? `Change ${editing.row.name}` : 'New industry'} go="Save" ready={editing.name.trim() !== '' && !!editing.module_key} onClose={() => setEditing(null)} width={560}
          run={() => api.saveIndustry(editing.row?.id ?? null, { name: editing.name, module_key: editing.module_key, legal_entity_id: editing.legal_entity_id || null, sort: Number(editing.sort || 0) })
            .then((x) => finish(x.message))}>
          <div className={s.one}>
            <Text>{editing.row ? `Code: ${editing.row.code}. It starts this industry's new product codes and never changes.` : 'The code is given when saved; it starts this industry\'s new product codes (e.g. GROCER-000001) and never changes.'}</Text>
            <TextField label="Name" required maxLength={120} value={editing.name} onChange={(x) => setEditing({ ...editing, name: x })} />
            <Choose label="Industry module its branches run" required options={data.modules} value={editing.module_key} onChange={(x) => setEditing({ ...editing, module_key: x })} />
            <Choose label="Books (legal entity)" options={data.legal_entities} value={editing.legal_entity_id} onChange={(x) => setEditing({ ...editing, legal_entity_id: x })} clearable
              placeholder="The head office company (one set of books)"
              hint="Only when this industry is registered as its own company (Company → Legal entities). Its branches then keep separate books and print that company's name and TIN. Change it at the start of a financial year." />
            <TextField label="Order" type="number" value={editing.sort} onChange={(x) => setEditing({ ...editing, sort: x })} />
          </div>
        </Ask>
      )}
      {archiving && <ArchiveAsk kind="industry" row={archiving.row} name={archiving.row.name} restore={archiving.restore} onClose={() => setArchiving(null)} onDone={finish} />}
    </div>
  )
}
