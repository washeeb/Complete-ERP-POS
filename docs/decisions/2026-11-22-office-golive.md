## Go-live and Import data in the new back office
- **An uploaded import file stays on the server under a token for the person who uploaded it** (cache, one day), so Check and Import use the same file without sending it again, and nobody else can run it; the duplicate check uses its fingerprint taken at upload.
- **Going live is refused until the go-live backup exists**, as before (GoLiveService); with required steps still open, the page asks for the reason first.
- **Every "Import" link now opens the new page**: `/admin/import-data?…` maps to `/office/import?…` with its type, count or adjustment, and the import to show.
