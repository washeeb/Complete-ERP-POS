# Customers, wholesale sales and receivables

This is Phase 6a. It covers customers, the order-to-cash flow, what customers owe, and credit sales on the till.

```
Customer → Quotation → Order (credit check) → Delivery note → Invoice (raised automatically)
        → Payment (settles the oldest invoices first) → Statement / aged receivables
```

## Customers (Sales → Customers)

- **Profile**
  - Profile fields:
    - type (individual or business);
    - name, contact, phone, email;
    - TIN or Ghana Card number;
    - address;
    - price list (for example Wholesale);
    - home branch, tags, notes.
  - Ghana phone numbers are stored as `+233…`.
  - Two customers cannot share a phone number.
- **Marketing consent** (SMS, WhatsApp, email)
  - Never assumed.
  - Every change records who made it, when, and how the customer agreed (in person, paper form, phone call or online).
- **Credit** (head office only, button **Credit**)
  - Credit limit. Leave it blank for a cash customer.
  - Payment terms in days.
  - A manual **credit hold**.
  - Branch servers cannot change credit (spec §4.2).
- **Opening balance:** what a customer already owed in your old books. You can enter it once, before any transactions.
- **The customer page shows:**
  - balance, overdue amount, limit, available credit and terms;
  - every open item (invoices and till credit sales not fully paid) with its due date.

## Quotations and orders (Sales → Quotes & orders)

1. **New quotation:** choose the customer, the warehouse to supply from and the lines.
   - Prices come from the customer's price list, including quantity tiers.
   - Tax is calculated from each product's tax group.
   - **Print** gives an A4 quotation.
2. **Turn into order.**
   - **Credit customers are checked.** The order goes **on credit hold** if:
     - the account is on hold;
     - anything is overdue; or
     - balance + undelivered orders + this order would exceed the limit.
   - **Cash customers** (no credit account) are confirmed directly and pay on delivery.
3. **Release credit hold** needs all of the following:
   - *Release credit holds*;
   - an override limit that covers the amount over the limit (or the whole amount if there's no credit account, a hold, or overdue debt);
   - a reason;
   - and it can't be the person who entered the order.

   | Role | Default override limit |
   |---|---|
   | Supervisor | GHS 1,000 |
   | Branch Manager | GHS 20,000 |
   | Finance Manager, General Manager, Owner | No limit |

4. **Deliver** all or part of the order.
   - Stock leaves the warehouse; a delivery can never take stock below zero.
   - Posting the delivery note **raises the invoice** for exactly what was delivered (owner decision).
   - The invoice is due after the customer's terms.
   - Nothing can be delivered while the order is on hold.
5. A quotation can be **marked lost**, and an open order **cancelled**. On a partly delivered order, what was already delivered stays invoiced.

Document numbers have one sequence per server:

| Document | Number format |
|---|---|
| Quotation | QT-HQ-000001 |
| Order | SO-… |
| Delivery note | DN-… |
| Invoice | INV-… |
| Credit note | CRN-… |
| Payment receipt | RCT-… |
| Customer | CUS-… |

## Invoices, payments, credit notes

- **Invoices never change.**
  - **Print / PDF** gives an A4 tax invoice showing the VAT, NHIL and GETFund split and the amount still owed.
- **Record payment** (on the customer page).
  - Methods: cash, mobile money (network + transaction ID), bank transfer, cheque, card.
  - The payment settles the **oldest open items first**. Anything left over stays on the account as credit and is used automatically against the next invoice or till credit sale.
  - The A4 payment receipt lists what the payment settled.
- **Credit note** (on an invoice) cancels the invoice.
  - What was still owed on it is cleared. If it was already paid, the amount becomes credit on the account.
  - Optionally, the goods come back into a warehouse.
- **Statement** (customer page): for any period it shows opening balance, each document, a running balance, closing balance and ageing.
- **Aged receivables** (Sales): who owes what, by days past due (not yet due, 1–30, 31–60, 61–90, over 90).
- `php artisan erp:receivables-check` proves two things:
  - every customer balance equals its ledger;
  - no document is settled for more than it is worth.

## On the till

- **Customer (F4)** finds a customer by name, code or phone. This works offline from the cached list.
- The customer's price list is applied to the sale.
- **On account** pays all or part of a sale on the customer's account.
- **Online, within available credit:** posted straight away. The sale appears as an open item, due after the customer's terms.
- **Online, over the limit** (or no credit account, on hold, or overdue): the till asks for a **supervisor**.
  - The supervisor enters their own username and PIN; their override limit covers the amount over the limit.
  - Wrong PINs count towards the PIN lockout.
  - The approver's name is kept on the payment.
- **Offline:** the sale is kept, because money and goods have already changed hands. If it went over the limit it is flagged for review (*Credit sale over the customer's limit*) when it reaches the server.
- A supervisor's PIN is sent only to the server, never into the offline queue.

## Permissions (Core 0.6.0)

| Permission | Allows | Level |
|---|---|---|
| customers.view | Look up customers and balances (till lookup too) | 1 |
| customers.manage | Create/edit customers and consent | 2 |
| customers.credit.manage | Credit limit, terms, hold, opening balance (HQ) | 4 |
| customers.credit.override | Release holds and approve credit sales, within limit | 3 |
| sales.orders.view | See quotes, orders, deliveries, invoices | 1 |
| sales.orders.manage | Create quotes and orders | 2 |
| sales.deliver | Deliver (issues stock, raises the invoice) | 2 |
| receivables.view | Statements, aged receivables | 2 |
| receivables.collect | Record payments | 2 |
| receivables.credit_note | Issue credit notes | 3 |

After upgrading, run `php artisan migrate` and `php artisan db:seed`.

## Sync

- **customers** group: profiles plus what customers owe.
  - Edits merge **field by field**: for each field the later change wins.
  - If the same field was changed on two servers at once, the losing value is put in the Review queue (*Customer edited in two places*).
  - Credit fields come only from head office.
- **receivables** group: quotes, orders, deliveries, invoices, payments and credit notes.
- Both groups flow both ways and are relayed by head office to every branch server.
- A branch's credit sales reach the account at HQ at the next sync.

## Sample data

`php artisan erp:demo-sales --as=<email>` adds three fictitious customers: two wholesale credit accounts and one cash customer.

## Not yet (6b and later)

| Item | Comes in |
|---|---|
| Promotions, loyalty and gift cards | 6b |
| Taking customer payments at the till | 6b |
| Returns and exchanges at the till | 6b |
| Sales reps and commissions | 6b |
| Campaigns (SMS/WhatsApp) | 12 |
| Customer portal | 12 |
| GRA E-VAT submission of invoices | Fiscal adapter |
| Posting to the general ledger | 7 |
