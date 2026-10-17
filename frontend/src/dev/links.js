/** Externí odkazy dashboardu — jedno místo pro lištu i prázdný stav (#129 D15). */
export const COMMIT_URL = 'https://github.com/shipard/shipard/commit/';

const DOCS_URLS = {
  cs: 'https://shipard.dev/pro-vyvojare/',
  en: 'https://shipard.dev/en/developers/',
};

/**
 * Dokumentace pro vývojáře podle jazyka dashboardu (D12): čeština vede na
 * českou stránku, cokoli jiného na anglickou. Čistá funkce, test
 * v tests/Unit/dev/links.test.mjs.
 * @param {string|null|undefined} lang  `cs` | `en`
 */
export function docsUrl(lang) {
  return DOCS_URLS[lang] ?? DOCS_URLS.en;
}
