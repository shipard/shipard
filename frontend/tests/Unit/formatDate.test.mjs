// Pásmo západně od UTC: `new Date('2026-01-01')` by tu dala 31. 12. Musí být
// nastavené před prvním použitím `Date`, proto hned na začátku souboru
// (každý testovací soubor běží ve vlastním procesu).
process.env.TZ = 'America/New_York';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatDateIn, formatDateTimeIn } from '../../src/utils/formatDateCore.js';

test('test opravdu běží v pásmu západně od UTC', () => {
  assert.equal(new Date('2026-01-01').getDate(), 31);
});

test('datum: česky s nulami, anglicky ISO', () => {
  assert.equal(formatDateIn('2026-01-01', 'cs'), '01.01.2026');
  assert.equal(formatDateIn('2026-12-31', 'cs'), '31.12.2026');
  assert.equal(formatDateIn('2026-01-01', 'en'), '2026-01-01');
});

test('neznámý jazyk dostane anglický zápis', () => {
  assert.equal(formatDateIn('2026-01-01', 'de'), '2026-01-01');
  assert.equal(formatDateIn('2026-01-01', undefined), '2026-01-01');
  assert.equal(formatDateTimeIn('2026-01-01 14:05:33', 'sk'), '2026-01-01 14:05');
});

test('čisté datum se v pásmu západně od UTC neposune', () => {
  assert.equal(formatDateIn('2026-01-01', 'cs'), '01.01.2026');
  assert.equal(formatDateIn('2026-01-01', 'en'), '2026-01-01');
  assert.equal(formatDateTimeIn('2026-01-01', 'cs'), '01.01.2026 00:00');
});

test('datum s časem na minuty', () => {
  assert.equal(formatDateTimeIn('2026-01-01 14:05:33', 'cs'), '01.01.2026 14:05');
  assert.equal(formatDateTimeIn('2026-01-01 14:05:33', 'en'), '2026-01-01 14:05');
});

test('čas bez posunu pásma se nepřevádí', () => {
  for (const value of ['2026-03-09 07:04:59', '2026-03-09 07:04', '2026-03-09T07:04:59', '2026-03-09T07:04:59.123', ' 2026-03-09 07:04:59 ']) {
    assert.equal(formatDateIn(value, 'cs'), '09.03.2026');
    assert.equal(formatDateTimeIn(value, 'cs'), '09.03.2026 07:04');
    assert.equal(formatDateTimeIn(value, 'en'), '2026-03-09 07:04');
  }
});

test('hodnota s posunem je okamžik — místní čas prohlížeče', () => {
  // 12:00 UTC = 07:00 v New Yorku (leden, EST).
  assert.equal(formatDateTimeIn('2026-01-15T12:00:00Z', 'cs'), '15.01.2026 07:00');
  assert.equal(formatDateTimeIn('2026-01-15T12:00:00+00:00', 'en'), '2026-01-15 07:00');
  assert.equal(formatDateTimeIn('2026-01-15 12:00:00+0000', 'en'), '2026-01-15 07:00');
  // 02:00 UTC je v New Yorku ještě předchozí den.
  assert.equal(formatDateIn('2026-01-15T02:00:00Z', 'cs'), '14.01.2026');
});

test('objekt Date v místním čase', () => {
  const d = new Date(2026, 1, 3, 4, 5, 6);
  assert.equal(formatDateIn(d, 'cs'), '03.02.2026');
  assert.equal(formatDateIn(d, 'en'), '2026-02-03');
  assert.equal(formatDateTimeIn(d, 'cs'), '03.02.2026 04:05');
});

test('prázdná hodnota je prázdný řetězec', () => {
  for (const value of [null, undefined, '']) {
    assert.equal(formatDateIn(value, 'cs'), '');
    assert.equal(formatDateTimeIn(value, 'en'), '');
  }
  assert.equal(formatDateIn(new Date(NaN), 'cs'), '');
});

test('hodnota, která datem není, se vrací beze změny', () => {
  for (const value of ['bez data', '2026-02-30', '2026-13-01', '2026-01-01 25:00:00', '1. 1. 2026', '26-01-01']) {
    assert.equal(formatDateIn(value, 'cs'), value);
    assert.equal(formatDateTimeIn(value, 'en'), value);
  }
  assert.equal(formatDateIn(20260101, 'cs'), '20260101');
});
