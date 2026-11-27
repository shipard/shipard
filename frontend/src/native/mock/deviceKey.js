/**
 * Klíč zařízení simulace (#177 U4, U12, D33): `deviceId` a pár ECDSA P-256
 * z WebCrypto, uložený jako JWK v `localStorage` — **jen pro vývoj**,
 * skutečná aplikace drží klíč v Secure Enclave.
 *
 * Identita je pro každý zdroj dat zvlášť: klíč úložiště nese adresu API
 * (`location.origin + API_BASE_URL`). Ve vývoji sdílejí všechny zdroje dat
 * jeden origin, bez adresy v klíči by si je simulace pletla.
 *
 * Podpis je skutečný — server ho ověří stejně jako u aplikace. Úložiště
 * i `crypto` jdou parametrem; stejnou funkcí podepisuje i Node (testy,
 * fixture serveru v tasku 3).
 */
import { bytesToBase64, randomUuid } from '../bytes.js';

/** Klíč `localStorage` s identitou pro daný zdroj dat. */
export function deviceStorageKey(apiBase) {
  return `shpd_native_mock_device:${apiBase}`;
}

/** Podepisovaný řetězec (docs/native-bridge.md §7.1). */
export function signingString(purpose, deviceId, challenge) {
  return `shipard-device-v1|${purpose}|${deviceId}|${challenge}`;
}

/** Má prohlížeč WebCrypto? `crypto.subtle` je jen v zabezpečeném kontextu (https, localhost). */
export function hasWebCrypto(crypto) {
  return typeof crypto?.subtle?.generateKey === 'function';
}

function derInteger(bytes) {
  let start = 0;
  while (start < bytes.length - 1 && bytes[start] === 0) start += 1;
  const body = bytes.subarray(start);
  // Kladné číslo: nejvyšší bit nesmí být nastavený — jinak úvodní nula.
  const pad = (body[0] & 0x80) !== 0 ? 1 : 0;
  const out = new Uint8Array(2 + pad + body.length);
  out[0] = 0x02;
  out[1] = pad + body.length;
  out.set(body, 2 + pad);
  return out;
}

/**
 * Podpis ECDSA z WebCrypto (r‖s, IEEE P1363) → DER `SEQUENCE { INTEGER r, INTEGER s }`.
 *
 * @param {Uint8Array|ArrayBuffer} signature  r a s stejné délky za sebou (P-256: 64 bajtů)
 * @returns {Uint8Array}
 */
export function p1363ToDer(signature) {
  const raw = signature instanceof Uint8Array ? signature : new Uint8Array(signature);
  if (raw.length === 0 || raw.length % 2 !== 0) throw new Error('Signature must be r||s of equal lengths');
  const half = raw.length / 2;
  const r = derInteger(raw.subarray(0, half));
  const s = derInteger(raw.subarray(half));
  // P-256: nejvýš 2 × (2 + 33) bajtů — délka se vejde do jednoho bajtu.
  const out = new Uint8Array(2 + r.length + s.length);
  out[0] = 0x30;
  out[1] = r.length + s.length;
  out.set(r, 2);
  out.set(s, 2 + r.length);
  return out;
}

const ALGORITHM = { name: 'ECDSA', namedCurve: 'P-256' };

/**
 * @param {{apiBase: string, storage: Storage, crypto: Crypto}} env
 */
export function createDeviceKey({ apiBase, storage, crypto }) {
  const storageKey = deviceStorageKey(apiBase);
  let cached = null; // { deviceId, privateKey: CryptoKey|null, publicKey: CryptoKey|null }
  let loading = null;

  function read() {
    try {
      const stored = JSON.parse(storage.getItem(storageKey) ?? 'null');
      return stored !== null && typeof stored === 'object' && typeof stored.deviceId === 'string' ? stored : null;
    } catch {
      return null;
    }
  }

  async function load() {
    let stored = read();
    if (stored === null) stored = { deviceId: randomUuid(crypto) };

    let privateKey = null;
    let publicKey = null;
    if (hasWebCrypto(crypto)) {
      if (stored.privateKey && stored.publicKey) {
        privateKey = await crypto.subtle.importKey('jwk', stored.privateKey, ALGORITHM, true, ['sign']);
        publicKey = await crypto.subtle.importKey('jwk', stored.publicKey, ALGORITHM, true, ['verify']);
      } else {
        const pair = await crypto.subtle.generateKey(ALGORITHM, true, ['sign', 'verify']);
        privateKey = pair.privateKey;
        publicKey = pair.publicKey;
        stored.privateKey = await crypto.subtle.exportKey('jwk', privateKey);
        stored.publicKey = await crypto.subtle.exportKey('jwk', publicKey);
      }
    }
    storage.setItem(storageKey, JSON.stringify(stored));
    return { deviceId: stored.deviceId, privateKey, publicKey };
  }

  /** Identita pro tento zdroj dat — vzniká při první práci s ním. */
  function identity() {
    if (cached) return Promise.resolve(cached);
    if (!loading) {
      loading = load().then(
        (value) => {
          cached = value;
          loading = null;
          return value;
        },
        (error) => {
          loading = null;
          throw error;
        },
      );
    }
    return loading;
  }

  async function keys() {
    const id = await identity();
    if (!id.privateKey || !id.publicKey) {
      throw new Error('WebCrypto is not available (the page needs a secure context: https or localhost)');
    }
    return id;
  }

  return {
    storageKey,

    /** Jde na této stránce podepisovat? */
    canSign() {
      return hasWebCrypto(crypto);
    },

    async deviceId() {
      return (await identity()).deviceId;
    },

    /**
     * Podpis řetězce `shipard-device-v1|<účel>|<deviceId>|<výzva>` — DER v base64.
     *
     * @param {string} purpose    účel výzvy (`register`, `self`, `peripherals`, …)
     * @param {string} challenge  výzva serveru
     */
    async sign(purpose, challenge) {
      const { deviceId, privateKey } = await keys();
      const message = new TextEncoder().encode(signingString(purpose, deviceId, challenge));
      const signature = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, privateKey, message);
      return bytesToBase64(p1363ToDer(new Uint8Array(signature)));
    },

    /** Veřejný klíč — SPKI DER v base64. */
    async publicKeySpki() {
      const { publicKey } = await keys();
      return bytesToBase64(new Uint8Array(await crypto.subtle.exportKey('spki', publicKey)));
    },

    /** Zahodí identitu tohoto zdroje dat (`DEVICE_REVOKED`); příští práce vytvoří novou. */
    forget() {
      cached = null;
      loading = null;
      storage.removeItem(storageKey);
    },
  };
}
