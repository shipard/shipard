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
export async function launchChromium({ headless = true, args = [], hostResolverRules = null, locale = null } = {}) {
  const resolverArgs = hostResolverRules ? [`--host-resolver-rules=${hostResolverRules}`] : [];
  // Locale procesu prohlížeče z `locale` scénáře (`cs-CZ` → `cs_CZ.UTF-8`):
  // jen podle něj Chromium formátuje nativní datumová a číselná pole
  // (`--lang` ani `locale` kontextu Playwrightu na ně nemají vliv).
  // Runner sám může běžet s `LC_ALL=C` (pevné prostředí `video-run`).
  const env = locale ? { ...process.env, ...processLocaleEnv(locale) } : undefined;

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
      ...(env ? { env } : {}),
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

/**
 * Proměnné prostředí s locale procesu Chromia pro BCP 47 `locale`
 * (`cs-CZ` → `cs_CZ.UTF-8`, `en` → `en.UTF-8`). Exportované kvůli testům.
 *
 * @param {string} locale
 * @returns {{LANG: string, LC_ALL: string, LANGUAGE: string}}
 */
export function processLocaleEnv(locale) {
  const posix = `${locale.trim().replace('-', '_')}.UTF-8`;
  return { LANG: posix, LC_ALL: posix, LANGUAGE: locale.trim().split('-')[0] };
}
