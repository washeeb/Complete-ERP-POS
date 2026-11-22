import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarBody, Text, Textarea } from '@fluentui/react-components'
import { CopyRegular } from '@fluentui/react-icons'
import { useState } from 'react'
import type { ShownOnce as Secret } from '../api'

function Copyable({ value, label }: { value: string; label: string }) {
  const [copied, setCopied] = useState(false)
  return (
    <div style={{ display: 'grid', gap: 6, marginTop: 8 }}>
      <Textarea readOnly value={value} rows={2} aria-label={label} style={{ fontFamily: 'monospace' }} onFocus={(e) => e.target.select()} />
      <div><Button size="small" icon={<CopyRegular />} onClick={() => navigator.clipboard?.writeText(value).then(() => setCopied(true)).catch(() => undefined)}>{copied ? 'Copied' : 'Copy'}</Button></div>
    </div>
  )
}

/**
 * A key or secret made just now (part 23): shown once, with Copy, and a warning that it is not shown again — the server
 * keeps only what it needs to check it.
 */
export function ShownOnceDialog({ secret, onClose }: { secret: Secret; onClose: () => void }) {
  return (
    <Dialog open onOpenChange={(_, d) => { if (!d.open) onClose() }}>
      <DialogSurface style={{ maxWidth: 640 }}>
        <DialogBody>
          <DialogTitle>{secret.title}</DialogTitle>
          <DialogContent>
            <MessageBar intent="warning"><MessageBarBody>Copy it now: it is not shown again.</MessageBarBody></MessageBar>
            <Copyable value={secret.value} label={secret.title} />
            {secret.second && <><Text block weight="semibold" style={{ marginTop: 12 }}>Secret</Text><Copyable value={secret.second} label="Secret" /></>}
          </DialogContent>
          <DialogActions><Button appearance="primary" onClick={onClose}>I have copied it</Button></DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
