## Users in the new back office
- **An IT Admin's change is kept as a request, not lost.** AccessService raises "sent for approval" after saving the request. The new controller catches it inside the database transaction, so the request is committed and the screen says it waits for the owner or SyncLytics support. Catching it outside would have rolled the request back.
- **Who may do what is unchanged:** the list follows the current screen's company and industry filter; Change, PIN, card and two-step reset need `admin.users.manage` and a person at or below your level; Grant and Revoke need `admin.roles.manage`. AccessService still decides every rule (no access to yourself, levels, technical roles, approvals).
- **Levels offered stop at your own (never the SyncLytics level),** as on the current form; the server refuses a higher one too.
- **Passwords and PINs are typed in masked boxes** and never sent back by the server.
- **Grant limits use the company currency's decimals** (the current screen multiplied by 100, which is the same for cedis).
- **One user opens as a page** with Change and the other actions on it, instead of the current edit form with buttons in its header; the access list shows current access, with a switch for ended ones (the current filter).
