/**
 * Vícejazyčnost vývojářského dashboardu (#129 D12): vlastní slovníky
 * (cs.js, en.js) a `t()` nad intl-messageformat, nezávislé na store aplikace
 * (`stores/language.svelte.js` tahá `api/account.js` a per-user nastavení;
 * dashboard žádného uživatele nemá).
 *
 * Jazyk: `localStorage` `shpd_dev_language` (`cs` | `en`), jinak podle
 * `navigator.language` (čeština → cs, cokoli jiného → en). Přepnutí = zápis
 * + reload jako v aplikaci: dashboard nedrží rozpracovaný stav, který by
 * reload ztratil, a `t()` tak zůstává čistá funkce bez reaktivity.
 * Kódy ze serveru (stav úlohy, stav pole…) překládá `label(prefix, code)`
 * — bez klíče vrátí kód, ne klíč. Paritu slovníků a použité klíče hlídá
 * `npm run check:i18n`. Testy: tests/Unit/dev/i18n.test.mjs.
 */
import IntlMessageFormat from 'intl-messageformat';
import cs from './cs.js';
import en from './en.js';

export const STORAGE_KEY = 'shpd_dev_language';
export const LANGUAGES = ['cs', 'en'];
const DICTIONARIES = { cs, en };

/**
 * Čistá volba jazyka: uložená platná hodnota, jinak podle jazyka prohlížeče.
 * @param {string|null|undefined} stored  hodnota z localStorage
 * @param {string|null|undefined} navigatorLanguage  např. `cs-CZ`, `en-US`
 * @returns {'cs'|'en'}
 */
export function resolveLanguage(stored, navigatorLanguage) {
  if (LANGUAGES.includes(stored)) return stored;
  const nav = String(navigatorLanguage ?? '').slice(0, 2).toLowerCase();
  return nav === 'cs' ? 'cs' : 'en';
}

function detect() {
  let stored = null;
  try {
    stored = globalThis.localStorage?.getItem(STORAGE_KEY) ?? null;
  } catch {
    stored = null; // soukromý režim / vypnuté úložiště
  }
  return resolveLanguage(stored, globalThis.navigator?.language ?? null);
}

let current = detect();
const formatters = new Map();

/** @returns {'cs'|'en'} */
export function currentLanguage() {
  return current;
}

/** Přepnutí bez persistence — start a testy (`use('cs')`); zahodí cache formátovačů. */
export function use(lang) {
  if (!LANGUAGES.includes(lang)) throw new Error(`Unknown dashboard language: ${lang}`);
  current = lang;
  formatters.clear();
}

/** Přepínač v liště: uloží volbu a načte dashboard znovu. */
export function setLanguage(lang) {
  if (!LANGUAGES.includes(lang) || lang === current) return;
  try {
    localStorage.setItem(STORAGE_KEY, lang);
  } catch {
    // soukromý režim — volba platí jen do dalšího načtení
  }
  use(lang);
  location.reload();
}

/** Klíč existuje v aktuálním slovníku nebo v angličtině (fallback). */
export function has(key) {
  return Object.hasOwn(DICTIONARIES[current], key) || Object.hasOwn(en, key);
}

/**
 * Překlad klíče s parametry ICU MessageFormat. Fallback: jazyk → en → klíč
 * (viditelný signál pro vývojáře; `check:i18n` ho zachytí dřív).
 * @param {string} key
 * @param {Record<string, unknown>} [params]
 * @returns {string}
 */
export function t(key, params) {
  const cacheKey = `${current}::${key}`;
  let formatter = formatters.get(cacheKey);
  if (!formatter) {
    const message = DICTIONARIES[current][key] ?? en[key] ?? key;
    // ignoreTag: texty dashboardu nemají formátovací značky; `<id>` nebo `<název>` je obyčejný text.
    formatter = new IntlMessageFormat(message, current, undefined, { ignoreTag: true });
    formatters.set(cacheKey, formatter);
  }
  try {
    return String(formatter.format(params ?? {}));
  } catch (e) {
    console.warn(`dev i18n: format error for '${key}'`, e);
    return key;
  }
}

/**
 * Popisek kódu ze serveru: klíč `<prefix>.<code>` je-li ve slovníku, jinak
 * kód sám — neznámý stav se ukáže, jak přišel, ne jako klíč.
 * @param {string} prefix  např. `jobs.status`
 * @param {string|null|undefined} code
 */
export function label(prefix, code) {
  const key = `${prefix}.${code}`;
  return has(key) ? t(key) : String(code ?? '');
}
