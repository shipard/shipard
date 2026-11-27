/**
 * Vstupy vývojového serveru (#183 D3, U10) — čisté funkce pro menu Adresa
 * a upozornění na nezabezpečený kontext. Data: `access` z `/_dev/api/info`
 * (`[{kind, origin, preferred, current}]`, allowlist ze serveru).
 * Test: tests/Unit/dev/access.test.mjs.
 */

/** Druhy vstupu, které zná server; `other` je adresa, přes kterou je stránka otevřená a server ji nenabízí. */
export const HTTPS_KINDS = ['https-host', 'https-ip'];

/**
 * Origin k porovnání: malými písmeny, bez lomítka na konci a bez výchozího
 * portu schématu (`http://x:80` = `http://x`).
 * @param {string|null|undefined} origin
 */
export function normalizeOrigin(origin) {
  let value = String(origin ?? '').trim().toLowerCase().replace(/\/+$/, '');
  if (value.startsWith('http://')) value = value.replace(/:80$/, '');
  if (value.startsWith('https://')) value = value.replace(/:443$/, '');
  return value;
}

/**
 * Adresa téže stránky na jiném vstupu: jiný origin, stejná cesta, dotaz
 * i kotva — přepnutí vstupu nesmí ztratit, kde uživatel je.
 * @param {string} origin  origin cílového vstupu (`https://10.0.0.7`)
 * @param {{pathname?: string, search?: string, hash?: string}|null|undefined} location
 */
export function entryUrl(origin, location) {
  const path = location?.pathname || '/';
  return String(origin ?? '').replace(/\/+$/, '')
    + (path.startsWith('/') ? path : `/${path}`)
    + (location?.search ?? '')
    + (location?.hash ?? '');
}

/**
 * Řádky menu Adresa: vstupy ze serveru s `current` podle originu, na kterém
 * stránka skutečně běží (prohlížeč zná i port, server ne vždy). Když aktuální
 * origin mezi vstupy není (`localhost`, jiná adresa stroje, tunel), přidá se
 * jako první řádek `kind: 'other'`.
 * @param {Array<{kind: string, origin: string, preferred?: boolean}>|null|undefined} access
 * @param {string|null|undefined} currentOrigin  `window.location.origin`
 * @returns {Array<{kind: string, origin: string, preferred: boolean, current: boolean}>}
 */
export function accessEntries(access, currentOrigin) {
  const here = normalizeOrigin(currentOrigin);
  const entries = (Array.isArray(access) ? access : [])
    .filter((entry) => entry && typeof entry.origin === 'string' && entry.origin !== '')
    .map((entry) => ({
      kind: String(entry.kind ?? ''),
      origin: entry.origin,
      preferred: Boolean(entry.preferred),
      current: here !== '' && normalizeOrigin(entry.origin) === here,
    }));
  if (here !== '' && !entries.some((entry) => entry.current)) {
    entries.unshift({ kind: 'other', origin: String(currentOrigin).replace(/\/+$/, ''), preferred: false, current: true });
  }
  return entries;
}

/**
 * https vstup, na který poslat uživatele z nezabezpečené stránky:
 * preferovaný, je-li https, jinak jméno před IP. `null` = žádný https vstup.
 * @param {Array<{kind: string, origin: string, preferred?: boolean}>|null|undefined} access
 */
export function httpsEntry(access) {
  const https = (Array.isArray(access) ? access : []).filter((entry) => entry && HTTPS_KINDS.includes(entry.kind));
  return https.find((entry) => entry.preferred)
    ?? HTTPS_KINDS.map((kind) => https.find((entry) => entry.kind === kind)).find(Boolean)
    ?? null;
}

/** Vstup, přes který je stránka otevřená (z {@link accessEntries}); `null` bez dat. */
export function currentEntry(entries) {
  return (Array.isArray(entries) ? entries : []).find((entry) => entry.current) ?? null;
}

/** Origin bez schématu pro popisek v pruhu (`10.0.0.7`, `shpd.example.com:8443`). */
export function originHost(origin) {
  return String(origin ?? '').replace(/^https?:\/\//i, '').replace(/\/+$/, '');
}

/** Je origin https? */
export function isHttps(origin) {
  return /^https:\/\//i.test(String(origin ?? ''));
}
