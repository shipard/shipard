import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  visibleTreeRows,
  isExpanded,
  ancestorIds,
  defaultExpanded,
  expandableIds,
  toggleExpanded,
  withExpanded,
  pruneExpanded,
  isTemporaryView,
} from '../../src/utils/viewerTree.js';

// Budova 1 (podlaží 10 s místnostmi 101, 102; podlaží 20 s místností 201)
// a samostatná budova 2 bez podřízených — pořadí průchodu do hloubky.
const ROWS = [
  { id: 1, parent: null, depth: 0, childCount: 2 },
  { id: 10, parent: 1, depth: 1, childCount: 2 },
  { id: 101, parent: 10, depth: 2, childCount: 0 },
  { id: 102, parent: 10, depth: 2, childCount: 0 },
  { id: 20, parent: 1, depth: 1, childCount: 1 },
  { id: 201, parent: 20, depth: 2, childCount: 0 },
  { id: 2, parent: null, depth: 0, childCount: 0 },
];

const ids = rows => rows.map(r => r.id);

test('visibleTreeRows: nic rozbaleno → jen kořeny', () => {
  assert.deepEqual(ids(visibleTreeRows(ROWS, new Set())), [1, 2]);
  assert.deepEqual(ids(visibleTreeRows(ROWS, null)), [1, 2]);
});

test('visibleTreeRows: rozbalený kořen ukáže druhou úroveň', () => {
  assert.deepEqual(ids(visibleTreeRows(ROWS, new Set([1]))), [1, 10, 20, 2]);
});

test('visibleTreeRows: rozbalená větev', () => {
  assert.deepEqual(ids(visibleTreeRows(ROWS, new Set([1, 20]))), [1, 10, 20, 201, 2]);
});

test('visibleTreeRows: rozbalený uzel pod sbaleným předkem vidět není', () => {
  assert.deepEqual(ids(visibleTreeRows(ROWS, new Set([10, 20]))), [1, 2]);
});

test('visibleTreeRows: sbalený uzel schová celý podstrom, sourozenec zůstane', () => {
  // 10 sbalené, 20 rozbalené — po podstromu 10 se skrývání musí vypnout.
  assert.deepEqual(ids(visibleTreeRows(ROWS, new Set([1, 20]))), [1, 10, 20, 201, 2]);
});

test('visibleTreeRows: expandAll ukáže všechno a sadu nečte', () => {
  assert.deepEqual(ids(visibleTreeRows(ROWS, new Set(), true)), ids(ROWS));
  assert.deepEqual(ids(visibleTreeRows(ROWS, null, true)), ids(ROWS));
});

test('visibleTreeRows: prázdný a chybějící vstup', () => {
  assert.deepEqual(visibleTreeRows([], new Set([1])), []);
  assert.deepEqual(visibleTreeRows(null, null), []);
});

test('isExpanded: uzel bez dětí není rozbalený ani v expandAll', () => {
  assert.equal(isExpanded(ROWS[0], new Set([1])), true);
  assert.equal(isExpanded(ROWS[0], new Set()), false);
  assert.equal(isExpanded(ROWS[0], null, true), true);
  assert.equal(isExpanded(ROWS[6], new Set([2]), true), false);
});

test('ancestorIds: cesta shora dolů bez řádku samotného', () => {
  assert.deepEqual(ancestorIds(ROWS, 201), [1, 20]);
  assert.deepEqual(ancestorIds(ROWS, 10), [1]);
  assert.deepEqual(ancestorIds(ROWS, 1), []);
});

test('ancestorIds: neznámé id a rodič mimo strom', () => {
  assert.deepEqual(ancestorIds(ROWS, 999), []);
  assert.deepEqual(ancestorIds([{ id: 5, parent: 77, depth: 0, childCount: 0 }], 5), []);
});

test('ancestorIds: cyklus v datech průchod ukončí', () => {
  const cyclic = [
    { id: 1, parent: 2, depth: 0, childCount: 1 },
    { id: 2, parent: 1, depth: 1, childCount: 1 },
  ];
  assert.deepEqual(ancestorIds(cyclic, 1), [2]);
});

test('defaultExpanded: kořeny s dětmi', () => {
  assert.deepEqual([...defaultExpanded(ROWS)], [1]);
  assert.deepEqual([...defaultExpanded([])], []);
  assert.deepEqual([...defaultExpanded(null)], []);
});

test('expandableIds: všechny uzly s dětmi', () => {
  assert.deepEqual([...expandableIds(ROWS)], [1, 10, 20]);
});

test('toggleExpanded: vrací novou sadu, původní nemění', () => {
  const original = new Set([1]);
  const opened = toggleExpanded(original, 10);
  assert.notEqual(opened, original);
  assert.deepEqual([...original], [1]);
  assert.deepEqual([...opened], [1, 10]);
  assert.deepEqual([...toggleExpanded(opened, 1)], [10]);
  assert.deepEqual([...toggleExpanded(null, 5)], [5]);
});

test('withExpanded: přidá chybějící, jinak vrací tutéž instanci', () => {
  const original = new Set([1]);
  assert.equal(withExpanded(original, [1]), original);
  assert.equal(withExpanded(original, []), original);
  const next = withExpanded(original, [1, 20]);
  assert.notEqual(next, original);
  assert.deepEqual([...next], [1, 20]);
  assert.deepEqual([...withExpanded(null, [])], []);
});

test('pruneExpanded: zahodí uzly mimo strom a uzly bez dětí', () => {
  assert.deepEqual([...pruneExpanded(new Set([1, 2, 20, 999]), ROWS)], [1, 20]);
  assert.deepEqual([...pruneExpanded(null, ROWS)], []);
});

test('isTemporaryView: výchozí pohled dočasný není', () => {
  assert.equal(isTemporaryView({ search: '', viewGroup: 'active', defaultViewGroup: 'active', filters: {}, defaultFilters: {} }), false);
  assert.equal(isTemporaryView({ viewGroup: 'active', defaultViewGroup: 'active' }), false);
});

test('isTemporaryView: hledání, jiná záložka stavů', () => {
  const base = { viewGroup: 'active', defaultViewGroup: 'active', filters: {}, defaultFilters: {} };
  assert.equal(isTemporaryView({ ...base, search: '101' }), true);
  assert.equal(isTemporaryView({ ...base, viewGroup: 'archive' }), true);
  assert.equal(isTemporaryView({ ...base, viewGroup: 'all' }), true);
});

test('isTemporaryView: filtr je vlastní, jen když se liší od výchozí hodnoty', () => {
  const base = { search: '', viewGroup: 'active', defaultViewGroup: 'active' };
  assert.equal(isTemporaryView({ ...base, filters: { kind: '3' }, defaultFilters: {} }), true);
  assert.equal(isTemporaryView({ ...base, filters: { year: '2026' }, defaultFilters: { year: '2026' } }), false);
  assert.equal(isTemporaryView({ ...base, filters: { year: 2026 }, defaultFilters: { year: '2026' } }), false);
  assert.equal(isTemporaryView({ ...base, filters: {}, defaultFilters: { year: '2026' } }), true);
  assert.equal(isTemporaryView({ ...base, filters: { kind: '' }, defaultFilters: {} }), false);
});
