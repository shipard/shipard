import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  PANEL, panelState, registrationFormError, registerBody, registerDevice,
  bridgeErrorKey, BRIDGE_ERROR_CODES, deviceRecordPath, peripheralsPath, peripheralRows, labelKey,
} from '../../../src/components/devices/thisDeviceState.js';
import { ERROR_CODES } from '../../../src/native/contract.js';

const API = 'http://127.0.0.1/test-test-test-test/api/v1';
const DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';

function info(overrides = {}) {
  return {
    apiVersion: 1,
    app: { version: '1.0.0', build: '17' },
    platform: 'ipados',
    osVersion: '18.2',
    dataSource: API,
    deviceId: DEVICE,
    keyStorage: 'secureEnclave',
    mode: 'unregistered',
    profile: null,
    capabilities: ['device', 'peripherals'],
    ...overrides,
  };
}

const SIGNED = { deviceId: DEVICE, algorithm: 'ES256', publicKey: 'MFkw', signature: 'MEUC' };

test('panel: bez mostu vysvětlení, s mostem bez info načítání nebo chyba', () => {
  assert.equal(panelState({ available: false, info: null, error: null, apiBase: API, isAdmin: true }), PANEL.NO_BRIDGE);
  assert.equal(panelState({ available: true, info: null, error: null, apiBase: API, isAdmin: true }), PANEL.LOADING);
  assert.equal(panelState({ available: true, info: null, error: new Error('x'), apiBase: API, isAdmin: true }), PANEL.BRIDGE_ERROR);
});

test('panel: most jiného zdroje dat registraci nenabídne (D33)', () => {
  const other = info({ dataSource: 'http://127.0.0.1/jiny-jiny-jiny-jiny/api/v1' });
  assert.equal(panelState({ available: true, info: other, error: null, apiBase: API, isAdmin: true }), PANEL.OTHER_DATA_SOURCE);
  // Stránka mimo zdroj dat hlásí dataSource null.
  assert.equal(panelState({ available: true, info: info({ dataSource: null }), error: null, apiBase: API, isAdmin: true }), PANEL.OTHER_DATA_SOURCE);
  // I registrované zařízení jiného zdroje dat je cizí.
  assert.equal(
    panelState({ available: true, info: { ...other, mode: 'shared' }, error: null, apiBase: API, isAdmin: true }),
    PANEL.OTHER_DATA_SOURCE,
  );
});

test('panel: neregistrované zařízení — administrátor registruje, ostatní ne', () => {
  assert.equal(panelState({ available: true, info: info(), error: null, apiBase: API, isAdmin: true }), PANEL.REGISTER);
  assert.equal(panelState({ available: true, info: info(), error: null, apiBase: API, isAdmin: false }), PANEL.NEEDS_ADMIN);
});

test('panel: bez capability device nejde podepisovat (simulace mimo zabezpečený kontext)', () => {
  const noDevice = info({ capabilities: ['peripherals'] });
  assert.equal(panelState({ available: true, info: noDevice, error: null, apiBase: API, isAdmin: true }), PANEL.CANNOT_SIGN);
  assert.equal(panelState({ available: true, info: info({ capabilities: undefined }), error: null, apiBase: API, isAdmin: false }), PANEL.CANNOT_SIGN);
});

test('panel: registrované zařízení v obou režimech, i pro ne-administrátora', () => {
  for (const mode of ['personal', 'shared']) {
    assert.equal(panelState({ available: true, info: info({ mode }), error: null, apiBase: API, isAdmin: false }), PANEL.REGISTERED);
  }
  // Registrované zařízení capability `device` k zobrazení nepotřebuje.
  assert.equal(
    panelState({ available: true, info: info({ mode: 'shared', capabilities: [] }), error: null, apiBase: API, isAdmin: true }),
    PANEL.REGISTERED,
  );
});

test('formulář registrace: název povinný a omezený, režim z kontraktu', () => {
  assert.deepEqual(registrationFormError({ name: '   ', mode: 'shared' }), { field: 'name', reason: 'required' });
  assert.deepEqual(registrationFormError({ name: null, mode: 'shared' }), { field: 'name', reason: 'required' });
  assert.deepEqual(registrationFormError({ name: 'a'.repeat(101), mode: 'shared' }), { field: 'name', reason: 'tooLong' });
  assert.deepEqual(registrationFormError({ name: 'Pokladna', mode: 'unregistered' }), { field: 'mode', reason: 'mode' });
  assert.equal(registrationFormError({ name: 'ř'.repeat(100), mode: 'personal' }), null);
});

test('tělo registrace podle kontraktu; nepovinná pole jen když je most hlásí', () => {
  assert.deepEqual(registerBody({ info: info(), signed: SIGNED, challenge: 'vyzva', name: '  Pokladna 2 ', mode: 'shared' }), {
    deviceId: DEVICE,
    algorithm: 'ES256',
    publicKey: 'MFkw',
    challenge: 'vyzva',
    signature: 'MEUC',
    name: 'Pokladna 2',
    mode: 'shared',
    platform: 'ipados',
    appVersion: '1.0.0',
    keyStorage: 'secureEnclave',
  });

  const bare = registerBody({ info: info({ app: undefined, keyStorage: undefined }), signed: SIGNED, challenge: 'v', name: 'X', mode: 'personal' });
  assert.equal('appVersion' in bare, false);
  assert.equal('keyStorage' in bare, false);
});

function recorder(responses) {
  const calls = [];
  const post = async (path, body) => {
    calls.push(['post', path, body]);
    return responses.post[path];
  };
  const nativeCall = async (method, params) => {
    calls.push(['native', method, params]);
    const result = responses.native[method];
    if (result instanceof Error) throw result;
    return result;
  };
  return { calls, post, nativeCall };
}

function bridgeError(code, message = code) {
  return Object.assign(new Error(message), { code });
}

test('registrace: výzva → podpis → registrace → synchronizace', async () => {
  const { calls, post, nativeCall } = recorder({
    post: {
      '/_devices/challenge': { success: true, data: { challenge: 'vyzva', expiresIn: 60 } },
      '/_devices/register': { success: true, data: { deviceId: DEVICE, name: 'Pokladna 2', mode: 'shared', state: 'active' } },
    },
    native: { 'device.register': SIGNED, 'device.sync': info({ mode: 'shared' }) },
  });

  const result = await registerDevice({ info: info(), name: 'Pokladna 2', mode: 'shared', post, nativeCall });

  assert.deepEqual(result, { ok: true, device: { deviceId: DEVICE, name: 'Pokladna 2', mode: 'shared', state: 'active' }, syncError: null });
  assert.deepEqual(calls.map((call) => `${call[0]} ${call[1]}`), [
    'post /_devices/challenge', 'native device.register', 'post /_devices/register', 'native device.sync',
  ]);
  assert.deepEqual(calls[0][2], { deviceId: DEVICE, purpose: 'register' });
  assert.deepEqual(calls[1][2], { challenge: 'vyzva' });
  assert.equal(calls[2][2].challenge, 'vyzva');
  assert.equal(calls[2][2].signature, 'MEUC');
});

test('registrace: chyba výzvy končí hned — most se nevolá', async () => {
  const { calls, post, nativeCall } = recorder({
    post: { '/_devices/challenge': { success: false, error: { code: 'FORBIDDEN', message: 'Admin access required' } } },
    native: {},
  });

  const result = await registerDevice({ info: info(), name: 'X', mode: 'shared', post, nativeCall });

  assert.deepEqual(result, { ok: false, step: 'challenge', source: 'server', error: { code: 'FORBIDDEN', message: 'Admin access required' } });
  assert.equal(calls.length, 1);
});

test('registrace: vypršená session (klient vrací null) je UNAUTHORIZED', async () => {
  const { post, nativeCall } = recorder({ post: { '/_devices/challenge': null }, native: {} });

  const result = await registerDevice({ info: info(), name: 'X', mode: 'shared', post, nativeCall });

  assert.equal(result.ok, false);
  assert.equal(result.error.code, 'UNAUTHORIZED');
});

test('registrace: odpověď bez výzvy je chyba kroku výzva', async () => {
  const { post, nativeCall } = recorder({ post: { '/_devices/challenge': { success: true, data: {} } }, native: {} });

  const result = await registerDevice({ info: info(), name: 'X', mode: 'shared', post, nativeCall });

  assert.equal(result.ok, false);
  assert.equal(result.step, 'challenge');
});

test('registrace: chyba podpisu na mostu — server se podruhé nevolá', async () => {
  const { calls, post, nativeCall } = recorder({
    post: { '/_devices/challenge': { success: true, data: { challenge: 'vyzva' } } },
    native: { 'device.register': bridgeError('NOT_SUPPORTED', 'no key') },
  });

  const result = await registerDevice({ info: info(), name: 'X', mode: 'shared', post, nativeCall });

  assert.deepEqual(result, { ok: false, step: 'sign', source: 'bridge', error: { code: 'NOT_SUPPORTED', message: 'no key' } });
  assert.equal(calls.length, 2);
});

test('registrace: chyba serveru při registraci — synchronizace neproběhne', async () => {
  const { calls, post, nativeCall } = recorder({
    post: {
      '/_devices/challenge': { success: true, data: { challenge: 'vyzva' } },
      '/_devices/register': { success: false, error: { code: 'DEVICE_EXISTS', message: 'Device is already registered' } },
    },
    native: { 'device.register': SIGNED },
  });

  const result = await registerDevice({ info: info(), name: 'X', mode: 'shared', post, nativeCall });

  assert.equal(result.ok, false);
  assert.equal(result.step, 'register');
  assert.equal(result.error.code, 'DEVICE_EXISTS');
  assert.equal(calls.length, 3);
});

test('registrace: chyba synchronizace registraci neruší', async () => {
  const { post, nativeCall } = recorder({
    post: {
      '/_devices/challenge': { success: true, data: { challenge: 'vyzva' } },
      '/_devices/register': { success: true, data: { deviceId: DEVICE, name: 'X', mode: 'shared', state: 'active' } },
    },
    native: { 'device.register': SIGNED, 'device.sync': bridgeError('NETWORK_ERROR', 'offline') },
  });

  const result = await registerDevice({ info: info(), name: 'X', mode: 'shared', post, nativeCall });

  assert.equal(result.ok, true);
  assert.deepEqual(result.syncError, { code: 'NETWORK_ERROR', message: 'offline' });
});

test('chyby mostu mají vlastní slovník a neznámý kód obecný text', () => {
  assert.equal(bridgeErrorKey('DEVICE_REVOKED'), 'devices.bridgeError.DEVICE_REVOKED');
  assert.equal(bridgeErrorKey('NETWORK_ERROR'), 'devices.bridgeError.NETWORK_ERROR');
  assert.equal(bridgeErrorKey('SOMETHING_NEW'), 'devices.bridgeError.unknown');
  assert.equal(bridgeErrorKey(undefined), 'devices.bridgeError.unknown');
  // Obecné kódy kontraktu (§2.3) panel zná všechny.
  for (const code of ['UNKNOWN_METHOD', 'INVALID_PARAMS', 'NOT_SUPPORTED', 'NOT_REGISTERED', 'DEVICE_REVOKED', 'NETWORK_ERROR', 'SERVER_ERROR', 'CANCELLED', 'INTERNAL_ERROR']) {
    assert.ok(ERROR_CODES.includes(code), `${code} v kontraktu`);
    assert.ok(BRIDGE_ERROR_CODES.includes(code), `${code} v panelu`);
  }
});

test('záznam zařízení a periferie se čtou generickým seznamem', () => {
  assert.equal(deviceRecordPath(DEVICE), `/core_devices_devices?filter[device_id]=eq:${DEVICE}&limit=1`);
  assert.equal(peripheralsPath(5), '/core_devices_peripherals?filter[device]=eq:5&limit=100');
});

test('periferie: připojené napřed, pak druh a název; role jen vyplněná', () => {
  const rows = peripheralRows([
    { id: 3, name: 'Stará', kind: 'printer', transport: 'tcp', status: 'ready', present: 0, role: 'receipt' },
    { id: 2, name: 'Čtečka', kind: 'scanner', transport: 'usb', status: 'ready', present: 1, role: null },
    { id: 1, name: 'Účtenky', kind: 'printer', transport: null, status: 'offline', present: '1', role: 'receipt          ' },
    null,
  ]);

  assert.deepEqual(rows.map((row) => row.id), [1, 2, 3]);
  assert.deepEqual(rows[0], { id: 1, name: 'Účtenky', kind: 'printer', transport: '', status: 'offline', present: true, role: 'receipt' });
  assert.equal(rows[1].role, null);
  assert.equal(rows[2].present, false);
  assert.deepEqual(peripheralRows(null), []);
});

test('popisky: známá hodnota má klíč, neznámá se ukáže, jak přišla', () => {
  assert.equal(labelKey('mode', 'shared'), 'devices.mode.shared');
  assert.equal(labelKey('platform', 'mock'), 'devices.platform.mock');
  assert.equal(labelKey('platform', 'ios'), 'devices.platform.ios');
  assert.equal(labelKey('kind', 'printer'), 'devices.kind.printer');
  assert.equal(labelKey('status', 'offline'), 'devices.status.offline');
  assert.equal(labelKey('role', 'receipt'), 'devices.role.receipt');
  assert.equal(labelKey('kind', 'display'), null);
  assert.equal(labelKey('platform', undefined), null);
  assert.equal(labelKey('transport', 'tcp'), null);
});
