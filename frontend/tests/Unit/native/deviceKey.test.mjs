// Klíč zařízení simulace (#177 U4, D33): podpis z WebCrypto převedený na
// DER musí ověřit `node:crypto` proti SPKI DER — stejně ho ověří server.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createPublicKey, verify, webcrypto } from 'node:crypto';
import {
  createDeviceKey,
  deviceStorageKey,
  hasWebCrypto,
  p1363ToDer,
  signingString,
} from '../../../src/native/mock/deviceKey.js';

export function memoryStorage() {
  const map = new Map();
  return {
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => map.set(key, String(value)),
    removeItem: (key) => map.delete(key),
    keys: () => [...map.keys()],
  };
}

const API_A = 'http://10.0.0.5/aaaa-aaaa-aaaa-aaaa/api/v1';
const API_B = 'http://10.0.0.5/bbbb-bbbb-bbbb-bbbb/api/v1';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

function verifyDer(spkiBase64, message, signatureBase64) {
  const key = createPublicKey({ key: Buffer.from(spkiBase64, 'base64'), format: 'der', type: 'spki' });
  return verify('sha256', Buffer.from(message, 'utf8'), { key, dsaEncoding: 'der' }, Buffer.from(signatureBase64, 'base64'));
}

test('podepisovaný řetězec a klíč úložiště', () => {
  assert.equal(signingString('register', 'dev-1', 'abc'), 'shipard-device-v1|register|dev-1|abc');
  assert.equal(deviceStorageKey(API_A), `shpd_native_mock_device:${API_A}`);
});

test('r‖s → DER: úvodní nula u nejvyššího bitu, ořez nul', () => {
  const hex = (bytes) => Buffer.from(bytes).toString('hex');
  const filled = (first, rest) => Uint8Array.from([first, ...new Array(31).fill(rest)]);

  // obě čísla bez nejvyššího bitu: INTEGER o 32 bajtech
  const plain = p1363ToDer(Uint8Array.from([...filled(0x7f, 0x11), ...filled(0x01, 0x22)]));
  assert.equal(hex(plain), `3044${'0220'}7f${'11'.repeat(31)}${'0220'}01${'22'.repeat(31)}`);

  // nejvyšší bit nastavený: úvodní nula, délka 33
  const high = p1363ToDer(Uint8Array.from([...filled(0x80, 0x00), ...filled(0xff, 0xff)]));
  assert.equal(hex(high), `3046${'0221'}0080${'00'.repeat(31)}${'0221'}00${'ff'.repeat(32)}`);

  // úvodní nuly se ořežou, po ořezu znovu platí pravidlo nejvyššího bitu
  const short = new Uint8Array(64);
  short[31] = 0x05; // r = 5
  short[62] = 0x80; // s = 0x8000
  assert.equal(hex(p1363ToDer(short)), '3008' + '020105' + '0203008000');

  // nula zůstane jedním bajtem
  assert.equal(hex(p1363ToDer(new Uint8Array(64))), '3006' + '020100' + '020100');
  assert.equal(hex(p1363ToDer(new Uint8Array(64).buffer)), '3006020100020100', 'bere i ArrayBuffer');
  assert.throws(() => p1363ToDer(new Uint8Array(63)), /equal lengths/);
});

test('podpis ověří node:crypto proti SPKI DER, řetězec přesně podle kontraktu', async () => {
  const key = createDeviceKey({ apiBase: API_A, storage: memoryStorage(), crypto: webcrypto });
  assert.equal(key.canSign(), true);
  const deviceId = await key.deviceId();
  assert.match(deviceId, UUID);

  const spki = await key.publicKeySpki();
  // několik podpisů — r a s s nejvyšším bitem i bez něj
  for (let i = 0; i < 12; i++) {
    const challenge = `p3mX0uJ8m1nq2Yb7c5VtR9kLwZ4aQeH6sD0fGiNoPx${i}`;
    const signature = await key.sign('register', challenge);
    assert.equal(verifyDer(spki, `shipard-device-v1|register|${deviceId}|${challenge}`, signature), true, `podpis ${i}`);
    assert.equal(verifyDer(spki, `shipard-device-v1|self|${deviceId}|${challenge}`, signature), false, 'jiný účel nesedí');
  }
});

test('identita se drží v úložišti a je pro každý zdroj dat jiná', async () => {
  const storage = memoryStorage();
  const a = createDeviceKey({ apiBase: API_A, storage, crypto: webcrypto });
  const b = createDeviceKey({ apiBase: API_B, storage, crypto: webcrypto });
  const [idA, idB] = [await a.deviceId(), await b.deviceId()];
  assert.notEqual(idA, idB);
  assert.notEqual(await a.publicKeySpki(), await b.publicKeySpki());
  assert.deepEqual(storage.keys().sort(), [deviceStorageKey(API_A), deviceStorageKey(API_B)]);

  // nová instance nad stejným úložištěm: stejné ID i klíč, podpis ověří původní veřejný klíč
  const again = createDeviceKey({ apiBase: API_A, storage, crypto: webcrypto });
  assert.equal(await again.deviceId(), idA);
  assert.equal(await again.publicKeySpki(), await a.publicKeySpki());
  assert.equal(verifyDer(await a.publicKeySpki(), signingString('self', idA, 'x'), await again.sign('self', 'x')), true);

  // zneplatnění smaže jen identitu tohoto zdroje dat
  a.forget();
  assert.deepEqual(storage.keys(), [deviceStorageKey(API_B)]);
  assert.notEqual(await a.deviceId(), idA);
  assert.equal(await b.deviceId(), idB);
});

test('bez WebCrypto (nezabezpečený kontext): ID ano, podpis ne; klíč vznikne později', async () => {
  const storage = memoryStorage();
  const insecure = { getRandomValues: (array) => webcrypto.getRandomValues(array) };
  assert.equal(hasWebCrypto(insecure), false);
  assert.equal(hasWebCrypto(webcrypto), true);

  const key = createDeviceKey({ apiBase: API_A, storage, crypto: insecure });
  assert.equal(key.canSign(), false);
  const deviceId = await key.deviceId();
  assert.match(deviceId, UUID);
  await assert.rejects(key.sign('self', 'x'), /secure context/);
  await assert.rejects(key.publicKeySpki(), /secure context/);

  const secure = createDeviceKey({ apiBase: API_A, storage, crypto: webcrypto });
  assert.equal(await secure.deviceId(), deviceId, 'ID zůstává');
  assert.equal(verifyDer(await secure.publicKeySpki(), signingString('self', deviceId, 'x'), await secure.sign('self', 'x')), true);
});

test('poškozený záznam v úložišti se nahradí novou identitou', async () => {
  const storage = memoryStorage();
  storage.setItem(deviceStorageKey(API_A), '{nope');
  const key = createDeviceKey({ apiBase: API_A, storage, crypto: webcrypto });
  assert.match(await key.deviceId(), UUID);
});
