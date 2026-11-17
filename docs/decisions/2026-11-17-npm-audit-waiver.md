## A dated waiver for one npm advisory with no fix yet
- **Found:** CI's `npm audit --audit-level=high` began failing on every PR because of GHSA-fc5q-39xc-vjpf: picomatch ReDoS, every release up to 4.0.7.
  - 4.0.7 is the newest picomatch, so no update can fix it.
  - It reaches only build tools: vite, vitest and vite-plugin-pwa (via tinyglobby, fdir and workbox-build), all devDependencies, matching our own file patterns at build time.
  - `npm audit --omit=dev` finds nothing: the till and the browser never run it.
- **Decided (owner, 2026-11-17):** waive exactly this advisory until 2026-12-31 rather than stop auditing build tools.
  - `pos/scripts/audit.mjs` (`npm run audit`, used by CI) still fails on any other high or critical advisory, and on this one once its waiver has ended. This was checked both ways.
  - When picomatch ships a fixed release: update it (`npm update picomatch`) and remove the waiver.
