// Kontroly kontraktu ze stránky Most (#177 D29): proti simulaci projdou,
// proti rozbitému mostu řeknou, co nesedí.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { UNKNOWN_METHOD_PROBE, runContractChecks } from '../../../src/dev/bridge/checks.js';
import { createMockBridge } from '../../../src/native/mock/install.js';

function memoryStorage() {
  const map = new Map();
  return {
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => map.set(key, String(value)),
    removeItem: (key) => map.delete(key),
  };
}

function mock() {
  return createMockBridge({
    apiBase: 'http://10.0.0.5/aaaa-aaaa-aaaa-aaaa/api/v1',
    storage: memoryStorage(),
    fetch: async () => {
      throw new TypeError('offline');
    },
    crypto: webcrypto,
    target: new EventTarget(),
  });
}

const statuses = (rows) => Object.fromEntries(rows.map((row) => [row.id, row.status]));

test('simulace projde kontrolami kontraktu a nic nevytiskne', async () => {
  const { bridge, controls } = mock();
  const rows = await runContractChecks(bridge.call, { outsideDataSource: false });
  assert.deepEqual(statuses(rows), { info: 'ok', unknownMethod: 'ok', printInvalidParams: 'ok', peripheralsList: 'ok' });
  assert.deepEqual(rows.find((r) => r.id === 'unknownMethod').detail, ['UNKNOWN_METHOD']);
  assert.equal(controls.jobs().length, 0);
});

test('mimo zdroj dat se čeká dataSource null (D33)', async () => {
  const { bridge } = mock();
  const inDataSource = (await runContractChecks(bridge.call)).find((r) => r.id === 'infoOutsideDataSource');
  assert.equal(inDataSource.status, 'failed');
  assert.equal(inDataSource.detail.length, 2, 'simulace běží ve zdroji dat: má adresu API i ID zařízení');
  assert.match(inDataSource.detail[0], /^dataSource: expected null/);
  assert.match(inDataSource.detail[1], /^deviceId: expected null/);

  const dashboardInfo = {
    apiVersion: 1,
    app: { version: '1.0.0', build: '17' },
    platform: 'macos',
    osVersion: '15.1',
    dataSource: null,
    deviceId: null,
    keyStorage: 'secureEnclave',
    mode: 'unregistered',
    profile: null,
    capabilities: [],
  };
  const rows = await runContractChecks(async (method) => {
    if (method === 'info') return dashboardInfo;
    throw { code: 'UNKNOWN_METHOD', message: 'Unknown method' };
  });
  assert.deepEqual(statuses(rows), {
    info: 'ok',
    infoOutsideDataSource: 'ok',
    unknownMethod: 'ok',
    printInvalidParams: 'skipped',
    peripheralsList: 'skipped',
  });
});

test('rozbitý most: nálezy říkají, co nesedí', async () => {
  const calls = [];
  const rows = await runContractChecks(async (method, params) => {
    calls.push([method, params]);
    if (method === 'info') return { apiVersion: 1, capabilities: ['print.escpos', 'peripherals'], dataSource: null, deviceId: null, mode: 'unregistered' };
    if (method === UNKNOWN_METHOD_PROBE) throw new Error('boom');
    if (method === 'print.submit') return { status: 'sent' };
    return { peripherals: [{ id: 'p-1', kind: 'printer' }] };
  });
  const byId = Object.fromEntries(rows.map((row) => [row.id, row]));

  assert.equal(byId.info.status, 'failed');
  assert.ok(byId.info.detail.some((d) => d.startsWith('platform: ')));
  assert.equal(byId.infoOutsideDataSource.status, 'ok');

  assert.equal(byId.unknownMethod.status, 'failed');
  assert.match(byId.unknownMethod.detail[0], /^expected UNKNOWN_METHOD, got /);
  assert.ok(byId.unknownMethod.detail.some((d) => d.startsWith('code: ')), 'tvar chyby podle validateError');

  assert.equal(byId.printInvalidParams.status, 'failed');
  assert.match(byId.printInvalidParams.detail[0], /the call resolved with \{"status":"sent"\}/);

  assert.equal(byId.peripheralsList.status, 'failed');
  assert.ok(byId.peripheralsList.detail.includes('peripherals.0.name: expected a non-empty string'));

  assert.deepEqual(calls.find(([m]) => m === 'print.submit'), ['print.submit', undefined], 'print.submit bez parametrů');
});

test('info selže: ostatní kontroly doběhnou', async () => {
  const rows = await runContractChecks(async (method) => {
    if (method === 'info') throw { code: 'INTERNAL_ERROR', message: 'x' };
    throw { code: 'UNKNOWN_METHOD', message: 'x' };
  });
  assert.deepEqual(statuses(rows), {
    info: 'failed',
    infoOutsideDataSource: 'skipped',
    unknownMethod: 'ok',
    printInvalidParams: 'skipped',
    peripheralsList: 'skipped',
  });
});
