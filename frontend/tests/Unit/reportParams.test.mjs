import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  defaultReportParams,
  coerceReportParam,
  overlayReportParams,
  extraParamEntries,
  reportQueryEntries,
  deepLinkEntries,
  parseReportDeepLink,
  fitPeriodToGranularities,
} from '../../src/utils/reportParams.js';

const declared = [
  { id: 'groupBy', type: 'enum', options: ['accountingGroup', 'type', 'none'], default: 'accountingGroup' },
  { id: 'onlyOpen', type: 'bool', options: [], default: false },
];

test('defaults come from the declaration', () => {
  assert.deepEqual(defaultReportParams(declared), { groupBy: 'accountingGroup', onlyOpen: false });
  assert.deepEqual(defaultReportParams(undefined), {});
});

test('coerce validates enum options and bool text', () => {
  assert.equal(coerceReportParam(declared[0], 'type'), 'type');
  assert.equal(coerceReportParam(declared[0], 'bogus'), undefined);
  assert.equal(coerceReportParam(declared[1], 'true'), true);
  assert.equal(coerceReportParam(declared[1], '0'), false);
  assert.equal(coerceReportParam(declared[1], 'yes'), undefined);
});

test('overlay keeps only declared and valid values', () => {
  assert.deepEqual(
    overlayReportParams(declared, { groupBy: 'type', onlyOpen: '1', detail: 'synthetic', fiscalYear: '2026' }),
    { groupBy: 'type', onlyOpen: true },
  );
  assert.deepEqual(overlayReportParams(declared, { groupBy: 'bogus' }), {});
});

test('extra entries skip period keys and empty values', () => {
  assert.deepEqual(
    extraParamEntries({ fiscalYear: '2026', monthFrom: 1, monthTo: 12, groupBy: 'type', onlyOpen: false, gone: undefined }),
    { groupBy: 'type', onlyOpen: 'false' },
  );
});

test('query entries for fiscal and VAT period reports', () => {
  assert.deepEqual(
    reportQueryEntries({ fiscalYear: '2026', monthFrom: 1, monthTo: 12, groupBy: 'type' }),
    { fiscalYear: '2026', monthFrom: '1', monthTo: '12', groupBy: 'type' },
  );
  assert.deepEqual(reportQueryEntries({ period: 141 }), { period: '141' });
});

test('deep link round trip keeps declared params', () => {
  const entries = deepLinkEntries('economy.assets.register', {
    fiscalYear: '2026', monthFrom: 1, monthTo: 12, groupBy: 'type', onlyOpen: true,
  });
  assert.deepEqual(entries, {
    report: 'economy.assets.register', fy: '2026', mf: '1', mt: '12', groupBy: 'type', onlyOpen: 'true',
  });

  const parsed = parseReportDeepLink(`?${new URLSearchParams(entries)}`);
  assert.equal(parsed.reportId, 'economy.assets.register');
  assert.deepEqual(parsed.params, {
    fiscalYear: '2026', monthFrom: 1, monthTo: 12, groupBy: 'type', onlyOpen: 'true',
  });
  assert.deepEqual(overlayReportParams(declared, parsed.params), { groupBy: 'type', onlyOpen: true });
});

test('deep link without report is null; legacy detail key still passes through', () => {
  assert.equal(parseReportDeepLink('?fy=2026'), null);
  assert.deepEqual(
    parseReportDeepLink('?report=economy.accounting.generalLedger&fy=2026&mf=5&mt=5&detail=synthetic').params,
    { fiscalYear: '2026', monthFrom: 5, monthTo: 5, detail: 'synthetic' },
  );
  assert.deepEqual(parseReportDeepLink('?report=x&p=141&mf=99').params, { period: 141 });
});

test('period is widened to the smallest declared granularity', () => {
  const month = { fiscalYear: '2026', monthFrom: 8, monthTo: 8 };
  assert.deepEqual(fitPeriodToGranularities(month, 12, null), month);
  assert.deepEqual(fitPeriodToGranularities(month, 12, ['month', 'year']), month);
  assert.deepEqual(fitPeriodToGranularities(month, 12, ['year']), { fiscalYear: '2026', monthFrom: 1, monthTo: 12 });
  assert.deepEqual(fitPeriodToGranularities(month, 12, ['quarter', 'year']), { fiscalYear: '2026', monthFrom: 7, monthTo: 9 });
  assert.deepEqual(fitPeriodToGranularities(month, 12, ['halfYear', 'year']), { fiscalYear: '2026', monthFrom: 7, monthTo: 12 });
  // Krátký fiskální rok: neúplné čtvrtletí / pololetí padá na celý rok.
  assert.deepEqual(fitPeriodToGranularities({ ...month, monthFrom: 8, monthTo: 8 }, 8, ['quarter', 'year']), {
    fiscalYear: '2026', monthFrom: 1, monthTo: 8,
  });
});
