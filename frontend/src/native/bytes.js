/**
 * Bajty pro most nativní aplikace: base64 (data tiskové úlohy, klíč
 * a podpis zařízení) a UUID. Čisté funkce bez importů — sdílí je simulace,
 * panel simulace i stránka Most ve vývojářském dashboardu.
 */

// Bez opakované skupiny — `(?:…{4})*` na megabajtech přeteče zásobník regexu.
const BASE64 = /^[A-Za-z0-9+/]*={0,2}$/;

/**
 * @param {Uint8Array|ArrayBuffer} bytes
 * @returns {string}
 */
export function bytesToBase64(bytes) {
  const data = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  let binary = '';
  // Po částech — `String.fromCharCode(...data)` na megabajtech přeteče zásobník.
  for (let i = 0; i < data.length; i += 0x8000) {
    binary += String.fromCharCode.apply(null, data.subarray(i, i + 0x8000));
  }
  return btoa(binary);
}

/**
 * @param {unknown} value
 * @returns {boolean} je to platný base64 se zarovnáním (i prázdný řetězec)
 */
export function isBase64(value) {
  return typeof value === 'string' && value.length % 4 === 0 && BASE64.test(value);
}

/** Velikost dat po dekódování, bez dekódování (kontrola limitu před alokací). */
export function base64DecodedLength(value) {
  const padding = value.endsWith('==') ? 2 : value.endsWith('=') ? 1 : 0;
  return (value.length / 4) * 3 - padding;
}

/**
 * @param {string} value  platný base64 (`isBase64`)
 * @returns {Uint8Array}
 */
export function base64ToBytes(value) {
  const binary = atob(value);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes;
}

/**
 * UUID verze 4. `crypto.randomUUID()` prohlížeč nabízí jen v zabezpečeném
 * kontextu — vývojový server na `http://<privátní adresa>` jím není, proto
 * z `getRandomValues`, které je všude.
 *
 * @param {Crypto} [crypto]
 * @returns {string}
 */
export function randomUuid(crypto = globalThis.crypto) {
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const hex = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
