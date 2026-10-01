/**
 * Parametry reportu mimo období (docs/reports.md §4, §16) — čisté funkce
 * bez importů, sdílené API helperem, deep-linkem a stránkou reportu.
 *
 * Stav stránky je plochý objekt: klíče období (`fiscalYear`, `monthFrom`,
 * `monthTo`, nebo `period` u reportů DPH) + jeden klíč per parametr
 * deklarace (`detail`, `groupBy`, …). Deklarace je jediný zdroj nabídky —
 * co v ní není, se na server neposílá (neznámý parametr = 400).
 */

/** Klíče období ve stavu stránky a na API. */
const PERIOD_KEYS = ['fiscalYear', 'monthFrom', 'monthTo', 'period'];

/** Klíče deep-linku, které nejsou parametry reportu. */
const DEEP_LINK_KEYS = ['report', 'fy', 'mf', 'mt', 'p'];

/**
 * Výchozí hodnoty parametrů deklarace.
 *
 * @param {Array<{id: string, default: any}>} declared
 * @returns {Record<string, any>}
 */
export function defaultReportParams(declared) {
  const out = {};
  for (const param of declared ?? []) out[param.id] = param.default;
  return out;
}

/**
 * Hodnota parametru z textu (query string) ověřená proti deklaraci;
 * `undefined` = nevalidní, volající nechá výchozí hodnotu.
 *
 * @param {{type: string, options?: string[]}} param
 * @param {any} raw
 * @returns {string|boolean|undefined}
 */
export function coerceReportParam(param, raw) {
  if (param.type === 'bool') {
    if (raw === true || raw === 'true' || raw === '1') return true;
    if (raw === false || raw === 'false' || raw === '0') return false;
    return undefined;
  }
  return typeof raw === 'string' && (param.options ?? []).includes(raw) ? raw : undefined;
}

/**
 * Přeloží hodnoty z deep-linku přes deklaraci — nevalidní a nedeklarované
 * se zahodí.
 *
 * @param {Array<{id: string, type: string, options?: string[]}>} declared
 * @param {Record<string, any>} pending
 * @returns {Record<string, string|boolean>}
 */
export function overlayReportParams(declared, pending) {
  const out = {};
  for (const param of declared ?? []) {
    const value = coerceReportParam(param, pending?.[param.id]);
    if (value !== undefined) out[param.id] = value;
  }
  return out;
}

/**
 * Parametry mimo období ze stavu stránky jako textové dvojice (query
 * string API i deep-linku).
 *
 * @param {Record<string, any>} params
 * @returns {Record<string, string>}
 */
export function extraParamEntries(params) {
  const out = {};
  for (const [key, value] of Object.entries(params ?? {})) {
    if (PERIOD_KEYS.includes(key) || value === undefined || value === null) continue;
    out[key] = String(value);
  }
  return out;
}

/**
 * Query string běhu reportu (`GET /_reports/{id}`).
 *
 * @param {Record<string, any>} params
 * @returns {Record<string, string>}
 */
export function reportQueryEntries(params) {
  const period = params.period != null
    ? { period: String(params.period) }
    : {
        fiscalYear: params.fiscalYear,
        monthFrom: String(params.monthFrom),
        monthTo: String(params.monthTo),
      };
  return { ...period, ...extraParamEntries(params) };
}

/**
 * Query string deep-linku (`?report=&fy=&mf=&mt=` / `&p=` + parametry
 * reportu pod svým id).
 *
 * @param {string} reportId
 * @param {Record<string, any>} params
 * @returns {Record<string, string>}
 */
export function deepLinkEntries(reportId, params) {
  const period = params.period != null
    ? { p: String(params.period) }
    : { fy: params.fiscalYear, mf: String(params.monthFrom), mt: String(params.monthTo) };
  return { report: reportId, ...period, ...extraParamEntries(params) };
}

/**
 * Deep-link reportu z query stringu — čistý parser. Bez `report` → null;
 * nevalidní pole období se zahodí (doplní je default v ReportsPage).
 * Ostatní klíče jdou dál jako text pod svým jménem; proti deklaraci
 * reportu je ověří až stránka (`overlayReportParams`) — parser katalog
 * nezná. „V tisících" do URL nepatří (čistě vizuální volba).
 *
 * @param {string} search window.location.search
 * @returns {{reportId: string, params: Record<string, any>}|null}
 */
export function parseReportDeepLink(search) {
  const query = new URLSearchParams(search);
  const reportId = query.get('report');
  if (!reportId) return null;

  const params = {};
  const fy = query.get('fy');
  if (fy) params.fiscalYear = fy;
  for (const [key, name] of [['mf', 'monthFrom'], ['mt', 'monthTo']]) {
    const value = Number.parseInt(query.get(key) ?? '', 10);
    if (Number.isInteger(value) && value >= 1 && value <= 12) params[name] = value;
  }
  const period = Number.parseInt(query.get('p') ?? '', 10);
  if (Number.isInteger(period) && period >= 1) params.period = period;

  for (const [key, value] of query.entries()) {
    if (DEEP_LINK_KEYS.includes(key) || PERIOD_KEYS.includes(key)) continue;
    params[key] = value;
  }

  return { reportId, params };
}

/**
 * Rozšíří jednoměsíční období na nejmenší granularitu, kterou report
 * deklaruje (report jen s roční granularitou nesmí dostat výchozí měsíc —
 * server by ho odmítl). S granularitou `month` nebo bez deklarace vrací
 * vstup beze změny.
 *
 * @param {{fiscalYear: string, monthFrom: number, monthTo: number}} period
 * @param {number} months počet běžných měsíců fiskálního roku
 * @param {string[]|null} [granularities]
 * @returns {{fiscalYear: string, monthFrom: number, monthTo: number}}
 */
export function fitPeriodToGranularities(period, months, granularities = null) {
  if (!Array.isArray(granularities) || granularities.length === 0 || granularities.includes('month')) {
    return period;
  }
  const month = period.monthFrom;
  if (granularities.includes('quarter')) {
    const q = Math.ceil(month / 3);
    if (3 * q <= months) return { ...period, monthFrom: 3 * q - 2, monthTo: 3 * q };
  }
  if (granularities.includes('halfYear')) {
    const h = month <= 6 ? 1 : 2;
    if (6 * h <= months) return { ...period, monthFrom: 6 * h - 5, monthTo: 6 * h };
  }
  return { ...period, monthFrom: 1, monthTo: months };
}
