import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeSpans, spansForDevice } from '../../src/components/viewer/viewerSpans.js';

const DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';
const OTHER = '5d0e7c34-8b1a-4c59-a2f7-3e9b6d4c1f02';

const SPANS = [
  { text: 'Sdílené pracoviště', class: 'primary' },
  { text: 'toto zařízení', badge: 'primary', nativeDevice: DEVICE },
];

test('normalizeSpans: řetězec, objekt, pole, nic', () => {
  assert.deepEqual(normalizeSpans('text'), [{ text: 'text' }]);
  assert.deepEqual(normalizeSpans({ text: 'a', class: 'muted' }), [{ text: 'a', class: 'muted' }]);
  assert.equal(normalizeSpans(SPANS), SPANS);
  assert.equal(normalizeSpans(null), null);
  assert.equal(normalizeSpans({}), null);
});

test('spansForDevice: span se značkou jen na zařízení, které hlásí most', () => {
  assert.deepEqual(spansForDevice(SPANS, DEVICE), SPANS);
  assert.deepEqual(spansForDevice(SPANS, OTHER), [SPANS[0]]);
});

test('spansForDevice: bez mostu se značený span neukáže nikomu', () => {
  for (const deviceId of [null, undefined, '']) {
    assert.deepEqual(spansForDevice(SPANS, deviceId), [SPANS[0]], String(deviceId));
  }
  // Prázdná značka na řádku není „každé zařízení“.
  assert.deepEqual(spansForDevice([{ text: 'x', nativeDevice: '' }], ''), []);
});

test('spansForDevice: spany bez značky a nespanové hodnoty projdou beze změny', () => {
  const plain = [{ text: 'a' }, { text: 'b', badge: 'done' }];
  assert.deepEqual(spansForDevice(plain, null), plain);
  assert.deepEqual(spansForDevice(plain, DEVICE), plain);
  assert.equal(spansForDevice(null, DEVICE), null);
});
