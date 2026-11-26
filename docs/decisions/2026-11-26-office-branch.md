## The branch switch
- **Owner 2026-11-26: free; filter + default.** A chosen branch narrows every list, report, the Business Portal and To-do, and new documents start with it.
- **One chokepoint:** `Erp::branchIds` already narrowed by industry for 172 call sites; the branch joins it there, so no screen needed its own change, and it can only narrow what a person may see, never widen it.
- **Defaults come from the narrowed choices:** forms already fill in the only/first store they offer, so with a branch chosen they offer (and fill in) its store. Choosing another branch on a form means switching at the top, as with the industry.
- **Kept in the session, like the industry,** not saved per person: it is a "where am I looking now" choice; signing in again starts with all branches.
- **On a phone** the two boxes do not fit beside the search, bell and name; they sit behind one button there (the layout check caught a 46 px overflow).
