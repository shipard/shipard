import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initialFilterValues, supportedFilters } from '../../src/utils/viewerFilters.js';

const defs = [
  { id: 'fiscal_year', type: 'select', default: 3, options: [{ value: 3, label: '2026' }] },
  { id: 'fiscal_month', type: 'select', parentFilter: 'fiscal_year', options: [] },
  { id: 'partner', type: 'text' },
  { id: 'only_open', type: 'checkbox', default: '1' },
  { id: 'legacy', type: 'enum', default: 'x' },
];

test('defaults of supported filters, coerced to strings', () => {
  assert.deepEqual(initialFilterValues(defs, null), { fiscal_year: '3', only_open: '1' });
});

test('empty or missing default is ignored', () => {
  const values = initialFilterValues([
    { id: 'a', type: 'text', default: '' },
    { id: 'b', type: 'text', default: null },
    { id: 'c', type: 'text' },
  ], null);
  assert.deepEqual(values, {});
});

test('pending filters win over defaults and add their own keys', () => {
  const values = initialFilterValues(defs, { fiscal_year: 1, partner: 'AKIMA' });
  assert.deepEqual(values, { fiscal_year: '1', only_open: '1', partner: 'AKIMA' });
});

test('pending with empty value clears a default', () => {
  assert.deepEqual(initialFilterValues(defs, { only_open: '' }), { fiscal_year: '3' });
});

test('default of dependent select applies only when parent has a value', () => {
  const dependent = [
    { id: 'year', type: 'select', options: [] },
    { id: 'month', type: 'select', parentFilter: 'year', default: '15', options: [] },
  ];
  assert.deepEqual(initialFilterValues(dependent, null), {});
  assert.deepEqual(initialFilterValues(dependent, { year: '3' }), { year: '3', month: '15' });
});

test('unsupported filter types are dropped from defs and defaults', () => {
  assert.deepEqual(supportedFilters(defs).map(f => f.id), ['fiscal_year', 'fiscal_month', 'partner', 'only_open']);
  assert.deepEqual(initialFilterValues([{ id: 'legacy', type: 'enum', default: 'x' }], null), {});
});
