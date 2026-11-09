import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  counterparty,
  effectiveRowVat,
  formatAddress,
  formatDate,
  formatMoney,
  issueNotice,
  registryHeader,
  reviewHeader,
  reviewParty,
  reviewRows,
  reviewTotals,
} from '../../src/components/exchange/reviewParts.js';

// Identita s parametry — klíč s parametrem vrátí „klíč{json}“, ať je v testu vidět.
const t = (key, params) => (params ? `${key}${JSON.stringify(params)}` : key);

test('counterparty: podle selfParty, bez něj null', () => {
  const doc = { selfParty: 'customer', supplier: { name: 'Dodavatel' }, customer: { name: 'My' } };
  assert.equal(counterparty(doc).name, 'Dodavatel');
  assert.equal(counterparty({ ...doc, selfParty: 'supplier' }).name, 'My');
  assert.equal(counterparty({ ...doc, selfParty: null }), null);
  assert.equal(counterparty({ ...doc, selfParty: undefined }), null);
  assert.equal(counterparty(null), null);
});

test('reviewHeader: titulek protistrana, podtitul číslo · text, dva štítky', () => {
  const header = reviewHeader(
    { selfParty: 'customer', supplier: { name: 'Energie Jiskra a.s.' }, docNumber: '2026071253', docText: 'Elektřina 9/2026', docType: 'invoiceReceived' },
    { status: 'ok' },
    t,
  );
  assert.deepEqual(header, {
    title: 'Energie Jiskra a.s.',
    subtitle: '2026071253 · Elektřina 9/2026',
    badges: [
      { label: 'exchange.preview.docType.invoiceReceived', style: 'primary' },
      { label: 'exchange.preview.status.summary.ok', style: 'success' },
    ],
  });
});

test('reviewHeader: bez protistrany číslo v titulku a jen text v podtitulku; bez čísla; bez textu', () => {
  const base = { docType: 'other', docNumber: 'FV-1', docText: 'Text' };
  assert.equal(reviewHeader(base, null, t).title, 'FV-1');
  assert.equal(reviewHeader(base, null, t).subtitle, 'Text');
  assert.equal(reviewHeader({ ...base, docText: null }, null, t).subtitle, '');
  assert.equal(reviewHeader({ ...base, docNumber: null, docText: null }, null, t).title, '—');
  const withParty = { ...base, selfParty: 'supplier', customer: { name: 'Odběratel' }, docNumber: null };
  assert.equal(reviewHeader(withParty, null, t).subtitle, 'Text');
  assert.equal(reviewHeader({ ...withParty, docText: null }, null, t).subtitle, '');
});

test('reviewHeader: štítek stavu jen se summary, needsAttention = warning; bez docType jen stav', () => {
  const doc = { docType: 'invoiceIssued', docNumber: '1' };
  assert.deepEqual(reviewHeader(doc, null, t).badges, [{ label: 'exchange.preview.docType.invoiceIssued', style: 'primary' }]);
  assert.deepEqual(reviewHeader(doc, { status: 'needsAttention' }, t).badges[1], {
    label: 'exchange.preview.status.summary.needsAttention',
    style: 'warning',
  });
  assert.deepEqual(reviewHeader({ docNumber: '1' }, { status: 'ok' }, t).badges, [
    { label: 'exchange.preview.status.summary.ok', style: 'success' },
  ]);
});

test('registryHeader: titulek, podtitul protistrana · IČO, štítek Spisovna · druh', () => {
  // Druh se překládá (`registry.docKind.contract` → „Smlouva“); identita by spustila surový fallback.
  const tt = (key, params) => (key === 'registry.docKind.contract' ? 'Smlouva' : t(key, params));
  const header = registryHeader({ docType: 'contract', title: 'Smlouva o dílo', party: { name: 'Firma', companyId: '12345678' } }, tt);
  assert.deepEqual(header, {
    title: 'Smlouva o dílo',
    subtitle: 'Firma · registry.preview.companyId 12345678',
    badges: [{ label: 'registry.preview.kindBadge{"kind":"Smlouva"}', style: 'primary' }],
  });
});

test('registryHeader: neznámý druh surově, bez protistrany, bez IČO, bez názvu', () => {
  const tt = (key, params) => (key === 'registry.docKind.weird' ? key : t(key, params));
  assert.equal(registryHeader({ docType: 'weird', title: 'X' }, tt).badges[0].label, 'registry.preview.kindBadge{"kind":"weird"}');
  assert.equal(registryHeader({ docType: 'contract', title: 'X' }, t).subtitle, '');
  assert.equal(registryHeader({ docType: 'contract', title: 'X', party: { name: 'Firma' } }, t).subtitle, 'Firma');
  assert.equal(registryHeader({ docType: 'contract' }, t).title, '—');
  assert.deepEqual(registryHeader({ title: 'X' }, t).badges, []);
});

test('reviewParty: kontaktní osoba první mezi kontakty, účet z IBAN před číslem', () => {
  const party = reviewParty({
    name: 'Firma',
    companyId: '12345678',
    taxId: 'CZ12345678',
    vatId: 'CZ12345678',
    address: { displayLine: 'Dlouhá 5, Praha' },
    bankAccount: { iban: 'CZ65 0800 0000 1920 0014 5399', accountNumber: '123/0100' },
    contact: { name: 'Jan Novák', email: 'jan@example.test', phone: null },
  }, t);
  assert.deepEqual(party, {
    name: 'Firma',
    ids: [
      { label: 'document.party.companyId', value: '12345678' },
      { label: 'document.party.taxId', value: 'CZ12345678' },
    ],
    address: 'Dlouhá 5, Praha',
    bank: { label: 'document.party.bankAccount', value: 'CZ65 0800 0000 1920 0014 5399' },
    contacts: ['exchange.preview.field.contactName: Jan Novák', 'jan@example.test'],
  });
  assert.equal(reviewParty({ name: 'F', bankAccount: { accountNumber: '123/0100' } }, t).bank.value, '123/0100');
  assert.equal(reviewParty({ name: 'F' }, t).bank, null);
  assert.equal(reviewParty(null, t), null);
});

test('formatAddress / formatDate / formatMoney: prázdné je null', () => {
  assert.equal(formatAddress(null), null);
  assert.equal(formatAddress({ street: 'Dlouhá', houseNumber: '5', zip: '110 00', city: 'Praha' }), 'Dlouhá 5, 110 00 Praha');
  assert.equal(formatAddress({}), null);
  assert.equal(formatDate(null), null);
  assert.equal(formatDate('2026-10-12'), '12. 10. 2026');
  assert.equal(formatMoney(null, 'CZK'), null);
  assert.equal(formatMoney('', 'CZK'), null);
  assert.equal(typeof formatMoney(1234.5, 'CZK'), 'string');
  assert.match(formatMoney(1234.5, 'CZK'), /1.234,50/);
});

test('effectiveRowVat: jen napárovaný kód', () => {
  assert.deepEqual(effectiveRowVat({ status: 'matched', createPayload: { code: 'cz-217', pct: 21 } }), { code: 'cz-217', pct: 21 });
  assert.equal(effectiveRowVat({ status: 'canCreate' }), null);
  assert.equal(effectiveRowVat(null), null);
});

test('reviewRows: text řádku ze serveru, ceny z přepočtu, sazba z kódu, extra', () => {
  const canonical = {
    currency: 'CZK',
    rows: [
      { orderPos: 1, item: { name: 'Elektřina', supplierCode: 'EL-1' }, quantity: 0.672, unit: 'MWh', unitPrice: 3190, totalPrice: 2143.68, vat: { pct: 0 } },
      { rowKind: 'text', item: { name: 'Poznámka' } },
    ],
  };
  const resolve = { rows: [{ rowText: 'Silová elektřina', vatCode: { status: 'matched', createPayload: { code: 'cz-217', pct: 21 } } }, {}] };
  const prices = new Map([[0, { unitPrice: 3200, totalPrice: 2150.4 }]]);
  const rows = reviewRows(canonical, resolve, prices);
  assert.equal(rows.length, 2);
  assert.equal(rows[0].key, 0);
  assert.equal(rows[0].index, 0);
  assert.equal(rows[0].pos, 1);
  assert.equal(rows[0].isText, false);
  assert.equal(rows[0].description, 'Silová elektřina');
  assert.equal(rows[0].quantity, '0.672');
  assert.equal(rows[0].unit, 'MWh');
  assert.match(rows[0].unitPrice, /3.200,00/);
  assert.match(rows[0].total, /2.150,40/);
  assert.equal(rows[0].vat, '21 %');
  assert.equal(rows[0].supplierCode, 'EL-1');
  assert.equal(rows[0].vatCode, 'cz-217');
  assert.deepEqual(rows[1], {
    key: 1, index: 1, pos: 2, isText: true, description: 'Poznámka',
    quantity: null, unit: null, unitPrice: null, vat: null, total: null, supplierCode: null, vatCode: null,
  });
});

test('reviewRows: bez resolve název položky a ceny z canonicalu, bez ničeho „—“', () => {
  const rows = reviewRows({ currency: 'EUR', rows: [{ item: { name: 'Věc' }, unitPrice: 10, totalPrice: 10, vat: { pct: 21 } }, {}] }, null, null);
  assert.equal(rows[0].description, 'Věc');
  assert.match(rows[0].unitPrice, /10,00/);
  assert.equal(rows[0].vat, '21 %');
  assert.equal(rows[1].description, '—');
  assert.equal(rows[1].vat, null);
  assert.deepEqual(reviewRows(null, null, null), []);
});

test('reviewTotals: rekapitulace s kódem a dvojicí, souhrn, zaokrouhlení jen nenulové', () => {
  const totals = reviewTotals(
    [{ vatPct: 21, base: 100, tax: 21, total: 121, vatCode: 'cz-217' }, { vatPct: 21, base: -100, tax: -21, total: -121, vatCode: 'cz-218', isReversePair: true }],
    { totalBase: 100, totalVat: 21, totalAmount: 121, totalRounding: 0 },
    'CZK',
  );
  assert.equal(totals.recap.length, 2);
  assert.equal(totals.recap[0].rate, '21 %');
  assert.equal(totals.recap[0].vatCode, 'cz-217');
  assert.equal(totals.recap[0].isReversePair, false);
  assert.equal(totals.recap[1].isReversePair, true);
  assert.match(totals.summary.total, /121,00/);
  assert.equal(totals.summary.rounding, null);
  assert.equal(totals.domestic, null);
  assert.match(reviewTotals([], { totalBase: 1, totalVat: 0, totalAmount: 1, totalRounding: -0.4 }, 'CZK').summary.rounding, /0,40/);
  assert.deepEqual(reviewTotals(null, null, 'CZK'), { recap: [], summary: null, domestic: null });
});

test('issueNotice: tón podle nejvyšší závažnosti, položky od nejzávažnější, pořadí stabilní', () => {
  const notice = issueNotice([
    { severity: 'info', message: 'I1', path: 'a' },
    { severity: 'warning', message: 'W1', path: 'b' },
    { severity: 'error', message: 'E1', path: 'rows[2].vatCode' },
    { severity: 'warning', message: 'W2', path: 'c' },
  ]);
  assert.equal(notice.tone, 'danger');
  assert.deepEqual(notice.items.map((i) => i.text), ['E1', 'W1', 'W2', 'I1']);
  assert.deepEqual(notice.items[0], { text: 'E1', meta: 'rows[2].vatCode' });
  assert.equal(issueNotice([{ severity: 'warning', message: 'W' }]).tone, 'warning');
  assert.equal(issueNotice([{ severity: 'info', message: 'I' }]).tone, 'info');
  assert.equal(issueNotice([{ message: 'bez závažnosti' }]).tone, 'info');
  assert.deepEqual(issueNotice([]), { tone: 'info', items: [] });
  assert.deepEqual(issueNotice(null).items, []);
});
