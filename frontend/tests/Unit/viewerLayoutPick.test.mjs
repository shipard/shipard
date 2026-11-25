import { test } from 'node:test';
import assert from 'node:assert/strict';
import { availableLayouts, effectiveLayoutOf, nextLayout } from '../../src/utils/viewerLayoutPick.js';

test('availableLayouts: desktop bere všechno, telefon bez gridu', () => {
  assert.deepEqual(availableLayouts(['list', 'grid', 'tree'], false), ['list', 'grid', 'tree']);
  assert.deepEqual(availableLayouts(['list', 'grid', 'tree'], true), ['list', 'tree']);
  assert.deepEqual(availableLayouts(['list', 'grid'], true), ['list']);
});

test('availableLayouts: bez meta jen seznam', () => {
  assert.deepEqual(availableLayouts(null, false), ['list']);
  assert.deepEqual(availableLayouts(undefined, true), ['list']);
  assert.deepEqual(availableLayouts([], false), ['list']);
});

test('effectiveLayoutOf: grid na telefonu degraduje na seznam', () => {
  assert.equal(effectiveLayoutOf('grid', ['list', 'grid'], false), 'grid');
  assert.equal(effectiveLayoutOf('grid', ['list', 'grid'], true), 'list');
});

test('effectiveLayoutOf: strom zůstává i na telefonu (#169 D24)', () => {
  assert.equal(effectiveLayoutOf('tree', ['list', 'tree'], false), 'tree');
  assert.equal(effectiveLayoutOf('tree', ['list', 'tree'], true), 'tree');
});

test('effectiveLayoutOf: layout, který viewer nenabízí, padá na seznam', () => {
  assert.equal(effectiveLayoutOf('tree', ['list', 'grid'], false), 'list');
  assert.equal(effectiveLayoutOf('grid', ['list'], false), 'list');
  assert.equal(effectiveLayoutOf('tree', null, false), 'list');
  assert.equal(effectiveLayoutOf('list', ['list', 'tree'], true), 'list');
});

test('nextLayout: dva layouty se střídají', () => {
  assert.equal(nextLayout('grid', ['list', 'grid'], false), 'list');
  assert.equal(nextLayout('list', ['list', 'grid'], false), 'grid');
  assert.equal(nextLayout('tree', ['list', 'tree'], false), 'list');
  assert.equal(nextLayout('list', ['list', 'tree'], false), 'tree');
});

test('nextLayout: tři layouty jdou dokola v pořadí z meta', () => {
  const layouts = ['list', 'grid', 'tree'];
  assert.equal(nextLayout('list', layouts, false), 'grid');
  assert.equal(nextLayout('grid', layouts, false), 'tree');
  assert.equal(nextLayout('tree', layouts, false), 'list');
});

test('nextLayout: telefon grid přeskakuje', () => {
  const layouts = ['list', 'grid', 'tree'];
  assert.equal(nextLayout('list', layouts, true), 'tree');
  assert.equal(nextLayout('tree', layouts, true), 'list');
});

test('nextLayout: není mezi čím přepínat', () => {
  assert.equal(nextLayout('list', ['list'], false), 'list');
  assert.equal(nextLayout('list', ['list', 'grid'], true), 'list');
  assert.equal(nextLayout('list', null, false), 'list');
});
