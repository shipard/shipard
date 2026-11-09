import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  detailParty,
  detailRows,
  detailTotals,
  formatAddress,
  rowMetaLine,
} from '../../src/components/document/documentParts.js';

const t = (key) => key;

test('detailParty: ids v pořadí IČO, DIČ, VAT ID; účet z čísla účtu; kontakty', () => {
  const party = detailParty({
    name: 'Firma s.r.o.',
    company_id: '12345678',
    tax_id: 'CZ12345678',
    vat_id: 'SK2020000000',
    address: { street: 'Dlouhá', house_number: '5', zip: '110 00', city: 'Praha' },
    bank_account: { account_number: '123-456/0100', iban: 'CZ65 0800 0000 1920 0014 5399' },
    contact: { email: 'info@example.test', phone: '+420 777 000 000' },
    person_id: 7,
  }, t);
  assert.deepEqual(party, {
    person_id: 7,
    name: 'Firma s.r.o.',
    ids: [
      { label: 'document.party.companyId', value: '12345678' },
      { label: 'document.party.taxId', value: 'CZ12345678' },
      { label: 'document.party.vatId', value: 'SK2020000000' },
    ],
    address: 'Dlouhá 5, 110 00 Praha',
    bank: { label: 'document.party.bankAccount', value: '123-456/0100' },
    contacts: ['info@example.test', '+420 777 000 000'],
  });
});

test('detailParty: VAT ID shodné s DIČ se vynechá, účet z IBAN, bez kontaktů', () => {
  const party = detailParty({
    name: 'Firma',
    tax_id: 'CZ12345678',
    vat_id: 'CZ12345678',
    bank_account: { account_number: null, iban: 'CZ65 0800 0000 1920 0014 5399' },
    contact: { email: null, phone: '' },
  }, t);
  assert.deepEqual(party.ids, [{ label: 'document.party.taxId', value: 'CZ12345678' }]);
  assert.deepEqual(party.bank, { label: 'document.party.bankAccount', value: 'CZ65 0800 0000 1920 0014 5399' });
  assert.deepEqual(party.contacts, []);
  assert.equal(party.address, null);
  assert.equal(party.person_id, null);
});

test('detailParty: display_line adresy má přednost, prázdný účet je null', () => {
  const party = detailParty({
    name: 'Firma',
    address: { display_line: 'Krátká 1, Brno', street: 'Jiná' },
    bank_account: { account_number: '', iban: null },
  }, t);
  assert.equal(party.address, 'Krátká 1, Brno');
  assert.equal(party.bank, null);
  assert.deepEqual(party.ids, []);
});

test('detailParty: null strana je null', () => {
  assert.equal(detailParty(null, t), null);
  assert.equal(detailParty(undefined, t), null);
});

test('formatAddress: skládá ulici a město, bez ničeho null', () => {
  assert.equal(formatAddress({ street: 'Dlouhá', house_number: '5' }), 'Dlouhá 5');
  assert.equal(formatAddress({ zip: '110 00', city: 'Praha' }), '110 00 Praha');
  assert.equal(formatAddress({}), null);
  assert.equal(formatAddress(null), null);
});

test('detailRows: položkový řádek, sazba z vat_pct, extra projde', () => {
  const rows = detailRows([
    {
      order_pos: 3, kind: 1, description: 'Diesel', quantity: '41,37', unit: 'l',
      unit_price: '36,20', vat_pct: '21', total_price: '1 497,60',
      item_id: 12, item_state: { label: 'Archiv', style: 'archive' },
    },
  ]);
  assert.deepEqual(rows, [{
    key: 0, pos: 3, isText: false, description: 'Diesel', quantity: '41,37', unit: 'l',
    unitPrice: '36,20', vat: '21 %', total: '1 497,60',
    item_id: 12, item_state: { label: 'Archiv', style: 'archive' },
  }]);
});

test('detailRows: textový řádek, pořadí bez order_pos, prázdné hodnoty null', () => {
  const rows = detailRows([
    { kind: 0, description: 'Poznámka k dodávce' },
    { kind: 1, description: 'Bez ceny', quantity: '2', unit: 'ks', unit_price: null, vat_pct: null, total_price: '' },
  ]);
  assert.deepEqual(rows[0], {
    key: 0, pos: 1, isText: true, description: 'Poznámka k dodávce',
    quantity: null, unit: null, unitPrice: null, vat: null, total: null,
  });
  assert.deepEqual(rows[1], {
    key: 1, pos: 2, isText: false, description: 'Bez ceny', quantity: '2', unit: 'ks',
    unitPrice: null, vat: null, total: null,
  });
  assert.deepEqual(detailRows(null), []);
});

test('detailTotals: hodnoty s měnou, zaokrouhlení, rekapitulace', () => {
  const totals = detailTotals(
    [{ vat_pct: '21', base: '1 000,00', tax: '210,00', total: '1 210,00' }],
    { currency: 'CZK', base: '1 000,00', vat: '210,00', amount: '1 210,00', rounding: '-0,40', dom: null },
  );
  assert.deepEqual(totals, {
    recap: [{ key: 0, rate: '21 %', base: '1 000,00', tax: '210,00', total: '1 210,00' }],
    summary: { base: '1 000,00 CZK', vat: '210,00 CZK', rounding: '-0,40 CZK', total: '1 210,00 CZK' },
    domestic: null,
  });
});

test('detailTotals: zaokrouhlení null / prázdné se vynechá, domácí měna', () => {
  const totals = detailTotals([], {
    currency: 'EUR', base: '100,00', vat: '21,00', amount: '121,00', rounding: null,
    dom: { currency: 'CZK', base: '2 500,00', vat: '525,00', amount: '3 025,00' },
  });
  assert.equal(totals.summary.rounding, null);
  assert.equal(totals.domestic, '2 500,00 + 525,00 = 3 025,00 CZK');
  assert.deepEqual(totals.recap, []);
  assert.equal(detailTotals([], { currency: 'CZK', base: '1', vat: '0', amount: '1', rounding: '' }).summary.rounding, null);
});

test('detailTotals: bez totals jen rekapitulace, prázdná hodnota null', () => {
  const totals = detailTotals([{ vat_pct: '0', base: '50,00', tax: null, total: '50,00' }], null);
  assert.deepEqual(totals, {
    recap: [{ key: 0, rate: '0 %', base: '50,00', tax: null, total: '50,00' }],
    summary: null,
    domestic: null,
  });
  assert.equal(detailTotals([], { currency: '', base: null, vat: '0,00', amount: '0,00' }).summary.base, null);
  assert.equal(detailTotals([], { currency: '', base: '1,00', vat: '0,00', amount: '1,00' }).summary.base, '1,00');
});

test('rowMetaLine: plný řádek', () => {
  assert.equal(
    rowMetaLine({ isText: false, quantity: '41,37', unit: 'l', unitPrice: '36,20', vat: '21 %' }),
    '41,37 l × 36,20 · 21 %',
  );
});

test('rowMetaLine: bez ceny, bez jednotky, bez sazby', () => {
  assert.equal(rowMetaLine({ isText: false, quantity: '2', unit: 'ks', unitPrice: null, vat: '21 %' }), '2 ks · 21 %');
  assert.equal(rowMetaLine({ isText: false, quantity: '2', unit: null, unitPrice: '100,00', vat: '21 %' }), '2 × 100,00 · 21 %');
  assert.equal(rowMetaLine({ isText: false, quantity: '2', unit: 'ks', unitPrice: '100,00', vat: null }), '2 ks × 100,00');
  assert.equal(rowMetaLine({ isText: false, quantity: null, unit: null, unitPrice: '100,00', vat: null }), '100,00');
});

test('rowMetaLine: textový řádek nebo nic je prázdný řetězec', () => {
  assert.equal(rowMetaLine({ isText: true, quantity: '2', unit: 'ks', unitPrice: '1', vat: '21 %' }), '');
  assert.equal(rowMetaLine({ isText: false, quantity: null, unit: null, unitPrice: null, vat: null }), '');
  assert.equal(rowMetaLine(null), '');
});
