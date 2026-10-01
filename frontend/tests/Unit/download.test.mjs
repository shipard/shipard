import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fileNameFromContentDisposition } from '../../src/utils/download.js';

test('plain quoted filename', () => {
  assert.equal(
    fileNameFromContentDisposition('attachment; filename="hlavni-kniha-2026-05.xlsx"'),
    'hlavni-kniha-2026-05.xlsx',
  );
});

test('unquoted filename', () => {
  assert.equal(fileNameFromContentDisposition('attachment; filename=report.csv'), 'report.csv');
});

test('RFC 5987 filename* wins over plain filename', () => {
  assert.equal(
    fileNameFromContentDisposition(
      "attachment; filename=\"hlavn__kniha.xlsx\"; filename*=UTF-8''hlavn%C3%AD%20kniha.xlsx",
    ),
    'hlavní kniha.xlsx',
  );
});

test('broken percent-encoding falls back to plain filename', () => {
  assert.equal(
    fileNameFromContentDisposition("attachment; filename=\"a.csv\"; filename*=UTF-8''%E0%A4%A"),
    'a.csv',
  );
});

test('missing header or filename yields null', () => {
  assert.equal(fileNameFromContentDisposition(null), null);
  assert.equal(fileNameFromContentDisposition(''), null);
  assert.equal(fileNameFromContentDisposition('attachment'), null);
});
