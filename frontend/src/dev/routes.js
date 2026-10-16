/**
 * Tabulka rout vývojářského dashboardu a čisté funkce nad URL —
 * bez prohlížeče, testovatelné v Node (tests/Unit/dev/routes.test.mjs).
 *
 * Routování podle cesty (ne hash), aby dnešní odkazy z návodů fungovaly
 * dál: `/_dev/logs/?ds=…`, `/_dev/ds-upgrade/?ds=…`. PHP servíruje SPA jen
 * pro známé prefixy stránek (DevDashboardController::isSpaPath), neznámá
 * cesta pod `/_dev/` dál vrací 404 JSON — tabulka tady a seznam v PHP si
 * musí odpovídat.
 */

export const DEV_BASE = '/_dev';

/** [vzor relativně k DEV_BASE, název routy]; `:x` = parametr. */
const ROUTES = [
  ['', 'dataSources'],
  ['ds-create', 'dsCreate'],
  ['logs', 'logs'],
  ['doctor', 'doctor'],
  ['upgrade-all', 'upgradeAll'],
  ['ds-upgrade', 'dsUpgrade'],
  // Testování (#103 D53) — sada, běhy, detail běhu, doklad v běhu, porovnání, doklad napříč běhy
  ['testing', 'testingDatasets'],
  // Úlohy a varianty (F6b) — před `testing/:set`: první shoda vítězí, sada se nesmí jmenovat `jobs` / `variants`
  ['testing/jobs', 'testingJobs'],
  ['testing/jobs/:jobId', 'testingJob'],
  ['testing/variants', 'testingVariants'],
  ['testing/variants/:name', 'testingVariant'],
  ['testing/:set', 'testingRuns'],
  ['testing/:set/compare', 'testingCompare'],
  ['testing/:set/runs/:file', 'testingRun'],
  ['testing/:set/runs/:file/messages/:messageId', 'testingMessage'],
  ['testing/:set/messages/:messageId', 'testingMessageRuns'],
];

const NOT_FOUND = 'notFound';

/**
 * @param {string} pathname  např. `/_dev/logs/`
 * @param {string} search    např. `?ds=abcd-…`
 * @returns {{name: string, params: Record<string,string>, query: Record<string,string>}}
 */
export function matchRoute(pathname, search = '') {
  const query = Object.fromEntries(new URLSearchParams(search));
  let rel;
  if (pathname === DEV_BASE || pathname === DEV_BASE + '/') {
    rel = '';
  } else if (pathname.startsWith(DEV_BASE + '/')) {
    rel = pathname.slice(DEV_BASE.length + 1).replace(/\/+$/, '');
  } else {
    return { name: NOT_FOUND, params: {}, query };
  }

  const segs = rel === '' ? [] : rel.split('/').map(safeDecode);
  for (const [pattern, name] of ROUTES) {
    const pSegs = pattern === '' ? [] : pattern.split('/');
    if (pSegs.length !== segs.length) continue;
    const params = {};
    let ok = true;
    for (let i = 0; i < pSegs.length; i++) {
      if (pSegs[i].startsWith(':')) {
        params[pSegs[i].slice(1)] = segs[i];
      } else if (pSegs[i] !== segs[i]) {
        ok = false;
        break;
      }
    }
    if (ok) return { name, params, query };
  }
  return { name: NOT_FOUND, params: {}, query };
}

/**
 * Cesta pro routu — vždy s koncovým lomítkem (`/_dev/`, `/_dev/logs/`),
 * prázdné hodnoty query se vynechají.
 *
 * @param {string} name
 * @param {Record<string,string>} [params]
 * @param {Record<string,string|number|null|undefined>} [query]
 */
export function buildPath(name, params = {}, query = {}) {
  const entry = ROUTES.find(([, n]) => n === name);
  if (!entry) throw new Error(`Neznámá routa: ${name}`);
  const segs = entry[0] === '' ? [] : entry[0].split('/').map((s) => {
    if (!s.startsWith(':')) return s;
    const value = params[s.slice(1)];
    if (value === undefined || value === null || value === '') throw new Error(`Routa ${name}: chybí parametr ${s}`);
    return encodeURIComponent(String(value));
  });
  const path = DEV_BASE + '/' + segs.map((s) => s + '/').join('');
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
  }
  const q = qs.toString();
  return q ? `${path}?${q}` : path;
}

/** Registrace rout jiné sekce (Testování) — volá se jednou při startu. */
export function registerRoutes(entries) {
  for (const [pattern, name] of entries) {
    if (!ROUTES.some(([p]) => p === pattern)) ROUTES.push([pattern, name]);
  }
}

function safeDecode(seg) {
  try {
    return decodeURIComponent(seg);
  } catch {
    return seg;
  }
}
