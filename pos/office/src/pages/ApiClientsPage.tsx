import { Badge, Button, Checkbox, Field, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell,
  TableRow, Text } from '@fluentui/react-components'
import { AddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ApiClientsData, type ShownOnce } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { Panel, ToneBadge, useProcStyles } from './ProcurementParts'
import { ShownOnceDialog } from './ShownOnce'

type Row = ApiClientsData['rows'][number]
type Doing = { kind: 'new' } | { kind: 'key' | 'webhook' | 'revoke'; row: Row } | null

/**
 * System → API clients (part 23): other systems that use the public API — their scopes, who they act as (they never see
 * more than that person may), the branch, when last used and their webhooks. New API client, New key (for rotation;
 * old keys keep working), Add webhook and Revoke. A key or signing secret is shown once, when made.
 */
export function ApiClientsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<ApiClientsData | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [doing, setDoing] = useState<Doing>(null)
  const [v, setV] = useState({ name: '', scopes: [] as string[], user_id: '', branch_id: '', url: '', events: [] as string[], reason: '' })
  const [secret, setSecret] = useState<ShownOnce | null>(null)
  useEffect(() => { api.apiClients().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening API clients…" /></div>
  const start = (x: Doing) => { setDone(''); setV({ name: '', scopes: [], user_id: '', branch_id: '', url: '', events: [], reason: '' }); setDoing(x) }
  const finish = (x: { message: string; secret?: ShownOnce; clients: ApiClientsData }) => { setD(x.clients); setDoing(null); setDone(x.message); if (x.secret) setSecret(x.secret) }
  const toggle = (list: string[], id: string, on: boolean) => (on ? [...list, id] : list.filter((x) => x !== id))
  return (
    <div className={page.page}>
      <PageHead title="API clients" hint={`Other systems that use the public API. API description for developers: ${d.openapi}`} classic="/admin/api-clients" />
      <div className={s.bar}><span className={s.grow} /><Button appearance="primary" icon={<AddRegular />} onClick={() => start({ kind: 'new' })}>New API client</Button></div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Clients">
        <Table size="small" aria-label="API clients" style={{ minWidth: 640 }}>
          <TableHeader><TableRow><TableHeaderCell>Name</TableHeaderCell><TableHeaderCell>Scopes</TableHeaderCell><TableHeaderCell>Acts as</TableHeaderCell><TableHeaderCell>Branch</TableHeaderCell>
            <TableHeaderCell>Last used</TableHeaderCell><TableHeaderCell>Webhooks</TableHeaderCell><TableHeaderCell>Active</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {d.rows.length === 0 && <TableRow><TableCell colSpan={8}><Text className={s.muted}>No API clients yet.</Text></TableCell></TableRow>}
            {d.rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell>{r.name}</TableCell>
                <TableCell>{r.scopes.map((x) => <Badge key={x} appearance="tint" size="small" style={{ marginRight: 4 }}>{x}</Badge>)}</TableCell>
                <TableCell>{r.acts_as ?? '—'}</TableCell><TableCell>{r.branch ?? 'All'}</TableCell><TableCell>{r.last_used ?? 'Never'}</TableCell><TableCell>{r.webhooks}</TableCell>
                <TableCell><ToneBadge tone={r.active ? 'success' : 'subtle'} label={r.active ? 'Active' : 'Revoked'} /></TableCell>
                <TableCell>{r.active && (
                  <Menu><MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${r.name}`} /></MenuTrigger>
                    <MenuPopover><MenuList>
                      <MenuItem onClick={() => start({ kind: 'key', row: r })}>New key</MenuItem>
                      <MenuItem onClick={() => start({ kind: 'webhook', row: r })}>Add webhook</MenuItem>
                      <MenuItem onClick={() => start({ kind: 'revoke', row: r })}>Revoke</MenuItem>
                    </MenuList></MenuPopover></Menu>
                )}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      {doing?.kind === 'new' && (
        <Ask title="New API client" go="Create" width={640} ready={v.name.trim() !== '' && v.scopes.length > 0 && !!v.user_id} onClose={() => setDoing(null)}
          run={() => api.createApiClient({ name: v.name, scopes: v.scopes, user_id: v.user_id, branch_id: v.branch_id || null }).then(finish)}>
          <div className={s.one}>
            <Text>The key acts as the person you choose: it never sees more than they may. Tip: create a user just for this system (e.g. "Shop website") with a narrow role.</Text>
            <TextField label='System (e.g. "Website", "Delivery partner")' required maxLength={120} value={v.name} onChange={(x) => setV({ ...v, name: x })} />
            <Field label="Scopes" required>{d.scopes.map((o) => <Checkbox key={o.id} label={o.label} checked={v.scopes.includes(o.id)} onChange={(_, e) => setV({ ...v, scopes: toggle(v.scopes, o.id, !!e.checked) })} />)}</Field>
            <Choose label="Acts as" required options={d.users} value={v.user_id} onChange={(x) => setV({ ...v, user_id: x })} />
            <Choose label="Only this branch (optional)" options={d.branches} value={v.branch_id} onChange={(x) => setV({ ...v, branch_id: x })} clearable />
          </div>
        </Ask>
      )}
      {doing?.kind === 'key' && (
        <Ask title={`New key for ${doing.row.name}?`} go="Make a new key" ready onClose={() => setDoing(null)} run={() => api.apiClientAction(doing.row.id, 'key').then(finish)}>
          <Text>A new key is added (for rotation). Old keys keep working until the client is revoked.</Text>
        </Ask>
      )}
      {doing?.kind === 'webhook' && (
        <Ask title={`Add a webhook for ${doing.row.name}`} go="Add" width={600} ready={/^https:\/\//.test(v.url) && v.events.length > 0} onClose={() => setDoing(null)}
          run={() => api.apiClientAction(doing.row.id, 'webhook', { url: v.url, events: v.events }).then(finish)}>
          <div className={s.one}>
            <TextField label="Address (https://…)" required maxLength={500} value={v.url} onChange={(x) => setV({ ...v, url: x })} />
            <Field label="Events" required>{d.events.map((o) => <Checkbox key={o.id} label={o.label} checked={v.events.includes(o.id)} onChange={(_, e) => setV({ ...v, events: toggle(v.events, o.id, !!e.checked) })} />)}</Field>
          </div>
        </Ask>
      )}
      {doing?.kind === 'revoke' && (
        <Ask title={`Revoke ${doing.row.name}?`} go="Revoke" ready={v.reason.trim() !== ''} onClose={() => setDoing(null)} run={() => api.apiClientAction(doing.row.id, 'revoke', { reason: v.reason }).then(finish)}>
          <div className={s.one}>
            <Text>Its keys stop working at once.</Text>
            <TextField label="Reason" required maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
          </div>
        </Ask>
      )}
      {secret && <ShownOnceDialog secret={secret} onClose={() => setSecret(null)} />}
    </div>
  )
}
