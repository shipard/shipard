import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolveLanguage, t, has, label, use, currentLanguage, LANGUAGES, STORAGE_KEY } from '../../../src/dev/i18n/index.js';
import cs from '../../../src/dev/i18n/cs.js';
import en from '../../../src/dev/i18n/en.js';

test('volba jazyka: uložená platná hodnota, jinak jazyk prohlížeče (čeština → cs, jinak en)', () => {
  assert.equal(resolveLanguage('en', 'cs-CZ'), 'en');
  assert.equal(resolveLanguage('cs', 'en-US'), 'cs');
  assert.equal(resolveLanguage(null, 'cs-CZ'), 'cs');
  assert.equal(resolveLanguage(undefined, 'cs'), 'cs');
  assert.equal(resolveLanguage('de', 'de-DE'), 'en');
  assert.equal(resolveLanguage('', 'sk-SK'), 'en');
  assert.equal(resolveLanguage(null, null), 'en');
  assert.deepEqual(LANGUAGES, ['cs', 'en']);
  assert.equal(STORAGE_KEY, 'shpd_dev_language');
});

test('t() překládá podle jazyka, parametry ICU, fallback en → klíč', () => {
  use('cs');
  assert.equal(currentLanguage(), 'cs');
  assert.equal(t('common.loading'), 'Načítám…');
  assert.equal(t('common.networkError', { message: 'timeout' }), 'Chyba sítě: timeout');
  use('en');
  assert.equal(t('common.loading'), 'Loading…');
  assert.equal(t('common.networkError', { message: 'timeout' }), 'Network error: timeout');
  assert.equal(t('nope.missing'), 'nope.missing');
  assert.equal(has('common.close'), true);
  assert.equal(has('nope.missing'), false);
  assert.throws(() => use('de'));
  use('cs');
});

test('label() překládá kód ze serveru, neznámý kód vrátí jak přišel', () => {
  use('cs');
  assert.equal(label('common', 'close'), 'Zavřít');
  assert.equal(label('common', 'whatever_code'), 'whatever_code');
  assert.equal(label('common', null), '');
});

test('slovníky mají stejné klíče a žádný prázdný text', () => {
  assert.deepEqual(Object.keys(cs).sort(), Object.keys(en).sort());
  for (const [k, v] of Object.entries(cs)) assert.ok(v.trim() !== '', `prázdný cs: ${k}`);
  for (const [k, v] of Object.entries(en)) assert.ok(v.trim() !== '', `prázdný en: ${k}`);
});
