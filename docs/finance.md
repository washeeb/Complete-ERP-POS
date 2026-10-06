# Finance: general ledger, month-end and supplier bills

Everything finance does is at **head office**; branches keep selling, receiving and counting, and their records reach the ledger when they sync.

## How the ledger fills itself

Nobody types sales into the books. Every few minutes (and on **Journals → Post now**) the system posts whatever is new:

| What happened | Debit | Credit |
|---|---|---|
| Till sales (one entry per branch per day) | Cash in tills / MoMo / card settlements / customer account / points / gift cards | Sales – shops, VAT, NHIL, GETFund |
| Goods leave or come back (sales, returns, deliveries) | Cost of goods sold ↔ Stock, at cost | |
| Goods received from a supplier | Stock | Goods received not yet billed |
| Supplier bill | Goods received not yet billed (+ price difference, input tax) | Supplier accounts |
| Supplier payment | Supplier accounts | Bank / MoMo / cash |
| Customer invoice / credit note / payment | Customer accounts ↔ Sales – wholesale and taxes; money accounts | |
| Stock count differences, damage | Stock losses ↔ Stock | |
| Transfers between branches | Stock in transit ↔ Stock | |
| Points earned / expired | Loyalty rewards ↔ Loyalty points | |
| Gift card sold / expired | Cash ↔ Gift cards; Gift cards ↔ Unused gift cards (income) | |
| Shift short or over | Cash over and short ↔ Cash in tills | |

Something dated in a **closed month** posts on the first day of the next open month (the description says so).

## Chart of accounts

**Finance → Chart of accounts.** The Ghana SME template is installed for you; your accountant can rename, renumber and add accounts. The column *Automatic posting* shows what each account is used for — pick another account to move a purpose (e.g. a second bank account for supplier payments). Accounts marked *Kept by* belong to a sub-ledger (customers, suppliers, stock, gift cards, points): only those screens post to them, which is how the system proves the books agree.

## Go-live (opening balances)

On the go-live date, from your old books:
1. **Customers**: Sales → Customers → *Opening balance* on each customer who owes.
2. **Suppliers**: Purchasing → Suppliers → *Opening balance* on each supplier you owe.
3. **Stock**: Stock → opening stock with **cost per unit**.
4. **Everything else** (bank, cash, MoMo, assets, loans, capital, taxes owed…): Finance → Journals → **Opening balances**.

The account *Opening balances (to clear)* collects the other side; it should be **zero** when all four are in. The ledger check shows what is left.

## Journals

**Finance → Journals → New journal** (accountant, finance manager): date, description, two or more lines that balance. A posted journal cannot be edited; **Reverse** it and post the right one.

## Supplier bills (3-way match)

**Finance → Supplier bills → Enter a bill.** Choose the supplier and the goods received notes; the lines fill in with what is still to bill at the order price — change the price to what the supplier charged. Rent, electricity and other charges go under *Other charges* to an expense account.

- You can never bill more than was received.
- If prices differ from the order by more than **2 % or GHS 50** (whichever is smaller; Settings → Finance), the bill **waits for a finance manager** to approve (with a reason) or reject. Nobody approves their own bill.
- **Supplier payments** (finance manager): pay from a bank, MoMo or cash account; the oldest bills are settled first. **Aged payables** shows what is owed and how late.

## Month-end

**Finance → Month-end.** Close months in order. A month closes only when everything dated in it is posted and the ledger check passes. Once closed, nothing can be posted into it. Only the **general manager or owner** can reopen a month (latest first, with a reason — it is in the audit log).

## Reports

**Finance → Reports**: trial balance, profit and loss, balance sheet, account ledger (click an account in the trial balance), each filterable by branch, and the **ledger check**. From a terminal: `php artisan erp:gl-check` (posts anything outstanding, then checks).

## Bank, MoMo and cash (Finance → Bank & cash)

1. **Add each account** (bank, MoMo wallet, petty cash) and link it to its ledger account — or give a new code and one is created.
2. **Import statement**: export CSV from the bank or MoMo portal (in Excel: Save As → CSV). Say which column is the date, the description, the amount (or money in / money out) and the balance; this is remembered for next time. Importing the same file twice adds nothing.
3. **Reconcile**: tick statement lines and ledger lines that are the same money and press *Match* (several to several is fine if the totals agree). *Match suggestions* does the obvious ones. For bank charges or interest, tick the statement line, choose the account and press *Post*. Tick the opening balance and press *…on an earlier statement*. The top shows whether the books explain the statement balance.
4. **Move money** between your own accounts: banking the till cash, topping up petty cash, sweeping MoMo to the bank.

## Expense claims and petty cash (Finance → Expense claims)

Anyone with *claim expenses* records what they spent, with a photo of the receipt. The branch manager approves claims up to **GHS 500**; finance approves larger ones; nobody approves their own. The person holding petty cash then presses **Pay** (from petty cash, or the bank/MoMo account used to reimburse). Top up petty cash with *Move money*.

## Fixed assets (Finance → Fixed assets)

Record the purchase as a supplier bill (*Other charges* → the asset account, e.g. 1620 Equipment), then **Register an asset** with its kind, cost and date. Depreciation is posted automatically each month (straight-line over the kind's useful life, e.g. computers 36 months). **Dispose of** an asset when it is sold or scrapped: the system works out the gain or loss.

## Budgets (Finance → Budgets)

Choose the year and branch, type a yearly figure per account and press *Spread*, or type each month. Compare under **Reports → Budget vs actual** (green = better than budget).

## VAT return (Finance → Reports → VAT return)

Choose the month: sales split into taxed and exempt, VAT, NHIL and GETFund charged on sales and paid on purchases, and what is due. Your accountant checks these against the GRA return before filing.

## Who can do what

| | View | Journals | Chart | Bills | Approve bills | Pay suppliers | Close month | Reopen |
|---|---|---|---|---|---|---|---|---|
| Accountant | ✓ | ✓ | | ✓ | | | | |
| Finance manager | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| General manager | ✓ | | | | ✓ | ✓ | | ✓ |
| Auditor | ✓ | | | | | | | |

Also: **banking** and **fixed assets** — accountant and finance manager; **budgets** — finance manager and GM; **expense claims** — most staff claim, branch and warehouse managers approve up to GHS 500 and finance/GM above, branch managers, accountants and finance pay.

## Not yet

Direct bank/MoMo feeds (CSV for now), supplier credit notes and returns to suppliers, foreign-currency revaluation, GRA E-VAT.
