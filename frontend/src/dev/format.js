/**
 * Formátování pro dev dashboard podle jeho jazyka (#129 D12): `cs-CZ`
 * / `en-GB` pro čísla a data, popisky ze slovníku dashboardu
 * (`i18n/index.js`), bez závislosti na i18n aplikace. Čisté funkce, testy
 * v tests/Unit/dev/format.test.mjs (nastavují jazyk přes `use()`).
 */
import { currentLanguage, t } from './i18n/index.js';

const LOCALES = { cs: 'cs-CZ', en: 'en-GB' };
const DASH = '—';

function locale() {
  return LOCALES[currentLanguage()] ?? LOCALES.en;
}

/** @param {number|null|undefined} value */
export function formatNumber(value, digits = 0) {
  if (value === null || value === undefined || Number.isNaN(value)) return DASH;
  return new Intl.NumberFormat(locale(), { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
}

/** Podíl 0–1 → `94,1 %`. */
export function formatPercent(ratio, digits = 1) {
  if (ratio === null || ratio === undefined || Number.isNaN(ratio)) return DASH;
  return `${formatNumber(ratio * 100, digits)} %`;
}

/** Rozdíl podílů v procentních bodech se znaménkem: `+0,4 b.` / `+0.4 pp`. */
export function formatPercentDelta(delta, digits = 1) {
  if (delta === null || delta === undefined || Number.isNaN(delta)) return DASH;
  const sign = delta > 0 ? '+' : delta < 0 ? '−' : '±';
  return `${sign}${formatNumber(Math.abs(delta) * 100, digits)} ${t('format.pp')}`;
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

/** ISO → `16. 10. 2026 14:05` / `16/10/2026, 14:05`. */
export function formatDateTime(iso) {
  const d = toDate(iso);
  if (!d) return iso ?? DASH;
  return d.toLocaleString(locale(), { day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/** ISO → `16. 10. 2026`. */
export function formatDate(iso) {
  const d = toDate(iso);
  if (!d) return iso ?? DASH;
  return d.toLocaleDateString(locale(), { day: 'numeric', month: 'numeric', year: 'numeric' });
}

/** ISO → `14:05:33` (řádky logu). */
export function formatTime(iso) {
  const d = toDate(iso);
  if (!d) return iso ?? DASH;
  return d.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

/**
 * Verze v pruhu dashboardu (#129 D9): `v0.1.1 · 02e2d3b7 · stable`. Bez hashe
 * (git nedostupný) jen `v0.1.1`; bez větve (odpojená HEAD) bez třetí části.
 *
 * @param {{version: string, hash?: string|null, branch?: string|null}} v
 */
export function versionLabel(v) {
  const parts = [`v${v.version}`];
  if (v.hash) {
    parts.push(v.hash);
    if (v.branch) parts.push(v.branch);
  }
  return parts.join(' · ');
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
  return dataClass === 'free' ? t('format.dataClass.free') : t('format.dataClass.realCopy');
}

/**
 * Bublina ke třídě dat: bez explicitní třídy upozorní, že v main.json
 * chybí; jinak původ a čas změny, pokud jsou.
 *
 * @param {{dataClass?: string, dataClassExplicit?: boolean, origin?: string|null, dataClassChanged?: string|null, serverAi?: boolean}} ds
 */
export function dataClassTitle(ds) {
  const parts = [];
  if (!ds.dataClassExplicit) parts.push(t('format.dataClass.missing'));
  if (ds.origin) parts.push(t('format.dataClass.setBy', { origin: ds.origin }));
  if (ds.dataClassChanged) parts.push(t('format.dataClass.changed', { when: formatDateTime(ds.dataClassChanged) }));
  if (ds.serverAi) parts.push(t('format.dataClass.serverAi'));
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
