## New back office, part 29: Ask your business, Alerts, AI usage log (branch `office-insights-ai`, base `office-servicedesk`)
- **Server:** `App\Domain\Office\InsightsAiScreen` (quick questions and AI status, alerts with filters, usage log with spend, one request) and `Office\InsightsAiController` (question with the chat so far, quick question, alert dealt with / not a problem, refresh now). Routes `office-api/ask…`, `office-api/alerts…`, `office-api/ai-usage…`.
- **Client:** `AskPage`, `AlertsPage`, `AiUsagePage`. Paths `/admin/copilot`, `/admin/alerts`, `/admin/ai-usage` → `/office/ask`, `/office/alerts`, `/office/ai-usage`.
- **office-check:** opens the three from Insights; on Ask your business it presses the first quick question and waits for its answer table.
- **Tests:** `OfficeInsightsAiTest` (3).
- **Next:** Industries: Restaurant, then Distribution, Manufacturing, Fashion, Electronics, Sensors, Maintenance and Online.
