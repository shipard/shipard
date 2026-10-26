import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isEmptyValue, visibleGroups, visibleItems } from '../../src/components/ui/propertyGroups.js';

test('prázdná hodnota je jen null, undefined a prázdný řetězec', () => {
  assert.equal(isEmptyValue(null), true);
  assert.equal(isEmptyValue(undefined), true);
  assert.equal(isEmptyValue(''), true);
  assert.equal(isEmptyValue(0), false);
  assert.equal(isEmptyValue(false), false);
  assert.equal(isEmptyValue(' '), false);
});

test('visibleItems: položka s prázdnou hodnotou vypadne, 0 zůstává', () => {
  const items = visibleItems([
    { label: 'Kód', value: 'A1' },
    { label: 'Prázdné', value: '' },
    { label: 'Null', value: null },
    { label: 'Chybí' },
    { label: 'Pořadí', value: 0 },
    null,
  ]);
  assert.deepEqual(items, [
    { label: 'Kód', value: 'A1' },
    { label: 'Pořadí', value: 0 },
  ]);
});

test('visibleItems: prázdný popisek zůstává jako prázdný řetězec', () => {
  assert.deepEqual(visibleItems([{ label: '', value: 'text poznámky' }]), [{ label: '', value: 'text poznámky' }]);
  assert.deepEqual(visibleItems([{ value: 'bez popisku' }]), [{ label: '', value: 'bez popisku' }]);
  assert.deepEqual(visibleItems([{ label: 12, value: 'x' }]), [{ label: '12', value: 'x' }]);
});

test('visibleItems: nepole je prázdný seznam', () => {
  assert.deepEqual(visibleItems(null), []);
  assert.deepEqual(visibleItems(undefined), []);
  assert.deepEqual(visibleItems('x'), []);
});

test('visibleGroups: prázdná skupina vypadne, pořadí zůstává', () => {
  const groups = visibleGroups([
    { title: 'Identifikace', items: [{ label: 'Kód', value: 'A1' }] },
    { title: 'Prázdná', items: [] },
    { title: 'Jen prázdné hodnoty', items: [{ label: 'X', value: null }, { label: 'Y', value: '' }] },
    { title: 'Platnost', items: [{ label: 'Od', value: '1. 1. 2026' }] },
  ]);
  assert.deepEqual(groups.map((g) => g.title), ['Identifikace', 'Platnost']);
  assert.deepEqual(groups[0], { title: 'Identifikace', items: [{ label: 'Kód', value: 'A1' }], wide: false });
});

test('visibleGroups: wide jen při přesně true', () => {
  const groups = visibleGroups([
    { title: 'Poznámka', items: [{ label: '', value: 'dlouhý text' }], wide: true },
    { title: 'Řetězec', items: [{ label: 'A', value: 1 }], wide: 'yes' },
    { title: 'Jednička', items: [{ label: 'A', value: 1 }], wide: 1 },
    { title: 'Bez příznaku', items: [{ label: 'A', value: 1 }] },
  ]);
  assert.deepEqual(groups.map((g) => g.wide), [true, false, false, false]);
});

test('visibleGroups: hodnota 0 drží skupinu naživu, chybějící title je prázdný', () => {
  const groups = visibleGroups([{ items: [{ label: 'Počet', value: 0 }] }]);
  assert.deepEqual(groups, [{ title: '', items: [{ label: 'Počet', value: 0 }], wide: false }]);
});

test('visibleGroups: nepole a null skupiny', () => {
  assert.deepEqual(visibleGroups(null), []);
  assert.deepEqual(visibleGroups(undefined), []);
  assert.deepEqual(visibleGroups([null, undefined]), []);
});
