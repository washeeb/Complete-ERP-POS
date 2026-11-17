## Transfers in the new back office
- **TransferService decides everything:** the same-warehouse refusal, transfers between industries (stock requests only), catalogue and serial rules, dispatch, receive and the difference on arrival. The new screens only gather what the person types.
- **Receive starts filled in with what was sent.** Usually everything arrives, so the receiver changes only the lines that differ. A difference is pointed out in the dialog, and the guide says to explain it in the note, because it goes to the review queue.
- **"Yours to receive" is the current menu badge's count** (in transit to the person's branches with `stock.transfer`), shown as a card.
- **Filter keys `from_wh` and `to_wh`:** `from` and `to` already mean dates elsewhere (sales, the ledger) and in the export's filter description.
- **The available-to-send figure** uses the same shared endpoint as adjustments (`stock/unit-stock`, limited to warehouses the person may see).
- **Found while testing: a test run could stop with "Maximum execution time of 600 seconds exceeded".**
  - Long exports and bulk changes call `set_time_limit()`. The whole test run is one PHP process, and on Windows the limit counts clock time, so a limit set by one test stopped the run once the tests after it took longer than that.
  - Earlier runs passed only because they were faster; this one was slower because the dev server and browser checks were running at the same time.
  - Decided: `Tests\TestCase::tearDown()` lifts the limit after each test. The live code keeps its limits.
