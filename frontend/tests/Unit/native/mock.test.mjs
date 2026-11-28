// Simulace mostu (#177 D29, U12): sama sebe kontroluje validátory
// kontraktu; limity U8, DUPLICATE_JOB, NOT_SUPPORTED u metod dalších tasků,
// podepsaný sync proti maketě serveru a identita pro každý zdroj dat (D33).

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createPublicKey, verify, webcrypto } from 'node:crypto';
import { validateError, validateInfo, validatePeripheralList } from '../../../src/native/contract.js';
import { createMockBridge, install, isoWithZone, mockControls } from '../../../src/native/mock/install.js';
import { deviceStorageKey } from '../../../src/native/mock/deviceKey.js';
import { MOCK_STATE_KEY, previewProfile, profilesFromCatalog } from '../../../src/native/mock/state.js';

const API_A = 'http://10.0.0.5/aaaa-aaaa-aaaa-aaaa/api/v1';
const API_B = 'http://10.0.0.5/bbbb-bbbb-bbbb-bbbb/api/v1';

function memoryStorage() {
  const map = new Map();
  return {
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => map.set(key, String(value)),
    removeItem: (key) => map.delete(key),
    keys: () => [...map.keys()],
  };
}

function json(status, body) {
  return { ok: status >= 200 && status < 300, status, json: async () => body };
}

const offline = async () => {
  throw new TypeError('fetch failed');
};

function setup({ apiBase = API_A, storage = memoryStorage(), fetch = offline, crypto = webcrypto } = {}) {
  const target = new EventTarget();
  const events = [];
  for (const name of ['scan', 'peripheral']) {
    target.addEventListener(`shipard:${name}`, (event) => events.push({ name, detail: event.detail }));
  }
  const mock = createMockBridge({ apiBase, storage, fetch, crypto, target, now: () => new Date(2026, 10, 27, 10, 15, 42) });
  return { ...mock, call: mock.bridge.call, storage, target, events };
}

/** Odmítnutí s daným kódem ve tvaru kontraktu. */
async function rejectsWith(promise, code) {
  await assert.rejects(promise, (error) => {
    assert.equal(error.code, code);
    assert.deepEqual(validateError(error), [], `chyba ${code} má tvar kontraktu`);
    assert.ok(!(error instanceof Error), 'most zamítá prostým objektem');
    return true;
  });
}

const job = (overrides = {}) => ({ jobId: 'job-1', peripheralId: 'mock-receipt', format: 'escpos', data: 'G0BIZWxsbwo=', ...overrides });

/** Katalog profilů, jak ho vrací `GET /_devices/printer-profiles` — jiné šířky než vestavěné, ať je vidět, odkud se berou. */
const SERVER_PROFILES = [
  { id: 'escpos-80-cp852', name: '80 mm', columns: 48, columnsSmall: 64, dotsPerLine: 576, codePage: 'cp852', features: ['cut', 'drawer'], models: [] },
  { id: 'escpos-58-cp852', name: '58 mm', columns: 30, columnsSmall: 40, dotsPerLine: 384, codePage: 'cp852', features: ['cut'], models: [] },
];

/** Maketa serveru pro podepsaná volání: výzvy, GET self, PUT peripherals, katalog profilů. */
function fakeServer({ mode = 'shared', errorFor = {}, profiles = SERVER_PROFILES } = {}) {
  const calls = [];
  let counter = 0;
  const fetch = async (url, init) => {
    const path = url.replace(/^.*\/api\/v1/, '');
    const body = init.body ? JSON.parse(init.body) : null;
    calls.push({ url, method: init.method, path, headers: init.headers, body, credentials: init.credentials });
    const key = `${init.method} ${path}`;
    if (errorFor[key]) return json(errorFor[key].status, { success: false, error: { code: errorFor[key].code, message: 'x' } });
    if (key === 'POST /_devices/challenge') return json(200, { success: true, data: { challenge: `challenge-${++counter}`, expiresIn: 60 } });
    if (key === 'GET /_devices/self') return json(200, { success: true, data: { mode, name: 'Pokladna 2', state: 'active', users: [], pinPolicy: {}, autoLockMinutes: null } });
    if (key === 'PUT /_devices/self/peripherals') return json(200, { success: true, data: { count: body.peripherals.length } });
    if (key === 'GET /_devices/printer-profiles') return json(200, { success: true, data: { profiles } });
    return json(404, { success: false, error: { code: 'NOT_FOUND', message: 'Not found' } });
  };
  return { fetch, calls };
}

test('info a periferie projdou validátory kontraktu', async () => {
  const { bridge, call } = setup();
  assert.equal(bridge.__mock, true);

  const info = await call('info');
  assert.deepEqual(validateInfo(info), []);
  assert.equal(info.platform, 'mock');
  assert.equal(info.keyStorage, 'mock');
  assert.equal(info.dataSource, API_A);
  assert.equal(info.mode, 'unregistered');
  assert.deepEqual(info.capabilities, ['device', 'peripherals', 'print.escpos', 'scanner']);
  assert.equal((await call('info')).deviceId, info.deviceId, 'ID zařízení se nemění');

  const list = await call('peripherals.list');
  assert.deepEqual(validatePeripheralList(list), []);
  assert.deepEqual(list.peripherals.map((p) => [p.id, p.kind, p.status]), [
    ['mock-receipt', 'printer', 'ready'],
    ['mock-scanner', 'scanner', 'ready'],
    ['mock-receipt-58', 'printer', 'ready'],
  ]);
  assert.equal(list.peripherals[0].profileId, 'escpos-80-cp852');
  assert.equal(list.peripherals[2].profileId, 'escpos-58-cp852');
  assert.deepEqual(list.peripherals[1].reads, ['barcode', 'rfid']);
});

test('neznámá metoda, parametry mimo objekt, metody dalších tasků', async () => {
  const { call } = setup();
  await rejectsWith(call('nope'), 'UNKNOWN_METHOD');
  await rejectsWith(call(), 'UNKNOWN_METHOD');
  await rejectsWith(call('info', 'x'), 'INVALID_PARAMS');
  await rejectsWith(call('peripherals.list', [1]), 'INVALID_PARAMS');
  for (const method of ['device.enroll', 'session.lock', 'session.take', 'auth.external']) {
    await rejectsWith(call(method, {}), 'NOT_SUPPORTED');
  }
});

test('print.submit: kontrola parametrů a limity U8', async () => {
  const { call, controls } = setup();
  await rejectsWith(call('print.submit'), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', {}), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', job({ jobId: '' })), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', job({ peripheralId: 7 })), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', job({ format: 'zpl' })), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', job({ data: 'není base64!' })), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', job({ data: '' })), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', job({ copies: 0 })), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', job({ copies: 11 })), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', job({ copies: 1.5 })), 'INVALID_PARAMS');
  await rejectsWith(call('print.submit', job({ reprint: 'yes' })), 'INVALID_PARAMS');
  assert.equal(controls.jobs().length, 0, 'odmítnutá úloha se netiskne');

  // 5 MB po dekódování projde, o bajt víc ne
  const fiveMb = 'A'.repeat(1747626 * 4) + 'AAA=';
  assert.deepEqual(await call('print.submit', job({ jobId: 'big', data: fiveMb })), { status: 'sent' });
  assert.equal(controls.jobs()[0].bytes.length, 5 * 1024 * 1024);
  await rejectsWith(call('print.submit', job({ jobId: 'bigger', data: fiveMb.slice(0, -4) + 'AAAAAA==' })), 'PAYLOAD_TOO_LARGE');

  assert.deepEqual(await call('print.submit', job({ jobId: 'ten', copies: 10 })), { status: 'sent' });
});

test('print.submit: periferie, formát a náhled úlohy', async () => {
  const { call, controls } = setup();
  await rejectsWith(call('print.submit', job({ peripheralId: 'p-nope' })), 'PERIPHERAL_NOT_FOUND');
  await rejectsWith(call('print.submit', job({ format: 'pdf' })), 'UNSUPPORTED_FORMAT');
  await rejectsWith(call('print.submit', job({ peripheralId: 'mock-scanner' })), 'UNSUPPORTED_FORMAT');

  assert.deepEqual(await call('print.submit', job({ copies: 2 })), { status: 'sent' });
  const [preview] = controls.jobs();
  assert.equal(preview.jobId, 'job-1');
  assert.equal(preview.copies, 2);
  assert.equal(preview.reprint, false);
  assert.equal(Buffer.from(preview.bytes).toString('latin1'), '\x1b@Hello\n');

  // odpojená tiskárna: úloha neodešla, jobId si simulace nepamatuje
  controls.setPeripheralStatus('mock-receipt', 'offline');
  await rejectsWith(call('print.submit', job({ jobId: 'job-2' })), 'PERIPHERAL_UNAVAILABLE');
  controls.setPeripheralStatus('mock-receipt', 'ready');
  assert.deepEqual(await call('print.submit', job({ jobId: 'job-2' })), { status: 'sent' });
});

test('print.submit: tiskárna ve stavu error hlásí PRINTER_ERROR s došlým papírem', async () => {
  const { call, controls } = setup();
  controls.setPeripheralStatus('mock-receipt', 'error');

  await assert.rejects(call('print.submit', job({ jobId: 'paper' })), (error) => {
    assert.equal(error.code, 'PRINTER_ERROR');
    assert.deepEqual(error.details, { paperOut: true });
    assert.deepEqual(validateError(error), []);
    assert.ok(!(error instanceof Error), 'most zamítá prostým objektem');
    return true;
  });
  assert.deepEqual(controls.jobs(), [], 'úloha se nevytiskla');

  // Chyba se týká jen té tiskárny — druhá tiskne dál.
  assert.deepEqual(await call('print.submit', job({ jobId: 'narrow', peripheralId: 'mock-receipt-58' })), { status: 'sent' });

  // Neúspěšnou úlohu si simulace nepamatuje: po opravě projde táž i bez
  // `reprint`, s `reprint` (Zkusit znovu) také.
  controls.setPeripheralStatus('mock-receipt', 'ready');
  assert.deepEqual(await call('print.submit', job({ jobId: 'paper', reprint: true })), { status: 'sent' });
  assert.deepEqual(controls.jobs().map((j) => [j.jobId, j.peripheralId, j.reprint]), [
    ['paper', 'mock-receipt', true],
    ['narrow', 'mock-receipt-58', false],
  ]);
});

test('profily náhledu: do registrace vestavěné, po synchronizaci katalog ze serveru', async () => {
  const server = fakeServer();
  const { call, controls } = setup({ fetch: server.fetch });

  assert.equal(controls.snapshot().profilesFromServer, false);
  assert.deepEqual(controls.profileOf('mock-receipt'), { columns: 42, columnsSmall: 56, codePage: 'cp852' });
  assert.deepEqual(controls.profileOf('mock-receipt-58'), { columns: 32, columnsSmall: 42, codePage: 'cp852' });
  // Neregistrované zařízení katalog stáhnout nemůže — a ani to nezkouší.
  assert.equal(await controls.ensureProfiles(), false);
  assert.equal(server.calls.length, 0);

  const changes = [];
  controls.subscribe(() => changes.push(1));
  await call('device.sync');

  assert.equal(controls.snapshot().profilesFromServer, true);
  assert.ok(changes.length > 0, 'stažení katalogu se hlásí panelu');
  assert.deepEqual(controls.profileOf('mock-receipt'), { columns: 48, columnsSmall: 64, codePage: 'cp852' });
  assert.deepEqual(controls.profileOf('mock-receipt-58'), { columns: 30, columnsSmall: 40, codePage: 'cp852' });
  assert.deepEqual(controls.profileOf('mock-scanner'), { columns: 32, columnsSmall: 42, codePage: 'cp852' }, 'bez profilu záložní');

  const before = server.calls.length;
  assert.equal(await controls.ensureProfiles(), true);
  assert.equal(server.calls.length, before, 'stažený katalog se nestahuje znovu');
});

test('profily náhledu: po novém načtení stránky si registrovaná simulace katalog stáhne', async () => {
  const storage = memoryStorage();
  const server = fakeServer();
  await setup({ storage, fetch: server.fetch }).call('device.sync');

  const reloaded = setup({ storage, fetch: server.fetch });
  assert.equal(reloaded.controls.snapshot().profilesFromServer, false);
  const before = server.calls.length;

  assert.equal(await reloaded.controls.ensureProfiles(), true);

  assert.deepEqual(server.calls.slice(before).map((c) => `${c.method} ${c.path}`), [
    'POST /_devices/challenge', 'GET /_devices/printer-profiles',
  ]);
  assert.deepEqual(reloaded.controls.profileOf('mock-receipt'), { columns: 48, columnsSmall: 64, codePage: 'cp852' });
});

test('profily náhledu: selhání katalogu synchronizaci neshodí a nechá vestavěné profily', async () => {
  const server = fakeServer({ errorFor: { 'GET /_devices/printer-profiles': { status: 404, code: 'NOT_FOUND' } } });
  const { call, controls } = setup({ fetch: server.fetch });

  const info = await call('device.sync');

  assert.equal(info.mode, 'shared');
  assert.equal(controls.snapshot().profilesFromServer, false);
  assert.deepEqual(controls.profileOf('mock-receipt'), { columns: 42, columnsSmall: 56, codePage: 'cp852' });
  assert.equal(await controls.ensureProfiles(), false);

  // Odpověď, která katalog není, se nepoužije.
  const odd = setup({ fetch: fakeServer({ profiles: 'nope' }).fetch });
  await odd.call('device.sync');
  assert.equal(odd.controls.snapshot().profilesFromServer, false);
});

test('katalog profilů: jen úplné profily, neznámý profil záložní', () => {
  assert.equal(profilesFromCatalog(null), null);
  assert.equal(profilesFromCatalog({ profiles: {} }), null);
  assert.deepEqual(profilesFromCatalog({ profiles: [] }), {});
  assert.deepEqual(
    profilesFromCatalog({ profiles: [
      ...SERVER_PROFILES,
      null,
      { id: 'no-columns', codePage: 'cp852' },
      { id: 'zero', columns: 0, columnsSmall: 10, codePage: 'cp852' },
      { id: '', columns: 10, columnsSmall: 12, codePage: 'cp852' },
      { id: 'no-page', columns: 10, columnsSmall: 12 },
    ] }),
    {
      'escpos-80-cp852': { columns: 48, columnsSmall: 64, codePage: 'cp852' },
      'escpos-58-cp852': { columns: 30, columnsSmall: 40, codePage: 'cp852' },
    },
  );

  const catalog = { 'escpos-80-cp852': { columns: 48, columnsSmall: 64, codePage: 'cp852' } };
  assert.deepEqual(previewProfile('escpos-80-cp852', catalog), catalog['escpos-80-cp852']);
  assert.deepEqual(previewProfile('escpos-58-cp852', catalog), { columns: 32, columnsSmall: 42, codePage: 'cp852' }, 'mimo katalog vestavěný');
  assert.deepEqual(previewProfile('unknown', catalog), { columns: 32, columnsSmall: 42, codePage: 'cp852' });
  assert.deepEqual(previewProfile(undefined, null), { columns: 32, columnsSmall: 42, codePage: 'cp852' });
});

test('DUPLICATE_JOB: paměť posledních 100 jobId, reprint projde', async () => {
  const { call, controls } = setup();
  await call('print.submit', job({ jobId: 'first' }));
  await rejectsWith(call('print.submit', job({ jobId: 'first' })), 'DUPLICATE_JOB');
  assert.deepEqual(await call('print.submit', job({ jobId: 'first', reprint: true })), { status: 'sent' });
  assert.equal(controls.jobs()[0].reprint, true);

  for (let i = 0; i < 99; i++) await call('print.submit', job({ jobId: `fill-${i}` }));
  await rejectsWith(call('print.submit', job({ jobId: 'first' })), 'DUPLICATE_JOB');
  await call('print.submit', job({ jobId: 'fill-99' }));
  assert.deepEqual(await call('print.submit', job({ jobId: 'first' })), { status: 'sent' }, 'stoprvní úloha vytlačí nejstarší ID');

  assert.equal(controls.jobs().length, 20, 'náhled drží posledních 20 úloh');
  assert.equal(controls.jobs()[0].jobId, 'first');
});

test('device.register: skutečný podpis výzvy', async () => {
  const { call } = setup();
  await rejectsWith(call('device.register'), 'INVALID_PARAMS');
  await rejectsWith(call('device.register', { challenge: '' }), 'INVALID_PARAMS');

  const challenge = 'p3mX0uJ8m1nq2Yb7c5VtR9kLwZ4aQeH6sD0fGiNoPxE';
  const result = await call('device.register', { challenge });
  assert.equal(result.algorithm, 'ES256');
  assert.equal(result.deviceId, (await call('info')).deviceId);
  const key = createPublicKey({ key: Buffer.from(result.publicKey, 'base64'), format: 'der', type: 'spki' });
  assert.equal(key.asymmetricKeyDetails.namedCurve, 'prime256v1');
  const message = Buffer.from(`shipard-device-v1|register|${result.deviceId}|${challenge}`, 'utf8');
  assert.equal(verify('sha256', message, { key, dsaEncoding: 'der' }, Buffer.from(result.signature, 'base64')), true);
});

test('device.sync: podepsané GET self a PUT peripherals, režim z odpovědi', async () => {
  const server = fakeServer({ mode: 'shared' });
  const { call, storage } = setup({ fetch: server.fetch });
  const before = await call('info');
  const registered = await call('device.register', { challenge: 'reg' });

  const info = await call('device.sync');
  assert.deepEqual(validateInfo(info), []);
  assert.equal(info.mode, 'shared');
  assert.equal(info.deviceId, before.deviceId);

  assert.deepEqual(server.calls.map((c) => `${c.method} ${c.path}`), [
    'POST /_devices/challenge',
    'GET /_devices/self',
    'POST /_devices/challenge',
    'PUT /_devices/self/peripherals',
    'POST /_devices/challenge',
    'GET /_devices/printer-profiles',
  ]);
  assert.ok(server.calls.every((c) => c.url.startsWith(API_A + '/_devices/') && c.credentials === 'omit'));
  assert.deepEqual(server.calls[0].body, { deviceId: info.deviceId, purpose: 'self' });
  assert.deepEqual(server.calls[2].body, { deviceId: info.deviceId, purpose: 'peripherals' });
  assert.deepEqual(server.calls[4].body, { deviceId: info.deviceId, purpose: 'printer-profiles' });

  const key = createPublicKey({ key: Buffer.from(registered.publicKey, 'base64'), format: 'der', type: 'spki' });
  for (const [index, purpose] of [[1, 'self'], [3, 'peripherals'], [5, 'printer-profiles']]) {
    const headers = server.calls[index].headers;
    assert.equal(headers['X-Shipard-Device'], info.deviceId);
    assert.equal(headers['X-Shipard-Challenge'], `challenge-${(index + 1) / 2}`);
    // Nepovinná hlavička verze aplikace (§7.1) — shodná s `info.app.version`.
    assert.equal(headers['X-Shipard-App-Version'], info.app.version);
    const message = Buffer.from(`shipard-device-v1|${purpose}|${info.deviceId}|${headers['X-Shipard-Challenge']}`, 'utf8');
    assert.equal(verify('sha256', message, { key, dsaEncoding: 'der' }, Buffer.from(headers['X-Shipard-Signature'], 'base64')), true, purpose);
  }
  assert.deepEqual(validatePeripheralList(server.calls[3].body), []);
  assert.equal(server.calls[3].body.peripherals.length, 3);

  // režim je uložený pro tento zdroj dat
  assert.deepEqual(JSON.parse(storage.getItem(MOCK_STATE_KEY)).modes, { [API_A]: 'shared' });
});

test('device.sync: chyby sítě a serveru', async () => {
  await rejectsWith(setup().call('device.sync'), 'NETWORK_ERROR');

  // server bez endpointů zařízení (do tasku 3)
  const missing = setup({ fetch: async () => json(404, { success: false, error: { code: 'NOT_FOUND', message: 'Not found' } }) });
  await assert.rejects(missing.call('device.sync'), (error) => {
    assert.equal(error.code, 'SERVER_ERROR');
    assert.deepEqual(error.details, { status: 404, code: 'NOT_FOUND' });
    assert.deepEqual(validateError(error), []);
    return true;
  });

  // odpověď, která není JSON
  const html = setup({ fetch: async () => ({ ok: false, status: 502, json: async () => { throw new SyntaxError('x'); } }) });
  await assert.rejects(html.call('device.sync'), (error) => error.code === 'SERVER_ERROR' && error.details.status === 502 && error.details.code === null);

  const unknown = fakeServer({ errorFor: { 'POST /_devices/challenge': { status: 401, code: 'DEVICE_UNKNOWN' } } });
  await rejectsWith(setup({ fetch: unknown.fetch }).call('device.sync'), 'NOT_REGISTERED');
});

test('identita je pro každý zdroj dat zvlášť, DEVICE_REVOKED smaže jen jednu (D33)', async () => {
  const storage = memoryStorage();
  const server = fakeServer({ mode: 'personal' });
  const a = setup({ apiBase: API_A, storage, fetch: server.fetch });
  const b = setup({ apiBase: API_B, storage, fetch: server.fetch });

  const [infoA, infoB] = [await a.call('info'), await b.call('info')];
  assert.notEqual(infoA.deviceId, infoB.deviceId, 'dvě adresy API → dvě identity');
  assert.equal(infoA.dataSource, API_A);
  assert.equal(infoB.dataSource, API_B);

  assert.equal((await a.call('device.sync')).mode, 'personal');
  assert.equal((await b.call('info')).mode, 'unregistered', 'registrace v jednom zdroji dat druhý nemění');
  assert.equal((await b.call('device.sync')).mode, 'personal');
  assert.ok(storage.keys().includes(deviceStorageKey(API_A)) && storage.keys().includes(deviceStorageKey(API_B)));

  // server zařízení ve zdroji A zneplatní
  const revoking = fakeServer({ errorFor: { 'GET /_devices/self': { status: 403, code: 'DEVICE_REVOKED' } } });
  const a2 = setup({ apiBase: API_A, storage, fetch: revoking.fetch });
  await rejectsWith(a2.call('device.sync'), 'DEVICE_REVOKED');

  assert.ok(!storage.keys().includes(deviceStorageKey(API_A)), 'identita zdroje A je pryč');
  assert.ok(storage.keys().includes(deviceStorageKey(API_B)), 'identita zdroje B zůstává');
  const afterA = await a2.call('info');
  assert.equal(afterA.mode, 'unregistered');
  assert.notEqual(afterA.deviceId, infoA.deviceId, 'po zneplatnění nové ID');
  const afterB = await setup({ apiBase: API_B, storage, fetch: server.fetch }).call('info');
  assert.equal(afterB.deviceId, infoB.deviceId);
  assert.equal(afterB.mode, 'personal');
});

test('události z ovládání: shipard:scan a shipard:peripheral', async () => {
  const server = fakeServer({ mode: 'personal' });
  const { controls, events, call } = setup({ fetch: server.fetch });

  assert.equal(controls.emitScan({ kind: 'barcode', data: '8594000123456', symbology: 'ean13' }), true);
  assert.equal(controls.emitScan({ kind: 'rfid', data: 'E2000017221101441890' }), true);
  assert.equal(controls.emitScan({ kind: 'barcode', data: '' }), false);
  assert.equal(controls.emitScan({ kind: 'nfc', data: 'x' }), false);
  assert.deepEqual(events, [
    { name: 'scan', detail: { peripheralId: 'mock-scanner', kind: 'barcode', data: '8594000123456', symbology: 'ean13', at: isoWithZone(new Date(2026, 10, 27, 10, 15, 42)) } },
    { name: 'scan', detail: { peripheralId: 'mock-scanner', kind: 'rfid', data: 'E2000017221101441890', symbology: null, at: isoWithZone(new Date(2026, 10, 27, 10, 15, 42)) } },
  ]);
  assert.match(events[0].detail.at, /^2026-11-27T10:15:42[+-]\d{2}:\d{2}$/);

  // přepnutí stavu: událost, neregistrované zařízení server nevolá
  events.length = 0;
  assert.equal(controls.setPeripheralStatus('mock-scanner', 'offline'), true);
  assert.equal(controls.setPeripheralStatus('mock-scanner', 'offline'), false, 'stejný stav nic nedělá');
  assert.equal(controls.setPeripheralStatus('mock-scanner', 'burning'), false);
  assert.deepEqual(events, [{ name: 'peripheral', detail: { peripheralId: 'mock-scanner', status: 'offline' } }]);
  assert.equal(server.calls.length, 0);
  assert.equal(controls.emitScan({ kind: 'barcode', data: 'x' }), false, 'odpojená čtečka nic neposílá');

  // registrované zařízení po změně periferií samo synchronizuje
  await call('device.sync');
  const callsBefore = server.calls.length;
  controls.setPeripheralStatus('mock-scanner', 'ready');
  await new Promise((resolve) => setTimeout(resolve, 20));
  const synced = server.calls.slice(callsBefore).map((c) => `${c.method} ${c.path}`);
  assert.deepEqual(synced, [
    'POST /_devices/challenge', 'GET /_devices/self', 'POST /_devices/challenge', 'PUT /_devices/self/peripherals',
    'POST /_devices/challenge', 'GET /_devices/printer-profiles',
  ]);
  assert.equal(server.calls.findLast((c) => c.method === 'PUT').body.peripherals[1].status, 'ready');
});

test('stav periferií a capabilities přežije novou instanci', async () => {
  const storage = memoryStorage();
  const first = setup({ storage });
  const changes = [];
  first.controls.subscribe(() => changes.push(1));
  first.controls.setPeripheralStatus('mock-receipt', 'error');
  first.controls.setCapability('scanner', false);
  first.controls.setCapability('print.pdf', true);
  assert.ok(changes.length >= 3, 'změny se hlásí odběratelům');

  const second = setup({ storage });
  assert.equal((await second.call('peripherals.list')).peripherals[0].status, 'error');
  const info = await second.call('info');
  assert.deepEqual(info.capabilities, ['device', 'peripherals', 'print.escpos', 'print.pdf']);
  assert.deepEqual(validateInfo(info), []);
  assert.equal(second.controls.emitScan({ kind: 'barcode', data: 'x' }), false, 'bez capability scanner žádná událost');

  // bez capability peripherals metoda NOT_SUPPORTED
  second.controls.setCapability('peripherals', false);
  await rejectsWith(second.call('peripherals.list'), 'NOT_SUPPORTED');

  // poškozený stav → výchozí
  storage.setItem(MOCK_STATE_KEY, '{nope');
  assert.deepEqual((await setup({ storage }).call('info')).capabilities, ['device', 'peripherals', 'print.escpos', 'scanner']);
});

test('bez WebCrypto simulace nenabízí capability device', async () => {
  const insecure = { getRandomValues: (array) => webcrypto.getRandomValues(array) };
  const { call, controls } = setup({ crypto: insecure });
  const info = await call('info');
  assert.deepEqual(validateInfo(info), []);
  assert.deepEqual(info.capabilities, ['peripherals', 'print.escpos', 'scanner']);
  assert.equal(controls.canSign(), false);
  await rejectsWith(call('device.register', { challenge: 'x' }), 'NOT_SUPPORTED');
  await rejectsWith(call('device.sync'), 'NOT_SUPPORTED');
  assert.deepEqual(await call('print.submit', job()), { status: 'sent' });
});

test('install vloží simulaci jako shipardNative a zpřístupní ovládání', async () => {
  assert.equal(mockControls(), null);
  const target = new EventTarget();
  const mock = install({ apiBase: API_A, storage: memoryStorage(), fetch: offline, crypto: webcrypto, target });
  assert.equal(target.shipardNative, mock.bridge);
  assert.equal(target.shipardNative.__mock, true);
  assert.equal(mockControls(), mock.controls);
  assert.equal(mockControls().snapshot().dataSource, API_A);
});

test('profily simulace sedí na katalog profilů serveru', async () => {
  const { readFileSync } = await import('node:fs');
  const { MOCK_FALLBACK_PROFILE, MOCK_PRINTER_PROFILES } = await import('../../../src/native/mock/state.js');
  const source = readFileSync(
    new URL('../../../../modules/core/prints/config/printerProfiles.jsonc', import.meta.url),
    'utf8',
  );
  const profileOf = (id) => {
    const block = source.slice(source.indexOf(`"${id}": {`));
    const number = (key) => Number(block.match(new RegExp(`"${key}": (\\d+),`))[1]);
    return { columns: number('columns'), columnsSmall: number('columnsSmall'), codePage: block.match(/"codePage": "([^"]+)"/)[1] };
  };

  for (const [id, profile] of Object.entries(MOCK_PRINTER_PROFILES)) {
    assert.deepEqual(profile, profileOf(id), id);
  }
  // Záložní profil = záložní profil serveru (PrinterProfiles::FALLBACK_ID).
  assert.deepEqual(MOCK_FALLBACK_PROFILE, profileOf('escpos-58-cp852'));
});
