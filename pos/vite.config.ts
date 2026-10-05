/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// The POS must load with the server unreachable, so the app shell is precached.
// API responses are deliberately NOT cached here: sale data lives in IndexedDB (Phase 3).
export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'prompt',
      manifest: {
        name: 'Synclytics POS',
        short_name: 'POS',
        display: 'standalone',
        start_url: '/',
        theme_color: '#1f2937',
        background_color: '#ffffff',
        icons: [{ src: 'favicon.svg', sizes: 'any', type: 'image/svg+xml' }],
      },
      workbox: { navigateFallback: '/index.html', runtimeCaching: [] },
    }),
  ],
  server: {
    port: 5173,
    proxy: { '/api': 'http://127.0.0.1:8000', '/up': 'http://127.0.0.1:8000' },
  },
  test: { environment: 'node' },
})
