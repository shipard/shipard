import { test } from 'node:test';
import assert from 'node:assert/strict';
import { use } from '../../../src/dev/i18n/index.js';
import { groupDatasets, datasetLabel } from '../../../src/dev/datasets.js';

// Popisky skupin a sad se testují česky; v Node je navigator.language en-US.
use('cs');

const vatPayer = { name: 'cz-vat-payer', title: 'Plátce DPH', kind: 'test', country: 'cz', profile: 'vat-payer' };
const nonPayer = { name: 'cz-non-payer', title: 'Neplátce', kind: 'test', country: 'cz', profile: 'non-payer' };
const demo = { name: 'it-servis', title: 'Demo — IT servis', kind: 'demo', country: 'cz', profile: null };

test('skupiny v pevném pořadí testovací → demo, pořadí sad ze serveru', () => {
  const groups = groupDatasets([demo, vatPayer, nonPayer]);
  assert.deepEqual(groups.map((g) => [g.kind, g.label, g.items.map((d) => d.name)]), [
    ['test', 'Testovací sady', ['cz-vat-payer', 'cz-non-payer']],
    ['demo', 'Demo sady', ['it-servis']],
  ]);
});

test('prázdná skupina se vynechá, bez sad žádná skupina', () => {
  assert.deepEqual(groupDatasets([demo]).map((g) => g.kind), ['demo']);
  assert.deepEqual(groupDatasets([vatPayer]).map((g) => g.kind), ['test']);
  assert.deepEqual(groupDatasets([]), []);
});

test('popisek: testovací sada s profilem, demo sada bez něj, chybějící země slovy', () => {
  assert.equal(datasetLabel(vatPayer), 'Plátce DPH — cz-vat-payer · cz · vat-payer');
  assert.equal(datasetLabel(demo), 'Demo — IT servis — it-servis · cz');
  assert.equal(datasetLabel({ ...vatPayer, country: null, profile: null }), 'Plátce DPH — cz-vat-payer · bez země · bez profilu');
});
