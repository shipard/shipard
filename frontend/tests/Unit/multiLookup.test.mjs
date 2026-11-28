import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  selectedIds,
  isSelected,
  withoutSelected,
  addId,
  removeId,
  removeLast,
  chipsOf,
  resolvedFor,
  multiLookupErrorColumn,
  joinFieldError,
  sameList,
} from '../../src/utils/multiLookup.js';

const items = [
  { id: 1, primary: 'Alpha', secondary: 'a' },
  { id: 2, primary: 'Beta', secondary: null },
  { id: 3, primary: 'Gamma', secondary: null },
];

test('selectedIds: cokoli jiného než pole je prázdný seznam', () => {
  assert.deepEqual(selectedIds([3, 1]), [3, 1]);
  assert.deepEqual(selectedIds(null), []);
  assert.deepEqual(selectedIds(undefined), []);
  assert.deepEqual(selectedIds('1,2'), []);
});

test('isSelected: id se porovnává textově', () => {
  assert.equal(isSelected([3, 1], 1), true);
  assert.equal(isSelected([3, 1], '1'), true);
  assert.equal(isSelected(['3'], 3), true);
  assert.equal(isSelected([3, 1], 2), false);
  assert.equal(isSelected(null, 2), false);
});

test('withoutSelected: vybrané záznamy z nabídky zmizí, pořadí zůstává', () => {
  assert.deepEqual(withoutSelected(items, [2]).map((i) => i.id), [1, 3]);
  assert.deepEqual(withoutSelected(items, ['1', 3]).map((i) => i.id), [2]);
  assert.deepEqual(withoutSelected(items, []).map((i) => i.id), [1, 2, 3]);
  assert.deepEqual(withoutSelected(items, [1, 2, 3]), []);
  assert.deepEqual(withoutSelected(null, [1]), []);
});

test('addId: přidává na konec, duplicitu nedovolí a původní pole nemění', () => {
  const ids = [3, 1];
  const next = addId(ids, 2);
  assert.deepEqual(next, [3, 1, 2]);
  assert.deepEqual(ids, [3, 1]);
  assert.notEqual(next, ids);

  assert.equal(addId(ids, 1), ids, 'už vybrané id seznam nemění');
  assert.equal(addId(ids, '3'), ids);
  assert.deepEqual(addId(null, 5), [5]);
});

test('removeId a removeLast: nové pole bez položky', () => {
  const ids = [3, 1, 2];
  assert.deepEqual(removeId(ids, 1), [3, 2]);
  assert.deepEqual(removeId(ids, '2'), [3, 1]);
  assert.deepEqual(removeId(ids, 9), [3, 1, 2]);
  assert.deepEqual(removeLast(ids), [3, 1]);
  assert.deepEqual(removeLast([]), []);
  assert.deepEqual(removeLast(null), []);
  assert.deepEqual(ids, [3, 1, 2]);
});

test('chipsOf: štítky v pořadí hodnoty, bez popisku zástupný text', () => {
  assert.deepEqual(chipsOf([3, 1], items), [
    { id: 3, primary: 'Gamma', secondary: null },
    { id: 1, primary: 'Alpha', secondary: 'a' },
  ]);
  assert.deepEqual(chipsOf([55], items), [{ id: 55, primary: '#55', secondary: null }]);
  assert.deepEqual(chipsOf([1], null), [{ id: 1, primary: '#1', secondary: null }]);
  assert.deepEqual(chipsOf(null, items), []);
});

test('resolvedFor: popisky v pořadí výběru, nová položka přidaná, odebraná pryč', () => {
  const resolved = [items[0], items[1]];
  assert.deepEqual(
    resolvedFor([2, 3, 1], resolved, { id: 3, primary: 'Gamma' }),
    [items[1], { id: 3, primary: 'Gamma', secondary: null }, items[0]],
  );
  assert.deepEqual(resolvedFor([2], resolved), [items[1]]);
  assert.deepEqual(resolvedFor([], resolved), []);
  assert.deepEqual(resolvedFor([55, 1], resolved), [items[0]], 'id bez popisku se vynechá');
});

test('multiLookupErrorColumn: přesná shoda i cesta do řádku child tabulky', () => {
  const elMap = {
    name: { type: 'input' },
    places: { type: 'multilookup' },
    tags: { type: 'multiselect' },
  };
  assert.equal(multiLookupErrorColumn('places', elMap), 'places');
  assert.equal(multiLookupErrorColumn('places.1.place', elMap), 'places');
  assert.equal(multiLookupErrorColumn('places.0', elMap), 'places');
  assert.equal(multiLookupErrorColumn('placesExtra.1', elMap), null, 'jen celý klíč před tečkou');
  assert.equal(multiLookupErrorColumn('name', elMap), null);
  assert.equal(multiLookupErrorColumn('tags.1', elMap), null, 'jiný prvek chyby řádků nesbírá');
  assert.equal(multiLookupErrorColumn('rows.0.unit_price', elMap), null);
  assert.equal(multiLookupErrorColumn('_form', elMap), null);
  assert.equal(multiLookupErrorColumn('', elMap), null);
  assert.equal(multiLookupErrorColumn(null, elMap), null);
  assert.equal(multiLookupErrorColumn('places', null), null);
});

test('joinFieldError: víc zpráv se spojí, stejná se neopakuje', () => {
  assert.equal(joinFieldError(undefined, 'A'), 'A');
  assert.equal(joinFieldError('A', 'B'), 'A\nB');
  assert.equal(joinFieldError('A\nB', 'A'), 'A\nB');
  assert.equal(joinFieldError('A', ''), 'A');
  assert.equal(joinFieldError('A', null), 'A');
  assert.equal(joinFieldError(undefined, null), '');
});

test('sameList: seznamy podle obsahu a pořadí', () => {
  assert.equal(sameList([3, 1], [3, 1]), true);
  assert.equal(sameList([3, 1], [1, 3]), false, 'pořadí je změna');
  assert.equal(sameList([3, 1], [3, 1, 2]), false);
  assert.equal(sameList([3, 1], ['3', '1']), true, 'id jako číslo i text');
  assert.equal(sameList(['a', 'b'], ['a', 'b']), true);
  assert.equal(sameList([{ a: 1 }], [{ a: 1 }]), true);
  assert.equal(sameList([{ a: 1 }], [{ a: 2 }]), false);
});

test('sameList: prázdný seznam je totéž co nevyplněno', () => {
  assert.equal(sameList([], []), true);
  assert.equal(sameList([], null), true);
  assert.equal(sameList(undefined, []), true);
  assert.equal(sameList('', []), true);
  assert.equal(sameList([1], null), false);
  assert.equal(sameList(null, [1]), false);
});

test('sameList: bez pole nerozhoduje', () => {
  assert.equal(sameList(1, 1), null);
  assert.equal(sameList('a', 'b'), null);
  assert.equal(sameList(null, null), null);
});
