### Added
- **The new back office, first part** (owner decision: a Windows-style app in React and Microsoft's Fluent UI, replacing today's screens step by step). At `/office/`:
  - the company's colours and logo;
  - two ways to show the menu: **across the top** (the default) or a **slim side menu** with Create at the top, chosen per person;
  - one search box (Ctrl+K) for pages and records;
  - **Create** for anything the person may add;
  - light or dark as the person chose.
- The menu is exactly the current back office's for that person (permissions, modules, licence). Pages not yet rebuilt open inside the frame without their own menus, and the address bar, Back and bookmarks follow them. Signed out, it signs you in and brings you back. **Classic back office** goes back.
