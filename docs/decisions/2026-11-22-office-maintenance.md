## Maintenance in the new back office
- **Equipment and work orders are pages, not dialogs,** like the current ones: each has many fields, a history (plans, work orders, parts) and several actions.
- **A blank checklist step is dropped, not refused:** the current form's tag field never sends one; the new form's list could, so the server treats it as nothing typed.
- **Archive shows only when ArchiveService allows it,** with its blockers (an open work order) shown in the dialog; an archived item shows why and offers Restore.
- **A ticket's Work order now opens the new work order page** (part 28 sent it to the current page while Maintenance was not rebuilt; the same address now maps to the new one).
- **office-check's "old page inside" step moved to Stock → Sensors**, the next page not rebuilt that the dev company has switched on.
