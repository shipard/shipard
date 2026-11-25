import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  clientErrors, dialogFields, errorText, initialValues, isInvalid, localDate, localDateTime,
  requestBody, requestValues, responseErrors, resultLevel,
} from '../../src/components/viewer/recordActionRequest.js';

// Dialog se všemi typy polí (#172 D13) — stejný jako fixtura backendu.
const FIELDS = [
  { id: 'info', type: 'notice', text: 'Nahradí stávající certifikát.' },
  { id: 'title', type: 'text', label: 'Název', required: true, default: 'Hlavní' },
  { id: 'note', type: 'textarea', label: 'Poznámka', required: false },
  { id: 'password', type: 'password', label: 'Heslo', required: true },
  { id: 'copies', type: 'number', label: 'Kopie', required: false, default: 1, min: 1, max: 5 },
  { id: 'validFrom', type: 'date', label: 'Platí od', required: false, default: 'now' },
  { id: 'paidAt', type: 'datetime', label: 'Čas platby', required: true, default: 'now' },
  { id: 'notify', type: 'checkbox', label: 'Upozornit', required: false, default: true },
  { id: 'mode', type: 'select', label: 'Režim', required: true, default: 'test',
    options: [{ value: 'test', label: 'Test' }, { value: 2, label: 'Ostrý' }] },
  { id: 'certificate', type: 'file', label: 'Certifikát', required: true, accept: '.p12', maxBytes: 2048 },
];

// 5. 3. 2026 9:07 místního času — jednociferný měsíc, den, hodina i minuta.
const NOW = new Date(2026, 2, 5, 9, 7, 30);

class FakeFormData {
  constructor() { this.parts = []; }
  append(name, value) { this.parts.push([name, value]); }
}

const translate = (code) => `<${code}>`;

test('localDate / localDateTime: místní čas s nulami, bez sekund', () => {
  assert.equal(localDate(NOW), '2026-03-05');
  assert.equal(localDateTime(NOW), '2026-03-05T09:07');
  assert.equal(localDateTime(new Date(2026, 11, 31, 23, 59)), '2026-12-31T23:59');
});

test('dialogFields: pole dialogu, akce bez dialogu žádná', () => {
  assert.deepEqual(dialogFields({ dialog: { fields: FIELDS } }), FIELDS);
  assert.deepEqual(dialogFields({ dialog: null }), []);
  assert.deepEqual(dialogFields({ dialog: {} }), []);
  assert.deepEqual(dialogFields(null), []);
});

test('initialValues: výchozí hodnoty, "now" = teď, soubor a hláška bez hodnoty', () => {
  assert.deepEqual(initialValues(FIELDS, NOW), {
    title: 'Hlavní',
    note: '',
    password: '',
    copies: 1,
    validFrom: '2026-03-05',
    paidAt: '2026-03-05T09:07',
    notify: true,
    mode: 'test',
  });
});

test('initialValues: bez výchozích hodnot prázdné; pevné datum zůstává', () => {
  const fields = [
    { id: 'a', type: 'text' },
    { id: 'b', type: 'number' },
    { id: 'c', type: 'date', default: '2026-01-31' },
    { id: 'd', type: 'datetime' },
    { id: 'e', type: 'checkbox' },
    { id: 'f', type: 'select', options: [] },
    { id: 'g', type: 'password', default: 'nikdy' },
  ];
  assert.deepEqual(initialValues(fields, NOW), { a: '', b: null, c: '2026-01-31', d: '', e: false, f: null, g: '' });
});

test('clientErrors: povinná pole a velikost souboru', () => {
  const values = initialValues(FIELDS, NOW);
  assert.deepEqual(clientErrors(FIELDS, values, {}), { password: 'required', certificate: 'required' });

  const filled = { ...values, password: 'x' };
  assert.deepEqual(clientErrors(FIELDS, filled, { certificate: { size: 2048 } }), {}, 'přesně na limitu projde');
  assert.deepEqual(clientErrors(FIELDS, filled, { certificate: { size: 2049 } }), { certificate: 'file_too_large' });
});

test('clientErrors: text z mezer je prázdný, heslo z mezer ne; checkbox se nevyžaduje', () => {
  const fields = [
    { id: 'title', type: 'text', required: true },
    { id: 'password', type: 'password', required: true },
    { id: 'agree', type: 'checkbox', required: true },
    { id: 'copies', type: 'number', required: true },
    { id: 'mode', type: 'select', required: true },
    { id: 'file', type: 'file', required: false, maxBytes: 10 },
  ];
  assert.deepEqual(
    clientErrors(fields, { title: '   ', password: '   ', agree: false, copies: null, mode: null }, {}),
    { title: 'required', copies: 'required', mode: 'required' },
  );
  assert.deepEqual(clientErrors(fields, { title: 'a', password: 'b', copies: 0, mode: 2 }, {}), {}, 'nula je vyplněná');
});

test('requestValues: jen pole s hodnotou, prázdné jako null, checkbox vždy bool', () => {
  const values = { ...initialValues(FIELDS, NOW), note: '  ', password: ' p ', copies: null, extra: 'pryč' };
  assert.deepEqual(requestValues(FIELDS, values), {
    title: 'Hlavní',
    note: null,
    password: ' p ',
    copies: null,
    validFrom: '2026-03-05',
    paidAt: '2026-03-05T09:07',
    notify: true,
    mode: 'test',
  });
  assert.deepEqual(requestValues([{ id: 'n', type: 'checkbox' }], {}), { n: false });
});

test('requestBody: bez souboru JSON {values}', () => {
  const body = requestBody(FIELDS, initialValues(FIELDS, NOW), {}, FakeFormData);
  assert.ok(!(body instanceof FakeFormData));
  assert.deepEqual(Object.keys(body), ['values']);
  assert.equal(body.values.title, 'Hlavní');
  assert.ok(!('certificate' in body.values), 'soubor mezi hodnoty nepatří');

  assert.deepEqual(requestBody([], {}, {}, FakeFormData), { values: {} }, 'akce bez dialogu');
});

test('requestBody: se souborem multipart — values jako JSON a files[<id>]', () => {
  const file = { name: 'cert.p12', size: 10 };
  const body = requestBody(FIELDS, initialValues(FIELDS, NOW), { certificate: file, other: { size: 1 } }, FakeFormData);

  assert.ok(body instanceof FakeFormData);
  assert.deepEqual(body.parts.map(([name]) => name), ['values', 'files[certificate]']);
  assert.equal(JSON.parse(body.parts[0][1]).paidAt, '2026-03-05T09:07');
  assert.equal(body.parts[1][1], file);
});

test('isInvalid: jen 422 RECORD_ACTION_INVALID', () => {
  assert.equal(isInvalid({ success: false, error: { code: 'RECORD_ACTION_INVALID' } }), true);
  assert.equal(isInvalid({ success: false, error: { code: 'RECORD_ACTION_NOT_AVAILABLE' } }), false);
  assert.equal(isInvalid({ success: true, data: {} }), false);
  assert.equal(isInvalid(null), false);
});

test('responseErrors: chyby k polím, zbytek nad pole', () => {
  const result = {
    success: false,
    error: {
      code: 'RECORD_ACTION_INVALID',
      details: [
        { field: 'title', code: 'required', message: 'This field is required' },
        { field: 'password', code: 'invalid', message: 'Heslo k certifikátu nesedí.' },
        { field: 'password', code: 'required', message: 'druhá chyba téhož pole se nezobrazí' },
        { field: 'certificate', code: 'file_too_large', message: 'The file is too large' },
        { field: '_form', code: 'invalid', message: 'Certifikát nejde uložit.' },
        { field: 'gone', code: 'required', message: 'This field is required' },
        { field: 'info', code: 'invalid', message: 'Chyba u hlášky patří nad pole.' },
        { field: 'mode', code: 'brand_new_code', message: 'Server text' },
        { field: 'copies', code: 'out_of_range' },
        null,
      ],
    },
  };

  assert.deepEqual(responseErrors(result, FIELDS, translate), {
    fields: {
      title: '<required>',
      password: 'Heslo k certifikátu nesedí.',
      certificate: '<file_too_large>',
      mode: 'Server text',
      copies: '<out_of_range>',
    },
    form: ['Certifikát nejde uložit.', '<required>', 'Chyba u hlášky patří nad pole.'],
  });
});

test('responseErrors: bez detailů nic; akce bez dialogu má vše nad poli', () => {
  assert.deepEqual(responseErrors({ success: false, error: { code: 'RECORD_ACTION_INVALID' } }, FIELDS, translate), { fields: {}, form: [] });
  assert.deepEqual(
    responseErrors({ error: { details: [{ field: 'password', code: 'invalid', message: 'Nejde to.' }] } }, [], translate),
    { fields: {}, form: ['Nejde to.'] },
  );
});

test('errorText: překládá jen známé kódy obecné validace', () => {
  assert.equal(errorText('required', translate), '<required>');
  assert.equal(errorText('invalid', translate), null, 'text chyby z akce dává server');
  assert.equal(errorText(undefined, translate), null);
  assert.equal(errorText('required', () => null), null);
});

test('resultLevel: neznámá úroveň je success', () => {
  assert.equal(resultLevel({ level: 'warning' }), 'warning');
  assert.equal(resultLevel({ level: 'info' }), 'info');
  assert.equal(resultLevel({ level: 'danger' }), 'success');
  assert.equal(resultLevel(null), 'success');
});
