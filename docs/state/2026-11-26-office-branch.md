## New back office, part 45: branch switch (branch `office-branch`, base `office-polish`)
- **`App\Support\BranchContext`** (session `erp.branch`): `options(User)` = active, unarchived branches the person's grants cover (company → all; legal entity, industry, branch), within `IndustryContext::branchIds()`; `selected()` ignores a choice no longer offered (another industry, access gone); remembered per request.
- **`Erp::branchIds`** intersects the industry's and the branch's ids (narrows only). Every branch-filtered list, report, `HomeData`, `TodoData` and the forms' choices (`Fields::warehouses`, …) follow it, so a new document defaults to the branch's store (first/only choice). Transfers still offer every destination store.
- **API:** `me.branch` (null under two options), `POST office-api/branch` (422 for a branch not offered).
- **Client:** `shell/BranchSwitch.tsx`; `shell/WhereSwitch.tsx` puts industry + branch inline, or behind one button on a phone; both switches ask before leaving unsaved work (`confirmLeave`), then reload. `api.chooseBranch`.
- **Tests:** `OfficeBranchSwitchTest` (3).
- **check:office:** the report-builder wait looks inside the page only (the branch box's options also contain " · ").
