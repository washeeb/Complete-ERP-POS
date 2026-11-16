### Added
- **The new back office, first part** (owner decision: a Windows-style app in React and Microsoft's Fluent UI, replacing today's screens step by step). At `/office/`:
  - the company's colours and logo;
  - two ways to show the menu: **across the top** (the default) or a **slim side menu** with Create at the top, chosen per person;
  - one search box (Ctrl+K) for pages and records;
  - **Create** for anything the person may add;
  - light or dark as the person chose.
- **Home, Recent and Favourites as icons at the start of the menu** (owner request), in both looks:
  - the clock lists the pages opened lately;
  - the star lists your favourite pages and adds or removes the page on screen; it is filled when that page is a favourite. It uses the same favourites as the classic back office.
- **A new Home**, after the owner's NetSuite-style template, with every panel:
  - **Reminders:** what waits for you, the urgent ones big;
  - **Tiles:** big coloured shortcuts, chosen under Personalize;
  - **Key performance indicators:** cards with a trend line, and the table of this period against the period before, with the change;
  - **KPI meter:** a dial for the figure you pick;
  - **Top 15 items by quantity sold:** donut, bars or table, for its own period;
  - **Monthly trend:** sales and buying over 12 months as columns, lines or a table, with a 3-month average;
  - **Shortcuts:** favourites, quick add, pages opened lately.

  **Viewing** sets the period, **Personalize** the panels and tiles, **Layout** two or three columns. Panels are dragged by their handle (or moved from their ⋯ menu); each person's arrangement is kept.
- The menu is exactly the current back office's for that person (permissions, modules, licence). Pages not yet rebuilt open inside the frame without their own menus, and the address bar, Back and bookmarks follow them. Signed out, it signs you in and brings you back. **Classic back office** goes back.
