import { Button, Checkbox, Field, Input, makeStyles, MessageBar, MessageBarBody, Spinner, Text, tokens } from '@fluentui/react-components'
import { ArrowUploadRegular, DeleteRegular, SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { api, type BrandingData } from '../api'
import { PageHead, usePageStyles } from '../list/ListParts'
import { Panel, useProcStyles } from './ProcurementParts'

const useStyles = makeStyles({
  grid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 240px), 1fr))', gap: '12px' },
  card: { border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusLarge, padding: '12px', display: 'grid', gap: '8px', alignContent: 'start' },
  preview: { height: '90px', display: 'flex', alignItems: 'center', justifyContent: 'center', borderRadius: tokens.borderRadiusMedium, backgroundColor: '#FFFFFF',
    border: `1px dashed ${tokens.colorNeutralStroke2}`, '& img': { maxWidth: '100%', maxHeight: '76px' } },
  dark: { backgroundColor: '#1C2A3A' },
  row: { display: 'flex', gap: '10px', alignItems: 'flex-end', flexWrap: 'wrap', marginBottom: '10px' },
  swatch: { width: '40px', height: '32px', padding: 0, border: `1px solid ${tokens.colorNeutralStroke1}`, borderRadius: tokens.borderRadiusMedium, cursor: 'pointer' },
  head: { display: 'flex', alignItems: 'center', gap: '10px', padding: '10px 14px', borderRadius: tokens.borderRadiusLarge, '& img': { height: '26px', borderRadius: '6px' } },
  btn: { marginLeft: 'auto', padding: '4px 12px', borderRadius: tokens.borderRadiusMedium, fontWeight: tokens.fontWeightSemibold },
  warn: { color: tokens.colorPaletteDarkOrangeForeground1 },
})

const HEX = /^#[0-9A-Fa-f]{6}$/
const onColor = (hex: string) => {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.179 ? '#111111' : '#FFFFFF'
}
const lightNote = (hex: string) => {
  if (!HEX.test(hex)) return null
  const lum = (h: string) => { const [r, g, b] = [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)); return 0.2126 * r + 0.7152 * g + 0.0722 * b }
  return 1.05 / (lum(hex) + 0.05) < 3 ? 'This colour is light: text and lines in it are hard to read on white. A darker shade works better for buttons.' : null
}

/**
 * Company → Branding (part 20), as the current page: the logo for light and dark backgrounds and the square icon
 * (upload, replace, remove; images are resized and cleaned on upload), the brand colour (with one taken from the logo
 * offered), the menu colour, the logo on till receipts, and a preview of the till header.
 */
export function BrandingPage() {
  const page = usePageStyles()
  const s = useStyles()
  const p = useProcStyles()
  const [b, setB] = useState<BrandingData | null>(null)
  const [color, setColor] = useState('')
  const [menu, setMenu] = useState('')
  const [receipt, setReceipt] = useState(true)
  const [error, setError] = useState('')
  const [done, setDone] = useState('')
  const [busy, setBusy] = useState('')
  const take = (x: BrandingData) => { setB(x); setColor(x.color); setMenu(x.menu_color); setReceipt(x.receipt_logo) }
  useEffect(() => { api.branding().then(take).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))) }, [])

  if (error && !b) return <div className={page.page}><MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar></div>
  if (!b) return <div className={page.page}><Spinner label="Opening your brand…" /></div>
  const doing = (what: string, p2: Promise<{ message: string; branding: BrandingData }>, keepColour = true) => {
    setBusy(what)
    setError('')
    setDone('')
    p2.then((x) => {
      const before = { color, menu, receipt }
      take(x.branding)
      if (keepColour) { setColor(before.color); setMenu(before.menu); setReceipt(before.receipt) } // what is typed and not saved yet stays
      setDone(x.message)
      if (what === 'logo' && !before.color && x.branding.suggestion) { // offered, saved only with "Save colour and receipts"
        setColor(x.branding.suggestion)
        setDone(`${x.message} Suggested colour ${x.branding.suggestion}, taken from your logo: press “Save colour and receipts” to use it.`)
      }
    }).catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(''))
  }
  const shown = HEX.test(color) ? color : b.shown_color
  const fg = onColor(shown)
  const note = lightNote(color || b.default_color)
  return (
    <div className={page.page}>
      <PageHead title="Your brand" hint="Your logo and colour on the back office, tills, receipts, invoices, PDFs and the online shop." />
      {error && <MessageBar intent="error" style={{ marginBottom: 12 }}><MessageBarBody>{error}</MessageBarBody></MessageBar>}
      {done && <MessageBar intent="success" style={{ marginBottom: 12 }}><MessageBarBody>{done}</MessageBarBody></MessageBar>}
      <Panel title="Logo and icon" about="PNG with a transparent background, or SVG, work best. Images are resized and cleaned when you upload them; up to 2 MB.">
        <div className={s.grid}>
          {b.assets.map((a) => (
            <div key={a.kind} className={s.card} role="group" aria-label={a.label}>
              <Text weight="semibold">{a.label}</Text>
              <div className={`${s.preview} ${a.kind === 'logo_dark' ? s.dark : ''}`}>
                {a.url ? <img src={a.url} alt={a.label} /> : a.kind === 'icon' ? <img src={b.icon_url} alt="Monogram placeholder" />
                  : <Text size={200} className={p.muted}>No logo yet{a.kind === 'logo_dark' ? ' — the normal logo is used' : ''}</Text>}
              </div>
              <Text size={200}>{a.hint}</Text>
              {a.about && <Text size={200} className={p.muted}>{a.about}</Text>}
              <div className={p.actions}>
                <Button size="small" icon={<ArrowUploadRegular />} disabled={!!busy} onClick={() => document.getElementById(`brand-${a.kind}`)?.click()}>
                  {busy === a.kind ? 'Uploading…' : a.url ? 'Replace' : 'Upload'}
                </Button>
                <input id={`brand-${a.kind}`} type="file" hidden accept="image/png,image/jpeg,image/webp,image/svg+xml,.svg" aria-label={`Upload ${a.label}`}
                  onChange={(e) => { const file = e.target.files?.[0]; e.target.value = ''; if (file) doing(a.kind, api.brandUpload(a.kind, file)) }} />
                {a.url && <Button size="small" appearance="subtle" icon={<DeleteRegular />} disabled={!!busy}
                  onClick={() => { if (window.confirm('Remove this image?')) doing(a.kind, api.brandRemove(a.kind)) }}>Remove</Button>}
              </div>
            </div>
          ))}
        </div>
      </Panel>
      <Panel title="Colour and receipts">
        <div className={s.row}>
          <Field label="Brand colour">
            <div style={{ display: 'flex', gap: 6 }}>
              <input type="color" className={s.swatch} value={shown} onChange={(e) => setColor(e.target.value.toUpperCase())} aria-label="Pick the colour" />
              <Input value={color} placeholder={b.default_color} maxLength={7} onChange={(_, d) => setColor(d.value)} aria-label="Brand colour code" />
            </div>
          </Field>
          {b.suggestion && b.suggestion.toLowerCase() !== color.toLowerCase() && (
            <Button size="small" onClick={() => setColor(b.suggestion!)}><span style={{ display: 'inline-block', width: 12, height: 12, borderRadius: 3, background: b.suggestion, marginRight: 6 }} />Use {b.suggestion} from your logo</Button>
          )}
        </div>
        {note && <Text block className={s.warn} style={{ marginBottom: 10 }}>{note}</Text>}
        <div className={s.row}>
          <Field label="Menu colour (optional)" hint="The band of tabs and the side menu. Left empty, they take a dark shade of your brand colour. White text must read clearly on it: choose a dark colour.">
            <div style={{ display: 'flex', gap: 6 }}>
              <input type="color" className={s.swatch} value={HEX.test(menu) ? menu : '#1C2A3A'} onChange={(e) => setMenu(e.target.value.toUpperCase())} aria-label="Pick the menu colour" />
              <Input value={menu} placeholder="Automatic" maxLength={7} onChange={(_, d) => setMenu(d.value)} aria-label="Menu colour code" />
            </div>
          </Field>
          {menu !== '' && <Button size="small" onClick={() => setMenu('')}>Automatic (from the brand colour)</Button>}
        </div>
        <Checkbox checked={receipt} onChange={(_, d) => setReceipt(!!d.checked)} label="Print the logo at the top of till receipts (thermal printers print it in black and white)" />
        <div style={{ marginTop: 12 }}>
          <Button appearance="primary" icon={<SaveRegular />} disabled={!!busy || (color !== '' && !HEX.test(color)) || (menu !== '' && !HEX.test(menu))}
            onClick={() => doing('save', api.saveBranding({ color: color || null, menu_color: menu || null, receipt_logo: receipt }), false)}>
            {busy === 'save' ? 'Saving…' : 'Save colour and receipts'}
          </Button>
        </div>
      </Panel>
      <Panel title="Preview" about="How your brand looks to staff and customers.">
        <div className={s.head} style={{ background: shown, color: fg }}>
          {b.logo_dark_url ? <img src={b.logo_dark_url} alt="" /> : <><img src={b.icon_url} alt="" /><strong>{b.company}</strong></>}
          <span className={s.btn} style={{ background: fg, color: shown }}>New sale</span>
        </div>
        <Text size={200} className={p.muted}>Till and back-office accent</Text>
      </Panel>
    </div>
  )
}
