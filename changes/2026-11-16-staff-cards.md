### Added
- **Staff card generator** (owner request). **People & access → Staff cards**:
  - Tick the people, press **Print new cards**, and the cards print straight away: on an A4 sheet (10 cards with cut lines) or on a PVC card printer (one card per page).
  - Each card has the company colour and logo, the person's photo or initials, name, role and branch, and a **barcode** (Code 128) that every till scanner reads, laser or camera.
  - The print page works once; card codes are never kept.
  - **Card lost: stop it** stops cards at once.
- **A staff card alone can sign in at the till** (System → Settings → Tills and cash; off by default). When on, scanning the card signs the person in without the PIN, offline too where the till already has their sign-in. Approvals always need the PIN or a manager's yes.
### Fixed
- **Scanning a staff card on the till's sign-in screen:** the box switched to "Staff card scanned" as soon as the card's first characters arrived, so the rest of the code and the scanner's Enter may have been lost. Now the card is taken whole, then the PIN is asked for.
