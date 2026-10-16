import { test } from 'node:test';
import assert from 'node:assert/strict';
import { matchRoute, buildPath, registerRoutes } from '../../../src/dev/routes.js';

test('kořen dashboardu s lomítkem i bez', () => {
  assert.equal(matchRoute('/_dev/').name, 'dataSources');
  assert.equal(matchRoute('/_dev').name, 'dataSources');
});

test('dnešní stránky s lomítkem i bez a s query', () => {
  assert.equal(matchRoute('/_dev/logs/').name, 'logs');
  assert.equal(matchRoute('/_dev/logs').name, 'logs');
  const r = matchRoute('/_dev/ds-upgrade/', '?ds=abcd-1234-efgh-5678');
  assert.equal(r.name, 'dsUpgrade');
  assert.deepEqual(r.query, { ds: 'abcd-1234-efgh-5678' });
  assert.equal(matchRoute('/_dev/ds-create').name, 'dsCreate');
  assert.equal(matchRoute('/_dev/doctor/').name, 'doctor');
  assert.equal(matchRoute('/_dev/upgrade-all/').name, 'upgradeAll');
});

test('neznámá cesta a cesta mimo /_dev → notFound', () => {
  assert.equal(matchRoute('/_dev/random').name, 'notFound');
  assert.equal(matchRoute('/_devil/').name, 'notFound');
  assert.equal(matchRoute('/abcd-1234-efgh-5678/app/').name, 'notFound');
});

test('registrované routy s parametry, dekódování segmentů', () => {
  registerRoutes([
    ['testing', 'testingDatasets'],
    ['testing/:set/runs/:file', 'testingRun'],
  ]);
  const r = matchRoute('/_dev/testing/cz-vat-payer/runs/2026-10-16-baseline.json/');
  assert.equal(r.name, 'testingRun');
  assert.deepEqual(r.params, { set: 'cz-vat-payer', file: '2026-10-16-baseline.json' });
  assert.equal(matchRoute('/_dev/testing/').name, 'testingDatasets');
  assert.equal(matchRoute('/_dev/testing/a%20b/runs/x/').params.set, 'a b');
  // opakovaná registrace stejného vzoru nic nezdvojí
  registerRoutes([['testing', 'testingDatasets']]);
  assert.equal(matchRoute('/_dev/testing').name, 'testingDatasets');
});

test('buildPath skládá cestu s lomítkem, parametry a query bez prázdných hodnot', () => {
  assert.equal(buildPath('dataSources'), '/_dev/');
  assert.equal(buildPath('logs', {}, { ds: 'abcd-1234-efgh-5678' }), '/_dev/logs/?ds=abcd-1234-efgh-5678');
  assert.equal(buildPath('logs', {}, { ds: '', x: null, y: undefined }), '/_dev/logs/');
  assert.equal(
    buildPath('testingRun', { set: 'cz-vat-payer', file: '2026-10-16-baseline.json' }, { repeat: 2 }),
    '/_dev/testing/cz-vat-payer/runs/2026-10-16-baseline.json/?repeat=2',
  );
  assert.throws(() => buildPath('nope'), /Neznámá routa/);
  assert.throws(() => buildPath('testingRun', { set: 'x' }), /chybí parametr :file/);
});

test('matchRoute(buildPath()) je identita', () => {
  const path = buildPath('testingRun', { set: 'cz vat', file: 'a.json' }, { b: 'c.json' });
  const [pathname, search] = path.split('?');
  const r = matchRoute(pathname, '?' + search);
  assert.equal(r.name, 'testingRun');
  assert.deepEqual(r.params, { set: 'cz vat', file: 'a.json' });
  assert.deepEqual(r.query, { b: 'c.json' });
});
