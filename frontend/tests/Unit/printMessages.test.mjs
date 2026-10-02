import { test } from 'node:test';
import assert from 'node:assert/strict';

import { parsePrintMessages } from '../../src/utils/printMessages.js';

test('dekóduje procentově kódované JSON pole hlášení', () => {
  const messages = [
    { severity: 'warning', code: 'payment.qrNoAccount', text: 'QR platba na dokladu chybí — doklad nemá bankovní účet s IBAN.' },
  ];
  const header = encodeURIComponent(JSON.stringify(messages));

  assert.deepEqual(parsePrintMessages(header), messages);
});

test('chybějící hlavička = žádná hlášení', () => {
  assert.deepEqual(parsePrintMessages(null), []);
  assert.deepEqual(parsePrintMessages(undefined), []);
  assert.deepEqual(parsePrintMessages(''), []);
});

test('poškozená hlavička náhled neshodí', () => {
  assert.deepEqual(parsePrintMessages('%E0%A4%A'), []);
  assert.deepEqual(parsePrintMessages('not json'), []);
  assert.deepEqual(parsePrintMessages(encodeURIComponent('{"code":"x"}')), []);
});

test('položky bez kódu nebo textu se zahodí', () => {
  const header = encodeURIComponent(JSON.stringify([
    { code: 'a', text: 'A' },
    { code: 'b' },
    null,
    'text',
    { text: 'bez kódu' },
  ]));

  assert.deepEqual(parsePrintMessages(header), [{ code: 'a', text: 'A' }]);
});
