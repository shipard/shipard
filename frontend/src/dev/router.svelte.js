/**
 * Router dev SPA nad History API. Jediný stav = aktuální routa
 * (`matchRoute(location)`), přepočítaná po `navigate()` a `popstate`.
 * Čistá logika (tabulka rout, matching, skládání cest) je v routes.js.
 */
import { matchRoute, buildPath } from './routes.js';

let current = $state(
  typeof window === 'undefined'
    ? { name: 'notFound', params: {}, query: {} }
    : matchRoute(window.location.pathname, window.location.search),
);

function sync() {
  current = matchRoute(window.location.pathname, window.location.search);
}

export const router = {
  get route() {
    return current;
  },

  /** @param {string} path  absolutní cesta vč. query */
  navigate(path, { replace = false } = {}) {
    if (replace) {
      window.history.replaceState(null, '', path);
    } else {
      window.history.pushState(null, '', path);
    }
    sync();
  },

  /** `router.go('logs', {}, {ds})` — navigace podle názvu routy. */
  go(name, params = {}, query = {}, options = {}) {
    this.navigate(buildPath(name, params, query), options);
  },

  /** Jen přepíše query aktuální cesty (úklid `?refresh=`, `?repeat=`…). */
  replaceQuery(query) {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
    }
    const q = qs.toString();
    this.navigate(window.location.pathname + (q ? `?${q}` : ''), { replace: true });
  },

  href: buildPath,
};

/**
 * `<a href onclick={onLinkClick}>` — levý klik bez modifikátorů na odkaz
 * uvnitř `/_dev` jde přes router; ostatní (nový tab, externí, `target`)
 * nechá prohlížeči.
 * @param {MouseEvent} event
 */
export function onLinkClick(event) {
  if (event.defaultPrevented || event.button !== 0) return;
  if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
  const anchor = event.currentTarget;
  if (!(anchor instanceof HTMLAnchorElement)) return;
  if (anchor.target && anchor.target !== '_self') return;
  const url = new URL(anchor.href, window.location.href);
  if (url.origin !== window.location.origin || !url.pathname.startsWith('/_dev')) return;
  event.preventDefault();
  router.navigate(url.pathname + url.search);
}

if (typeof window !== 'undefined') {
  window.addEventListener('popstate', sync);
}
