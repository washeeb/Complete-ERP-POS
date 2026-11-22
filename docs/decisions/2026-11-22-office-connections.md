## API clients, mobile money, support access and sync in the new back office
- **A key or secret is answered once and never listed again** (an API key, a webhook signing secret, a branch server's join secret), shown in one dialog with Copy and a warning, as the current page's notification.
- **Saved payment provider secrets never leave the server**: their boxes come back empty with "Saved — leave empty to keep"; an empty box keeps the saved one (`MomoCollections::saveGateway`).
- **Go-live and Import data are a part of their own (24)**: together they are about 1,070 lines of current pages.
