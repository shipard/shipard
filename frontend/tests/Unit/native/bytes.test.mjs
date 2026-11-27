// Bajty pro most: base64 a UUID.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { base64DecodedLength, base64ToBytes, bytesToBase64, isBase64, randomUuid } from '../../../src/native/bytes.js';

test('base64 tam i zpět, i velká data', () => {
  assert.equal(bytesToBase64(Uint8Array.from([0x1b, 0x40, 0x48, 0x65, 0x6c, 0x6c, 0x6f, 0x0a])), 'G0BIZWxsbwo=');
  assert.deepEqual([...base64ToBytes('G0BIZWxsbwo=')], [0x1b, 0x40, 0x48, 0x65, 0x6c, 0x6c, 0x6f, 0x0a]);
  assert.equal(bytesToBase64(new Uint8Array(0)), '');
  assert.equal(bytesToBase64(Uint8Array.from([1, 2, 3]).buffer), 'AQID', 'bere i ArrayBuffer');

  const big = new Uint8Array(300000).map((_, i) => i % 251);
  const encoded = bytesToBase64(big);
  assert.equal(encoded, Buffer.from(big).toString('base64'));
  assert.deepEqual(base64ToBytes(encoded), big);
});

test('isBase64 a velikost po dekódování', () => {
  for (const ok of ['', 'AQID', 'AQI=', 'AQ==', 'G0BIZWxsbwo=']) assert.equal(isBase64(ok), true, ok);
  for (const bad of ['AQI', 'A===', 'AQ=D', 'není', 'AQID\n', null, 7, undefined]) assert.equal(isBase64(bad), false, String(bad));
  // megabajty nesmí přetéct zásobník regexu
  assert.equal(isBase64('A'.repeat(8 * 1024 * 1024)), true);

  assert.equal(base64DecodedLength(''), 0);
  assert.equal(base64DecodedLength('AQID'), 3);
  assert.equal(base64DecodedLength('AQI='), 2);
  assert.equal(base64DecodedLength('AQ=='), 1);
});

test('randomUuid: verze 4 i bez crypto.randomUUID', () => {
  const insecure = { getRandomValues: (array) => webcrypto.getRandomValues(array) };
  const seen = new Set();
  for (let i = 0; i < 50; i++) {
    const id = randomUuid(insecure);
    assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    seen.add(id);
  }
  assert.equal(seen.size, 50);
  assert.match(randomUuid(), /^[0-9a-f-]{36}$/);
});
