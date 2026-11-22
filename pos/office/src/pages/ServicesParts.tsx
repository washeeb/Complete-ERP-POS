import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, Text } from '@fluentui/react-components'
import { MoreHorizontalRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type ApptRow, type BookingForm, type Option } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField } from '../form/Fields'
import { SearchPicker } from '../form/SearchPicker'
import { useProcStyles } from './ProcurementParts'

/**
 * Book appointment (part 26): branch, service, who does it (staff of that branch who take appointments), when, the
 * customer (or a name), a phone for the SMS confirmation and reminder, and a note — through AppointmentService::book.
 */
export function BookAsk({ branch, at, onClose, onDone }: { branch?: string; at?: string; onClose: () => void; onDone: (message: string) => void }) {
  const s = useProcStyles()
  const [f, setF] = useState<BookingForm | null>(null)
  const [staff, setStaff] = useState<Option[]>([])
  const [v, setV] = useState({ branch_id: branch ?? '', product_id: '', employee_id: '', starts_at: at ?? '', customer_id: '', customer: null as string | null, customer_name: '', phone: '', note: '' })
  useEffect(() => { api.bookingForm(branch).then((x) => { setF(x); setStaff(x.staff); setV((o) => ({ ...o, branch_id: o.branch_id || x.branch })) }).catch(() => undefined) }, [branch])
  const pickBranch = (b: string) => {
    setV({ ...v, branch_id: b, employee_id: '' })
    api.serviceStaff(b).then((x) => setStaff(x.staff)).catch(() => setStaff([]))
  }
  return (
    <Ask title="Book appointment" go="Book" width={640} ready={!!v.branch_id && !!v.product_id && !!v.employee_id && !!v.starts_at} onClose={onClose}
      run={() => api.bookAppointment({ branch_id: v.branch_id, product_id: v.product_id, employee_id: v.employee_id, starts_at: v.starts_at, customer_id: v.customer_id || null,
        customer_name: v.customer_name || null, phone: v.phone || null, note: v.note || null }).then((x) => onDone(x.message))}>
      {!f ? <Text>Opening…</Text> : (
        <div className={s.one}>
          <Choose label="Branch" required options={f.branches} value={v.branch_id} onChange={pickBranch} />
          <Choose label="Service" required options={f.services} value={v.product_id} onChange={(x) => setV({ ...v, product_id: x })} />
          <Choose label="With" required options={staff} value={v.employee_id} onChange={(x) => setV({ ...v, employee_id: x })}
            hint={v.branch_id && staff.length === 0 ? 'Nobody here takes appointments: switch on “Takes appointments” on the employee.' : undefined} />
          <TextField label="Starts" required type="datetime-local" value={v.starts_at} onChange={(x) => setV({ ...v, starts_at: x })} />
          <SearchPicker label="Customer (if known)" value={v.customer_id} text={v.customer} search={api.serviceCustomers} placeholder="Type a name or phone"
            onChange={(x) => setV({ ...v, customer_id: x?.id ?? '', customer: x?.label ?? null, phone: v.phone || x?.detail || '' })} />
          <TextField label="Name (if not a customer)" maxLength={120} value={v.customer_name} onChange={(x) => setV({ ...v, customer_name: x })} />
          <TextField label="Phone" maxLength={20} hint="For the SMS confirmation and reminder." value={v.phone} onChange={(x) => setV({ ...v, phone: x })} />
          <TextField label="Note" maxLength={255} value={v.note} onChange={(x) => setV({ ...v, note: x })} />
        </div>
      )}
    </Ask>
  )
}

/** Arrived, No-show, Change (time and who) and Cancel (with the reason) on an appointment, as the current list's buttons. */
export function ApptMenu({ a, onDone }: { a: ApptRow; onDone: (message: string) => void }) {
  const s = useProcStyles()
  const [doing, setDoing] = useState<'no_show' | 'change' | 'cancel' | null>(null)
  const [v, setV] = useState({ starts_at: a.starts_iso ?? '', employee_id: a.employee_id, reason: '' })
  const [staff, setStaff] = useState<Option[]>([])
  if (!a.can_arrive && !a.can_no_show && !a.can_change && !a.can_cancel) return null
  const run = (action: 'arrived' | 'no_show' | 'change' | 'cancel', body: Record<string, unknown> = {}) => api.appointmentAction(a.id, action, body).then((x) => { setDoing(null); onDone(x.message) })
  return (
    <>
      <Menu><MenuTrigger disableButtonEnhancement><Button size="small" appearance="subtle" icon={<MoreHorizontalRegular />} aria-label={`Act on ${a.number}`} onClick={(e) => e.stopPropagation()} /></MenuTrigger>
        <MenuPopover><MenuList>
          {a.can_arrive && <MenuItem onClick={() => run('arrived').catch(() => undefined)}>Arrived</MenuItem>}
          {a.can_no_show && <MenuItem onClick={() => setDoing('no_show')}>No-show</MenuItem>}
          {a.can_change && <MenuItem onClick={() => { api.serviceStaff(a.branch_id).then((x) => setStaff(x.staff)).catch(() => undefined); setDoing('change') }}>Change</MenuItem>}
          {a.can_cancel && <MenuItem onClick={() => setDoing('cancel')}>Cancel</MenuItem>}
        </MenuList></MenuPopover></Menu>
      {doing === 'no_show' && <Ask title="Mark as no-show?" go="No-show" ready onClose={() => setDoing(null)} run={() => run('no_show')}><Text>{a.customer} did not come for {a.service}.</Text></Ask>}
      {doing === 'change' && (
        <Ask title={`Change ${a.number}`} go="Change" ready={!!v.starts_at && !!v.employee_id} onClose={() => setDoing(null)} run={() => run('change', { starts_at: v.starts_at, employee_id: v.employee_id })}>
          <div className={s.one}>
            <TextField label="Starts" required type="datetime-local" value={v.starts_at} onChange={(x) => setV({ ...v, starts_at: x })} />
            <Choose label="With" required options={staff} value={v.employee_id} onChange={(x) => setV({ ...v, employee_id: x })} />
          </div>
        </Ask>
      )}
      {doing === 'cancel' && (
        <Ask title={`Cancel ${a.number}?`} go="Cancel the appointment" ready={v.reason.trim() !== ''} onClose={() => setDoing(null)} run={() => run('cancel', { reason: v.reason })}>
          <TextField label="Reason" required maxLength={255} value={v.reason} onChange={(x) => setV({ ...v, reason: x })} />
        </Ask>
      )}
    </>
  )
}
