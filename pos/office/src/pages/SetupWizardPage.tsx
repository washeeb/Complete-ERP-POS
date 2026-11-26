import { Button, Checkbox, Field, makeStyles, MessageBar, MessageBarBody, Radio, RadioGroup, Spinner, Tab, TabList, Text, tokens } from '@fluentui/react-components'
import { AddRegular, ArrowLeftRegular, ArrowRightRegular, CheckmarkRegular, DeleteRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type SetupBranch, type SetupValues, type SetupWizardData } from '../api'
import { Choose, TextField, Toggle, useFieldStyles } from '../form/Fields'
import { PageHead, usePageStyles } from '../list/ListParts'
import { Panel, useProcStyles } from './ProcurementParts'

const STEPS = ['Deployment', 'Company', 'Your brand', 'Branches & modules'] as const

const useStyles = makeStyles({
  file: { display: 'grid', gap: '4px' },
  colour: { display: 'flex', alignItems: 'center', gap: '8px' },
  swatch: { width: '40px', height: '32px', padding: 0, border: `1px solid ${tokens.colorNeutralStroke1}`, borderRadius: tokens.borderRadiusMedium, background: 'none' },
  branch: { borderTop: `1px solid ${tokens.colorNeutralStroke2}`, padding: '12px 0', display: 'grid', gap: '8px' },
  modules: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: '4px 12px' },
})

/**
 * Set up your company (part 38), as the current first-run wizard: how the system runs, the company and its legal entity,
 * the brand (logo, icon, colour, logo on receipts) and the branches with their tills and modules (only those the licence
 * covers). Finish setup creates them; the brand is saved after, and a logo problem never undoes the setup. Until it is
 * done, the new back office opens here first.
 */
export function SetupWizardPage() {
  const page = usePageStyles()
  const s = useProcStyles()
  const f = useFieldStyles()
  const st = useStyles()
  const [d, setD] = useState<SetupWizardData | null>(null)
  const [v, setV] = useState<SetupValues | null>(null)
  const [logo, setLogo] = useState<File | null>(null)
  const [icon, setIcon] = useState<File | null>(null)
  const [step, setStep] = useState(0)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<{ open: string; brand_problem?: string } | null>(null)
  useEffect(() => {
    api.setupWizard().then((x) => { setD(x); if (x.values) setV(x.values) }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e)))
  }, [])

  if (error && !d) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!d) return <div className={page.page}><Spinner label="Opening the setup…" /></div>
  if (d.done || result) {
    return (
      <div className={page.page}>
        <PageHead title="Set up your company" hint="First-run setup." classic="/admin/setup-wizard" />
        <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{result ? 'Company set up.' : 'Your company is already set up. Change branches and modules from their own pages.'}</MessageBarBody></MessageBar>
        {result?.brand_problem && <MessageBar intent="warning" style={{ marginBottom: 12 }}><MessageBarBody>{result.brand_problem}</MessageBarBody></MessageBar>}
        <Button appearance="primary" onClick={() => location.assign(result?.open ?? '/office/')}>Open the back office</Button>
      </div>
    )
  }
  if (!v) return null
  const company = (k: keyof SetupValues['company']) => (x: string) => setV({ ...v, company: { ...v.company, [k]: x } })
  const entity = (k: keyof SetupValues['legal_entity']) => (x: string) => setV({ ...v, legal_entity: { ...v.legal_entity, [k]: x } })
  const branch = (i: number, p: Partial<SetupBranch>) => setV({ ...v, branches: v.branches.map((b, j) => (j === i ? { ...b, ...p } : b)) })
  const hq = v.branches.filter((b) => b.is_hq).length
  const stepOk = [
    !!v.deployment_mode,
    v.company.name.trim() !== '' && !!v.company.country_code && !!v.company.currency_code && !!v.company.timezone,
    v.brand.color === '' || /^#[0-9A-Fa-f]{6}$/.test(v.brand.color),
    v.branches.length > 0 && hq === 1 && v.branches.every((b) => b.name.trim() !== '' && b.tills !== '' && Number(b.tills) >= 0 && Number(b.tills) <= 50),
  ]
  const finish = () => {
    setBusy(true)
    setError('')
    api.finishSetup({ ...v, branches: v.branches.map((b) => ({ ...b, tills: String(Number(b.tills)) })) }, logo, icon)
      .then(setResult).catch((e: unknown) => { setError(e instanceof Error ? e.message : String(e)); window.scrollTo({ top: 0, behavior: 'smooth' }) }).finally(() => setBusy(false))
  }
  const file = (label: string, hint: string, value: File | null, set: (x: File | null) => void) => (
    <Field label={label} hint={hint} className={st.file}>
      <input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" aria-label={label}
        onChange={(e) => { const x = e.target.files?.[0] ?? null; if (x && x.size > 2 * 1024 * 1024) { setError(`${label}: the image is larger than 2 MB.`); return } set(x) }} />
      {value && <Text size={200}>{value.name}</Text>}
    </Field>
  )
  return (
    <div className={page.page}>
      <PageHead title="Set up your company" hint="A few questions before the first sale. Everything here can be changed later." classic="/admin/setup-wizard" />
      <TabList selectedValue={String(step)} onTabSelect={(_, x) => { const n = Number(x.value); if (stepOk.slice(0, n).every(Boolean)) setStep(n) }} aria-label="Steps" style={{ marginBottom: 12, flexWrap: 'wrap' }}>
        {STEPS.map((t, i) => <Tab key={t} value={String(i)}>{`${i + 1}. ${t}`}</Tab>)}
      </TabList>
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      {step === 0 && (
        <Panel title="Deployment">
          <Field label="How will this system run?" required hint="You can add branch servers or move to the cloud later; this records the starting layout.">
            <RadioGroup value={v.deployment_mode} onChange={(_, x) => setV({ ...v, deployment_mode: x.value })}>
              {(d.modes ?? []).map((m) => <Radio key={m.id} value={m.id} label={m.label} />)}
            </RadioGroup>
          </Field>
        </Panel>
      )}
      {step === 1 && (
        <>
          <Panel title="Company">
            <div className={f.grid}>
              <TextField label="Company / trading name" required maxLength={120} value={v.company.name} onChange={company('name')} />
              <Choose label="Country" required options={d.countries ?? []} value={v.company.country_code} onChange={company('country_code')} />
              <Choose label="Base currency" required options={d.currencies ?? []} value={v.company.currency_code} onChange={company('currency_code')} />
              <Choose label="Time zone" required options={(d.timezones ?? []).map((z) => ({ id: z, label: z }))} value={v.company.timezone} onChange={company('timezone')} />
            </div>
          </Panel>
          <Panel title="Legal entity" about="Optional — needed for VAT invoices; can be added later.">
            <div className={f.grid}>
              <TextField label="Registered name" maxLength={150} value={v.legal_entity.name} onChange={entity('name')} />
              <TextField label="TIN" maxLength={30} value={v.legal_entity.tin} onChange={entity('tin')} />
              <TextField label="VAT number" maxLength={30} value={v.legal_entity.vat_no} onChange={entity('vat_no')} />
            </div>
          </Panel>
        </>
      )}
      {step === 2 && (
        <Panel title="Your brand" about="Your logo and colour appear on the back office, the tills, receipts, invoices and your online shop. Optional — you can add or change them later under Company → Your brand.">
          <div className={f.grid}>
            {file('Logo', 'PNG with a transparent background, or SVG. Up to 2 MB.', logo, setLogo)}
            {file('Square icon (optional)', 'Your initials are used until you add one.', icon, setIcon)}
            <Field label="Brand colour" hint="Leave empty to take it from your logo." validationMessage={stepOk[2] ? undefined : 'A colour is # and six letters or digits, e.g. #B5471B.'}>
              <div className={st.colour}>
                <input type="color" className={st.swatch} aria-label="Pick the brand colour" value={/^#[0-9A-Fa-f]{6}$/.test(v.brand.color) ? v.brand.color : '#b5471b'}
                  onChange={(e) => setV({ ...v, brand: { ...v.brand, color: e.target.value.toUpperCase() } })} />
                <TextField label="Colour code" placeholder="#B5471B" maxLength={7} value={v.brand.color} onChange={(x) => setV({ ...v, brand: { ...v.brand, color: x.trim() } })} />
              </div>
            </Field>
            <Toggle label="Print the logo on till receipts" value={v.brand.receipt_logo} onChange={(x) => setV({ ...v, brand: { ...v.brand, receipt_logo: x } })} />
          </div>
        </Panel>
      )}
      {step === 3 && (
        <Panel title="Branches & modules" about="Each branch gets a main warehouse and the tills you ask for. Industry modules are chosen per branch; ones not released yet are reserved and switch on when they ship — they are never shown as working before then.">
          {hq !== 1 && <MessageBar intent="warning" style={{ marginBottom: 8 }}><MessageBarBody>Mark exactly one branch as head office.</MessageBarBody></MessageBar>}
          {v.branches.map((b, i) => (
            <div key={i} className={st.branch} role="group" aria-label={`Branch ${i + 1}`}>
              <div className={f.grid}>
                <TextField label="Name" required hint="Its code (used in receipt numbers) is made from the name." maxLength={120} value={b.name} onChange={(x) => branch(i, { name: x })} />
                <TextField label="Tills" required type="number" min={0} max={50} step="1" value={b.tills} onChange={(x) => branch(i, { tills: x })} />
                <Toggle label="Head office (exactly one)" value={b.is_hq} onChange={(x) => branch(i, { is_hq: x })} />
              </div>
              <Text weight="semibold">Industry & optional modules</Text>
              <div className={st.modules}>
                {(d.modules ?? []).map((m) => (
                  <Checkbox key={m.id} checked={b.modules.includes(m.id)} label={<>{m.label}<Text size={200} block className={s.muted}>{m.detail}</Text></>}
                    onChange={(_, x) => branch(i, { modules: x.checked ? [...b.modules, m.id] : b.modules.filter((k) => k !== m.id) })} />
                ))}
              </div>
              {v.branches.length > 1 && <div><Button appearance="subtle" icon={<DeleteRegular />} onClick={() => setV({ ...v, branches: v.branches.filter((_, j) => j !== i) })}>Remove branch</Button></div>}
            </div>
          ))}
          {v.branches.length < 50 && <Button icon={<AddRegular />} onClick={() => setV({ ...v, branches: [...v.branches, { name: '', is_hq: false, tills: '1', modules: [] }] })}>Add branch</Button>}
        </Panel>
      )}

      <div className={s.foot}>
        {step > 0 && <Button icon={<ArrowLeftRegular />} onClick={() => setStep(step - 1)}>Back</Button>}
        {step < STEPS.length - 1
          ? <Button appearance="primary" icon={<ArrowRightRegular />} iconPosition="after" disabled={!stepOk[step]} onClick={() => setStep(step + 1)}>Next</Button>
          : <Button appearance="primary" icon={<CheckmarkRegular />} disabled={busy || !stepOk.every(Boolean)} onClick={finish}>{busy ? 'Setting up…' : 'Finish setup'}</Button>}
      </div>
    </div>
  )
}
