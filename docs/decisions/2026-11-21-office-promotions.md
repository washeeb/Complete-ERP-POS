## Promotions in the new back office
- **Every rule stays in PromotionService:** the numbers each kind needs, unique coupon codes, both happy-hour times or neither, the end after the start. Percentages and money go through `PromotionResource::toDomain`, so the new and classic forms store the same values.
- **Only the fields a kind asks for are sent.** The current form hides the others and does not save them; the server drops them too, so an amount typed before switching to Buy X get Y is not kept.
- **Who sees what is unchanged:** people with catalog access see the list without New or Change; changing needs promotions.manage on head office (branch servers only receive promotions).
- **Archived branches already chosen stay on the promotion** and show as "(archived)", as now.
