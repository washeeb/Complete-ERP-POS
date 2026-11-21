## More health checks (branch `health-tests`, base `office-cash`)
- `HealthReading::KINDS` gains `hb`, `hiv`, `typhoid`, `wound`; `RESULTS` (the rapid tests' results), `RECEIPT_ONLY_DONE` (hiv), FLAGS `retest` and `done`; new column `health_readings.result` (migration `2027_01_05_100001_health_test_results`). `HealthReadings::flag()` takes the result; `make()` checks each kind (HB 1–25 g/dL, a result for the tests, a note for a wound).
- `HealthReading::auditRedacted()` and `AuditLog::record()`: an HIV test's result, flag and note are `[redacted]` in the audit row. `SaleService` replaces an HIV line's note with "HIV test done" whatever the till sent.
- Till: `reading.ts` (`RESULTS`, `UNITS`, `readingReady`), `ReadingPrompt` (result buttons, "What was done"), `types.ts`. Classic Health checks page records the new kinds.
- **Tests:** `HealthReadingsTest`, `PosSaleTest` (HIV receipt), till `reading.test.ts`.
