// Validátory kontraktu mostu (docs/native-bridge.md) nad platnými
// i rozbitými tvary.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  CAPABILITIES,
  ERROR_CODES,
  MODES,
  PLATFORMS,
  validateError,
  validateInfo,
  validatePeripheral,
  validatePeripheralList,
} from '../../../src/native/contract.js';

/** Příklad z kontraktu §3.1. */
function info(overrides = {}) {
  return {
    apiVersion: 1,
    app: { version: '1.0.0', build: '17' },
    platform: 'ipados',
    osVersion: '18.2',
    dataSource: 'https://firma.example.com/api/v1',
    deviceId: '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10',
    keyStorage: 'secureEnclave',
    mode: 'shared',
    profile: { id: '5d0e7c34-8b1a-4c59-a2f7-3e9b6d4c1f02', userId: 12 },
    capabilities: ['device', 'peripherals', 'print.escpos', 'scanner', 'profiles'],
    ...overrides,
  };
}

const paths = (findings) => findings.map((f) => f.path);

test('seznamy kontraktu', () => {
  assert.ok(CAPABILITIES.includes('print.escpos') && CAPABILITIES.includes('auth.external'));
  assert.ok(ERROR_CODES.includes('DUPLICATE_JOB') && ERROR_CODES.includes('UNKNOWN_METHOD'));
  assert.deepEqual(MODES, ['unregistered', 'personal', 'shared']);
  assert.ok(PLATFORMS.includes('mock'), 'simulace hlásí platform mock');
  assert.ok(PLATFORMS.includes('ios'), 'iPhone hlásí platform ios (#177 D41)');
  assert.ok(!ERROR_CODES.includes('NOT_AVAILABLE'), 'NOT_AVAILABLE je kód frontendu, ne kontraktu');
});

test('platné info projde', () => {
  assert.deepEqual(validateInfo(info()), []);
  assert.deepEqual(validateInfo(info({ mode: 'personal', profile: null })), []);
  assert.deepEqual(validateInfo(info({ platform: 'ios' })), []);
  assert.deepEqual(validateInfo(info({ profile: { id: '5d0e7c34-8b1a-4c59-a2f7-3e9b6d4c1f02', userId: null } })), []);
  // stránka mimo zdroj dat (vývojářský dashboard)
  assert.deepEqual(validateInfo(info({ dataSource: null, deviceId: null, mode: 'unregistered', profile: null })), []);
  // neznámá pole a neznámá capability nejsou chyba (§2.4)
  assert.deepEqual(validateInfo(info({ future: 1, capabilities: ['device', 'nfc.write'] })), []);
});

test('rozbité info hlásí cestu každého nálezu', () => {
  assert.deepEqual(paths(validateInfo(null)), ['']);
  assert.deepEqual(paths(validateInfo(info({ apiVersion: '1' }))), ['apiVersion']);
  assert.deepEqual(paths(validateInfo(info({ app: { version: '1.0.0' } }))), ['app.build']);
  assert.deepEqual(paths(validateInfo(info({ platform: 'windows' }))), ['platform']);
  assert.deepEqual(paths(validateInfo(info({ keyStorage: 'file' }))), ['keyStorage']);
  assert.deepEqual(paths(validateInfo(info({ mode: 'kiosk' }))), ['mode']);
  assert.deepEqual(paths(validateInfo(info({ deviceId: 'abc' }))), ['deviceId']);
  assert.deepEqual(paths(validateInfo(info({ capabilities: 'device' }))), ['capabilities']);
  assert.deepEqual(paths(validateInfo(info({ capabilities: ['device', 'device'] }))), ['capabilities']);
  assert.deepEqual(paths(validateInfo(info({ capabilities: ['device', 7] }))), ['capabilities.1']);
  assert.deepEqual(paths(validateInfo(info({ profile: { id: 'x', userId: '12' } }))), ['profile.id', 'profile.userId']);

  const missing = info();
  delete missing.profile;
  delete missing.osVersion;
  assert.deepEqual(paths(validateInfo(missing)).sort(), ['osVersion', 'profile']);
});

test('info: souvislosti mezi poli', () => {
  assert.deepEqual(paths(validateInfo(info({ mode: 'personal' }))), ['profile'], 'profil jen v režimu shared');
  assert.deepEqual(
    paths(validateInfo(info({ dataSource: null, profile: null }))).sort(),
    ['deviceId', 'mode'],
    'mimo zdroj dat bez identity a unregistered',
  );
});

test('periferie: tiskárna a čtečka z kontraktu', () => {
  const printer = { id: 'p-1a2b', kind: 'printer', name: 'Účtenky – pult', transport: 'tcp', status: 'ready', profileId: 'escpos-80-cp852' };
  const scanner = { id: 'p-3c4d', kind: 'scanner', name: 'Čtečka u kasy', transport: 'ble', status: 'offline', reads: ['barcode'] };
  assert.deepEqual(validatePeripheral(printer), []);
  assert.deepEqual(validatePeripheral(scanner), []);
  assert.deepEqual(validatePeripheral({ ...scanner, reads: ['barcode', 'rfid'] }), []);
  assert.deepEqual(validatePeripheral({ ...printer, transport: 'nfc' }), [], 'neznámý transport není chyba (§2.4)');

  assert.deepEqual(paths(validatePeripheral({ ...printer, profileId: undefined })), ['profileId']);
  assert.deepEqual(paths(validatePeripheral({ ...printer, status: 'busy', kind: 'display' })), ['kind', 'status']);
  assert.deepEqual(paths(validatePeripheral({ ...scanner, reads: [] })), ['reads']);
  assert.deepEqual(paths(validatePeripheral({ ...scanner, reads: ['nfc'] })), ['reads.0']);
  assert.deepEqual(paths(validatePeripheral('p-1')), ['']);
});

test('seznam periferií: cesty s indexem a jedinečné id', () => {
  const printer = { id: 'p-1', kind: 'printer', name: 'A', transport: 'tcp', status: 'ready', profileId: 'x' };
  assert.deepEqual(validatePeripheralList({ peripherals: [] }), []);
  assert.deepEqual(validatePeripheralList({ peripherals: [printer] }), []);
  assert.deepEqual(paths(validatePeripheralList({})), ['peripherals']);
  assert.deepEqual(paths(validatePeripheralList({ peripherals: [printer, { ...printer, name: '' }] })), ['peripherals.1.name', 'peripherals.1.id']);
  assert.deepEqual(paths(validatePeripheralList({ peripherals: [null] })), ['peripherals.0']);
});

test('chyba mostu', () => {
  assert.deepEqual(validateError({ code: 'UNKNOWN_METHOD', message: 'Unknown method' }), []);
  assert.deepEqual(validateError({ code: 'PIN_INVALID', message: 'x', details: { attemptsLeft: 1 } }), []);
  assert.deepEqual(validateError({ code: 'CANCELLED', message: '', details: null }), []);

  assert.deepEqual(paths(validateError(new Error('boom'))), ['code']);
  assert.deepEqual(paths(validateError({ code: 'not_found', message: 'x' })), ['code']);
  assert.deepEqual(paths(validateError({ code: 'MADE_UP', message: 'x' })), ['code']);
  assert.deepEqual(paths(validateError({ code: 'TIMEOUT' })), ['message']);
  assert.deepEqual(paths(validateError({ code: 'TIMEOUT', message: 'x', details: 'paperOut' })), ['details']);
  assert.deepEqual(paths(validateError('TIMEOUT')), ['']);
});
