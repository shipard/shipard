import { defineConfig } from 'vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'

// Druhý vstup: vývojářský dashboard `/_dev/` (tasks/testing-phase6a.md,
// #103 D57). Samostatný config místo druhého vstupu ve vite.config.js —
// každý build má vlastní outDir s emptyOutDir, jinak by si výstupy mazaly.
// Zdroje (komponenty, styly, ikony) sdílí z frontend/src/, texty jsou
// jen česky (žádný slovník). `npm run build` staví oba vstupy.
//
// Servírování: nginx `try_files $uri /index.php` pošle `/_dev/…` bez
// souboru do PHP, DevDashboardController vrátí `public/_dev/dev.html`
// (jen v módu development); soubory v `public/_dev/assets/` servíruje
// nginx přímo. Viz docs/operations/production.md k produkci.
export default defineConfig({
  plugins: [svelte()],
  base: '/_dev/',
  // PWA ikony z frontend/public/ patří jen hlavní aplikaci.
  publicDir: false,
  build: {
    outDir: '../public/_dev',
    emptyOutDir: true,
    rollupOptions: {
      input: 'dev.html',
      output: {
        manualChunks(id) {
          if (id.includes('node_modules')) return 'vendor'
        },
      },
    },
  },
  server: {
    proxy: {
      '/_dev/api': {
        target: 'http://localhost:80',
        changeOrigin: true,
      },
    },
  },
})
