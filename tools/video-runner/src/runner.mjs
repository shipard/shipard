/**
 * Sestavení prohlížeče pro průchod scénářem. Sdílené mezi `check`
 * a záznamem (`record`), aby se aplikace v obou případech vykreslila
 * stejně.
 */

import { existsSync } from 'node:fs';
import { chmod } from 'node:fs/promises';

import { DEFAULT_TIMEOUT, launchChromium } from './browser.mjs';
import { pageUrl } from './config.mjs';
import { UserError } from './errors.mjs';
import { installOverlay } from './overlay.mjs';
import { APP_PATH, isLoginScreen, SELECTORS } from './shipard.mjs';

/**
 * @param {import('./config.mjs').Config} config
 * @param {import('./scenario.mjs').Scenario} scenario
 * @param {object} [options]
 * @param {boolean} [options.headless]
 * @param {string[]} [options.args]
 * @param {number} [options.scale] Přebíje `capture.scale` ze scénáře — používá
 *   `check` při ladění s viditelným prohlížečem.
 * @param {(line: string) => void} [options.log]
 */
export async function createSession(config, scenario, options = {}) {
  if (!existsSync(config.storageState)) {
    throw new UserError(
      'Uložená session neexistuje.',
      'Spusť nejdřív: video-runner login',
    );
  }

  const log = options.log ?? ((line) => console.log(line));

  // Geometrie je pro `check` i záznam stejná a **nesmí** se dělat Playwrightním
  // `deviceScaleFactor`. Ten hustotu jen emuluje přes
  // `Emulation.setDeviceMetricsOverride` a CDP screencast pak posílá framy
  // ve velikosti emulovaného zařízení v DIP — tedy 1×, i když stránka hlásí
  // `devicePixelRatio: 2`. Skutečný raster ve dvojnásobné hustotě dělá
  // teprve `--force-device-scale-factor`, a rozměr okna musí přijít zvnějšku
  // (`viewport: null`), jinak ho Playwright přebíje zpátky na emulaci.
  const { width, height } = scenario.viewport;
  const scale = options.scale ?? scenario.capture.scale;

  const browser = await launchChromium({
    headless: options.headless ?? true,
    // Připíchnutá adresa hostitele (D97) — předává ji `shpd-ds video-run`.
    hostResolverRules: config.hostResolverRules,
    // Nativní prvky (datumová a číselná pole) formátuje Chromium podle
    // locale svého procesu, ne podle `locale` kontextu — bez toho má
    // české video data jako 10/12/2026.
    locale: scenario.locale,
    args: [
      `--window-size=${width},${height}`,
      '--window-position=0,0',
      `--force-device-scale-factor=${scale}`,
      // Headless scrollbary skrývá sám, headed ne. Bez tohohle by se
      // headful `check` lišil od záznamu o šířku scrollbaru a vypadalo by
      // to jako rozdíl aplikace, přitom je to rozdíl nastavení.
      '--hide-scrollbars',
      ...(options.args ?? []),
    ],
  });

  const context = await browser.newContext({
    storageState: config.storageState,
    viewport: null,
    // Jazyk aplikace se odvozuje z `navigator.language`, takže bez tohohle
    // by video vyšlo v angličtině (Playwright startuje v en-US). Jazyk
    // i zóna jdou ze scénáře (D121).
    locale: scenario.locale,
    timezoneId: scenario.timezone,
  });

  // Pevný čas dne (D120): `Date` v prohlížeči stojí na zadaném okamžiku,
  // takže „dnes“ a „před chvílí“ ve videu nezávisí na tom, kdy se točilo.
  // Musí přijít před první navigací — i ověření session načítá aplikaci
  // a ta by si čas přečetla ještě skutečný. Server počítá dnešek sám;
  // proto `video-run` dává za datum skutečný dnešek a mění jen čas dne.
  if (config.fixedTime) {
    await context.clock.setFixedTime(config.fixedTime);
    log(`  čas: ${config.fixedTime.toISOString()}`);
  }

  await installOverlay(context);
  const page = await context.newPage();
  await calibrateViewport(context, page, width, height);

  const close = async () => {
    // Backend při refreshi vydává nový token s novým expires_at. Nový
    // token dostane jen běžící prohlížeč — kdyby se neuložil, zůstal by
    // v souboru ten původní z verbu `login` a po TTL by umřel (Z10).
    // Ukládá se i po chybě běhu: horší než stav na disku být nemůže.
    try {
      await context.storageState({ path: config.storageState });
      await chmod(config.storageState, 0o600);
    } catch {
      // Kontext po pádu už nemusí žít — stará session na disku zůstane.
    }
    await browser.close();
  };

  return { browser, context, page, close };
}

/**
 * Dorovnání okna tak, aby viewport vyšel přesně na rozměr ze scénáře.
 *
 * Plné Chromium (`channel: 'chromium'`, viz browser.mjs) si z
 * `--window-size` ukrajuje výšku na okenní chrome: okno 1280×800 dá
 * viewport 1280×713. Headless shell nic neukrajoval, takže Z1 platilo
 * beze zbytku; spoléhat na konkrétní deltu ale nejde — mezi verzemi
 * Chromia se může hnout. Proto se viewport po startu změří a okno se
 * přes CDP `Browser.setWindowBounds` posune o rozdíl. Jednotky sedí:
 * `--window-size` i bounds jsou CSS px, hustotu řeší až
 * `--force-device-scale-factor`.
 *
 * @param {import('playwright').BrowserContext} context
 * @param {import('playwright').Page} page
 * @param {number} width  Cílový viewport v CSS px.
 * @param {number} height
 */
async function calibrateViewport(context, page, width, height) {
  const measure = () => page.evaluate(() => [window.innerWidth, window.innerHeight]);

  const cdp = await context.newCDPSession(page);
  try {
    for (let attempt = 0; attempt < 3; attempt++) {
      const [w, h] = await measure();
      if (w === width && h === height) return;

      const { windowId } = await cdp.send('Browser.getWindowForTarget');
      const { bounds } = await cdp.send('Browser.getWindowBounds', { windowId });
      await cdp.send('Browser.setWindowBounds', {
        windowId,
        bounds: {
          width: bounds.width + (width - w),
          height: bounds.height + (height - h),
        },
      });
      // Resize se do stránky propíše asynchronně; další měření počká,
      // až se skutečně stane, jinak by smyčka korigovala dvakrát totéž.
      await page
        .waitForFunction(
          ([tw, th]) => window.innerWidth === tw && window.innerHeight === th,
          [width, height],
          { timeout: 1000 },
        )
        .catch(() => {});
    }

    const [w, h] = await measure();
    if (w !== width || h !== height) {
      throw new UserError(
        `Viewport se nepodařilo dorovnat na ${width}×${height}, zůstal ${w}×${h}.`,
        'Změnilo se chování oken v nové verzi Chromia? Viz Z9 v tasks/video-runner-spike.md.',
      );
    }
  } finally {
    await cdp.detach().catch(() => {});
  }
}

/**
 * Ověří, že uložená session pořád platí — **dřív, než se začne nahrávat**.
 * Z vypršelé session nikdy nesmí vzniknout video přihlašovací stránky.
 *
 * Vedlejší efekt je užitečný: tahle první navigace zahřeje cache bundlu,
 * takže vlastní záznam nezačíná načítáním SPA.
 *
 * @param {import('playwright').Page} page
 * @param {import('./config.mjs').Config} config
 */
export async function assertSession(page, config) {
  const url = pageUrl(config, APP_PATH);

  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: DEFAULT_TIMEOUT });

  try {
    await page.waitForSelector(`${SELECTORS.appShell}, ${SELECTORS.loginName}`, {
      timeout: DEFAULT_TIMEOUT,
    });
  } catch {
    throw new UserError(
      `Na ${url} se nenačetla ani aplikace, ani přihlašovací formulář.`,
      'Běží instance? Sedí SHPD_BASE_URL včetně ID datového zdroje?',
    );
  }

  if (await isLoginScreen(page)) {
    throw new UserError(
      'Session neplatná — aplikace ukazuje přihlašovací formulář.',
      'Spusť: video-runner login',
    );
  }

  // Kontrola formuláře nestačí: s mrtvou session se shell vykreslí taky,
  // jen sidebar skončí ve stavu „Nepřihlášen" a API vrací 401 (Z10).
  // Scénář by pak umřel timeoutem na nevinném selektoru. Přihlášení se
  // proto ověřuje přímo autentizovaným endpointem; první 401 může být
  // závod s refreshem, který aplikace právě dělá, proto jeden opakovaný
  // pokus s odstupem.
  for (let attempt = 0; ; attempt++) {
    const status = await page.evaluate(async () => {
      // /{ds}/app/ na dev, /app/ v produkci — stejný odvozovací princip
      // jako frontend/src/api/config.js.
      const base = location.pathname.replace(/\/app\/.*$/, '');
      const response = await fetch(`${base}/api/v1/_ui/navigation`, {
        headers: { Accept: 'application/json' },
      });
      return response.status;
    });

    if (status === 200) return;

    if (status === 401 || status === 403) {
      if (attempt === 0) {
        await page.waitForTimeout(1000);
        continue;
      }
      throw new UserError(
        'Session vypršela — aplikace se vykreslí, ale API vrací 401.',
        'Spusť: video-runner login',
      );
    }

    throw new UserError(
      `Ověření session selhalo — GET /_ui/navigation vrátil ${status}.`,
      'Běží backend instance v pořádku?',
    );
  }
}
