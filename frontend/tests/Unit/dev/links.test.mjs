import { test } from 'node:test';
import assert from 'node:assert/strict';
import { docsUrl, COMMIT_URL } from '../../../src/dev/links.js';

test('odkaz Dokumentace podle jazyka dashboardu: cs česky, cokoli jiného anglicky', () => {
  assert.equal(docsUrl('cs'), 'https://shipard.dev/pro-vyvojare/');
  assert.equal(docsUrl('en'), 'https://shipard.dev/en/developers/');
  assert.equal(docsUrl('de'), 'https://shipard.dev/en/developers/');
  assert.equal(docsUrl(undefined), 'https://shipard.dev/en/developers/');
  assert.equal(docsUrl(null), 'https://shipard.dev/en/developers/');
});

test('odkaz na commit je základ URL GitHubu s lomítkem na konci', () => {
  assert.equal(COMMIT_URL, 'https://github.com/shipard/shipard/commit/');
});
