import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pendingPaths } from '../../src/components/exchange/reviewGate.js';

test('pendingPaths: bez bloku _resolve nic nečeká', () => {
  assert.deepEqual(pendingPaths(null, {}), []);
  assert.deepEqual(pendingPaths(undefined, {}), []);
  assert.deepEqual(pendingPaths({}, {}), []);
});

test('pendingPaths: napárované strany a položky nečekají', () => {
  const resolve = {
    supplier: { status: 'matched', id: 5 },
    rows: [{ item: { status: 'matched', id: 7 } }],
  };
  assert.deepEqual(pendingPaths(resolve, {}), []);
});

test('pendingPaths: nenapárovaná strana i bez odznaku (bankovní účet) čeká', () => {
  const resolve = {
    supplier: { status: 'canCreate' },
    supplierBank: { status: 'canCreate' },
    customer: { status: 'matched', id: 1 },
  };
  assert.deepEqual(pendingPaths(resolve, {}), ['supplier', 'supplierBank']);
  assert.deepEqual(pendingPaths(resolve, { supplier: 'useExisting:9' }), ['supplierBank']);
  assert.deepEqual(pendingPaths(resolve, { supplier: 'useExisting:9', supplierBank: 'create' }), []);
});

test('pendingPaths: položky řádků v pořadí, rozhodnutí null se nepočítá', () => {
  const resolve = {
    rows: [
      { item: { status: 'notFound' } },
      { item: { status: 'matched', id: 2 } },
      { item: { status: 'ambiguous', candidates: [] } },
      { unit: { status: 'canCreate' } },
    ],
  };
  assert.deepEqual(pendingPaths(resolve, {}), ['rows[0].item', 'rows[2].item']);
  assert.deepEqual(pendingPaths(resolve, { 'rows[0].item': 'skip', 'rows[2].item': null }), ['rows[2].item']);
  assert.deepEqual(pendingPaths(resolve, { 'rows[0].item': 'noItem', 'rows[2].item': 'useExisting:3' }), []);
});

test('pendingPaths: kód DPH notFound chce useCode z nabídky', () => {
  const resolve = {
    vatCodeOptions: [{ code: 'IN21' }, { code: 'IN12' }],
    rows: [
      { item: { status: 'matched', id: 1 }, vatCode: { status: 'notFound' } },
      { item: { status: 'matched', id: 1 }, vatCode: { status: 'matched' } },
    ],
  };
  assert.deepEqual(pendingPaths(resolve, {}), ['rows[0].vatCode']);
  assert.deepEqual(pendingPaths(resolve, { 'rows[0].vatCode': 'useCode:XX' }), ['rows[0].vatCode']);
  assert.deepEqual(pendingPaths(resolve, { 'rows[0].vatCode': 'useCode:IN21' }), []);
});

test('pendingPaths: strany před položkami, položky před kódy DPH', () => {
  const resolve = {
    customer: { status: 'notFound' },
    vatCodeOptions: [],
    rows: [{ item: { status: 'canCreate' }, vatCode: { status: 'notFound' } }],
  };
  assert.deepEqual(pendingPaths(resolve, {}), ['customer', 'rows[0].item', 'rows[0].vatCode']);
});
