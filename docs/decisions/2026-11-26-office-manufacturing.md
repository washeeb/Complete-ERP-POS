## Manufacturing in the new back office
- **New work order stays a dialog,** as on the current list (its own page would add nothing); it is shared by the list and offered from a BOM's Make… and from planning's Raise.
- **Issue and Return show one quantity box per material line,** the same lines the current modals list, so materials by hand and returns need no product search.
- **A BOM in use cannot open its form:** the server refuses with "Start a new revision", as the current page hides Edit; New revision opens the draft's form at once.
- **Planning uses the current page's own plan** (`ProductionPlanning::plan()`), so its suggestions and "Raise" give exactly what the current page gives.
- **Whole-number fix in four earlier screens:** found by this part's test (run minutes "30" came back "3"); it would have shown wrong numbers wherever a value is stored without decimals, so it is fixed for every install.
- **Verified with the module on:** the dev company has Manufacturing off. Manufacturing is an industry module, so HQ was put back to company level (no industry) before and after one check:office run. This also undid HQ being left as a Services branch by the part 26 check: switching an industry module off leaves the branch in that industry.
