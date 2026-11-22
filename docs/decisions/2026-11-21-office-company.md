## Company setup, health checks and scale items in the new back office
- **A branch is one page**, new or existing: its details and the settings it may set for itself on top, its stores and tills below, instead of the current page's form plus two separate tables. A setting left empty follows the company, as now; one in a section not shown (Pharmacy off) keeps what the branch had.
- **One archive endpoint for the five kinds** (branch, store, till, industry, legal entity), through `ArchiveService` as the current buttons; a branch's stores and tills are found only within the branches the person may manage.
- **A brand colour suggested from a new logo is only offered**, saved with "Save colour and receipts", as now.
- **Health checks keep the current page's rule**: only people who may see patients, and the patient's name in a row filters to their history.
