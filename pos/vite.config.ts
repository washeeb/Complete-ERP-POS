/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// The POS must load with the server unreachable, so the app shell is precached.
// API responses are deliberately NOT cached here: sale data lives in IndexedDB (Phase 3).
// Release builds are stamped with the build id (release:build); it is embedded in the bundle and the page.
const BUILD = process.env.VITE_BUILD_STAMP ?? 'DEV'

// `npm run build:server` (mode "server") builds the till into the server's public/pos folder, so the server itself hands
// it out at https://<server>/pos/ — same address as the API, no separate web server for the till.
export default defineConfig(({ mode }) => {
  const base = mode === 'server' ? '/pos/' : '/'
  return {
    base,
    // A release build (release:build --with-pos) writes into its own copy of the server via POS_OUT_DIR.
    build: mode === 'server' ? { outDir: process.env.POS_OUT_DIR ?? '../server/public/pos', emptyOutDir: true } : {},
    define: { __BUILD_STAMP__: JSON.stringify(BUILD) },
    plugins: [
      react(),
      VitePWA({
        registerType: 'prompt',
        manifest: {
          name: 'Synclytics POS',
          short_name: 'POS',
          display: 'standalone',
          start_url: base,
          scope: base,
          theme_color: '#1f2937',
          background_color: '#ffffff',
          icons: [{ src: 'favicon.svg', sizes: 'any', type: 'image/svg+xml' }],
        },
        workbox: { navigateFallback: `${base}index.html`, runtimeCaching: [] },
      }),
    ],
    server: {
      port: 5173,
      proxy: { '/api': 'http://127.0.0.1:8010', '/up': 'http://127.0.0.1:8010' },
    },
    test: { environment: 'node', exclude: ['e2e/**', 'node_modules/**'] },
  }
})
