// Datum a čas na obrazovce v jazyce rozhraní (#133 D73) — to, co volají
// komponenty. Zápis tvoří `formatDateCore.js` (čisté funkce, testy); tady se
// jen doplňuje zvolený jazyk, stejně jako ve `formatNumber.js`.
//
// Mimo rozsah: pole formuláře pro zadání data (`DateInput`) a relativní čas.

import { language } from '../i18n/index.js';
import { formatDateIn, formatDateTimeIn } from './formatDateCore.js';

/**
 * `01.01.2026` česky, `2026-01-01` anglicky. Prázdná hodnota → `''`,
 * hodnota, která datem není, se vrací beze změny.
 *
 * @param {string|Date|null|undefined} value ISO řetězec nebo `Date`
 * @returns {string}
 */
export function formatDate(value) {
  return formatDateIn(value, language.current);
}

/**
 * `01.01.2026 14:05` česky, `2026-01-01 14:05` anglicky.
 *
 * @param {string|Date|null|undefined} value ISO řetězec nebo `Date`
 * @returns {string}
 */
export function formatDateTime(value) {
  return formatDateTimeIn(value, language.current);
}
