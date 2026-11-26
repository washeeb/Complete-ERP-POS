## Today in the new back office
- **Same figures, one endpoint:** the page reads `OwnerToday::build` as the current page does, so the daily WhatsApp summary, the current page and the new one can never disagree.
- **Kept "Add to Home screen":** the current page had a web manifest; without one the new Today would open as a browser tab. `office.webmanifest` starts at `/office/today`; phones that saved the old shortcut keep working, as `/admin/today` still opens.
- **Yesterday is in the address** (`?yesterday=1`), as the current page, so a saved link opens the same day view.
- **The "page inside" check moved to Departments:** with Today rebuilt, Departments (opened from Employees) is a current page still shown inside the new look.
- **Not every old page is rebuilt yet:** part 36 called Today the last one; Departments, Tax and SSNIT rules, My profile and the Setup wizard still open as current pages. The owner decides whether they are rebuilt next.
