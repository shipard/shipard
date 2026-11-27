// Obálka mostu nativní aplikace (#177 D21): normalizace chyb, paměť `info`
// a odběr událostí. `window` je v Node holý EventTarget.

import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = new EventTarget();

const {
  NativeBridgeError,
  isNativeAvailable,
  isNativeMock,
  nativeCall,
  nativeInfo,
  resetNativeInfo,
  hasCapability,
  onNativeEvent,
} = await import('../../../src/native/bridge.js');

function setBridge(call, extra = {}) {
  window.shipardNative = { call, ...extra };
}

beforeEach(() => {
  delete window.shipardNative;
  resetNativeInfo();
});

test('bez mostu: nedostupný a volání končí NOT_AVAILABLE', async () => {
  assert.equal(isNativeAvailable(), false);
  assert.equal(isNativeMock(), false);
  await assert.rejects(nativeCall('info'), (e) => e instanceof NativeBridgeError && e.code === 'NOT_AVAILABLE');

  window.shipardNative = { call: 'nope' };
  assert.equal(isNativeAvailable(), false, 'objekt bez funkce call není most');
});

test('most se pozná podle funkce call, simulace podle __mock', () => {
  setBridge(async () => ({}));
  assert.equal(isNativeAvailable(), true);
  assert.equal(isNativeMock(), false);
  setBridge(async () => ({}), { __mock: true });
  assert.equal(isNativeMock(), true);
});

test('předá metodu a parametry, params lze vynechat', async () => {
  const calls = [];
  setBridge(async (...args) => {
    calls.push(args);
    return { ok: true };
  });
  assert.deepEqual(await nativeCall('peripherals.list'), { ok: true });
  assert.deepEqual(await nativeCall('print.submit', { jobId: 'j1' }), { ok: true });
  assert.deepEqual(calls, [['peripherals.list'], ['print.submit', { jobId: 'j1' }]]);
});

test('chyba mostu si nese code, message a details', async () => {
  setBridge(async () => {
    throw { code: 'PIN_INVALID', message: 'PIN does not match', details: { attemptsLeft: 2 } };
  });
  await assert.rejects(nativeCall('device.enroll', {}), (e) => {
    assert.ok(e instanceof NativeBridgeError);
    assert.equal(e.code, 'PIN_INVALID');
    assert.equal(e.message, 'PIN does not match');
    assert.deepEqual(e.details, { attemptsLeft: 2 });
    return true;
  });
});

test('chyba bez zprávy a s podrobnostmi mimo objekt se dorovná', async () => {
  setBridge(async () => {
    throw { code: 'TIMEOUT', details: 'x' };
  });
  await assert.rejects(nativeCall('print.submit', {}), (e) => {
    assert.equal(e.code, 'TIMEOUT');
    assert.equal(e.message, 'TIMEOUT');
    assert.equal(e.details, null);
    return true;
  });
});

test('odmítnutí mimo tvar kontraktu je INVALID_RESPONSE', async () => {
  for (const thrown of [new Error('boom'), 'text', null, { message: 'bez kódu' }, { code: '' }]) {
    setBridge(async () => {
      throw thrown;
    });
    await assert.rejects(nativeCall('info'), (e) => e instanceof NativeBridgeError && e.code === 'INVALID_RESPONSE');
  }
  // synchronní výjimka z call() se normalizuje stejně
  setBridge(() => {
    throw new Error('sync');
  });
  await assert.rejects(nativeCall('info'), (e) => e.code === 'INVALID_RESPONSE');
});

test('výsledek mimo objekt je INVALID_RESPONSE', async () => {
  for (const result of [undefined, null, 'sent', 1, []]) {
    setBridge(async () => result);
    await assert.rejects(nativeCall('info'), (e) => e instanceof NativeBridgeError && e.code === 'INVALID_RESPONSE');
  }
});

test('nativeInfo si výsledek pamatuje, refresh se ptá znovu', async () => {
  let count = 0;
  setBridge(async () => ({ apiVersion: 1, capabilities: ['scanner'], n: ++count }));

  assert.equal(hasCapability('scanner'), false, 'před prvním info nic');
  const [a, b] = await Promise.all([nativeInfo(), nativeInfo()]);
  assert.equal(a.n, 1);
  assert.equal(b.n, 1, 'souběžná volání sdílejí jeden dotaz');
  assert.equal((await nativeInfo()).n, 1);
  assert.equal(hasCapability('scanner'), true);
  assert.equal(hasCapability('profiles'), false);

  assert.equal((await nativeInfo({ refresh: true })).n, 2);
  assert.equal((await nativeInfo()).n, 2);
});

test('selhání info se nepamatuje', async () => {
  let fail = true;
  setBridge(async () => {
    if (fail) throw { code: 'INTERNAL_ERROR', message: 'x' };
    return { apiVersion: 1, capabilities: [] };
  });
  await assert.rejects(nativeInfo(), (e) => e.code === 'INTERNAL_ERROR');
  fail = false;
  assert.equal((await nativeInfo()).apiVersion, 1);
});

test('onNativeEvent předá detail a odhlášení přestane poslouchat', () => {
  const seen = [];
  const off = onNativeEvent('peripheral', (detail) => seen.push(detail));
  window.dispatchEvent(new CustomEvent('shipard:peripheral', { detail: { peripheralId: 'p-1', status: 'offline' } }));
  window.dispatchEvent(new CustomEvent('shipard:scan', { detail: { data: 'jiná událost' } }));
  assert.deepEqual(seen, [{ peripheralId: 'p-1', status: 'offline' }]);

  off();
  window.dispatchEvent(new CustomEvent('shipard:peripheral', { detail: { peripheralId: 'p-1', status: 'ready' } }));
  assert.equal(seen.length, 1);
});
