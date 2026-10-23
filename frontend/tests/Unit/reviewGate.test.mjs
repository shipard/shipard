import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  REFERENCE_TABLES, bankParentPath, hasControl, isPending, pendingPaths, reviewReferences,
} from '../../src/components/exchange/reviewGate.js';

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

test('pendingPaths: nenapárovaná strana i její bankovní účet čekají', () => {
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

test('reviewReferences: druhy, rodič účtu a pořadí brány; i napárované', () => {
  const resolve = {
    supplier: { status: 'matched', matchedId: 5 },
    supplierBank: { status: 'canCreate' },
    customer: { status: 'notFound' },
    customerBank: { status: 'matched', matchedId: 9 },
    rows: [
      { item: { status: 'matched', matchedId: 1 }, vatCode: { status: 'notFound' } },
      { unit: { status: 'canCreate' } },
      { item: { status: 'ambiguous', candidates: [] } },
    ],
  };
  assert.deepEqual(
    reviewReferences(resolve).map((r) => [r.path, r.kind, r.parentPath ?? null]),
    [
      ['supplier', 'party', null],
      ['customer', 'party', null],
      ['supplierBank', 'bankAccount', 'supplier'],
      ['customerBank', 'bankAccount', 'customer'],
      ['rows[0].item', 'item', null],
      ['rows[2].item', 'item', null],
      ['rows[0].vatCode', 'vatCode', null],
    ],
  );
  assert.deepEqual(reviewReferences(null), []);
  assert.equal(bankParentPath('supplierBank'), 'supplier');
  assert.equal(bankParentPath('customerBank'), 'customer');
  assert.equal(bankParentPath('supplier'), null);
  assert.equal(bankParentPath('supplier.bankAccount'), null);
});

test('hasControl: read-only nic; napárovaná strana a účet ne; položka a kód DPH i napárované', () => {
  const matched = { status: 'matched', matchedId: 1 };
  const open = { status: 'canCreate' };
  for (const kind of ['party', 'bankAccount', 'item', 'vatCode']) {
    assert.equal(hasControl({ kind, block: open }, { interactive: false }), false, `${kind} read-only`);
    assert.equal(hasControl({ kind, block: open }, { interactive: true }), true, `${kind} otevřená`);
  }
  assert.equal(hasControl({ kind: 'party', block: matched }, { interactive: true }), false);
  assert.equal(hasControl({ kind: 'bankAccount', block: matched }, { interactive: true }), false);
  assert.equal(hasControl({ kind: 'item', block: matched }, { interactive: true }), true);
  assert.equal(hasControl({ kind: 'vatCode', block: matched }, { interactive: true }), true);
  assert.equal(hasControl({ kind: 'unit', block: open }, { interactive: true }), false, 'neznámý druh ovládání nemá');
  assert.equal(hasControl({ kind: 'party', block: null }, { interactive: true }), false);
});

// Pravidlo D98 (#103): každá cesta, kterou brána vrací jako blokující, má
// v náhledu ovládání. Nová blokující cesta bez větve v hasControl nebo bez
// tabulky entity tu padne — i když má náhled jinak všechno správně.
test('pravidlo D98: každá blokující cesta má ovládání', () => {
  const statuses = ['matched', 'ambiguous', 'notFound', 'canCreate'];
  const block = (status) => (status === 'matched' ? { status, matchedId: 1 } : { status });
  for (const vatCodeOptions of [[], [{ code: 'IN21' }]]) {
    for (const status of statuses) {
      const resolve = {
        vatCodeOptions,
        supplier: block(status),
        customer: block(status),
        supplierBank: block(status),
        customerBank: block(status),
        rows: statuses.map((s) => ({ item: block(s), vatCode: block(s), unit: block(s), account: block(s) })),
      };
      const refs = reviewReferences(resolve);
      for (const ref of refs) {
        assert.ok(ref.kind === 'vatCode' || REFERENCE_TABLES[ref.kind], `druh ${ref.kind} má tabulku entity`);
      }
      const controlled = new Set(refs.filter((r) => hasControl(r, { interactive: true })).map((r) => r.path));
      for (const userActions of [{}, { supplier: 'useExisting:9', 'rows[1].item': 'skip', 'rows[2].vatCode': 'useCode:IN21' }]) {
        for (const path of pendingPaths(resolve, userActions)) {
          assert.ok(controlled.has(path), `blokující cesta ${path} (stav ${status}, nabídka ${vatCodeOptions.length}) nemá ovládání`);
        }
        // Čekající reference je vždy mezi referencemi (žádná cesta mimo výčet).
        for (const path of pendingPaths(resolve, userActions)) {
          assert.ok(refs.some((r) => r.path === path && isPending(r, userActions, resolve)));
        }
      }
    }
  }
});
