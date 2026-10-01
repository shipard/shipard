/**
 * API helpers for the reports endpoints (tasks/reports-phase3.md, docs/reports.md):
 *   GET /_reports              — catalog of declared reports + fiscal periods
 *   GET /_reports/{reportId}   — run a report; query = period keys
 *                                (fiscalYear, monthFrom, monthTo | period)
 *                                + the report's declared params by id
 *
 *                                + format=xlsx|csv → file download (export)
 *
 * Result with `status: errors` is HTTP 200 — a data error is not a request
 * error; the renderer shows it (badge + red rows), it is not a fetch failure.
 */

import { get, getBlob } from './client.js';
import { fileNameFromContentDisposition, saveBlob } from '../utils/download.js';
import { fitPeriodToGranularities, reportQueryEntries } from '../utils/reportParams.js';

// Deep-link parser je čistá funkce v utils — re-export drží import v main.js.
export { parseReportDeepLink } from '../utils/reportParams.js';

/**
 * @returns {Promise<{success: boolean, data?: {items: Array<{id: string, name: string,
 *   periodGranularities: string[], params: Array<object>}>,
 *   periods: {fiscalYears: Array<{name: string, months: number}>}}, error?: object}>}
 */
export async function fetchReportCatalog() {
  return await get('/_reports');
}

/**
 * Query se staví ze stavu stránky — report s periodSource 'vatPeriod'
 * posílá `period` (id instance daňového tvrzení), fiskální report
 * fiscalYear+monthFrom/monthTo; ostatní klíče jsou parametry deklarace
 * reportu (server neznámý parametr odmítne jako 400, stav stránky proto
 * nese jen deklarované).
 *
 * @param {string} reportId
 * @param {Record<string, any>} params
 * @returns {Promise<{success: boolean, data?: object, error?: object}>} data = ReportResult
 */
export async function runReport(reportId, params) {
  return await get(`/_reports/${encodeURIComponent(reportId)}?${reportQuery(params)}`);
}

/**
 * Stáhne export reportu (docs/reports.md §15) se stejnými parametry jako
 * zobrazený výsledek. Název souboru určuje server (Content-Disposition).
 *
 * @param {string} reportId
 * @param {object} params stejné jako u runReport
 * @param {'xlsx'|'csv'} format
 * @returns {Promise<{success: boolean, error?: object}|null>} null = 401
 */
export async function downloadReport(reportId, params, format) {
  const query = reportQuery(params);
  query.set('format', format);
  const res = await getBlob(`/_reports/${encodeURIComponent(reportId)}?${query}`);
  if (res === null || !res.success) return res;
  const fileName = fileNameFromContentDisposition(res.headers.get('Content-Disposition'))
    ?? `report.${format}`;
  saveBlob(res.blob, fileName);
  return { success: true };
}

function reportQuery(params) {
  return new URLSearchParams(reportQueryEntries(params));
}

/**
 * Výchozí období = poslední celý měsíc existujícího fiskálního roku.
 * Fiskální roky v1 jsou zarovnané na kalendář (name = kalendářní rok) —
 * bez mapy fiskální↔kalendářní měsíc bereme pořadí měsíce v roce jako
 * kalendářní měsíc; u ne-kalendářního roku degraduje na poslední měsíc.
 *
 * Report bez měsíční granularity dostane nejmenší deklarované období,
 * do kterého ten měsíc patří (čtvrtletí → pololetí → rok).
 *
 * @param {Array<{name: string, months: number}>} fiscalYears (řazené dle name)
 * @param {Date} [now]
 * @param {string[]|null} [granularities] granularity deklarace reportu
 * @returns {{fiscalYear: string, monthFrom: number, monthTo: number}|null} null bez fiskálních roků
 */
export function defaultPeriod(fiscalYears, now = new Date(), granularities = null) {
  if (!Array.isArray(fiscalYears) || fiscalYears.length === 0) return null;
  const currentYear = now.getFullYear();
  const candidates = fiscalYears.filter((y) => Number(y.name) <= currentYear);
  const year = (candidates.length > 0 ? candidates : fiscalYears).at(-1);
  const month = Number(year.name) === currentYear
    ? Math.min(Math.max(now.getMonth(), 1), year.months) // getMonth() 0-based → minulý měsíc
    : year.months;
  return fitPeriodToGranularities(
    { fiscalYear: String(year.name), monthFrom: month, monthTo: month },
    year.months,
    granularities,
  );
}

/**
 * Výchozí instance tvrzení = poslední uzavřená (dateEnd < dnes) instance
 * typu reportu první registrace, která nějakou má; bez uzavřené poslední
 * existující. Null, když žádná registrace instanci daného typu nemá.
 *
 * @param {Array<{id: number, name: string,
 *   periods: Array<{id: number, type: string, name: string, dateBegin: string, dateEnd: string}>}>} registrations
 * @param {string} reportType 'return' | 'cs' | 'rs'
 * @param {Date} [now]
 * @returns {{period: number}|null}
 */
export function defaultVatPeriod(registrations, reportType, now = new Date()) {
  if (!Array.isArray(registrations) || registrations.length === 0) return null;
  const today = now.toISOString().slice(0, 10);
  for (const registration of registrations) {
    const periods = (registration.periods ?? [])
      .filter((p) => p.type === reportType)
      .sort((a, b) => a.dateBegin.localeCompare(b.dateBegin));
    if (periods.length === 0) continue;
    const closed = periods.filter((p) => p.dateEnd < today);
    const period = (closed.length > 0 ? closed : periods).at(-1);
    return { period: period.id };
  }
  return null;
}

/**
 * Existuje instance daného id a typu v katalogu? (validace deep-linku)
 *
 * @param {Array<{periods: Array<{id: number, type: string}>}>} registrations
 * @param {number} periodId
 * @param {string} reportType
 * @returns {boolean}
 */
export function hasVatPeriod(registrations, periodId, reportType) {
  return (registrations ?? []).some((r) =>
    (r.periods ?? []).some((p) => p.id === periodId && p.type === reportType));
}
