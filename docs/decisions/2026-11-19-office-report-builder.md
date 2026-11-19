## Report builder in the new back office
- **Same engine, same rules:** runs through `ReportEngine` (permissions, branch scope, company-wide datasets) and saves through `ReportService` (only who saved a report changes, sends or deletes it; a schedule needs a recipient and its own permission).
- **The builder's choices stay on the page, not in the address;** a saved report has its own address (`?report=…`) to bookmark or share. Export sends the choices with the request so the file is exactly the report shown, with the "Open" column left out.
- **Runs as choices change** (the text filter after a pause), as the current page does, so there is no separate Run step to forget.
