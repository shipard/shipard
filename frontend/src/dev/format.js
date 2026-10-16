/**
 * Formátování pro dev dashboard — česky (`cs-CZ`), bez závislosti na
 * i18n aplikace. Čisté funkce, testy v tests/Unit/dev/format.test.mjs.
 */

const LOCALE = 'cs-CZ';
const DASH = '—';

/** @param {number|null|undefined} value */
export function formatNumber(value, digits = 0) {
  if (value === null || value === undefined || Number.isNaN(value)) return DASH;
  return new Intl.NumberFormat(LOCALE, { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
}

/** Podíl 0–1 → `94,1 %`. */
export function formatPercent(ratio, digits = 1) {
  if (ratio === null || ratio === undefined || Number.isNaN(ratio)) return DASH;
  return `${formatNumber(ratio * 100, digits)} %`;
}

/** Rozdíl podílů v procentních bodech se znaménkem: `+0,4 b.`. */
export function formatPercentDelta(delta, digits = 1) {
  if (delta === null || delta === undefined || Number.isNaN(delta)) return DASH;
  const sign = delta > 0 ? '+' : delta < 0 ? '−' : '±';
  return `${sign}${formatNumber(Math.abs(delta) * 100, digits)} b.`;
}

/** Rozdíl čísla se znaménkem. */
export function formatDelta(delta, digits = 0) {
  if (delta === null || delta === undefined || Number.isNaN(delta)) return DASH;
  const sign = delta > 0 ? '+' : delta < 0 ? '−' : '±';
  return `${sign}${formatNumber(Math.abs(delta), digits)}`;
}

/** Cena v USD: `0,0380 USD`. */
export function formatUsd(value, digits = 4) {
  if (value === null || value === undefined || Number.isNaN(value)) return DASH;
  return `${formatNumber(value, digits)} USD`;
}

/** Milisekundy → `850 ms` / `8,3 s` / `2,1 min`. */
export function formatMs(ms) {
  if (ms === null || ms === undefined || Number.isNaN(ms)) return DASH;
  if (ms < 1000) return `${formatNumber(ms)} ms`;
  if (ms < 60_000) return `${formatNumber(ms / 1000, 1)} s`;
  return `${formatNumber(ms / 60_000, 1)} min`;
}

/** ISO → `16. 10. 2026 14:05`. */
export function formatDateTime(iso) {
  const d = toDate(iso);
  if (!d) return iso ?? DASH;
  return d.toLocaleString(LOCALE, { day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** ISO → `16. 10. 2026`. */
export function formatDate(iso) {
  const d = toDate(iso);
  if (!d) return iso ?? DASH;
  return d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'numeric', year: 'numeric' });
}

/** ISO → `14:05:33` (řádky logu). */
export function formatTime(iso) {
  const d = toDate(iso);
  if (!d) return iso ?? DASH;
  return d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

/** Zkrácený commit `1baa027d`; `dirty` → `1baa027d*`. */
export function formatCommit(commit, dirty = false) {
  if (!commit) return DASH;
  return String(commit).slice(0, 8) + (dirty ? '*' : '');
}

/**
 * Popisek třídy dat zdroje dat (#126 D6): `free` → „volný“, cokoli
 * jiného (realCopy, chybějící, neznámé) → „reálná kopie“ — stejný
 * fail-safe jako server.
 */
export function dataClassLabel(dataClass) {
  return dataClass === 'free' ? 'volný' : 'reálná kopie';
}

/**
 * Bublina ke třídě dat: bez explicitní třídy upozorní, že v main.json
 * chybí; jinak původ a čas změny, pokud jsou.
 *
 * @param {{dataClass?: string, dataClassExplicit?: boolean, origin?: string|null, dataClassChanged?: string|null, serverAi?: boolean}} ds
 */
export function dataClassTitle(ds) {
  const parts = [];
  if (!ds.dataClassExplicit) parts.push('v main.json chybí — platí reálná kopie');
  if (ds.origin) parts.push(`nastavil: ${ds.origin}`);
  if (ds.dataClassChanged) parts.push(`změněno: ${formatDateTime(ds.dataClassChanged)}`);
  if (ds.serverAi) parts.push('používá serverové AI připojení');
  return parts.join(' · ');
}

/** Zkrácení na `max` znaků s výpustkou; null/prázdné → pomlčka. */
export function truncate(text, max = 40) {
  if (text === null || text === undefined || text === '') return DASH;
  const s = String(text);
  return s.length > max ? s.slice(0, max - 1).trimEnd() + '…' : s;
}

function toDate(iso) {
  if (!iso) return null;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d;
}
