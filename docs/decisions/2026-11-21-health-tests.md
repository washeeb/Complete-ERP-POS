## HIV, typhoid, HB and wound dressing as health checks
- **Owner's choices (2026-11-21):** free for every client; the receipt says only "HIV test done"; a wound dressing is recorded with a note of what was done.
- **An HIV result is kept out of the audit log**, not only off the receipt: every reading was written to the audit log with its values, which more people may read (auditors, managers) than may see patients. The audit row keeps who recorded which kind of test, when and where, and marks the result, flag and note `[redacted]`. Other readings are logged as before.
- **The server enforces the receipt rule** (the sale line's note for an HIV test is always "HIV test done"), so an older till or a mistake cannot print a result.
- **Limits are screening prompts, not a diagnosis:** HB below 12 g/dL low, below 7 refer now, 18 or more high (one limit for everyone, as the till does not know sex or pregnancy); reactive HIV refer now (counselling and a confirmatory test); positive typhoid see a clinic; invalid, test again. The pharmacist should agree them.
- **Rapid-test results are stored as a result** (`result` column) rather than a number, so they are never mistaken for a measurement.
