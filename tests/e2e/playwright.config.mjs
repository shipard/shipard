// Konfigurace proklikání datových sad (#103 F7a, D89). Vstupy jen z
// prostředí — nastavuje je `shpd-ds dataset-e2e` (docs/test-corpus.md § 19):
//   SHPD_E2E_APP_URL        adresa aplikace zdroje dat (…/<ds-id>/app/)
//   SHPD_E2E_STORAGE_STATE  soubor Playwright storageState se session (0600)
//   SHPD_E2E_OUTPUT_DIR     adresář běhu (report.json, playwright-report/, traces/,
//                           walkthrough.json); ručně výchozí tests/e2e/.out
//   SHPD_E2E_EXPECTED_CARDS počet zpráv sady s otevřeným návrhem
//   SHPD_E2E_PROGRESS_FILE  volitelně JSON řádky průběhu (`card` události)
// Chybějící povinná proměnná shodí už sběr testů s jejím jménem (D95 —
// selhání je nález, ne tichý běh nad ničím).
import { defineConfig } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { readEnv, PER_CARD_TIMEOUT_MS, BASE_TIMEOUT_MS } from './lib/walkthrough.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const env = readEnv(process.env, { defaultOutputDir: path.join(here, '.out') });

export default defineConfig({
  testDir: here,
  testMatch: /.*\.spec\.mjs$/,
  outputDir: path.join(env.outputDir, 'test-results'),
  // Jeden projekt, jeden worker, žádné opakování: selhání je nález, ne
  // nestabilita (D89). Trace řídí spec sám po kartách (I2) — zde vypnutý.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: true,
  reportSlowTests: null,
  timeout: BASE_TIMEOUT_MS + env.expectedCards * PER_CARD_TIMEOUT_MS,
  expect: { timeout: 15_000 },
  reporter: [
    ['list'],
    ['json', { outputFile: path.join(env.outputDir, 'report.json') }],
    ['html', { outputFolder: path.join(env.outputDir, 'playwright-report'), open: 'never' }],
  ],
  use: {
    baseURL: env.appUrl,
    storageState: env.storageState,
    channel: 'chromium',
    headless: true,
    locale: 'cs-CZ',
    timezoneId: 'Europe/Prague',
    viewport: { width: 1440, height: 900 },
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    trace: 'off',
    screenshot: 'off',
    video: 'off',
  },
});
