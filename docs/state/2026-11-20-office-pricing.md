## New back office, part 8: pricing rules and prices to review (branch `office-pricing`, base main)
- **Server:** `App\Domain\Office\PricingScreen` (rules through `PricingRuleResource::getEloquentQuery`, options, product search, suggestions for the industries this person prices) and `Office\PricingController` (save rule, apply all, archive/restore, approve or keep ticked suggestions) through `PricingRules` and `ArchiveService`.
- **Routes:** `office-api/pricing-rules` (GET, POST), `pricing-rules/options`, `pricing-rules/products?q=`, POST `pricing-rules/apply-all`, POST `pricing-rules/{id}`, POST `pricing-rules/{id}/{archive|restore}`, `prices-to-review`, POST `prices-to-review/{approve|keep}`.
- **Client:** `pages/PricingRulesPage.tsx`, `pages/PricesToReviewPage.tsx`. `/admin/pricing-rules` and `/admin/prices-to-review` map to the new pages. API types are `PricingRule*` (the reorder rules already use `Rule*`).
- **Tests:** `OfficePricingTest` (real deliveries change the cost). office-check opens both from Stock.
- **Next:** part 9: promotions; then price list export and shared products.
