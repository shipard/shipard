// Hledání v lookupu tabulky (`GET /_ui/lookup/{table}/search`) — sdílí ho
// `LookupInput` (jeden záznam) a `MultiLookupInput` (víc záznamů, #176).
// Čisté funkce a řadič bez závislosti na API klientu: `get` dodá komponenta,
// takže jde testovat v `npm test`.

/** Zpoždění hledání při psaní (ms). */
export const LOOKUP_SEARCH_DELAY = 300;

/** Výchozí a nejvyšší počet položek, které endpoint vrátí. */
export const LOOKUP_DEFAULT_LIMIT = 20;
export const LOOKUP_MAX_LIMIT = 50;

/**
 * URL hledání: text, filtr prvku (`lookup.filter` — klíče mimo whitelist
 * lookupu server zahodí, `null` / `undefined` se neposílají) a nepovinný
 * limit.
 *
 * @param {{table: string, filter?: Record<string, unknown>|null}} lookup
 * @param {string} term
 * @param {{limit?: number|null}} [options]
 */
export function buildLookupSearchUrl(lookup, term, { limit = null } = {}) {
  const params = new URLSearchParams();
  params.set('q', term);
  if (lookup?.filter) {
    for (const [k, v] of Object.entries(lookup.filter)) {
      if (v !== null && v !== undefined) {
        params.set(`filter[${k}]`, String(v));
      }
    }
  }
  if (limit != null) {
    params.set('limit', String(limit));
  }
  return `/_ui/lookup/${lookup.table}/search?${params.toString()}`;
}

/**
 * Limit hledání pro pole s vybranými záznamy: vybrané se z nabídky skrývají
 * až u klienta, takže se jich žádá o tolik víc — do stropu endpointu.
 *
 * @param {number} selectedCount
 */
export function lookupLimitFor(selectedCount) {
  const count = Number.isFinite(selectedCount) && selectedCount > 0 ? Math.floor(selectedCount) : 0;
  return Math.min(LOOKUP_MAX_LIMIT, LOOKUP_DEFAULT_LIMIT + count);
}

/**
 * Řadič hledání: `run(term)` hledá hned, `schedule(term)` se zpožděním
 * (další volání předchozí čekání ruší), `cancel()` zruší čekající hledání.
 * Odpověď staršího dotazu, kterou předběhl novější, se zahodí.
 *
 * Stav hlásí přes `onState(patch)`:
 *  - začátek `{loading: true, failure: null}`,
 *  - výsledek `{loading: false, items, failure: null}`,
 *  - chyba `{loading: false, items: [], failure}` — `failure` je odpověď
 *    serveru s `success: false`, nebo `{network: true}`, když dotaz neprošel;
 *    text chyby skládá komponenta (překlady).
 *
 * @param {{
 *   get: (url: string) => Promise<any>,
 *   getLookup: () => ({table: string, filter?: Record<string, unknown>|null}|null|undefined),
 *   onState: (patch: {loading: boolean, items?: Array<any>, failure: any}) => void,
 *   getLimit?: () => (number|null),
 *   delay?: number,
 *   setTimer?: typeof setTimeout,
 *   clearTimer?: typeof clearTimeout,
 * }} options
 */
export function createLookupSearch({
  get,
  getLookup,
  onState,
  getLimit = () => null,
  delay = LOOKUP_SEARCH_DELAY,
  setTimer = setTimeout,
  clearTimer = clearTimeout,
}) {
  let timer = null;
  /** Token nejnovějšího dotazu — starší odpovědi se ignorují. */
  let currentToken = 0;

  function cancel() {
    if (timer !== null) {
      clearTimer(timer);
      timer = null;
    }
  }

  async function run(term) {
    const lookup = getLookup();
    if (!lookup?.table) {
      onState({ loading: false, items: [], failure: null });
      return;
    }
    const myToken = ++currentToken;
    onState({ loading: true, failure: null });
    const res = await get(buildLookupSearchUrl(lookup, term, { limit: getLimit() }));
    if (myToken !== currentToken) {
      return;
    }
    if (res === null || res === undefined) {
      onState({ loading: false, items: [], failure: { network: true } });
      return;
    }
    if (!res.success) {
      onState({ loading: false, items: [], failure: res });
      return;
    }
    onState({ loading: false, items: res.data?.items ?? [], failure: null });
  }

  function schedule(term) {
    cancel();
    timer = setTimer(() => {
      timer = null;
      run(term);
    }, delay);
  }

  return { run, schedule, cancel };
}
