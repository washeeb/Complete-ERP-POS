# Performance (Phase 13)

`php artisan erp:load-test --sales=300 --lines=3` posts sales through the real sale service (stock, tax, promotions, audit, outbox) and times the heavy reads, inside one transaction that is rolled back.

Development machine (Windows 11, PHP 8.4 via Herd without OPcache tuning, PostgreSQL 17 local, demo catalogue), 2026-10-29:

| Measure | Result |
|---|---|
| Sales posted | 300 × 3 lines |
| Throughput (one till, one connection) | 11.5 sales/second |
| Posting time p50 / p95 / max | 83 / 109 / 455 ms |
| Report: sales by product this month | 124 ms |
| Report: receipts by hour (today) | 7 ms |
| Demand forecast for a warehouse | 128 ms |

Reading: a till posts a sale in about a tenth of a second; a busy shop does a sale every 20–60 seconds per till, so one server handles dozens of tills with room to spare. Sales from several tills post in parallel (row locks are per product, taken in a fixed order). Production servers (Linux, OPcache, PHP-FPM workers) are faster than this Windows development setup.

Database: indexes exist on every foreign key used in filters, on `(tenant_id, status)` / date columns of documents, and on the ledgers' source columns; analytics read 12 weeks at most. Re-run the load test after large data imports or on new hardware.
