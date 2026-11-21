## Price list and shared products in the new back office
- **The ticked products stay in the session, not the address:** hundreds may be ticked. The new page reads them (without removing them) while `only=1` is in the address, so paging and Export see the same products.
- **Export takes every column this person may see,** including stock (and cost and margin for people who set prices), whatever is switched on screen. The classic export followed the columns shown; a customer copy still never carries cost, because that depends on the person, not the switch.
- **The product filter keeps names only for products picked on this visit;** after a reload a picked product shows as "Chosen product" (the filter still applies).
- **Moving shared products stays in SharedProducts:** the ticked ones go to their single suggested industry and the rest stay; Move all only to a licensed industry.
