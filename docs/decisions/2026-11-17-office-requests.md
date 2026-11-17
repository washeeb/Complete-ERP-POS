## Stock requests in the new back office
- **StockRequestService decides everything:**
  - who may approve, and that the person who asked cannot approve;
  - the order of approvals between industries;
  - the own-product rule and the serial-number refusal when sending;
  - the reasons.
  The screens only gather what the person types and show what the service allows.
- **"Waiting on you" in SQL.** The current menu badge loads every open request and filters in PHP.
  - The new list needs the same rule as a filter that can be paged, so `RequestsScreen::waitingOnMe()` writes it as a query.
  - Rows still show the current list's wording ("Awaiting approval (asking side)").
- **"They have" shows any warehouse's stock,** as the current form does. A branch needs to see what it is asking for. The shared `stock/unit-stock` endpoint is limited to warehouses the person may see, so requests have their own endpoint.
- **The own-product search** between industries is open only to someone who may send that request now. It searches the supplier's industry, plus products with no industry.
- **The Send dialog asks for an own product only on lines with a quantity above 0.** The service needs one only for lines actually sent.
- **The framed example in the office check** moved to Waybills, which is not rebuilt yet.
