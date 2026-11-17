## The product form in the new back office
- **Saved by the same service, through one translation layer.** `ProductForm::save` turns the form's values into exactly what the current form sends to `CatalogService::saveProduct`. All rules (SKU, PLU, medicines need batches, serials versus batches, packs without stock, recipes, the buying unit) stay in one place.
- **Hidden sections are not sent.**
  - Found: sending empty values for a module the company does not use would clear data a product may still hold (for example from an import).
  - Decided: only the sections shown are sent.
- **The base unit is not sent once stock has moved.** The service would refuse it. The form shows it locked with the way to change it (Change the unit on the product page), so a stray pick cannot block the save.
- **Prices need the pricing permission for the product's industry**, as on the current form. Without it they are neither shown nor sent, so they are never wiped.
- **Quick add picks an existing entry** with the same name (or unit code) instead of making a second one, as the current form's "+" does.
- **Form fields live outside the page component**, so typing never rebuilds them. The browser check types into a field to prove it.
