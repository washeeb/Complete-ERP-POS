# Supervisor guide

You look after the tills and the cashiers during the day. You sign in at a till with your username and PIN, and in the back office (a web browser) with your e-mail and password.

## Every morning

1. Check that every till shows **Connected** (bottom right). If one says *working offline*, it still sells — tell IT.
2. Check **Sales → Review queue** in the back office: anything a till sent that the server refused (a price that changed, stock that ran out on a strict item). Fix it, then press **Retry refused** on that till.
3. Make sure each cashier opens their own shift with a counted float.

## During the day

| Situation | What you do |
|---|---|
| Discount above the cashier's limit | At the till, type your username and PIN in the approval box. Only approve what you would do yourself: it is recorded under your name. |
| Return without a receipt | Approve at the till; check the item came from your shop. |
| Cashier locked out (5 wrong PINs) | Wait 15 minutes, or a manager sets a new PIN: back office → **People & access → Users** → the cashier → **Set POS PIN**. |
| A sale "stuck" on a till | Do not ring it again. Check the yellow bar: it counts sales waiting to send. They go on their own. |
| Red bar on a till | The server refused a sale: open **Sales → Review queue**, read the reason, fix, then **Retry refused**. |
| Printer prints nothing | Check paper and power. Desktop till app: **Printer and scale set-up** on the receipt screen. Then **Report a problem**. |

## End of the day

1. Each cashier closes their shift and counts the cash. You see over/short in **Sales → Till shifts**.
2. Any difference above the limit: talk to the cashier, add a note, tell the manager.
3. **During the parallel run** (if your shop has one): the manager enters the old system's day totals in **System → Go-live**; help find any sale rung up in only one system.

## Practise first

In the **practice company** (purple PRACTICE bar), try: a discount approval, a return without receipt, closing a shift with a short of 5.00, and fixing an item in the review queue.
