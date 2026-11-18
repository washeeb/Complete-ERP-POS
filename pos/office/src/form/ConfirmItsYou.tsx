import { Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, makeStyles, Text, tokens } from '@fluentui/react-components'
import { ShieldCheckmarkRegular } from '@fluentui/react-icons'

const useStyles = makeStyles({
  frame: { width: '100%', height: '460px', border: 'none', borderRadius: tokens.borderRadiusMedium, backgroundColor: tokens.colorNeutralBackground2 },
})

/**
 * "Confirm it's you" (passkey, fingerprint or the code from the app) before a step the company protects — approving a
 * till request, writing off what a customer owes… The server answers such a step with the page's address (ApiError code
 * "reconfirm"); it opens here, and the person then presses the button to do the step again.
 */
export function ConfirmItsYou({ url, why, go, onCancel, onGo }: { url: string; why: string; go: string; onCancel: () => void; onGo: () => void }) {
  const s = useStyles()
  return (
    <Dialog open onOpenChange={(_, d) => { if (!d.open) onCancel() }}>
      <DialogSurface>
        <DialogBody>
          <DialogTitle>Confirm it's you</DialogTitle>
          <DialogContent>
            <Text block style={{ marginBottom: 8 }}>{why} Prove it is really you (passkey, fingerprint or the code from your app), then press <strong>{go}</strong>.</Text>
            <iframe className={s.frame} src={url} title="Confirm it's you" allow="publickey-credentials-get *" />
          </DialogContent>
          <DialogActions>
            <Button onClick={onCancel}>Cancel</Button>
            <Button appearance="primary" icon={<ShieldCheckmarkRegular />} onClick={onGo}>{go}</Button>
          </DialogActions>
        </DialogBody>
      </DialogSurface>
    </Dialog>
  )
}
