## New back office, release 5 part 3: goods received (branch `office-goods-received`, stacked on `office-purchase-orders`)
- **Server:** `App\Domain\Office\GoodsReceiptsScreen` (`filtered` / `sorted` / `rowOf` on `GoodsReceiptResource::getEloquentQuery`: q on number, delivery note, supplier, order; status; warehouse; cards value posted and held; `show` with lines, product ids for labels, and actions accept / refuse / reverse for `stock.receive.approve`, labels, waybill view-or-create). `Office\GoodsReceiptsController`: index, options, show, `action` through `GoodsReceiptService`. `ListExport` gains `goods-receipts`.
- **Client:** `pages/GoodsReceiptsPage.tsx`, `GoodsReceiptPage.tsx` (Print labels opens the Products page's `ProductAction` labels dialog with the note's products). `REBUILT` maps `/admin/goods-receipts` (a search carries over; the purchase order's "Goods received" lands here) and `/{uuid}`, to `/office/goods-received`.
- **office-check:** the list, a note, and the labels dialog (nothing made).
- **Tests:** OfficeGoodsReceiptsTest (3).
- **Next:** release 5 part 4, direct purchases.
