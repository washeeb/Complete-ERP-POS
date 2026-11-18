## Goods received in the new back office
- **Same service, same rules.** Accept, refuse and reverse go through `GoodsReceiptService`; notes are still made only by receiving an order (or on the handheld), as now.
- **Labels reuse the Products dialog** (the same choices and the same label service), given the products on the note, so labels look the same wherever they are printed.
- **Supplier waybill** opens the current waybill page (not rebuilt yet): the note's waybill if there is one, else a new inbound waybill for it.
- **The address is `/office/goods-received`**, the words on the menu, rather than the classic `goods-receipts`.
