## Every address in the new look, from one map
- **The client's address map is the source; the server's is generated from it** (`office-routes.json`), not copied by hand: two hand-kept lists of ~300 pages would drift. Both sides test that the file is current.
- **Call sites write the new address itself** (owner 2026-11-26: "clear every classic address"), with the query names the new pages read; only links whose page is known at run time are converted.
- **Query strings:** the new pages read the same names (kind, source, customer, scan, invoice, id, type), and a search is `q`; the computer filter of the audit log is `computer` (it was dropped before: fixed).
- **Saved addresses moved once by a migration,** and kept as new-look addresses from now on; the client still reads old ones (Recent in the browser, anything not yet migrated) through `appPathFor`.
- **Found: the new back office has no notifications bell;** notifications show only in the classic back office. It must be rebuilt before the classic back office is switched off (part 43).
