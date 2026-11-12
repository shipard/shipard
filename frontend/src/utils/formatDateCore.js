// Datum a čas na obrazovce — jediné místo ve frontendu, které tvoří jejich
// zápis (#133 D73). Stejné výstupy jako `Shipard\Core\I18n\DisplayDate`
// v backendu: česky `01.01.2026` (s časem `01.01.2026 14:05`), jinak ISO
// `2026-01-01` (s časem `2026-01-01 14:05`).
//
// Čisté funkce s jazykem jako parametrem (testy v Node bez storu jazyka);
// komponenty volají `formatDate()` / `formatDateTime()` z `formatDate.js`,
// které jazyk doplní. Záměrně bez `Intl` — výstup nesmí záviset na verzi
// prohlížeče.
//
// Časové pásmo: čisté datum a čas bez posunu se čtou z řetězce ručně a
// nepřevádějí se (`new Date('2026-01-01')` je půlnoc UTC a v pásmu západně
// od UTC by dala 31. 12.). Jen hodnota s výslovným posunem (`Z`, `+02:00`)
// je okamžik a ukáže se v místním čase prohlížeče.

const PATTERN = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::\d{2}(?:[.,]\d+)?)?)?(Z|[+-]\d{2}(?::?\d{2})?)?$/;

const pad = (n) => String(n).padStart(2, '0');

function fromDate(d) {
  if (Number.isNaN(d.getTime())) return null;
  return [String(d.getFullYear()).padStart(4, '0'), pad(d.getMonth() + 1), pad(d.getDate()), pad(d.getHours()), pad(d.getMinutes())];
}

function isRealDate(year, month, day) {
  const d = new Date(Date.UTC(year, month - 1, day));
  return d.getUTCFullYear() === year && d.getUTCMonth() === month - 1 && d.getUTCDate() === day;
}

/** Rok, měsíc, den, hodina, minuta s úvodními nulami; null = není datum. */
function parts(value) {
  if (value instanceof Date) return fromDate(value);
  if (typeof value !== 'string') return null;
  const m = PATTERN.exec(value.trim());
  if (!m) return null;
  const [, year, month, day, hour, minute, offset] = m;
  if (!isRealDate(Number(year), Number(month), Number(day))) return null;
  if (hour === undefined) return [year, month, day, '00', '00'];
  if (Number(hour) > 23 || Number(minute) > 59) return null;
  if (offset !== undefined) return fromDate(new Date(value.trim().replace(' ', 'T')));
  return [year, month, day, hour, minute];
}

function datePart([year, month, day], lang) {
  return lang === 'cs' ? `${day}.${month}.${year}` : `${year}-${month}-${day}`;
}

function passThrough(value) {
  if (value === null || value === undefined || value === '' || value instanceof Date) return '';
  return String(value);
}

/**
 * Datum bez času. Prázdná hodnota → `''`, hodnota, která datem není, se vrací
 * beze změny.
 *
 * @param {string|Date|null|undefined} value ISO řetězec nebo `Date`
 * @param {string} lang jazyk rozhraní (`cs`, jinak ISO zápis)
 * @returns {string}
 */
export function formatDateIn(value, lang) {
  const p = parts(value);
  return p ? datePart(p, lang) : passThrough(value);
}

/**
 * Datum s časem na minuty; hodnota bez času dostane `00:00`.
 *
 * @param {string|Date|null|undefined} value ISO řetězec nebo `Date`
 * @param {string} lang jazyk rozhraní (`cs`, jinak ISO zápis)
 * @returns {string}
 */
export function formatDateTimeIn(value, lang) {
  const p = parts(value);
  return p ? `${datePart(p, lang)} ${p[3]}:${p[4]}` : passThrough(value);
}
