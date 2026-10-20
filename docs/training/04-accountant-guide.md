# Accountant guide

Sales, purchases, stock and payroll post to the accounts **automatically**. Your work is checking, the few journals that are really manual, and month-end.

## Going live (once)

1. Check the **chart of accounts** (Finance → Chart of accounts) — especially what each automatic purpose posts to.
2. Load, in this order (System → Import data, "Coming from" = your old system): **chart of accounts** (if you bring yours), **opening trial balance**, **customer balances**, **supplier balances**; the shop loads products, prices and **opening stock**.
3. Do **not** put customers, suppliers, stock, gift cards or loyalty points in the opening trial balance as amounts: they come in through their own imports. The trial balance import skips those rows for you.
4. **System → Go-live → Check the figures**: enter the old system's totals; press **Post and check again**. Everything must match, and **"Opening balances (to clear)"** must be **0.00**. If not, something is missing or loaded twice.
5. A wrong opening trial balance: reverse the opening journal (Finance → Journals → Reverse) and load the corrected file.

## Every week

- **Finance → Bank reconciliation** for each bank and MoMo account.
- **Aged receivables / payables**: chase what is overdue, plan payments.
- **Sales → Till drawer**: expenses paid from tills (each is also a paid expense claim, posted from *Cash on hand – tills* to its expense account), and cash drops / pay-ins between tills and the safe (*Cash on hand – office and safe*). Check the receipt photos.
- **GRA E-VAT** (if used): nothing waiting or refused for more than a day.

## Month-end

**Separate companies (if set up):** run each company's trial balance and VAT return under *Books*, and the **Ledger check**, which proves both sides agree on what they owe each other. Settle large balances between them by bank, with a journal that has both companies' bank lines.

**Industries:** check **Finance → Reports → Profit by industry**. If head office costs are shared (System → Settings → Finance), set the *To* date in the month, click **Share head office costs**, check the shares and post. Do this after all of the month's costs are in, before closing the month. To redo it, reverse that journal and share again.

1. Every till shift closed; nothing in the review queue.
2. **Finance → Month-end**: the checks show unposted items, whether the sub-ledgers tie to the control accounts, and anything left in suspense.
3. Journals for accruals and prepayments (Finance → Journals). Every journal needs a description.
4. **Close the period.** Posted documents are never edited: corrections are reversals and new entries.

## Practise first

In the **practice company**: post a manual journal and reverse it; reconcile a bank account with one unmatched line; run the month-end checks.
