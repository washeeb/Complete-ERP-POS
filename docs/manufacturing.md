# Manufacturing (Phase 10f)

Owner decision: production is costed at **actual cost**.

## Set-up
- **Work centres** — where work is done, with a cost per hour (labour + overhead) and hours per day (capacity).
- **Bills of materials (BOMs)** — per product: output per batch, materials (quantity per batch, expected loss %, backflush or by hand), by-products, quality check required, and the routing (operations with work centre, set-up and run minutes per batch, backflushed or recorded).
  - Draft → **In use** → Replaced. One BOM in use per product; a BOM in use never changes — **New revision** copies it to a draft that replaces it when put in use.
  - Multi-level: a material with its own BOM is a sub-assembly. Loops (a product made from itself at any depth) are refused.
  - Serial-numbered items cannot be in a BOM yet.
  - The BOM page shows an **estimated cost per unit** from today's average costs and work-centre rates.

## Work orders
Draft → **Released** (materials and operations copied from the BOM; optionally draft orders are raised for sub-assemblies the warehouse is short of) → **In production** → **Finished** (or Cancelled while nothing has been done).

| Step | What happens to stock | What happens to cost |
|---|---|---|
| Issue materials (by hand) | materials leave the warehouse | their average cost goes into work in progress (WIP) |
| Report output (good, scrapped) | backflushed materials leave for good + scrapped units | backflushed operations charged at planned minutes × rate |
| Record time | — | minutes × work-centre rate into WIP |
| Good output (or passed at the quality check) | by-products come in at their average cost; the product comes in | WIP so far ÷ good units still expected, so the last units take what is left |
| Quality check | passed → stock; failed → scrapped | scrapped units add nothing; their cost stays with the good units |
| Return materials | unused materials back | at the cost this order paid for them |
| Finish | — | anything left in WIP → *Production cost differences* |

Issuing and backflushing never take stock below zero. Finishing with fewer made than planned needs a reason. A job card PDF lists materials and operations.

## Accounts (automatic posting)
- Materials issued / returned, output and by-products: stock movements against **1340 Work in progress (production)**.
- Labour and overhead: Dr 1340 WIP, Cr **5500 Labour and overhead charged to production** (this offsets the wages and running costs already in expenses).
- Left over at finish: Dr **5600 Production cost differences**, Cr 1340.

## Production planning (MRP)
Per warehouse: demand = open customer orders + reorder levels (min → max) + materials open work orders still need; supply = stock on hand + output still to come from open work orders (made items) or approved purchase orders not yet received (bought items). Netted level by level down the BOMs. Shows **Make** (with *Raise work order* / *Raise all*), **Buy** and **Work centre load** (hours and days of work). Suggestions only — nothing is ordered by itself.

## Permissions and roles
`manufacturing.setup` (level 4), `manufacturing.produce` (2), `manufacturing.qc` (3). Roles: Production Manager, Machine Operator, Quality Inspector; General and Branch Managers get all three; Supervisors can run orders.

## Sync
Group `production` (both ways, relayed): work centres, BOMs (with lines), work orders (with materials), cost entries, quality checks.

## Deferred
Serial-numbered components and outputs, standard costing, capacity-aware scheduling (finite), subcontracting, a shop-floor tablet screen, raising purchase orders straight from MRP.
