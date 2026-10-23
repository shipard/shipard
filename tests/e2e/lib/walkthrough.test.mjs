import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
  readEnv, messageNdxFromCardId, traceFileName, parsePendingPaths, isUndecided,
  refPathFromTestId, CardFailure, WalkthroughState, summarize,
} from './walkthrough.mjs';

const okEnv = {
  SHPD_E2E_APP_URL: 'http://127.0.0.1/abcd-abcd-abcd-abcd/app',
  SHPD_E2E_STORAGE_STATE: '/run/x/storage.json',
  SHPD_E2E_OUTPUT_DIR: '/run/x/out',
  SHPD_E2E_EXPECTED_CARDS: '16',
};

test('readEnv: povinné proměnné a lomítko na konci adresy', () => {
  const env = readEnv(okEnv);
  assert.equal(env.appUrl, 'http://127.0.0.1/abcd-abcd-abcd-abcd/app/');
  assert.equal(env.expectedCards, 16);
  assert.equal(env.progressFile, null);
  assert.equal(readEnv({ ...okEnv, SHPD_E2E_PROGRESS_FILE: ' /p.jsonl ' }).progressFile, '/p.jsonl');
});

test('readEnv: chybějící proměnná jmenuje, co chybí', () => {
  for (const name of ['SHPD_E2E_APP_URL', 'SHPD_E2E_STORAGE_STATE', 'SHPD_E2E_EXPECTED_CARDS']) {
    const env = { ...okEnv };
    delete env[name];
    assert.throws(() => readEnv(env), new RegExp(name));
  }
  assert.throws(() => readEnv({ ...okEnv, SHPD_E2E_EXPECTED_CARDS: 'x' }), /EXPECTED_CARDS/);
  assert.throws(() => readEnv({ ...okEnv, SHPD_E2E_APP_URL: '/app/' }), /APP_URL/);
  const noOut = { ...okEnv };
  delete noOut.SHPD_E2E_OUTPUT_DIR;
  assert.throws(() => readEnv(noOut), /OUTPUT_DIR/);
  assert.equal(readEnv(noOut, { defaultOutputDir: '/d' }).outputDir, '/d');
});

test('id karty, cesta reference, název trace', () => {
  assert.equal(messageNdxFromCardId('mail_suggestion:17'), 17);
  assert.equal(messageNdxFromCardId('mail_attention:17'), null);
  assert.equal(messageNdxFromCardId('mail_suggestion:x'), null);
  assert.equal(traceFileName('mail_suggestion:17'), 'trace-mail_suggestion_17.zip');
  assert.equal(traceFileName('::'), 'trace-card.zip');
  assert.equal(refPathFromTestId('review-ref:rows[3].item'), 'rows[3].item');
  assert.equal(refPathFromTestId('review-ref-create:supplier'), null);
});

test('parsePendingPaths a isUndecided', () => {
  assert.deepEqual(parsePendingPaths('["supplier","rows[0].item"]'), ['supplier', 'rows[0].item']);
  assert.deepEqual(parsePendingPaths('[1,"a"]'), ['a']);
  assert.deepEqual(parsePendingPaths('nic'), []);
  assert.deepEqual(parsePendingPaths(null), []);
  assert.equal(isUndecided('canCreate'), true);
  assert.equal(isUndecided('matchedDecided'), false);
  assert.equal(isUndecided(null), false);
});

test('CardFailure: z cizí výjimky pozná timeout', () => {
  const f = CardFailure.from(new Error('Timeout 15000ms exceeded.\nCall log…'), 'open_review');
  assert.equal(f.step, 'open_review');
  assert.equal(f.reason, 'timeout');
  assert.deepEqual(f.toJSON(), { step: 'open_review', reason: 'timeout', message: 'Timeout 15000ms exceeded.' });
  const own = new CardFailure('decide', 'no_create_offered', { path: 'rows[2].vatCode' });
  assert.equal(CardFailure.from(own), own);
  assert.equal(own.message, 'decide/no_create_offered (rows[2].vatCode)');
});

test('WalkthroughState: karty, průběh, walkthrough.json', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'shpd-e2e-test-'));
  const progressFile = path.join(dir, 'progress.jsonl');
  let tick = 0;
  const state = new WalkthroughState({ expected: 2, outputDir: dir, progressFile, now: () => new Date(1_700_000_000_000 + 1000 * tick++) });

  state.begin('mail_suggestion:5');
  state.browserError('console', 'boom');
  state.apiResponse(404, 'GET', 'http://x/_ui/lookup');
  state.applied_(42);
  state.begin('mail_suggestion:6');
  state.dialog('alert', 'Akce se nezdařila');
  assert.equal(state.lastDialogMessage(), 'Akce se nezdařila');
  state.fail(new CardFailure('apply', 'rejected:conflict', { path: null }), 'trace-mail_suggestion_6.zip');
  state.finish(0);
  const file = state.write();

  const json = JSON.parse(fs.readFileSync(file, 'utf8'));
  assert.equal(json.expected, 2);
  assert.equal(json.applied.length, 1);
  assert.equal(json.applied[0].savedDocId, 42);
  assert.equal(json.browserErrors[0].cardId, 'mail_suggestion:5');
  assert.equal(json.failed[0].reason, 'rejected:conflict');
  assert.equal(json.failed[0].trace, 'trace-mail_suggestion_6.zip');
  assert.equal(json.complete, true);
  assert.equal(json.current, null);
  assert.deepEqual([...state.processed], ['mail_suggestion:5', 'mail_suggestion:6']);

  const lines = fs.readFileSync(progressFile, 'utf8').trim().split('\n').map((l) => JSON.parse(l));
  assert.equal(lines.length, 2);
  assert.equal(lines[0].event, 'card');
  assert.equal(lines[0].status, 'applied');
  assert.equal(lines[1].status, 'failed');
  assert.equal(lines[1].done, 2);
  assert.equal(lines[1].total, 2);
  assert.match(lines[0].at, /^2023-/);

  assert.deepEqual(summarize(state), { expected: 2, applied: 1, failed: 1, browserErrors: 1, serverErrors: 0, ok: false });
  fs.rmSync(dir, { recursive: true });
});
