import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mobileKebabActions } from '../../src/components/exchange/reviewFooter.js';
import cs from '../../src/i18n/cs.js';

const ready = { isRegistry: false, inQueue: false, canApply: true, hasData: true, loading: false };
const ids = (state) => mobileKebabActions(state).map((a) => a.id);

test('mobileKebabActions: doklad mimo frontu — Vystavit koncept a Zamítnout', () => {
  assert.deepEqual(ids(ready), ['applyDraft', 'reject']);
});

test('mobileKebabActions: ve frontě přibude Přeskočit na konci', () => {
  assert.deepEqual(ids({ ...ready, inQueue: true }), ['applyDraft', 'reject', 'skip']);
});

test('mobileKebabActions: Spisovna nemá Vystavit koncept', () => {
  assert.deepEqual(ids({ ...ready, isRegistry: true }), ['reject']);
  assert.deepEqual(ids({ ...ready, isRegistry: true, inQueue: true }), ['reject', 'skip']);
});

test('mobileKebabActions: destruktivní je jen Zamítnout', () => {
  const danger = mobileKebabActions({ ...ready, inQueue: true }).filter((a) => a.danger).map((a) => a.id);
  assert.deepEqual(danger, ['reject']);
});

test('mobileKebabActions: testid jako tlačítka na desktopu', () => {
  const testids = Object.fromEntries(mobileKebabActions({ ...ready, inQueue: true }).map((a) => [a.id, a.testid]));
  assert.deepEqual(testids, { applyDraft: 'review-apply-draft', reject: 'review-reject', skip: 'review-skip' });
});

test('mobileKebabActions: neaktivní akce zůstává ve výčtu', () => {
  const byId = (state) => Object.fromEntries(mobileKebabActions(state).map((a) => [a.id, a.disabled]));
  // Náhled se načítá: nic použít nejde, přeskočit také ne.
  assert.deepEqual(
    byId({ isRegistry: false, inQueue: true, canApply: false, hasData: false, loading: true }),
    { applyDraft: true, reject: true, skip: true },
  );
  // Načteno, čeká na rozhodnutí: koncept ne, zamítnout a přeskočit ano.
  assert.deepEqual(
    byId({ ...ready, inQueue: true, canApply: false }),
    { applyDraft: true, reject: false, skip: false },
  );
  assert.deepEqual(byId({ ...ready, inQueue: true }), { applyDraft: false, reject: false, skip: false });
});

test('mobileKebabActions: popisky jsou ve slovníku', () => {
  for (const action of mobileKebabActions({ ...ready, inQueue: true })) {
    assert.equal(typeof cs[action.labelKey], 'string', action.labelKey);
  }
});
