### Added
- **More health checks at the pharmacy:** HB (haemoglobin, in g/dL), HIV and typhoid rapid tests (the result: non-reactive / reactive / invalid, negative / positive / invalid) and wound dressing (what was done). Set up as a fee service with its "Reading taken", sold at the till, kept on the patient's history. A reactive HIV test prompts private counselling and a confirmatory test; an invalid test, a retest.

### Security
- **An HIV result never prints on the receipt** (only "HIV test done"), even when a till sends one, and is kept out of the audit log: only people who may see patients see it, on Health checks. A wound dressing's details stay in the patient's record, not on the receipt.
