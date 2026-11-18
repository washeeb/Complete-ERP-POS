import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Text, tokens } from '@fluentui/react-components'
import { useState, type ReactNode } from 'react'

/** A dialog with its fields, Back, and the button that does it (off until what is required is filled in). */
export function Ask({ title, go, ready, onClose, run, children, width = 520 }: {
  title: string; go: string; ready: boolean; onClose: () => void; run: () => Promise<unknown>; children: ReactNode; width?: number
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  return (
    <Dialog open onOpenChange={(_, d) => !d.open && onClose()}>
      <DialogSurface style={{ maxWidth: width }}>
        <DialogBody>
          <DialogTitle>{title}</DialogTitle>
          <DialogContent>
            {children}
            {error && <Text block style={{ color: tokens.colorPaletteRedForeground1, marginTop: 10 }}>{error}</Text>}
          </DialogContent>
          <DialogActions>
            <Button onClick={onClose}>Back</Button>
            <Button appearance="primary" disabled={busy || !ready} onClick={() => {
              setBusy(true)
              setError('')
              run().catch((e: unknown) => setError(e instanceof Error ? e.message : String(e))).finally(() => setBusy(false))
            }}>{busy ? 'Working…' : go}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}

