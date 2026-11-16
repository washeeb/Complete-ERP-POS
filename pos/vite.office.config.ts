/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { readFileSync } from 'node:fs'
import { defineConfig } from 'vite'

// The new back office (owner decision 2026-11-15: React + Fluent UI, a Windows-style app). `npm run build:office` writes it
// into the server's public/office folder, so the server hands it out at https://<server>/office/ next to the current back office.
// Development: `npm run dev:office` (port 5175) with the server on 8010; sign in at /admin first, in the same browser.
const BUILD = process.env.VITE_BUILD_STAMP ?? 'DEV'
const PRODUCT = JSON.parse(readFileSync(new URL('../branding/product/brand.json', import.meta.url), 'utf8')) as { name: string }

export default defineConfig({
  root: 'office',
  base: '/office/',
  build: {
    outDir: process.env.OFFICE_OUT_DIR ?? '../../server/public/office',
    emptyOutDir: true,
    rolldownOptions: {
      output: {
        codeSplitting: {
          groups: [
            { name: 'react', test: /node_modules[\/](react|react-dom|scheduler)[\/]/ },
            { name: 'fluent', test: /node_modules[\/](@fluentui|@griffel|tabster|keyborg)[\/]/ },
            { name: 'vendor', test: /node_modules[\/]/ },
          ],
        },
      },
    },
  },
  define: { __BUILD_STAMP__: JSON.stringify(BUILD) },
  plugins: [react(), { name: 'product-name', transformIndexHtml: (html: string) => html.replace(/%PRODUCT_NAME%/g, PRODUCT.name) }],
  server: {
    port: 5175,
    proxy: Object.fromEntries(['/office-api', '/admin', '/livewire', '/brand', '/user-photo', '/css', '/js', '/fonts', '/print', '/push-sw.js']
      .map((p) => [p, { target: 'http://127.0.0.1:8010', changeOrigin: false }])),
  },
  test: { environment: 'node', root: '.', include: ['office/src/**/*.test.ts'] },
})
