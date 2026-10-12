# Cashier and Income Controller guide

This guide is for shops that use **cash control with vaults**: each till has a cash tray (a **vault**) per shift, and the money is checked at every step.

**The chain:** Teller → Cashier → Income Controller → bank.

- **Teller:** sells at a till and counts their tray at the end of the shift.
- **Cashier:** issues each tray with its float, receives it back, and counts it again.
- **Income Controller:** receives the day from the Cashier, counts it again, decides any difference, and banks the money.

**Blind counts:** nobody sees the figure they are checking against before they have counted. If your count differs from the previous person's, the screen asks you to count once more, still without showing either figure. The second count stands. This is how banks handle cash: a difference is found at the step where it happened, and it belongs to the person responsible.

**Separate hands:** the Cashier and the Income Controller do not sell, and nobody receives their own tray or decides their own difference. The system refuses it.

## Cashier: start of the day

1. Count each float into its tray, for example GH₵200 for *T01 · Morning*.
2. Open **Cash → Cash desk**. If you work for more than one branch, choose the branch at the top.
3. On each tray, click **Issue float**, choose the teller who works that till and shift, and check the amount. **Issue float**.
4. Hand the tray to the teller. Only that teller can open that till on that tray.

**Teller called in sick?** Before the till is opened, **Take back** returns the float to the safe. Then issue it to someone else.

**Afternoon shift:** issue the *Afternoon* trays when the afternoon tellers arrive, the same way.

## Teller: on the till (for reference)

- **Open shift:**
  - choose your vault;
  - count the float in the tray and tick *I have counted the float*;
  - open the shift.

  If the float is wrong, do not open: give the tray back.
- **Close shift:** count everything in the tray, note by note and coin by coin, and enter the numbers. You do not see the expected figure. Close the tray and hand it to the Cashier.

## Cashier: receiving the trays

1. On **Cash desk**, a closed tray shows **Counted by the teller**. Take the tray.
2. Click **Receive and count**. Count everything in it, float included, and enter it note by note.
3. **"Your count does not match the teller's":** count the whole tray again from the start and enter it once more. The second count stands. Any difference is recorded against the teller and goes to the Income Controller to decide.
4. **Received** means the tray is now your responsibility. Keep the trays together, locked away.

## Cashier: handing over

At the end of the day (or of your shift):

1. Click **Hand over to the income controller**. All the trays you received are ticked.
2. Bundle the cash and hand it over with the handover number shown (e.g. *CH-HQ-000012*).

## Income Controller: receiving the day

1. Open **Cash → Income control**. Under *Handovers to receive*, click **Receive and count** on the bundle.
2. Count all the cash and enter it note by note. If it differs from the Cashier's total, you are asked to count once more; the second count stands, and any difference is recorded against the Cashier.
3. *Received: reconciliation* shows, for each tray:
   - what the system expected, from the sales;
   - what the teller counted;
   - what the cashier counted.

   It also shows what you counted for the bundle. Figures that differ are highlighted.

## Income Controller: differences

Under *Differences to decide*, each short or over amount names the person responsible. Click **Decide**:

- **Cash over/short:** the business bears a shortage, or keeps an overage. Use this for honest mistakes within your shop's tolerance.
- **Charged to the staff member** (shortages only):
  - the amount is recovered from the person's pay, as a staff loan over the number of months you choose;
  - it needs the person's employee record (HR & payroll);
  - tell the person before you charge them, and write the reason.

You cannot decide a difference on your own count. Each decision is recorded with your name and reason.

## Income Controller: the bank deposit

1. Take the counted cash to the bank and keep the deposit slip.
2. On the received handover, click **Record deposit**. Choose the bank account, check the amount and date, and enter the **slip number**. **Record deposit**.
3. The deposit appears in **Finance → Bank & cash** and is ticked off against the bank statement there.

## In the books (for the accountant)

| Step | Moves cash from | To |
|---|---|---|
| Float issued | Cash on hand – office and safe | Cash on hand – tills |
| Tray received | Cash on hand – tills | Cash with cashiers |
| Handover received | Cash with cashiers | Cash on hand – office and safe |
| Bank deposit | Cash on hand – office and safe | Bank |

- **Differences** wait in *Cash differences awaiting approval* until decided. They then go to *Cash over and short*, or to *Staff advances and loans*.
- **At the end of a day** where everything is received and decided, *Cash on hand – tills*, *Cash with cashiers* and *Cash differences awaiting approval* are all at zero.

## Exercises (in the practice company)

1. Issue floats to two tellers. Each sells a little, then closes with a slightly wrong count. Receive both trays: count one correctly the first time; count the other wrongly once, then correctly.
2. Hand over, receive and count the bundle, and decide both differences: one as cash short, one charged to the teller (give them an employee record first).
3. Record the bank deposit, and find it in *Finance → Bank & cash*.
