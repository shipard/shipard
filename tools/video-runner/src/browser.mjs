/**
 * Spouštění Chromia. Sdílené mezi verbem `login` a interpretem scénáře,
 * aby se prohlížeč choval v obou případech stejně.
 */

import { chromium } from 'playwright';

import { UserError } from './errors.mjs';

/** Výchozí timeout čekání na prvek. Delší nemá smysl — scénář má být rychlý. */
export const DEFAULT_TIMEOUT = 15_000;

/**
 * Argumenty pro deterministické vykreslování. Bez nich se stroje rozejdou
 * v hintingu písma a v barevném profilu, takže by se videa natočená jinde
 * vizuálně neshodovala.
 */
const RENDER_ARGS = [
  '--force-color-profile=srgb',
  '--font-render-hinting=none',
];

/**
 * @param {object} [options]
 * @param {boolean} [options.headless]
 * @param {string[]} [options.args] Argumenty navíc (geometrie okna).
 * @param {string|null} [options.hostResolverRules] Připíchnutá adresa hostitele
 *   (D97): jméno serveru se v prohlížeči nepřekládá znovu, aby ho mezi
 *   ověřením a během nešlo přesměrovat. Syntaxe `--host-resolver-rules`,
 *   např. `MAP demo.example.dev 127.0.0.1`. `null` = bez mapování.
 */
export async function launchChromium({ headless = true, args = [], hostResolverRules = null } = {}) {
  const resolverArgs = hostResolverRules ? [`--host-resolver-rules=${hostResolverRules}`] : [];

  try {
    // `channel: 'chromium'` = plné Chromium v novém headless režimu, ne
    // ořezaný `chromium-headless-shell`. Rozdíl je vestavěný PDF viewer:
    // headless shell ho nemá, takže náhledy PDF (`AttachmentGrid`,
    // `PdfViewerPanel`) zůstávaly ve videu prázdné. Plné Chromium vykreslí
    // PDF v iframe stejně jako prohlížeč skutečného uživatele.
    return await chromium.launch({
      channel: 'chromium',
      headless,
      args: [...RENDER_ARGS, ...resolverArgs, ...args],
    });
  } catch (error) {
    if (/Executable doesn't exist|please run the following command/i.test(error.message)) {
      throw new UserError(
        'Chromium pro Playwright není nainstalované.',
        'Spusť z tools/video-runner/: npx playwright install chromium',
      );
    }
    throw error;
  }
}
