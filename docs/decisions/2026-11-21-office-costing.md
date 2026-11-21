## Landed costs, cost runs, estimated costs and spend analytics in the new back office
- **One screen class for the four**, as they are all about what stock and buying really cost; the services are unchanged (`LandedCostService`, `StockCostRuns`, `SpendAnalytics`).
- **A run's products come a page at a time** with the same filter as the current page (what happens) plus a search, because a run can cover the whole catalogue.
- **After Undo the page stays on the run**, now marked undone, with a link to the run that undid it (and that run links back), as the current page stays on the record.
- **Spend analytics exports its own tables** (summary, month, supplier, category, how it was bought, department) instead of reading them back from the page's HTML, which the new page does not draw on the server.
- **office-check's "old page inside" step moved to Sales → Leads**, the next page not rebuilt, since Cost runs now opens the new screen.
