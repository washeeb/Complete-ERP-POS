### Changed
- **The classic back office is switched off (part 43).** The new back office is the only one: any old address, bookmark or shortcut (`/admin/…`, or `/office/a/…`) opens the same page in the new look, and the server's own address (`/`) opens it too. The *Classic version* links, the classic look choice and the user-menu switch are gone. Installers print the new address.
- **Notifications bell in the new look:** the unread count (fresh every half minute), the latest notifications (one opens its page and is marked read), *Mark all read* and *Clear*.
- **Industry switch in the new look:** people who cover several industries, or the whole company, choose *All industries* or one industry at the top; every screen then shows only that industry.
- **A server not licensed yet opens the licence page first** in the new look, as the classic one did.
- **`erp:page-timings` times the new back office:** every page's data (and, with `--deep`, the first record of each list), each call rolled back on its own; `--errors` lists only the calls that failed.
