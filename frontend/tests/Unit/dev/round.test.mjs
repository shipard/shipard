import { test } from 'node:test';
import assert from 'node:assert/strict';
import { use } from '../../../src/dev/i18n/index.js';
import { roundSummary, roundSummaryText, groupRoundSteps, roundStatusClass, roundStepClass, roundSelectionText, roundStepNote, roundLayerLabel } from '../../../src/dev/testing/round.js';

use('cs');

const steps = [
  { layer: 'code', step: 'phpunit', status: 'ok', counts: { tests: 9982, failed: 0 } },
  { layer: 'datasets', dataset: 'cz-non-payer', step: 'reseed', status: 'failed', reason: 'exit 1' },
  { layer: 'datasets', dataset: 'cz-non-payer', step: 'verify', status: 'skipped', reason: 'přeseedování neproběhlo' },
  { layer: 'videos', dataset: 'it-servis', scenario: 's1', step: 'video', status: 'running' },
  { layer: 'datasets', dataset: 'cz-vat-payer', step: 'eval', status: 'ok', eval: { fieldsHit: 0.9876, costUsd: 1.5 } },
];

test('součty kroků ze steps, záloha ze summary serveru', () => {
  assert.deepEqual(roundSummary({ steps }), { ok: 2, failed: 1, skipped: 1, cancelled: 0, running: 1 });
  assert.deepEqual(roundSummary({ summary: { ok: 3, failed: 1 } }), { ok: 3, failed: 1, skipped: 0, cancelled: 0, running: 0 });
  assert.deepEqual(roundSummary(null), { ok: 0, failed: 0, skipped: 0, cancelled: 0, running: 0 });
  assert.equal(roundSummaryText({ steps }), '2 ok, 1 selhalo, 1 přeskočeno, 0 zrušeno');
});

test('seskupení po vrstvách drží pořadí kód → sady → videa a pořadí kroků', () => {
  const groups = groupRoundSteps(steps);
  assert.deepEqual(groups.map((g) => g.layer), ['code', 'datasets', 'videos']);
  assert.deepEqual(groups[1].steps.map((s) => s.step), ['reseed', 'verify', 'eval']);
  assert.deepEqual(groupRoundSteps([]), []);
  assert.deepEqual(groupRoundSteps([{ layer: 'x', step: 'y', status: 'ok' }]).map((g) => g.layer), ['x']);
});

test('třídy odznaků a popisky', () => {
  assert.equal(roundStatusClass('ok'), 'ok');
  assert.equal(roundStatusClass('failed'), 'bad');
  assert.equal(roundStatusClass('skipped'), 'warn');
  assert.equal(roundStatusClass('running'), 'neutral');
  assert.equal(roundStepClass('ok'), 'match');
  assert.equal(roundStepClass('cancelled'), 'missing');
  assert.equal(roundLayerLabel('code'), 'kód');
  assert.equal(roundLayerLabel('unknown'), 'unknown');
});

test('text výběru a poznámka kroku', () => {
  assert.equal(roundSelectionText({ layers: ['code', 'datasets'], datasets: ['cz-non-payer'], scenarios: [], eval: null }), 'kód, sady · cz-non-payer');
  assert.equal(roundSelectionText({ layers: ['datasets'], datasets: [], scenarios: [], eval: { variant: 'baseline', repeat: 2, maxCostUsd: 1.5 } }), 'sady · evaluace baseline ×2, strop 1.5 USD');
  assert.equal(roundSelectionText(null), '');
  assert.equal(roundStepNote(steps[0]), '9982 testů, 0 selhalo');
  assert.equal(roundStepNote(steps[2]), 'přeseedování neproběhlo');
  assert.equal(roundStepNote(steps[4]), 'shoda polí 98.8 %, cena 1.50 USD');
});
