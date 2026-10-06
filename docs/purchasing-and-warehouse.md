# Purchasing and warehouse

Phase 5. From ordering goods to having them on the right shelf, moving them between warehouses, and counting them.

## The flow

```
Supplier → Purchase order (draft → submitted → approved) → Goods received (GRN) → receiving dock
        → Put away into bins → Transfer to another warehouse (in transit) → Received there → Counted
```

All of this is in the back office (**Purchasing** and **Stock** menus) and, for warehouse staff, on the **handheld** (a scanner phone running the till app, enrolled as a *Handheld scanner*).

## Suppliers (Purchasing → Suppliers)

For each supplier you can record:
- code, name, contact person, phone and email;
- TIN/VAT number;
- payment terms and delivery lead time.

Under **Products supplied**, list each product in the unit you buy it in (for example a carton of 24), with:
- the supplier's own code;
- your last cost before tax;
- the minimum order;
- whether this is your preferred supplier for the product.

Purchase orders and reorder suggestions fill in from this list. Receiving goods updates the last cost.

## Purchase orders (Purchasing → Purchase orders)

1. **New purchase order**:
   - choose the supplier and the warehouse to deliver to, then add lines (product and unit, quantity, cost);
   - a blank cost uses the supplier's last cost;
   - tax is worked out from each product's tax group, and costs are before tax.
2. **Submit for approval.**
3. **Approve** or **Send back** (with a reason). The rules:
   - **The person who created the order can never approve it.**
   - The order total must be within the approver's limit:

     | Role | Default approval limit |
     |---|---|
     | Purchasing Officer | GHS 5,000 |
     | Branch Manager, Warehouse Manager | GHS 20,000 |
     | Finance Manager, General Manager, Purchase Approver, Owner | no limit |

     Limits can be changed per person (Users → access → Limits, key `po_approve_minor`, in pesewas).
4. **Receive goods** (on the order, or on the handheld). See below.
5. If the supplier will not deliver the rest, use **Close short**.
6. **Cancel order** works only while nothing has been received.

Numbers are `PO-<node>-000001`, one sequence per server, so branch servers never clash.

## Goods received (GRN)

- Receiving posts the goods into stock at their cost.
  - Stock is valued at **weighted average** cost per product and warehouse. Each receipt re-averages; sales and transfers go out at the current average.
  - In a warehouse with bins, the goods go to the **receiving dock** unless you choose a bin.
- **Over-delivery.** Up to **5 %** more than ordered is accepted (setting: *Purchasing → Over-delivery accepted without a manager*). More than that:
  - the GRN is **held**: nothing enters stock, and a review item is raised;
  - someone with *approve deliveries* must **Accept** it (the whole delivery is posted) or **Refuse** it (nothing is posted; receive the right quantity again).
  - A manager receiving more than the tolerance themselves is recorded as having accepted it.
- **Without an order:** only a manager can receive goods that have no purchase order.
- **Mistakes:** a posted GRN never changes. **Reverse** it (manager, with a reason):
  - the goods are taken back out of the same bins;
  - the order quantities reopen;
  - the note stays, marked *Reversed*.
- **Handheld resends:** a handheld sends each delivery with its own id. If its connection drops and it sends again, the goods are received once.

## Bins (Stock → Bins)

- **Bins on/off** works per warehouse. With bins **on**:
  - a **receiving bin (RECV)** is created;
  - stock already in the warehouse is placed in it; the warehouse total does not change;
  - every movement names a bin.
- Create bins with codes such as `A-01-02` (aisle-bay-level) and print the code as a barcode on the shelf.
- **Pick order** decides where sales and transfers take stock from: lower numbers first, and the receiving bin last.
- **Move stock** (put-away) moves between two bins. The warehouse total does not change.
- Bins can be switched off only when every bin is empty. A bin holding stock cannot be deactivated.
- `php artisan erp:stock-check` proves that every bin, and every warehouse, equals its movements. It also checks that a bin-managed warehouse equals the sum of its bins.

## Transfers (Stock → Transfers)

1. **New transfer**: choose from and to warehouses and add lines.
2. **Dispatch** takes the goods out of the sending warehouse (at its average cost). They are now **in transit**, in neither warehouse.
3. **Receive** at the destination: enter what actually arrived, and optionally a bin.
   - Any difference is flagged as a review item (*Transfer arrived with a different quantity*) and the transfer is marked *Difference*. Nothing is silently absorbed.
4. A draft can be cancelled. A transfer in transit must be received; enter 0 for anything lost.

## Stock counts (Stock → Stock counts)

1. **Start a count** for a whole warehouse or one bin. The expected quantities are frozen at that moment.
   - Counts are **blind** by default: counters do not see expected figures.
2. Counters enter what they find in the back office (*Enter a count*) or on the handheld (scan the bin, then products, then type the quantity).
   - Products not on the list can be counted too.
3. **Submit for approval.** Products nobody counted are left unchanged; they are not set to zero.
4. A **different person** with *approve and post counts* posts it.
   - The differences become count movements, with the difference value at average cost.
   - Count a busy area while it is quiet: sales during the count are kept as they are.

## Reorder levels (Purchasing → Reorder levels, What to order)

- Set **minimum** and **maximum** (in the base unit) per product and warehouse.
- **What to order** lists products where *on hand + on order + in transit* is below the minimum.
  - The suggested quantity tops up to the maximum, in the preferred supplier's unit and at least their minimum order.
- Select rows and choose **Create draft purchase orders**. One draft is created per supplier, ready to check and submit.

## Handheld

1. Register the device under System → Devices as **Handheld scanner** (assign the branch) and enrol it with the one-time code.
2. Staff sign in with their PIN. They need receive, transfer or count rights at that branch; till access is not needed.
3. The menu shows only what the person may do:
   - **Receive delivery**: pick the order, scan each item (each scan adds one unit), choose a bin, then post;
   - **Put away / move**;
   - **Receive transfer**;
   - **Count stock**.
4. A hardware scanner types the code and presses Enter; the camera is not used.

The handheld works against its branch's server over the local network. If that server is unreachable it shows the error and does not queue work, unlike the till.

## Permissions (Core 0.5.0)

| Permission | What it allows | Level |
|---|---|---|
| suppliers.manage | Suppliers and the products they supply | 2 |
| purchasing.view | See purchase orders, goods received, supplier prices | 1 |
| purchasing.manage | Create and submit purchase orders; close short; cancel | 2 |
| purchasing.approve | Approve within limit (never own orders) | 2 |
| stock.receive | Receive goods, put away | 2 |
| stock.receive.approve | Accept/refuse over-deliveries, reverse GRNs, receive without an order | 3 |
| stock.transfer | Create, dispatch and receive transfers | 2 |
| stock.count | Start counts and enter counted quantities | 2 |
| stock.count.approve | Post counts (not your own) | 3 |
| warehouse.manage | Bins on/off, bins, reorder levels | 3 |

After upgrading, run `php artisan migrate` and `php artisan db:seed`. Standard roles receive these permissions and their default limits; changes an owner has made are not overwritten.

## Sync between servers

- Suppliers, purchase orders and goods received form the **purchasing** sync group. Bins, transfers, counts and reorder levels form the **warehouse** group. Both flow in both directions.
- A branch server can receive an HQ order. Its GRN, order status, stock and cost then reach HQ at the next sync; this is tested with two databases.
- Head office **relays** these groups and stock movements. What branch server A sends reaches branch server B at B's next sync (it is never echoed back to A), so a transfer from one branch server to another arrives there to be received.

## Sample data

`php artisan erp:demo-purchasing --as=<email>` adds, after `erp:demo-catalog`:
- three sample suppliers;
- bins at the head-office warehouse;
- reorder levels.
