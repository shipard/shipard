import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  formatNumber, formatPercent, formatPercentDelta, formatDelta, formatUsd, formatMs, formatCommit,
} from '../../../src/dev/format.js';

// Intl v Node odděluje tisíce i desetiny podle cs-CZ (nezlomitelná mezera, čárka);
// testy porovnávají po normalizaci mezer, aby nezávisely na verzi ICU.
const norm = (s) => s.replace(/ | /g, ' ');

test('čísla a procenta česky, null → pomlčka', () => {
  assert.equal(norm(formatNumber(1234.5, 1)), '1 234,5');
  assert.equal(formatNumber(null), '—');
  assert.equal(norm(formatPercent(0.9412)), '94,1 %');
  assert.equal(norm(formatPercent(1, 0)), '100 %');
  assert.equal(formatPercent(undefined), '—');
});

test('rozdíly se znaménkem', () => {
  assert.equal(norm(formatPercentDelta(0.004)), '+0,4 b.');
  assert.equal(norm(formatPercentDelta(-0.0123, 2)), '−1,23 b.');
  assert.equal(norm(formatPercentDelta(0)), '±0,0 b.');
  assert.equal(norm(formatDelta(3)), '+3');
  assert.equal(norm(formatDelta(-2.5, 1)), '−2,5');
});

test('cena, čas, commit', () => {
  assert.equal(norm(formatUsd(0.037984)), '0,0380 USD');
  assert.equal(norm(formatMs(850)), '850 ms');
  assert.equal(norm(formatMs(8334)), '8,3 s');
  assert.equal(norm(formatMs(125000)), '2,1 min');
  assert.equal(formatMs(null), '—');
  assert.equal(formatCommit('1baa027d9f3c', true), '1baa027d*');
  assert.equal(formatCommit(null), '—');
});
