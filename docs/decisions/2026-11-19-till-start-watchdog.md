## A till never waits silently at start-up
- **Found:** the product-list download was the only start-up request without a time limit; a slow or stalled answer left the till on "Starting the till…" for good, with no message and no button.
- **Decided:** a time limit on that download long enough for a large shop's list (2 minutes, against 20 s for the other calls), after which the saved product list is used, as for any network failure; and a start-up watchdog that names the step it is on.
- **Why not more:** the cause at the supermarket is not confirmed (the owner was away). This makes the till say where it stops instead of guessing; a server-side fix to the product list follows only if the screen or the slow-pages list shows that is where it stops.
