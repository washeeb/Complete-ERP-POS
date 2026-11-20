## Roles and access requests in the new back office
- **A role is changed on its own page,** not in a pop-up: with about 160 permissions the list needs room, a search box and an "only the ones it has" switch. Save sends the whole set; AccessService still refuses permissions you do not hold company-wide, business permissions on a technical role, and levels above your own.
- **Technical or not is chosen when a role is added and then fixed,** as on the current form (it was disabled when editing). The server refuses a change to it.
- **Approving your own request is refused with "not allowed"** (AccessRequests decides who may decide; only the owner or SyncLytics support, never the asker).
- **An approved change that cannot be done is shown as such,** with the reason, as on the current screen ("Approved, but it could not be done").
- **The users and roles controllers share one way of answering** (the approval-aware transaction), so an IT Admin's request is never rolled back by either.
