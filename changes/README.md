# Change notes

One file per change, named `YYYY-MM-DD-topic.md` (the date the change was made, a few words for the topic), added in
the same PR as the change. Write it as it should read in `CHANGELOG.md`:

```markdown
### Fixed
- **What was wrong, in plain words** (who reported it). What happens now.
### Changed
- …
```

Kinds: Added, Changed, Fixed, Security, Removed.

**Why one file per change:** every PR used to edit the top of `CHANGELOG.md` (and of `docs/STATE.md`, and the end of
`docs/DECISIONS.md`), so any two open PRs collided. New files never collide. Before a release, `npm run notes` (in the
`pos` folder, on an up-to-date main, in its own PR) folds them into `CHANGELOG.md` and deletes them. The same goes for
`docs/state/` (resume notes, into `docs/STATE.md`) and `docs/decisions/` (decisions, into `docs/DECISIONS.md`).
