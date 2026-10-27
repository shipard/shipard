/**
 * Verb `login` — jediné místo, kde runner potřebuje heslo.
 *
 * Ostatní verby už jen čtou uloženou session. Když vyprší, `record` skončí
 * hláškou; nikdy z toho nesmí vzniknout video přihlašovací stránky.
 */

import { chmod } from 'node:fs/promises';
import { relative } from 'node:path';

import { launchChromium, DEFAULT_TIMEOUT } from '../browser.mjs';
import { pageUrl, PROJECT_ROOT } from '../config.mjs';
import { UserError } from '../errors.mjs';
import { APP_PATH, SELECTORS } from '../shipard.mjs';

export default async function login({ config }) {
  const url = pageUrl(config, APP_PATH);
  console.log(`Přihlašuji ${config.login} na ${url}`);

  const browser = await launchChromium({
    headless: !config.headful,
    hostResolverRules: config.hostResolverRules,
  });
  try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await context.newPage();

    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: DEFAULT_TIMEOUT });

    // SPA vykresluje formulář až po načtení bundlu, takže se čeká na pole,
    // ne na navigaci.
    try {
      await page.waitForSelector(SELECTORS.loginName, { timeout: DEFAULT_TIMEOUT });
    } catch {
      throw new UserError(
        `Na ${url} se neobjevil přihlašovací formulář (${SELECTORS.loginName}).`,
        'Sedí SHPD_BASE_URL včetně ID datového zdroje? Běží instance?',
      );
    }

    await page.fill(SELECTORS.loginName, config.login);
    await page.fill(SELECTORS.loginPassword, config.password);
    // LoginScreen.svelte odesílá formulář na Enter — na tlačítko se neklikáme,
    // jeho popisek se mění s jazykem.
    await page.press(SELECTORS.loginPassword, 'Enter');

    try {
      await page.waitForSelector(SELECTORS.appShell, { timeout: DEFAULT_TIMEOUT });
    } catch {
      // Chybová hláška se čte až tady: kdyby se na ni čekalo souběžně,
      // trefila by se do předvyplněné chyby z předchozího pokusu.
      const message = await page.locator(SELECTORS.loginError).first()
        .textContent().catch(() => null);
      throw new UserError(
        message
          ? `Přihlášení odmítnuto: ${message.trim()}`
          : 'Přihlášení neproběhlo — aplikace se nenačetla.',
        'Zkontroluj SHPD_LOGIN a SHPD_PASSWORD v .env.',
      );
    }

    await context.storageState({ path: config.storageState });
    // Session je stejně citlivá jako heslo — ať ji nečte kdokoli na stroji.
    await chmod(config.storageState, 0o600);

    console.log(`Session uložena do ${relative(PROJECT_ROOT, config.storageState)}`);
  } finally {
    await browser.close();
  }
}
