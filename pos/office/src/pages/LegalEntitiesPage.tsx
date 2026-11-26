import { Badge, Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, SearchBox, Text } from '@fluentui/react-components'
import { AddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type EntityRow } from '../api'
import { Ask } from '../form/Ask'
import { TextField, Toggle } from '../form/Fields'
import { DataTable } from '../list/DataTable'
import { PageHead, usePageStyles } from '../list/ListParts'
import { serialize, str, useQueryParams } from '../list/query'
import { useList } from '../list/useList'
import { ArchiveAsk, ShowFilter, Status } from './CompanyParts'
import { useProcStyles } from './ProcurementParts'

type Values = { name: string; registration_no: string; tin: string; vat_no: string; address: string; is_primary: boolean }
const blank: Values = { name: '', registration_no: '', tin: '', vat_no: '', address: '', is_primary: false }

/**
 * Company → Legal entities (part 20): the registered companies of the group, with their TIN and VAT number (printed on
 * the receipts and invoices of their branches). Exactly one is the head office company. An industry registered as its
 * own company is given its legal entity under Company → Industries.
 */
export function LegalEntitiesPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [params, set] = useQueryParams()
  const [refresh, setRefresh] = useState(0)
  const { data, loading, error } = useList(api.legalEntities, serialize(params), refresh)
  const [q, setQ] = useState(str(params, 'q'))
  const [editing, setEditing] = useState<{ row: EntityRow | null; v: Values } | null>(null)
  const [archiving, setArchiving] = useState<{ row: EntityRow; restore: boolean } | null>(null)
  const [done, setDone] = useState('')
  useEffect(() => {
    const t = setTimeout(() => { if (q !== str(params, 'q')) set({ q }) }, 300)
    return () => clearTimeout(t)
  }, [q]) // eslint-disable-line react-hooks/exhaustive-deps
  const finish = (m: string) => { setEditing(null); setArchiving(null); setDone(m); setRefresh((n) => n + 1) }
  const open = (r: EntityRow | null) => setEditing({ row: r, v: r ? { name: r.name, registration_no: r.registration_no, tin: r.tin, vat_no: r.vat_no, address: r.address, is_primary: r.primary } : blank })
  const put = <K extends keyof Values>(k: K) => (x: Values[K]) => setEditing((o) => (o ? { ...o, v: { ...o.v, [k]: x } } : o))
  return (
    <div className={page.page}>
      <PageHead title="Legal entities" hint="The registered companies of the group. Their TIN and VAT number print on their branches' receipts and invoices." />
      <div className={s.bar}>
        <SearchBox className={s.search} placeholder="Registered name" value={q} onChange={(_, d) => setQ(d.value)} aria-label="Search legal entities" />
        <ShowFilter value={str(params, 'show')} onChange={(v) => set({ show: v })} />
        <span className={s.grow} />
        {data?.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => { setDone(''); open(null) }}>New legal entity</Button>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <DataTable<EntityRow> label="Legal entities" rows={data?.rows ?? []} sort="name" dir="asc" loading={loading} empty="No legal entities match." onSort={() => undefined}
        onOpen={(r) => data?.can_edit && open(r)}
        columns={[
          { key: 'name', label: 'Registered name', render: (r) => <>{r.name}{r.primary && <Badge appearance="outline" size="small" style={{ marginLeft: 6 }}>Head office</Badge>}</> },
          { key: 'tin', label: 'TIN', render: (r) => r.tin || '—' },
          { key: 'vat', label: 'VAT number', render: (r) => r.vat_no || '—' },
          { key: 'status', label: 'Status', render: (r) => <Status r={r} /> },
          { key: 'more', label: '', render: (r) => (r.can_archive || r.can_restore) && (
            <Menu><MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${r.name}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
              <MenuPopover><MenuList>
                {r.can_archive && <MenuItem onClick={() => setArchiving({ row: r, restore: false })}>Archive</MenuItem>}
                {r.can_restore && <MenuItem onClick={() => setArchiving({ row: r, restore: true })}>Restore</MenuItem>}
              </MenuList></MenuPopover></Menu>
          ) },
        ]} />

      {editing && (
        <Ask title={editing.row ? `Change ${editing.row.name}` : 'New legal entity'} go="Save" ready={editing.v.name.trim() !== ''} onClose={() => setEditing(null)} width={600}
          run={() => api.saveLegalEntity(editing.row?.id ?? null, editing.v).then((x) => finish(x.message))}>
          <div className={s.one}>
            <TextField label="Registered name" required maxLength={150} value={editing.v.name} onChange={put('name')} />
            <TextField label="Registration number" maxLength={40} value={editing.v.registration_no} onChange={put('registration_no')} />
            <TextField label="TIN" maxLength={15} value={editing.v.tin} onChange={put('tin')} hint="Printed on receipts and invoices of its branches." />
            <TextField label="VAT number" maxLength={30} value={editing.v.vat_no} onChange={put('vat_no')} />
            <TextField label="Address" maxLength={255} value={editing.v.address} onChange={put('address')} />
            <Toggle label="Head office company" value={editing.v.is_primary} onChange={put('is_primary')}
              hint="Lines without a branch, and branches without a company of their own, are booked here." />
            {!editing.row && <Text size={200} className={s.muted}>The first legal entity is always the head office company.</Text>}
          </div>
        </Ask>
      )}
      {archiving && <ArchiveAsk kind="legal_entity" row={archiving.row} name={archiving.row.name} restore={archiving.restore} onClose={() => setArchiving(null)} onDone={finish} />}
    </div>
  )
}
