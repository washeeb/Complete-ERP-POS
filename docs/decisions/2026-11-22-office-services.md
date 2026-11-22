## Services in the new back office
- **Every action reuses the current domain services** (appointments, passes, memberships, licence keys), so the rules, audit rows and events are the same as on the current pages.
- **Show key goes through its own request** and appears in the shown-once dialog; the list only ever carries a key's last characters, as now.
- **Pause and Cancel ask for a reason** (on a membership, as on a pass Void), so the history says why.
- **office-check skips Services pages missing from the menu**, because the services module is an industry switch that a company may have off.
- **Services stays under More**, as in the current menu (it is not one of the named tabs; a More holding only Services is named Services). Moving it to a named tab is a menu change for the owner to decide, not part of the rebuild.
- **office-check waits for a closed dialog to be gone** before using the menu again: while a dialog fades out, the page behind it is hidden from the accessibility tree, and an immediate count found no menu button.
- **Verified with the module on:** the dev company has services off, so the check was run once with it switched on for HQ (every page and its dialog opened), then switched off again.
