import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle } from '@fluentui/react-components'
import { SaveRegular } from '@fluentui/react-icons'
import { useEffect, useState } from 'react'
import { saveThenLeave, setLeaveAsker } from '../unsaved'

/** "You have unsaved changes": Save and leave, Leave without saving, or Stay (mounted once, for every page). */
export function LeaveDialog() {
  const [leave, setLeave] = useState<(() => void) | null>(null)
  useEffect(() => {
    setLeaveAsker((go) => setLeave(() => go))
    return () => setLeaveAsker(null)
  }, [])
  const close = () => setLeave(null)
  return (
    <Dialog open={!!leave} onOpenChange={(_, d) => { if (!d.open) close() }}>
      <DialogSurface>
        <DialogBody>
          <DialogTitle>You have unsaved changes</DialogTitle>
          <DialogContent>Save them before you leave this page? If you leave without saving, they are lost.</DialogContent>
          <DialogActions fluid>
            <Button appearance="primary" icon={<SaveRegular />} onClick={() => { const go = leave; close(); if (go) saveThenLeave(go) }}>Save and leave</Button>
            <Button onClick={() => { const go = leave; close(); go?.() }}>Leave without saving</Button>
            <Button appearance="subtle" onClick={close}>Stay</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
