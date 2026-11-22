import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow,
  Text } from '@fluentui/react-components'
import { AddRegular, MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CrmCampaignList } from '../api'
import { Ask } from '../form/Ask'
import { PageHead, usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { END, Panel, ToneBadge, useProcStyles } from './ProcurementParts'

type Row = CrmCampaignList['rows'][number]

/**
 * CRM → Campaigns (part 25): one message to many customers (and leads) by SMS, WhatsApp or e-mail — only to those who
 * agreed. Each campaign with its status, how many it went to and how many arrived. Send now (or Schedule), Cancel, Copy;
 * a draft or scheduled one opens to change it.
 */
export function CrmCampaignsPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const [d, setD] = useState<CrmCampaignList | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [doing, setDoing] = useState<{ row: Row; kind: 'send' | 'cancel' } | null>(null)
  useEffect(() => { api.crmCampaigns().then(setD).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening campaigns…" /></div>
  const act = (id: string, kind: 'send' | 'cancel' | 'copy') => api.crmCampaignAction(id, kind).then((x) => {
    if (x.open) { navigate(x.open); return }
    if (x.campaigns) setD(x.campaigns)
    setDoing(null)
    setDone(x.message)
  })
  return (
    <div className={page.page}>
      <PageHead title="Campaigns" hint="One message to many customers by SMS, WhatsApp or e-mail — only to those who agreed. Every message is in Messages, with whether it arrived." classic="/admin/crm-campaigns" />
      <div className={s.bar}><span className={s.grow} />{d.can_create && <Button appearance="primary" icon={<AddRegular />} onClick={() => navigate('/office/crm/campaigns/new')}>New campaign</Button>}</div>
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Campaigns">
        <Table size="small" aria-label="Campaigns list" style={{ minWidth: 720 }}>
          <TableHeader><TableRow><TableHeaderCell>No.</TableHeaderCell><TableHeaderCell>Campaign</TableHeaderCell><TableHeaderCell>By</TableHeaderCell><TableHeaderCell>Status</TableHeaderCell>
            <TableHeaderCell button={END}>Sent to</TableHeaderCell><TableHeaderCell button={END}>Delivered</TableHeaderCell><TableHeaderCell>Sent</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {d.rows.length === 0 && <TableRow><TableCell colSpan={8}><Text className={s.muted}>No campaigns yet. Tell customers about offers, new stock or reminders — only those who agreed to hear from you.</Text></TableCell></TableRow>}
            {d.rows.map((r) => (
              <TableRow key={r.id} style={r.can_edit ? { cursor: 'pointer' } : undefined} onClick={() => r.can_edit && navigate(`/office/crm/campaigns/${r.id}`)}>
                <TableCell><Text font="monospace" size={200} className={s.muted}>{r.number}</Text></TableCell>
                <TableCell><Text weight="semibold">{r.name}</Text><Text size={200} block className={s.muted}>{r.body}</Text></TableCell>
                <TableCell>{r.channel}</TableCell>
                <TableCell><ToneBadge tone={r.tone} label={r.status_label} />{r.status === 'scheduled' && r.send_at && <Text size={200} block className={s.muted}>{r.send_at}</Text>}</TableCell>
                <TableCell className={s.end}>{r.sent}{r.skipped > 0 && <Text size={200} block className={s.muted}>{r.skipped} left out</Text>}</TableCell>
                <TableCell className={s.end}>{r.delivered ?? ''}{!!r.failed && <Text size={200} block style={{ color: 'var(--zx-bad)' }}>{r.failed} failed</Text>}</TableCell>
                <TableCell>{r.sent_at ?? '—'}</TableCell>
                <TableCell onClick={(e) => e.stopPropagation()}>
                  {(r.can_send || r.can_cancel || d.can_create) && (
                    <Menu><MenuTrigger disableButtonEnhancement><Button appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`More for ${r.name}`} /></MenuTrigger>
                      <MenuPopover><MenuList>
                        {r.can_send && <MenuItem onClick={() => { setDone(''); setDoing({ row: r, kind: 'send' }) }}>{r.later ? 'Schedule' : 'Send now'}</MenuItem>}
                        {r.can_cancel && <MenuItem onClick={() => { setDone(''); setDoing({ row: r, kind: 'cancel' }) }}>Cancel</MenuItem>}
                        {d.can_create && <MenuItem onClick={() => act(r.id, 'copy').catch((e: unknown) => setDone(e instanceof Error ? e.message : String(e)))}>Copy</MenuItem>}
                      </MenuList></MenuPopover></Menu>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>
      {doing?.kind === 'send' && (
        <Ask title={`Send “${doing.row.name}”?`} go={doing.row.later ? 'Schedule' : 'Send now'} ready onClose={() => setDoing(null)} run={() => act(doing.row.id, 'send')}>
          <Text>It goes to {doing.row.reach ?? 0} people by {doing.row.channel}{doing.row.later && doing.row.send_at ? ` on ${doing.row.send_at}` : ' now'}. This cannot be called back.</Text>
        </Ask>
      )}
      {doing?.kind === 'cancel' && (
        <Ask title={`Cancel “${doing.row.name}”?`} go="Cancel the campaign" ready onClose={() => setDoing(null)} run={() => act(doing.row.id, 'cancel')}>
          <Text>It is kept, with anything already sent, but nothing more goes out.</Text>
        </Ask>
      )}
    </div>
  )
}
