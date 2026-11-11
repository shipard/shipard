import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createdDocId, errorText } from '../../src/components/viewer/detailActionResult.js';

const translate = (error) => `[${error?.code ?? 'NETWORK_ERROR'}] ${error?.message ?? ''}`.trim();

test('createdDocId: id konceptu jen z úspěšné odpovědi s kladným číslem', () => {
  assert.equal(createdDocId({ success: true, data: { docId: 123 } }), 123);
  assert.equal(createdDocId({ success: true, data: {} }), null);
  assert.equal(createdDocId({ success: true, data: { docId: '123' } }), null);
  assert.equal(createdDocId({ success: true, data: { docId: 0 } }), null);
  assert.equal(createdDocId({ success: false, error: { code: 'NOT_FOUND' } }), null);
  assert.equal(createdDocId(null), null);
  assert.equal(createdDocId(undefined), null);
});

test('errorText: hláška bez detailů', () => {
  const result = { success: false, error: { code: 'CORRECTIVE_SOURCE_INVALID', message: 'jen V pořádku' } };
  assert.equal(errorText(result, translate), '[CORRECTIVE_SOURCE_INVALID] jen V pořádku');
});

test('errorText: odrážky z hlášek applieru, prázdné a cizí tvary se přeskočí', () => {
  const result = {
    success: false,
    error: {
      code: 'CORRECTIVE_APPLY_FAILED',
      message: 'Doklad neprošel validací.',
      details: [
        { field: 'customer', code: 'required', message: 'U vydané faktury je odběratel povinný.' },
        { field: 'rows.0.vat.code', code: 'vat_code_unknown', message: '' },
        null,
        { field: 'x', code: 'y' },
      ],
    },
  };
  assert.equal(
    errorText(result, translate),
    '[CORRECTIVE_APPLY_FAILED] Doklad neprošel validací.\n\n• U vydané faktury je odběratel povinný.',
  );
});

test('errorText: výpadek sítě bez obálky', () => {
  assert.equal(errorText(null, translate), '[NETWORK_ERROR]');
});
