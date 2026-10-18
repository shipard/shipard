import { test } from 'node:test';
import assert from 'node:assert/strict';
import { use } from '../../../src/dev/i18n/index.js';
import { chipKind, statusKind, headMatches, daysSince, minutesSince, isOlderThanDays, blockerText, checkErrorText, versionAriaLabel } from '../../../src/dev/updates.js';

// Popisky se testují česky; v Node je navigator.language en-US.
use('cs');

const NOW = Date.parse('2026-10-17T12:00:00Z');
const HEAD = 'a'.repeat(40);

function status(overrides = {}, stateOverrides = {}) {
  return {
    state: { ok: true, behind: 3, ahead: 0, head: { hash: HEAD, branch: 'stable' }, oldestMissingAt: '2026-10-15T08:00:00Z', checkedAt: '2026-10-17T11:50:00Z', ...stateOverrides },
    stale: false,
    checking: false,
    head: { hash: HEAD, branch: 'stable' },
    blockers: [],
    canUpdate: true,
    ...overrides,
  };
}

test('druh štítku: pozadu / aktuální / nelze ověřit / neznámý stav', () => {
  assert.equal(chipKind(status(), null), 'behind');
  assert.equal(chipKind(status({}, { behind: 0 }), null), null, 'aktuální = bez štítku');
  assert.equal(chipKind(status({}, { ok: false, behind: null }), null), 'unverified');
  assert.equal(chipKind(null, null), null, 'před prvním načtením nic');
  assert.equal(chipKind({ state: null, stale: true, checking: true }, null), null, 'kontrola běží, stav neznámý');
});

test('stav pro bublinu: aktuální / pozadu / nelze ověřit / neznámý (D24)', () => {
  assert.equal(statusKind(status({}, { behind: 0 })), 'current');
  assert.equal(statusKind(status()), 'behind');
  assert.equal(statusKind(status({}, { ok: false, behind: null })), 'unverified');
  assert.equal(statusKind(null), 'unknown', 'před prvním načtením');
  assert.equal(statusKind({ state: null, stale: true, checking: true }), 'unknown', 'kontrola běží, stav neznámý');
  assert.equal(statusKind(status({ head: { hash: 'b'.repeat(40), branch: 'stable' } }, { behind: 0 })), 'unknown', 'stav z cache pro jiný HEAD není „aktuální“');
  assert.equal(statusKind(status({}, { ahead: 2, behind: 0 })), 'current', 'napřed bez chybějících změn = aktuální (pojistka řeší tlačítko)');
});

test('po lokálním commitu čísla z cache neplatí — štítek se neukazuje', () => {
  const moved = status({ head: { hash: 'b'.repeat(40), branch: 'stable' } });
  assert.equal(headMatches(status()), true);
  assert.equal(headMatches(moved), false);
  assert.equal(chipKind(moved, null), null);
  assert.equal(chipKind(status({}, { ahead: 2 }), null), 'behind', 'pozadu i napřed = štítek ano (U5), tlačítko řeší pojistka');
});

test('běžící úloha a výzva k obnovení mají přednost', () => {
  assert.equal(chipKind(status(), { id: 'x', status: 'running' }), 'updating');
  assert.equal(chipKind(status(), { id: 'x', status: 'starting' }), 'updating');
  assert.equal(chipKind(status(), { id: 'x', status: 'finished' }), 'behind', 'doběhlá úloha štítek nemění');
  assert.equal(chipKind(status(), { id: 'x', status: 'running' }, true), 'reload');
  assert.equal(chipKind(null, null, true), 'reload');
});

test('stáří: dny, minuty a práh plného štítku', () => {
  assert.equal(daysSince('2026-10-15T08:00:00Z', NOW), 2);
  assert.equal(daysSince('2026-10-17T11:00:00Z', NOW), 0);
  assert.equal(daysSince('2026-10-18T11:00:00Z', NOW), 0, 'budoucnost = 0');
  assert.equal(daysSince(null, NOW), null);
  assert.equal(daysSince('nonsense', NOW), null);
  assert.equal(minutesSince('2026-10-17T11:50:00Z', NOW), 10);
  assert.equal(isOlderThanDays('2026-10-10T12:00:01Z', NOW), false, 'přesně 7 dní ještě není víc než 7');
  assert.equal(isOlderThanDays('2026-10-09T12:00:00Z', NOW), true);
  assert.equal(isOlderThanDays(null, NOW), false);
});

test('texty pojistek a chyb kontroly', () => {
  assert.equal(blockerText({ code: 'notStable', branch: 'feature' }), 'Checkout je na větvi feature, ne na stable — přepni na stable, nebo aktualizuj ručně.');
  assert.equal(blockerText({ code: 'notStable', branch: null }), 'Checkout je na větvi odpojená HEAD, ne na stable — přepni na stable, nebo aktualizuj ručně.');
  assert.match(blockerText({ code: 'notFastForward', ahead: 2 }), /\(2\)/);
  assert.equal(blockerText({ code: 'jobRunning', jobId: '20261017-115800-aaaaaa', type: 'reseed' }), 'Běží úloha 20261017-115800-aaaaaa (Přeseedování) — počkej na její doběhnutí.');
  assert.match(blockerText({ code: 'missingTool', tools: ['composer', 'npm'] }), /composer, npm/);
  assert.equal(blockerText({ code: 'weird' }), 'Aktualizace je zablokovaná (weird).');
  assert.equal(checkErrorText({ errorCode: 'fetchFailed', error: 'fatal: unable to access' }), 'GitHub nedostupný (fatal: unable to access)');
  assert.equal(checkErrorText({ errorCode: 'timeout' }), 'časový limit kontroly');
  assert.equal(checkErrorText(null), 'kontrola selhala');
});

test('aria-label tlačítka verze s celým stavem (D23)', () => {
  const v = { version: '0.1.1', hash: '6aaa2a47', fullHash: '6aaa2a47' + 'f'.repeat(32), branch: 'stable', commitDate: '2026-10-17T10:00:00Z' };
  assert.equal(versionAriaLabel(v, 'behind', 1), 'Verze 0.1.1, 6aaa2a47, stable — 1 commit pozadu');
  assert.equal(versionAriaLabel(v, 'behind', 3), 'Verze 0.1.1, 6aaa2a47, stable — 3 commity pozadu');
  assert.equal(versionAriaLabel(v, 'unverified'), 'Verze 0.1.1, 6aaa2a47, stable — nelze ověřit');
  assert.equal(versionAriaLabel(v, null), 'Verze 0.1.1, 6aaa2a47, stable', 'aktuální nebo neznámý stav = bez pomlčky');
  assert.equal(versionAriaLabel({ ...v, branch: null }, null), 'Verze 0.1.1, 6aaa2a47', 'odpojená HEAD bez větve');
  assert.equal(versionAriaLabel({ version: '0.1.1', hash: null, branch: null }, null), 'Verze 0.1.1', 'bez gitu jen číslo verze');
  assert.equal(versionAriaLabel(null, null), 'Verze a stav aktualizace', 'před načtením /_dev/api/info');
});
