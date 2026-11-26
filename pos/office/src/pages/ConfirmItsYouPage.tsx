import { Text } from '@fluentui/react-components'
import { ConfirmForm } from '../form/ConfirmItsYou'
import { PageHead, usePageStyles } from '../list/ListParts'
import { Panel } from './ProcurementParts'

/** "Confirm it's you" on its own page (part 40): opened from the classic look's note, or a saved link. */
export function ConfirmItsYouPage() {
  const page = usePageStyles()
  return (
    <div className={page.page}>
      <div style={{ maxWidth: 560 }}>
        <PageHead title="Confirm it's you" hint="Before money leaves, prove it is really you." classic="/admin/confirm-its-you" />
        <Panel title="Confirm it's you">
          <ConfirmForm />
        </Panel>
        <Text size={200}>Then go back to what you were doing and press the button again. You can close this tab.</Text>
      </div>
    </div>
  )
}
