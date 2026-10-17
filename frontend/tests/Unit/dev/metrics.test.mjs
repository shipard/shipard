import { test } from 'node:test';
import assert from 'node:assert/strict';
import { use } from '../../../src/dev/i18n/index.js';
import {
  metricLabel, metricKind, formatMetric, formatMetricDelta, rangeText, fieldMetricRows, metricRows,
  topDeviations, unstableNodes, deviationLevel, sumOf, maxRepeat, isHeadlineMetric, DOCUMENT_METRICS,
} from '../../../src/dev/testing/metrics.js';

// Popisky se testují česky; v Node je navigator.language en-US.
use('cs');

const norm = (s) => s.replace(/ | /g, ' ');

test('popisky a druhy metrik', () => {
  assert.equal(metricLabel('fields.supplier.hit'), 'Dodavatel — shoda');
  assert.equal(metricLabel('fields.all.n'), 'Celkem — polí');
  assert.equal(metricLabel('documents.clean'), 'Doklady bez odchylky');
  assert.equal(metricLabel('calls.failed.schema_error'), 'Selhání: schema_error');
  assert.equal(metricLabel('weird.key'), 'weird.key');
  assert.equal(metricKind('fields.all.hit'), 'percent');
  assert.equal(metricKind('fields.all.missing'), 'int');
  assert.equal(metricKind('cost.perDocument'), 'usd');
  assert.equal(metricKind('time.perDocumentMs'), 'ms');
  assert.equal(metricKind('tokens.input'), 'int');
});

test('formát metrik a rozdílů', () => {
  assert.equal(norm(formatMetric('fields.all.hit', 0.9412)), '94,1 %');
  assert.equal(norm(formatMetric('cost.usd', 2.847098)), '2,8471 USD');
  assert.equal(norm(formatMetric('time.perDocumentMs', 8334)), '8,3 s');
  assert.equal(norm(formatMetric('calls.ok', 19.666667)), '19,67');
  assert.equal(formatMetric('calls.ok', null), '—');
  assert.equal(norm(formatMetricDelta('fields.all.hit', 0.004)), '+0,4 b.');
  assert.equal(norm(formatMetricDelta('cost.usd', -0.3)), '−0,3000 USD');
  assert.equal(formatMetricDelta('calls.total', 0), '±0');
});

test('rozpětí opakování', () => {
  assert.equal(norm(rangeText(0.9412, 0.9382, 0.9441)), '94,1 % (93,8–94,4)');
  assert.equal(norm(rangeText(0.95, null, null)), '95,0 %');
  assert.equal(norm(rangeText(20, 20, 20, 'int')), '20');
  assert.equal(rangeText(null, 1, 2), '—');
});

test('řádky metrik polí a dokladů', () => {
  const metrics = {
    'fields.header.n': 100, 'fields.header.hit': 0.9, 'fields.header.normalized': 0.01, 'fields.header.missing': 5, 'fields.header.extra': 1, 'fields.header.wrong': 4,
    'fields.all.n': 100, 'fields.all.hit': 0.9,
    'documents.clean': 0.1, 'documents.amountsCorrect': 1,
  };
  const range = { 'fields.header.hit': { min: 0.88, max: 0.92 } };
  const rows = fieldMetricRows(metrics, range);
  assert.deepEqual(rows.map((r) => r.id), ['header', 'all']);
  assert.deepEqual(rows[0].hit, { avg: 0.9, min: 0.88, max: 0.92 });
  assert.equal(rows[0].wrong, 4);
  assert.deepEqual(rows[1].hit, { avg: 0.9, min: null, max: null });
  const docs = metricRows(DOCUMENT_METRICS, metrics, null);
  assert.deepEqual(docs.map((r) => r.key), ['documents.clean', 'documents.amountsCorrect']);
  assert.equal(docs[0].label, 'Doklady bez odchylky');
});

test('nejčastější odchylky a stabilita', () => {
  const top = topDeviations({ 'supplier.contact.web': 48, 'docNumber': 2, 'notes.onDocument': 29 }, 60, 2);
  assert.deepEqual(top.map((t) => t.node), ['supplier.contact.web', 'notes.onDocument']);
  assert.equal(top[0].share, 0.8);
  assert.equal(unstableNodes(null), null);
  assert.deepEqual(unstableNodes({ a: 1, b: 0.5, c: 0.9 }), [{ node: 'b', ratio: 0.5 }, { node: 'c', ratio: 0.9 }]);
});

test('úroveň odchylek, součty, maximum opakování', () => {
  assert.equal(deviationLevel(0), 'ok');
  assert.equal(deviationLevel(3), 'warn');
  assert.equal(deviationLevel(4), 'bad');
  assert.equal(deviationLevel(null), 'failed');
  assert.equal(sumOf([1, null, 2.5]), 3.5);
  assert.equal(sumOf([null]), null);
  assert.equal(maxRepeat([{ repeat: 1, repeats: [] }, { repeat: 3, repeats: [{ repeat: 1 }, { repeat: 2 }, { repeat: 3 }] }]), 3);
  assert.equal(maxRepeat([]), 1);
});

test('metriky přehledu porovnání', () => {
  assert.equal(isHeadlineMetric('fields.all.hit'), true);
  assert.equal(isHeadlineMetric('fields.all.normalized'), true);
  assert.equal(isHeadlineMetric('fields.header.missing'), false);
  assert.equal(isHeadlineMetric('calls.failed'), true);
  assert.equal(isHeadlineMetric('calls.failed.schema_error'), false);
  assert.equal(isHeadlineMetric('cost.perDocument'), true);
});
