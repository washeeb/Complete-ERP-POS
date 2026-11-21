## Purchase requests, RFQs and contracts in the new back office
- **One screen class for the three**, as they share the buying line (a product unit, or a service with its kind of spending) and the lists' shape; one `ProcList` on the client for the three lists.
- **A request for quotation's prices stay sealed** exactly as the current page: the comparison and totals are only sent once the closing time passed or the buyer closed it (`Rfq::isUnsealed`), so a person cannot read them from the page's data early.
- **The signed agreement goes up with the form** (multipart, lines as JSON), stored like the current form's upload; replacing it is optional when editing.
