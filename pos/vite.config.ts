/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { readFileSync } from 'node:fs'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// The POS must load with the server unreachable, so the app shell is precached.
// API responses are deliberately NOT cached here: sale data lives in IndexedDB (Phase 3).
// Release builds are stamped with the build id (release:build); it is embedded in the bundle and the page.
const BUILD = process.env.VITE_BUILD_STAMP ?? 'DEV'

// The product's name and colours (branding/product/brand.json, the same source as `npm run brand`).
const PRODUCT = JSON.parse(readFileSync(new URL('../branding/product/brand.json', import.meta.url), 'utf8')) as { name: string; tile: string }

// `npm run build:server` (mode "server") builds the till into the server's public/pos folder, so the server itself hands
// it out at https://<server>/pos/ — same address as the API, no separate web server for the till.
export default defineConfig(({ mode }) => {
  const base = mode === 'server' ? '/pos/' : '/'
  return {
    base,
    // A release build (release:build --with-pos) writes into its own copy of the server via POS_OUT_DIR.
    build: {
      ...(mode === 'server' ? { outDir: process.env.POS_OUT_DIR ?? '../server/public/pos', emptyOutDir: true } : {}),
      // Libraries in their own files (owner request 2026-10-23): an update re-downloads only the till's own code, which
      // changes, not React, the offline store or live updates, which rarely do. All files are still precached, so the
      // till keeps working offline.
      rolldownOptions: {
        output: {
          codeSplitting: {
            groups: [
              { name: 'react', test: /node_modules[\\/](react|react-dom|scheduler)[\\/]/ },
              { name: 'storage', test: /node_modules[\\/](dexie|uuid)[\\/]/ },
              { name: 'live', test: /node_modules[\\/](pusher-js|laravel-echo)[\\/]/ },
              { name: 'vendor', test: /node_modules[\\/]/ },
            ],
          },
        },
      },
    },
    define: { __BUILD_STAMP__: JSON.stringify(BUILD) },
    plugins: [
      react(),
      { name: 'product-name', transformIndexHtml: (html: string) => html.replace(/%PRODUCT_NAME%/g, PRODUCT.name) },
      VitePWA({
        registerType: 'prompt',
        injectRegister: false, // registered by swUpdate.ts, which switches to a new version at the sign-in screen
        manifest: {
          name: `${PRODUCT.name} Till`,
          short_name: 'Till',
          display: 'standalone',
          start_url: base,
          scope: base,
          theme_color: PRODUCT.tile,
          background_color: '#ffffff',
          icons: [
            { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
            { src: 'icon-512.png', sizes: '512x512', type: 'image/png' },
            { src: 'icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
            { src: 'favicon.svg', sizes: 'any', type: 'image/svg+xml' },
          ],
        },
        workbox: { navigateFallback: `${base}index.html`, runtimeCaching: [] },
      }),
    ],
    server: {
      port: 5173,
      proxy: { '/api': 'http://127.0.0.1:8010', '/up': 'http://127.0.0.1:8010', '/brand': 'http://127.0.0.1:8010' },
    },
    test: { environment: 'node', exclude: ['e2e/**', 'node_modules/**'] },
  }
})
