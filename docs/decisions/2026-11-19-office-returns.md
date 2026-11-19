## Returns in the new back office
- **Built on the till sales list's filters,** so a return is found the same way as a sale (period, person, search) and branch access is the same.
- **The return slip is one method used by both back offices** (`ReturnsReport::slipPdf`), so the slip cannot differ between them; the new back office calls it without the classic page's on-screen notices.
- **The 30-day summary keeps the current rule:** one person is named when they did more than half of the returns and at least five.
