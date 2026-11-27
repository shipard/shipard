/**
 * Fixture interoperability podpisu zařízení (#177 U4, task 3): klíč ECDSA
 * P-256 a podpisy z WebCrypto, vyrobené **stejnou funkcí jako simulace mostu**
 * (`frontend/src/native/mock/deviceKey.js`). PHP je ověřuje v `DeviceKeyTest`
 * — hlídá, že server a klient skládají tentýž řetězec a rozumí si ve formátu
 * klíče (SPKI DER) i podpisu (DER).
 *
 * Fixture se generuje jednou a commituje; nový běh dá jiný klíč i podpisy:
 *
 *   node tests/Fixtures/Devices/generate-webcrypto-fixture.mjs > tests/Fixtures/Devices/webcrypto-es256.json
 *
 * Až bude aplikace, přibude vedle fixture z CryptoKitu (task 5).
 */
import { webcrypto } from 'node:crypto';
import { createDeviceKey, signingString } from '../../../frontend/src/native/mock/deviceKey.js';

const memory = new Map();
const storage = {
  getItem: (key) => (memory.has(key) ? memory.get(key) : null),
  setItem: (key, value) => memory.set(key, String(value)),
  removeItem: (key) => memory.delete(key),
};

const key = createDeviceKey({ apiBase: 'https://fixture.example.com/api/v1', storage, crypto: webcrypto });
const deviceId = await key.deviceId();

// Víc podpisů: DER kóduje r a s proměnnou délkou (úvodní nula u čísla
// s nejvyšším bitem), jediný vzorek by nemusel pokrýt všechny tvary.
const purposes = ['register', 'self', 'peripherals'];
const signatures = [];
for (let i = 0; i < 12; i += 1) {
  const purpose = purposes[i % purposes.length];
  const challenge = Buffer.from(webcrypto.getRandomValues(new Uint8Array(32))).toString('base64url');
  signatures.push({
    purpose,
    challenge,
    signingString: signingString(purpose, deviceId, challenge),
    signature: await key.sign(purpose, challenge),
  });
}

process.stdout.write(`${JSON.stringify({
  source: 'WebCrypto (Node) via frontend/src/native/mock/deviceKey.js',
  algorithm: 'ES256',
  deviceId,
  publicKey: await key.publicKeySpki(),
  signatures,
}, null, 2)}\n`);
