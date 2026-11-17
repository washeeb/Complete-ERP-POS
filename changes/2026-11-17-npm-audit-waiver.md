### Changed
- **The build's security audit** now runs through `npm run audit`. It still stops the build on any serious advisory, except one listed with its reason and an end date. The first such waiver is a flaw in a build-only tool that has no fixed release yet (picomatch, until 31 December 2026); nothing in the till or the browser is affected.
