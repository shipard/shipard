import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  itemDecision,
  matchSourceKey,
  isWeakSource,
  isEnrichmentSource,
  hasSecondLine,
} from '../../src/components/exchange/rowMatch.js';

test('itemDecision: useExisting / noItem / skip / nic', () => {
  const ua = { 'rows[0].item': 'useExisting:77', 'rows[1].item': 'noItem', 'rows[2].item': 'skip' };
  assert.deepEqual(itemDecision(ua, 0), { kind: 'useExisting', id: 77 });
  assert.deepEqual(itemDecision(ua, 1), { kind: 'noItem' });
  assert.deepEqual(itemDecision(ua, 2), { kind: 'skip' });
  assert.equal(itemDecision(ua, 3), null);
  assert.equal(itemDecision(null, 0), null);
});

test('itemDecision: neplatný tvar → null', () => {
  assert.equal(itemDecision({ 'rows[0].item': 'useExisting:abc' }, 0), null);
  assert.equal(itemDecision({ 'rows[0].item': 'useExisting:0' }, 0), null);
  assert.equal(itemDecision({ 'rows[0].item': 'create' }, 0), null);
  assert.equal(itemDecision({ 'rows[0].item': 42 }, 0), null);
});

test('matchSourceKey: pin a založená položka → user', () => {
  assert.equal(matchSourceKey({ matchedBy: 'ourCode' }, null, { code: 'A', pinned: true }), 'user');
  assert.equal(matchSourceKey({ matchedBy: 'supplierCode' }, null, { code: 'A', pinned: false }, true), 'user');
  assert.equal(matchSourceKey({ matchedBy: 'created' }, null, null), 'user');
});

test('matchSourceKey: ourCode z historie podle enrichment.matchedBy', () => {
  const display = { code: 'NET500', pinned: false };
  const block = { matchedBy: 'ourCode' };
  const e = (matchedBy) => ({ matchedBy, suggested: { ourCode: 'NET500' } });
  assert.equal(matchSourceKey(block, e('historyExactRaw'), display), 'historyExact');
  assert.equal(matchSourceKey(block, e('historyExactNorm'), display), 'historyExact');
  assert.equal(matchSourceKey(block, e('historyFuzzy'), display), 'historyFuzzy');
  assert.equal(matchSourceKey(block, e('historyDominantItem'), display), 'historyDominant');
  assert.equal(matchSourceKey(block, e('contentTag'), display), 'contentTag');
});

test('matchSourceKey: ourCode z AI (kód se s návrhem neshoduje nebo enrichment chybí) → ourCode', () => {
  const block = { matchedBy: 'ourCode' };
  assert.equal(matchSourceKey(block, null, { code: 'NET500' }), 'ourCode');
  assert.equal(
    matchSourceKey(block, { matchedBy: 'historyFuzzy', suggested: { ourCode: 'OTHER' } }, { code: 'NET500' }),
    'ourCode',
  );
  // Enrichment jen s účtem (resolution accountOnly) — kód nedoplnil.
  assert.equal(
    matchSourceKey(block, { matchedBy: 'contentTag', suggested: { account: '518100' } }, { code: 'NET500' }),
    'ourCode',
  );
});

test('matchSourceKey: resolver probes a neznámé', () => {
  assert.equal(matchSourceKey({ matchedBy: 'supplierCode' }, null, null), 'supplierCode');
  assert.equal(matchSourceKey({ matchedBy: 'ean' }, null, null), 'ean');
  assert.equal(matchSourceKey({ matchedBy: 'sku' }, null, null), 'sku');
  assert.equal(matchSourceKey({ matchedBy: 'name' }, null, null), 'name');
  assert.equal(matchSourceKey({ matchedBy: 'whatever' }, null, null), 'ourCode');
  assert.equal(matchSourceKey({ status: 'canCreate' }, null, null), null);
  assert.equal(matchSourceKey(null, null, null), null);
});

test('isWeakSource / isEnrichmentSource', () => {
  for (const k of ['historyFuzzy', 'historyDominant', 'contentTag', 'name']) assert.equal(isWeakSource(k), true, k);
  for (const k of ['historyExact', 'ourCode', 'supplierCode', 'ean', 'sku', 'user', null]) assert.equal(isWeakSource(k), false, String(k));
  for (const k of ['historyExact', 'historyFuzzy', 'historyDominant', 'contentTag']) assert.equal(isEnrichmentSource(k), true, k);
  for (const k of ['ourCode', 'name', 'user', null]) assert.equal(isEnrichmentSource(k), false, String(k));
});

test('hasSecondLine: rozhodnutí nebo efektivní položka', () => {
  assert.equal(hasSecondLine({ kind: 'noItem' }, null), true);
  assert.equal(hasSecondLine({ kind: 'skip' }, null), true);
  assert.equal(hasSecondLine({ kind: 'useExisting', id: 7 }, null), true);
  assert.equal(hasSecondLine(null, { id: 18, code: 'A', name: 'B', pinned: false }), true);
  assert.equal(hasSecondLine(null, null), false);
  assert.equal(hasSecondLine(null, undefined), false);
});

test('matchSourceKey: matchedDisplay bez pinned (panel po ruční volbě) → zdroj automatického napárování, ne user', () => {
  // Po ruční volbě nese display zvolenou položku (pinned); panel čte
  // automatické napárování z matchedDisplay (#111 D10).
  const block = { matchedBy: 'ourCode', display: { code: 'OTHER', pinned: true } };
  const matched = { id: 18, code: 'NET500', name: 'Sitovy kabel' };
  const e = { matchedBy: 'historyExactRaw', suggested: { ourCode: 'NET500' } };
  assert.equal(matchSourceKey(block, e, matched), 'historyExact');
  assert.equal(matchSourceKey({ matchedBy: 'supplierCode' }, null, matched), 'supplierCode');
  // Smazaná položka: matchedDisplay chybí — zdroj z matchedBy, bez pádu.
  assert.equal(matchSourceKey(block, e, null), 'ourCode');
});
