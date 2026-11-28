## Till new look, part 3: drawer, shifts and cash-up (branch `till-new-look-3`, base `till-new-look-2`)
- **Drawer.tsx:** actions as `.pay-methods` tiles with icons (tab list unchanged, names unchanged); fields in `.drawer-fields` (two columns from 600 px); `.drawer` 860 px wide.
- **Setup.tsx CloseShift:** `close-shift` class: 900 px wide, other checks in columns, total counted large (brand), buttons sticky at the bottom.
- **scanner.ts:** keys timed by `KeyboardEvent.timeStamp` (when pressed) instead of when handled; `SCAN_GAP_MS` 35 → 50. Unit cases: 45 ms (slow till) is a scan, 80 ms (fast typist) is not. Handheld e2e types with no delay, as a scanner.
- **Local e2e flakes under full-suite load** (handheld scan count, the slow-server receipt without its GRA code): each passes alone and in CI (retries 1); both are the till's own safeguards (scan speed, 4 s sale wait) meeting a loaded test machine.
- **Next:** part 4, returns and approvals.
