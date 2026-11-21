import {
  Button, Dropdown, Field, Input, MessageBar, MessageBarBody, Option, Spinner, Switch, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text,
} from '@fluentui/react-components'
import { AddRegular, EditRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type CashSetupData, type Option as Choice, type OwnVault, type ShiftTemplateRow } from '../api'
import { Ask } from '../form/Ask'
import { money } from '../home/format'
import { PageHead, usePageStyles } from '../list/ListParts'
import { useList } from '../list/useList'
import { END, Panel, useCashStyles } from './CashParts'

type ChainVault = CashSetupData['vaults'][number]
type Doing = { kind: 'shift'; t: ShiftTemplateRow | null } | { kind: 'vault'; v: ChainVault } | { kind: 'own'; v: OwnVault | null } | { kind: 'lend'; v: OwnVault } | null
type Form = { name: string; starts_at: string; ends_at: string; sort: string; active: boolean; float: string; branch: string; holder: string; till: string; user: string; reason: string }

/**
 * Shifts and vaults: the shifts (Morning, Afternoon…), each till's vault per shift with the float the cashier usually
 * issues (cash control), or, with personal vaults, each teller's own vault, who holds it, and lending it for today.
 */
export function CashSetupPage() {
  const page = usePageStyles()
  const c = useCashStyles()
  const [nonce, setNonce] = useState(0)
  const { data, loading, error } = useList(api.cashSetup, '', nonce)
  const [doing, setDoing] = useState<Doing>(null)
  const [done, setDone] = useState('')
  const [form, setForm] = useState<Form>({ name: '', starts_at: '', ends_at: '', sort: '', active: true, float: '', branch: '', holder: '', till: '', user: '', reason: '' })
  const [who, setWho] = useState<{ people: Choice[]; tills: Choice[] } | null>(null)
  const sellersFor = doing?.kind === 'own' ? form.branch : doing?.kind === 'lend' ? doing.v.branch_id : ''

  useEffect(() => {
    if (!sellersFor) return
    setWho(null)
    api.cashSellers(sellersFor).then(setWho).catch(() => setWho({ people: [], tills: [] }))
  }, [sellersFor])

  if (!data) return <div className={page.page}>{error ? <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar> : <Spinner label="Opening shifts and vaults…" />}</div>
  const m = (v: number) => money(v, data.currency, data.exponent)
  const amount = (v: number) => (v / 10 ** data.exponent).toFixed(data.exponent)
  const put = (k: keyof Form, v: string | boolean) => setForm((f) => ({ ...f, [k]: v }))
  const finish = (message: string) => { setDoing(null); setDone(message); setNonce((n) => n + 1) }
  const open = (d: Doing) => {
    setDone('')
    const blank: Form = { name: '', starts_at: '', ends_at: '', sort: '', active: true, float: '', branch: '', holder: '', till: '', user: '', reason: '' }
    if (d?.kind === 'shift') setForm(d.t ? { ...blank, name: d.t.name, starts_at: d.t.starts_at, ends_at: d.t.ends_at, sort: String(d.t.sort), active: d.t.active } : { ...blank, sort: String(data.next_sort) })
    if (d?.kind === 'vault') setForm({ ...blank, float: amount(d.v.standard_float), active: d.v.active })
    if (d?.kind === 'own') setForm(d.v ? { ...blank, name: d.v.name, branch: d.v.branch_id, holder: d.v.holder_id ?? '', till: d.v.till_id ?? '', float: amount(d.v.standard_float), active: d.v.active }
      : { ...blank, name: data.next_vault, branch: data.branches[0]?.id ?? '', float: amount(0) })
    if (d?.kind === 'lend') setForm(blank)
    setDoing(d)
  }
  const pick = (label: string, list: Choice[], value: string, onPick: (v: string) => void, placeholder: string, required = true) => (
    <Field label={label} required={required}>
      <Dropdown placeholder={placeholder} value={list.find((x) => x.id === value)?.label ?? ''} selectedOptions={value ? [value] : []} onOptionSelect={(_, d) => onPick(d.optionValue ?? '')}>
        {list.map((x) => <Option key={x.id} value={x.id}>{x.label}</Option>)}
      </Dropdown>
    </Field>
  )

  return (
    <div className={page.page}>
      <PageHead title="Shifts and vaults" hint="The shifts, each till's vault per shift, or each teller's own vault." classic="/admin/cash-setup" />
      <div className={c.bar}>
        {loading && <Spinner size="tiny" />}
        <span className={c.grow} />
        {data.personal && <Button icon={<AddRegular />} onClick={() => open({ kind: 'own', v: null })}>Add a vault</Button>}
        <Button icon={<AddRegular />} onClick={() => open({ kind: 'shift', t: null })}>Add a shift</Button>
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}

      {data.personal ? (
        <Panel title="Each teller's own vault" hint="A till opens only on a vault the person holds, or one a manager lends them for today. Nobody sells in someone else's name.">
          <Table aria-label="Each teller's own vault" size="small" style={{ minWidth: 760 }}>
            <TableHeader><TableRow>
              <TableHeaderCell>Vault</TableHeaderCell><TableHeaderCell>Held by</TableHeaderCell><TableHeaderCell>Branch · till</TableHeaderCell>
              <TableHeaderCell button={END}>Standard float</TableHeaderCell><TableHeaderCell>Today</TableHeaderCell><TableHeaderCell />
            </TableRow></TableHeader>
            <TableBody>
              {data.own_vaults.length === 0 && <TableRow><TableCell colSpan={6}><Text className={c.muted}>No vaults yet: press Add a vault and give each teller theirs.</Text></TableCell></TableRow>}
              {data.own_vaults.map((v) => (
                <TableRow key={v.id}>
                  <TableCell><Text weight="semibold">{v.name}</Text>{!v.active && <Text className={c.muted}> (not in use)</Text>}</TableCell>
                  <TableCell>{v.holder ?? '—'}</TableCell><TableCell>{v.branch} · {v.till ?? 'any till'}</TableCell>
                  <TableCell className={c.end}>{m(v.standard_float)}</TableCell>
                  <TableCell>{v.in_use && <>Open on {v.in_use}</>}{v.in_use && v.lent && ' · '}{v.lent && <>Lent to {v.lent.to} by {v.lent.by}</>}</TableCell>
                  <TableCell className={c.act}>
                    {data.can_lend && <Button size="small" appearance="subtle" onClick={() => open({ kind: 'lend', v })}>Lend for today</Button>}
                    <Button size="small" icon={<EditRegular />} onClick={() => open({ kind: 'own', v })}>Edit</Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Panel>
      ) : !data.enabled && (
        <MessageBar intent="info" style={{ marginBottom: 12 }}><MessageBarBody>
          Cash control with vaults is off: whoever closes a till counts the drawer. To use vaults, the Cashier and the Income Controller, switch on
          “Cash control with vaults” under System → Settings → Tills and cash. Shifts and vaults can be set up before that. For each teller's own vault instead
          (no Cashier needed), switch on “Each teller sells from their own vault” there.
        </MessageBarBody></MessageBar>
      )}

      <Panel title="Shifts" hint="Each till gets one vault per shift in use. A teller is assigned to the vault of the shift they work.">
        <Table aria-label="Shifts" size="small" style={{ minWidth: 480 }}>
          <TableHeader><TableRow><TableHeaderCell>Shift</TableHeaderCell><TableHeaderCell>Hours</TableHeaderCell><TableHeaderCell>In use</TableHeaderCell><TableHeaderCell /></TableRow></TableHeader>
          <TableBody>
            {data.templates.map((t) => (
              <TableRow key={t.id}>
                <TableCell><Text weight="semibold">{t.name}</Text></TableCell><TableCell>{t.starts_at}–{t.ends_at}</TableCell><TableCell>{t.active ? 'Yes' : 'No'}</TableCell>
                <TableCell className={c.act}><Button size="small" icon={<EditRegular />} onClick={() => open({ kind: 'shift', t })}>Edit</Button></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      {!data.personal && (
        <Panel title="Vaults" hint="One cash tray per till and shift, with the float the cashier usually issues to it.">
          <Table aria-label="Vaults" size="small" style={{ minWidth: 560 }}>
            <TableHeader><TableRow>
              <TableHeaderCell>Vault</TableHeaderCell><TableHeaderCell>Branch</TableHeaderCell><TableHeaderCell button={END}>Standard float</TableHeaderCell>
              <TableHeaderCell>In use</TableHeaderCell><TableHeaderCell />
            </TableRow></TableHeader>
            <TableBody>
              {data.vaults.length === 0 && <TableRow><TableCell colSpan={5}><Text className={c.muted}>No tills yet.</Text></TableCell></TableRow>}
              {data.vaults.map((v) => (
                <TableRow key={v.id}>
                  <TableCell><Text weight="semibold">{v.name}</Text></TableCell><TableCell>{v.branch}</TableCell>
                  <TableCell className={c.end}>{m(v.standard_float)}</TableCell><TableCell>{v.active ? 'Yes' : 'No'}</TableCell>
                  <TableCell className={c.act}><Button size="small" icon={<EditRegular />} onClick={() => open({ kind: 'vault', v })}>Edit</Button></TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Panel>
      )}

      {doing?.kind === 'shift' && (
        <Ask title={doing.t ? `Edit the shift: ${doing.t.name}` : 'Add a shift'} go="Save" ready={form.name.trim() !== '' && form.starts_at !== '' && form.ends_at !== ''}
          onClose={() => setDoing(null)}
          run={async () => finish((await api.saveShiftTemplate({ ...(doing.t ? { id: doing.t.id } : {}), name: form.name, starts_at: form.starts_at, ends_at: form.ends_at,
            sort: Number.parseInt(form.sort, 10) || 0, is_active: form.active })).message)}>
          <div className={c.form}>
            <Field label="Name" required><Input maxLength={40} placeholder="Evening" value={form.name} onChange={(_, d) => put('name', d.value)} /></Field>
            <Field label="Starts" required><Input type="time" value={form.starts_at} onChange={(_, d) => put('starts_at', d.value)} /></Field>
            <Field label="Ends" required><Input type="time" value={form.ends_at} onChange={(_, d) => put('ends_at', d.value)} /></Field>
            <Field label="Order"><Input type="number" step={1} value={form.sort} onChange={(_, d) => put('sort', d.value)} /></Field>
            <Switch label="In use" checked={form.active} onChange={(_, d) => put('active', d.checked)} />
          </div>
        </Ask>
      )}
      {doing?.kind === 'vault' && (
        <Ask title={`Edit the vault: ${doing.v.name}`} go="Save" ready={form.float !== ''} onClose={() => setDoing(null)}
          run={async () => finish((await api.saveVault(doing.v.id, { float: form.float, is_active: form.active })).message)}>
          <div className={c.form}>
            <Field label="Standard float" required hint="Suggested when the cashier issues this vault; it can be changed on the day.">
              <Input type="number" min={0} step="any" value={form.float} onChange={(_, d) => put('float', d.value)} />
            </Field>
            <Switch label="In use" checked={form.active} onChange={(_, d) => put('active', d.checked)} />
          </div>
        </Ask>
      )}
      {doing?.kind === 'own' && (
        <Ask title={doing.v ? `Edit the vault: ${doing.v.name}` : 'Add a vault'} go="Save"
          ready={form.name.trim() !== '' && form.branch !== '' && form.holder !== '' && form.float !== '' && (!data.till_required || form.till !== '')} onClose={() => setDoing(null)}
          run={async () => finish((await api.saveOwnVault({ ...(doing.v ? { id: doing.v.id } : {}), name: form.name, branch: form.branch, holder: form.holder, till: form.till,
            float: form.float, is_active: form.active })).message)}>
          <div className={c.form}>
            <Field label="Name" required><Input maxLength={80} placeholder="Vault 1" value={form.name} onChange={(_, d) => put('name', d.value)} /></Field>
            {pick('Branch', data.branches, form.branch, (v) => setForm((f) => ({ ...f, branch: v, holder: '', till: '' })), 'Choose the branch')}
            {who === null && form.branch ? <Spinner size="tiny" /> : <>
              {pick('Teller who holds it', who?.people ?? [], form.holder, (v) => put('holder', v), 'Choose the teller')}
              <Text size={200} className={c.muted}>Only this person sees and opens this vault on a till. One vault per person.</Text>
              {pick('Till', [...(data.till_required ? [] : [{ id: '', label: 'Any till of the branch (removable tray)' }]), ...(who?.tills ?? [])], form.till,
                (v) => put('till', v), 'Any till of the branch (removable tray)', data.till_required)}
            </>}
            <Field label="Standard float" required hint="What is in the tray when the teller opens; they confirm it by counting.">
              <Input type="number" min={0} step="any" value={form.float} onChange={(_, d) => put('float', d.value)} />
            </Field>
            <Switch label="In use" checked={form.active} onChange={(_, d) => put('active', d.checked)} />
          </div>
        </Ask>
      )}
      {doing?.kind === 'lend' && (
        <Ask title={`Lend ${doing.v.name} for today`} go="Lend for today" ready={form.user !== '' && form.reason.trim().length >= 3} onClose={() => setDoing(null)}
          run={async () => finish((await api.lendVault(doing.v.id, { user: form.user, reason: form.reason })).message)}>
          <div className={c.form}>
            <Text>Its holder cannot open it today; the person you choose can, until midnight. Recorded in the audit log.</Text>
            {who === null ? <Spinner size="tiny" />
              : pick('Lend to', who.people.filter((p) => p.id !== doing.v.holder_id), form.user, (v) => put('user', v), 'Choose who')}
            <Field label="Reason" required><Input maxLength={255} placeholder="e.g. Ama is on leave; cover for the afternoon" value={form.reason} onChange={(_, d) => put('reason', d.value)} /></Field>
          </div>
        </Ask>
      )}
    </div>
  )
}
