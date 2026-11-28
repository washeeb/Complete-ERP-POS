### Changed
- **The till in the new look, part 3: drawer and closing the shift.** The Drawer window's actions are large tiles with icons and its fields sit in two columns; *Close shift* is wide enough for the cash count and the mobile money / card / voucher / coupon checks side by side, with its buttons kept at the bottom.

### Fixed
- **A scan made while the cursor was not in a box could be missed on a slow or busy till:** the till now times each key from when it was pressed, not from when the screen got round to it, and allows up to 50 ms between a scanner's keys (a person types far slower).
