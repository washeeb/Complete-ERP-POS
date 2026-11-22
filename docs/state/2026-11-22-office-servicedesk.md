## New back office, part 28: Service desk (branch `office-servicedesk`, base `office-projects`)
- **Server:** `App\Domain\Office\ServiceDeskScreen` (tickets list with filters and where tickets come from, the new-ticket form, one ticket with clocks, conversation and actions, deadlines) and `Office\ServiceDeskController` (open; reply, note, pass on, work order (opens the current work order page), resolve, reopen; save a deadline). Routes `office-api/tickets/…`, `office-api/service-levels/…`.
- **Client:** `TicketsPage`, `TicketNewPage`, `TicketPage`, `ServiceLevelsPage`. Paths `/admin/tickets…`, `/admin/service-levels` → `/office/tickets…`, `/office/service-levels`.
- **office-check:** opens Tickets and Deadlines from Sales where the add-on is on, the first ticket with its conversation and Note, and New ticket (nothing saved).
- **Tests:** `OfficeServiceDeskTest` (3).
- **Next:** Insights (Alerts, Ask your business, AI usage log).
