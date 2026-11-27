import { Button, MessageBar, MessageBarBody, Spinner, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow, Text, tokens } from '@fluentui/react-components'
import { ArchiveRegular, ArrowLeftRegular, ArrowSyncRegular, ArrowUndoRegular, ClipboardTaskRegular, LinkRegular, SaveRegular } from '@fluentui/react-icons'
import { useCallback, useEffect, useState } from 'react'
import { api, type SensorDetail, type SensorValues } from '../api'
import { Ask } from '../form/Ask'
import { Choose, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { usePageStyles } from '../list/ListParts'
import { navigate } from '../nav'
import { Fact, Panel, ToneBadge, useProcStyles } from './ProcurementParts'
import { AlarmTable, DayChart } from './SensorParts'
import { SaveBar } from '../form/SaveBar'

type Dialog = 'check' | 'address' | 'new_address' | 'archive' | 'restore'

/**
 * One sensor (part 32), as the current page: the reading now, its state, last reading and battery, the last 24 hours
 * drawn; what it watches, where, the equipment it opens breakdown work orders for, its range, when it alarms, and how it
 * reaches ZionEX. Record a check, Sensor address and New address (direct sensors), its alarms and checks, archive and
 * restore.
 */
export function SensorPage({ id, onTitle }: { id: string | null; onTitle: (t: string) => void }) {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const [d, setD] = useState<SensorDetail | null>(null)
  const [v, setV] = useState<SensorValues | null>(null)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState(false)
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const [check, setCheck] = useState({ seen: '', ok: true, note: '' })
  const [why, setWhy] = useState('')
  const [address, setAddress] = useState<string | null>(null)
  const load = useCallback(() => {
    api.sensor(id).then((x) => { setD(x); setV(x.values); onTitle(x.id ? x.values.name : 'New sensor') }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [id, onTitle])
  useEffect(load, [load])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d || !v) return <div className={page.page}><Spinner label="Opening the sensor…" /></div>
  const put = <K extends keyof SensorValues>(k: K) => (x: SensorValues[K]) => setV((o) => (o ? { ...o, [k]: x } : o))
  const a = d.actions
  const edit = a.edit
  const ranged = !['power', 'door'].includes(v.kind)
  const back = () => (history.length > 1 ? history.back() : navigate('/office/sensors'))
  const fail = (e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const after = (m: string) => { setDialog(null); setError(''); setDone(m); load() }
  const kind = (k: string) => {
    const def = d.kinds.find((x) => x.id === k)
    setV({ ...v, kind: k, ...(def ? { min_temp: def.min_temp, max_temp: def.max_temp, min_humidity: def.min_humidity, max_humidity: def.max_humidity } : {}) })
  }
  const save = () => {
    setBusy(true)
    setError('')
    api.saveSensor(d.id, v).then((x) => { if (d.id) after(x.message); else navigate(`/office/sensors/${x.id}`, true) }).catch(fail).finally(() => setBusy(false))
  }
  const ready = v.name.trim() !== '' && !!v.branch_id && (v.connection === 'direct' || (!!v.connection_id && v.external_id.trim() !== ''))
  return (
    <div className={page.page}>
      <Button appearance="transparent" icon={<ArrowLeftRegular />} onClick={back}>Sensors</Button>
      <div className={s.head}>
        <h1 className={s.title}>{d.id && <span style={{ fontFamily: tokens.fontFamilyMonospace }}>{d.number}</span>}{d.id ? d.values.name : 'New sensor'}
          {d.id && d.status_label && <ToneBadge tone={d.tone ?? 'subtle'} label={d.status_label} />}</h1>
        {d.id && <div className={s.actions}>
          {a.check && <Button icon={<ClipboardTaskRegular />} onClick={() => { setCheck({ seen: '', ok: true, note: '' }); setDialog('check') }}>Record a check</Button>}
          {a.address && <Button icon={<LinkRegular />} onClick={() => { setAddress(null); api.sensorAddress(d.id!).then((x) => { setAddress(x.url); setDialog('address') }).catch(fail) }}>Sensor address</Button>}
          {a.new_address && <Button icon={<ArrowSyncRegular />} onClick={() => setDialog('new_address')}>New address</Button>}
          {d.archive?.can_archive && <Button icon={<ArchiveRegular />} onClick={() => { setWhy(''); setDialog('archive') }}>Archive</Button>}
          {d.archive?.can_restore && <Button icon={<ArrowUndoRegular />} onClick={() => setDialog('restore')}>Restore</Button>}
        </div>}
      </div>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      {d.archive?.archived && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>{d.archive.archived_note}</MessageBarBody></MessageBar>}

      {d.id && (
        <Panel title="Now">
          <div className={s.facts} style={{ marginBottom: 10 }}>
            <Fact label="Reading"><Text weight="semibold" size={500}>{d.reading}</Text><Text size={200} block className={s.muted}>Range {d.range}</Text></Fact>
            <Fact label="State">{d.status_label}</Fact>
            <Fact label="Last reading">{d.last ? new Date(d.last).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'None yet'}
              {d.last_ago && <Text size={200} block className={s.muted}>{d.last_ago}</Text>}</Fact>
            <Fact label="Battery">{d.battery !== null && d.battery !== undefined ? `${d.battery} %` : '—'}</Fact>
          </div>
          {d.temperature && <><Text size={200} block className={s.muted}>Last 24 hours</Text><DayChart rows={d.chart ?? []} min={d.min ?? null} max={d.max ?? null} /></>}
        </Panel>
      )}

      <Panel title="Sensor">
        <div className={f.grid}>
          <TextField label="Name" required className={f.spanAll} maxLength={255} placeholder="e.g. Vaccine fridge, dispensary" disabled={!edit} value={v.name} onChange={put('name')} />
          <Choose label="It watches" required disabled={!edit} options={d.kinds.map((k) => ({ id: k.id, label: k.label }))} value={v.kind} onChange={kind} />
          <Choose label="Branch" required disabled={!edit} options={d.branches} value={v.branch_id} onChange={put('branch_id')} />
          <Choose label="Equipment (for breakdown work orders)" disabled={!edit} options={d.assets} value={v.asset_id} onChange={put('asset_id')} clearable />
        </div>
      </Panel>
      {ranged && (
        <Panel title="Range">
          <div className={f.grid}>
            <TextField label="Lowest °C" type="number" step="any" disabled={!edit} value={v.min_temp} onChange={put('min_temp')} />
            <TextField label="Highest °C" type="number" step="any" disabled={!edit} value={v.max_temp} onChange={put('max_temp')} />
            {v.kind === 'room' && <TextField label="Lowest humidity %" type="number" min={0} max={100} disabled={!edit} value={v.min_humidity} onChange={put('min_humidity')} />}
            {v.kind === 'room' && <TextField label="Highest humidity %" type="number" min={0} max={100} disabled={!edit} value={v.max_humidity} onChange={put('max_humidity')} />}
          </div>
        </Panel>
      )}
      <Panel title="Alarms">
        <div className={f.grid}>
          <TextField label="Alarm after this many minutes out of range" type="number" min={0} max={240} disabled={!edit} value={v.delay_minutes} onChange={put('delay_minutes')}
            hint="So opening the door to take something out is not an alarm. 10 suits most fridges." />
          <TextField label="Alarm when no reading for (minutes)" type="number" min={5} max={1440} disabled={!edit} value={v.silent_minutes} onChange={put('silent_minutes')}
            hint="A silent sensor (battery, Wi-Fi, power) could hide a warm fridge." />
        </div>
      </Panel>
      <Panel title="How it reaches ZionEX">
        <div className={f.grid}>
          <Choose label="Connection" required disabled={!edit} options={d.connections} value={v.connection} onChange={put('connection')} />
          {v.connection !== 'direct' && <Choose label="Account" required disabled={!edit} options={d.accounts.filter((x) => x.provider === v.connection)} value={v.connection_id} onChange={put('connection_id')} />}
          {v.connection !== 'direct' && <TextField label="The sensor’s id in that cloud" required maxLength={120} disabled={!edit} value={v.external_id} onChange={put('external_id')} />}
        </div>
        {v.connection === 'direct' && !d.id && <Text size={200} block className={s.muted}>After saving, this page shows the address the sensor posts its readings to.</Text>}
      </Panel>
      {edit && (
        <SaveBar value={v}>
          <Button onClick={back}>Cancel</Button>
          <Button appearance="primary" icon={<SaveRegular />} disabled={busy || !ready} onClick={save}>{busy ? 'Saving…' : d.id ? 'Save' : 'Add sensor'}</Button>
        </SaveBar>
      )}

      {d.id && <Panel title="Sensor alarms"><AlarmTable rows={d.alarms ?? []} withSensor={false} onDone={after} /></Panel>}
      {d.id && d.temperature && (
        <Panel title="Checks" about="What the fridge display shows, signed with your name (most pharmacies check morning and afternoon).">
          {(d.checks ?? []).length === 0 ? <Text className={s.muted}>No checks yet.</Text> : (
            <Table size="small" aria-label="Checks" style={{ minWidth: 520 }}>
              <TableHeader><TableRow><TableHeaderCell>When</TableHeaderCell><TableHeaderCell>By</TableHeaderCell><TableHeaderCell>Display showed</TableHeaderCell>
                <TableHeaderCell>All well</TableHeaderCell><TableHeaderCell>Note</TableHeaderCell></TableRow></TableHeader>
              <TableBody>{(d.checks ?? []).map((c) => (
                <TableRow key={c.id}><TableCell>{c.when}</TableCell><TableCell>{c.by}</TableCell><TableCell>{c.seen ?? '—'}</TableCell>
                  <TableCell><ToneBadge tone={c.ok ? 'success' : 'danger'} label={c.ok ? 'Yes' : 'No'} /></TableCell><TableCell>{c.note ?? '—'}</TableCell></TableRow>
              ))}</TableBody>
            </Table>
          )}
        </Panel>
      )}

      {dialog === 'check' && (
        <Ask title="Record a check" go="Sign" onClose={() => setDialog(null)} ready
          run={() => api.sensorAction(d.id!, 'check', { seen: check.seen === '' ? null : check.seen, ok: check.ok, note: check.note }).then((x) => after(x.message))}>
          <div className={s.one}>
            <TextField label="The display shows (°C)" type="number" step="any" value={check.seen} onChange={(x) => setCheck({ ...check, seen: x })} />
            <Toggle label="All is well (door shut, nothing frozen or warm)" value={check.ok} onChange={(x) => setCheck({ ...check, ok: x })} />
            <TextField label="Note" maxLength={255} value={check.note} onChange={(x) => setCheck({ ...check, note: x })} />
          </div>
        </Ask>
      )}
      {dialog === 'address' && address && (
        <Ask title="Sensor address" go="Close" ready onClose={() => setDialog(null)} run={async () => setDialog(null)}>
          <div className={s.one}>
            <Text>The device sends its readings to this address every few minutes. Keep it private: anyone who has it can send readings.</Text>
            <pre style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', background: tokens.colorNeutralBackground3, padding: 10, borderRadius: 6, margin: 0 }}>{address}</pre>
            <Text><b>JSON (most devices, The Things Network)</b>: POST <code>{'{"temperature": 4.6, "humidity": 52, "battery": 88}'}</code> — also understood: <code>temp</code>, <code>hum</code>,
              <code> power</code> (on/off), <code>door</code> (open/closed), Tasmota's <code>StatusSNS</code> and The Things Network's <code>decoded_payload</code>.</Text>
            <Text><b>A link (Shelly H&amp;T and devices that can only call a web address)</b>: <code>{address}?temp=4.6&amp;hum=52</code></Text>
            <Text>The sensor shows <b>No reading yet</b> until the first reading arrives.</Text>
          </div>
        </Ask>
      )}
      {dialog === 'new_address' && (
        <Ask title="Make a new address?" go="New address" ready onClose={() => setDialog(null)} run={() => api.sensorAction(d.id!, 'new_address').then((x) => after(x.message))}>
          <Text>The old address stops working at once: set the new one on the device. Use this when a device is replaced or the address was shared.</Text>
        </Ask>
      )}
      {dialog === 'archive' && (
        <Ask title={`Archive ${d.values.name}?`} go="Archive" ready={why.trim().length >= 3 && (d.archive?.archive_blockers.length ?? 0) === 0} onClose={() => setDialog(null)}
          run={() => api.archiveSensor(d.id!, false, why).then((x) => after(x.message))}>
          <div className={s.one}>
            {(d.archive?.archive_blockers.length ?? 0) > 0 && <Text>It cannot be archived yet. {d.archive!.archive_blockers.join(' ')}</Text>}
            <Text>It stops being watched and leaves the lists. Its readings, alarms and checks stay for inspections.</Text>
            <TextField label="Why" required maxLength={255} value={why} onChange={setWhy} />
          </div>
        </Ask>
      )}
      {dialog === 'restore' && (
        <Ask title={`Restore ${d.values.name}?`} go="Restore" ready onClose={() => setDialog(null)} run={() => api.archiveSensor(d.id!, true, '').then((x) => after(x.message))}>
          <Text>It comes back into the lists and is watched again.</Text>
        </Ask>
      )}
    </div>
  )
}
