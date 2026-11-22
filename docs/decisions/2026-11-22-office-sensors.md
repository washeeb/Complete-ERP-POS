## Sensors in the new back office
- **The sensor address is its own request, for people who manage sensors only:** it is a secret (anyone with it can send readings), so it is not part of the sensor's page data.
- **Cloud passwords and secrets never come back to the page:** the form shows them empty, and empty keeps the saved one, as the current form.
- **The 24-hour chart is drawn in the page** from the readings the server sends (the current page draws the same on the server).
- **Printing the temperature log prints only the log:** a print style hides the menus and filters, as the current page's print does.
- **office-check's "old page inside" step moved to Sales → Online orders**, the next page not rebuilt that the dev company has switched on.
