// Odběr čteček (#177 U15): zásobník odběratelů nad událostí `shipard:scan`.

import { test } from 'node:test';
import assert from 'node:assert/strict';

globalThis.window = new EventTarget();

const { onScan, subscriberCount, onSubscriberCountChange } = await import('../../../src/native/scanRouter.js');

function scan(data) {
  window.dispatchEvent(new CustomEvent('shipard:scan', { detail: { peripheralId: 'p-1', kind: 'barcode', data, symbology: null, at: '2026-11-27T10:15:42+01:00' } }));
}

test('bez odběratele se událost zahodí', () => {
  assert.equal(subscriberCount(), 0);
  assert.doesNotThrow(() => scan('8594000123456'));
});

test('událost dostane poslední přihlášený, po odhlášení předchozí', () => {
  const first = [];
  const second = [];
  const offFirst = onScan((s) => first.push(s.data));
  scan('a');
  const offSecond = onScan((s) => second.push(s.data));
  assert.equal(subscriberCount(), 2);
  scan('b');
  offSecond();
  scan('c');
  offFirst();
  scan('d');

  assert.deepEqual(first, ['a', 'c']);
  assert.deepEqual(second, ['b']);
  assert.equal(subscriberCount(), 0);
});

test('odhlášení ze středu zásobníku nechá vrchol na místě', () => {
  const seen = [];
  const offA = onScan(() => seen.push('a'));
  const offB = onScan(() => seen.push('b'));
  const offC = onScan(() => seen.push('c'));
  offB();
  scan('x');
  offC();
  scan('y');
  offA();
  assert.deepEqual(seen, ['c', 'a']);
});

test('opakované odhlášení nic neudělá a neodhlásí cizího odběratele', () => {
  const seen = [];
  const offA = onScan(() => seen.push('a'));
  offA();
  const offB = onScan(() => seen.push('b'));
  offA();
  assert.equal(subscriberCount(), 1);
  scan('x');
  assert.deepEqual(seen, ['b']);
  offB();
});

test('změny počtu odběratelů se hlásí', () => {
  const counts = [];
  const stop = onSubscriberCountChange((n) => counts.push(n));
  const off = onScan(() => {});
  off();
  stop();
  onScan(() => {})();
  assert.deepEqual(counts, [1, 0]);
});
