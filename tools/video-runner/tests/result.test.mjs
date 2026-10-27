import assert from 'node:assert/strict';
import { access, mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, test } from 'node:test';

import { UserError } from '../src/errors.mjs';
import { buildResult, RESULT_NAME, withResult, writeResult } from '../src/result.mjs';

const root = await mkdtemp(join(tmpdir(), 'video-runner-result-'));
after(() => rm(root, { recursive: true, force: true }));

let counter = 0;
const freshDir = () => join(root, `s${counter++}`);

const exists = (path) => access(path).then(() => true, () => false);

test('úspěch: ok, doba běhu, bez error a video', () => {
  const result = buildResult({ verb: 'check', scenarioId: 's1', startedAt: 1_000, now: 3_500 });
  assert.deepEqual(result, { verb: 'check', scenario: 's1', status: 'ok', durationMs: 2500 });
  assert.equal('error' in result, false);
  assert.equal('video' in result, false);
});

test('úspěch s videem nese absolutní cestu', () => {
  const result = buildResult({
    verb: 'build', scenarioId: 's1', startedAt: 0, now: 10,
    video: { path: '/opt/videos/s1.mp4' },
  });
  assert.equal(result.status, 'ok');
  assert.deepEqual(result.video, { path: '/opt/videos/s1.mp4' });
});

test('selhání: hláška, hint a krok z UserError', () => {
  const error = new UserError(
    'krok #3 (click @feed-card): prvek se do 15 s neobjevil.',
    'Sedí selektor?',
    { step: { index: 3, verb: 'click', value: '@feed-card' } },
  );
  const result = buildResult({ verb: 'check', scenarioId: 's1', startedAt: 0, now: 42, error });
  assert.deepEqual(result, {
    verb: 'check',
    scenario: 's1',
    status: 'failed',
    durationMs: 42,
    error: {
      message: 'krok #3 (click @feed-card): prvek se do 15 s neobjevil.',
      hint: 'Sedí selektor?',
      step: { index: 3, verb: 'click', value: '@feed-card' },
    },
  });
});

test('selhání bez hintu a kroku nese jen hlášku; video se při chybě nezapíše', () => {
  const result = buildResult({
    verb: 'compose', scenarioId: 's1', startedAt: 0, now: 1,
    error: new Error('ffmpeg spadl'),
    video: { path: '/x.mp4' },
  });
  assert.deepEqual(result.error, { message: 'ffmpeg spadl' });
  assert.equal('video' in result, false);
});

test('záporná doba se ořízne na nulu', () => {
  const result = buildResult({ verb: 'check', scenarioId: 's1', startedAt: 10, now: 5 });
  assert.equal(result.durationMs, 0);
});

test('writeResult založí adresář a zapíše odsazený JSON', async () => {
  const dir = join(freshDir(), 'nested');
  const result = buildResult({ verb: 'record', scenarioId: 's1', startedAt: 0, now: 1 });

  const path = await writeResult(dir, result);
  assert.equal(path, join(dir, RESULT_NAME));

  const text = await readFile(path, 'utf8');
  assert.deepEqual(JSON.parse(text), result);
  assert.match(text, /^\{\n {2}"verb": "record",\n/);
  assert.ok(text.endsWith('\n'));
});

test('withResult: starý výsledek zmizí před během, nový se zapíše po úspěchu', async () => {
  const dir = freshDir();
  await writeResult(dir, { verb: 'check', scenario: 'old', status: 'failed', durationMs: 1 });

  let seenDuringRun;
  const outcome = await withResult({
    verb: 'compose',
    dir,
    scenarioId: 's1',
    run: async () => {
      seenDuringRun = await exists(join(dir, RESULT_NAME));
      return { videoPath: '/out/s1.mp4', other: 'ignored' };
    },
  });

  assert.equal(seenDuringRun, false);
  assert.deepEqual(outcome, { videoPath: '/out/s1.mp4', other: 'ignored' });

  const written = JSON.parse(await readFile(join(dir, RESULT_NAME), 'utf8'));
  assert.equal(written.verb, 'compose');
  assert.equal(written.scenario, 's1');
  assert.equal(written.status, 'ok');
  assert.deepEqual(written.video, { path: '/out/s1.mp4' });
  assert.equal(typeof written.durationMs, 'number');
});

test('withResult: návrat bez videoPath nedá video', async () => {
  const dir = freshDir();
  await withResult({ verb: 'record', dir, scenarioId: 's1', run: async () => ({ rawPath: '/x' }) });
  const written = JSON.parse(await readFile(join(dir, RESULT_NAME), 'utf8'));
  assert.equal('video' in written, false);

  const dir2 = freshDir();
  await withResult({ verb: 'check', dir: dir2, scenarioId: 's1', run: async () => undefined });
  const written2 = JSON.parse(await readFile(join(dir2, RESULT_NAME), 'utf8'));
  assert.equal(written2.status, 'ok');
});

test('withResult: chyba se zapíše a pustí dál', async () => {
  const dir = freshDir();
  const error = new UserError('krok #2 (waitFor @x): prvek se neobjevil.', 'hint', {
    step: { index: 2, verb: 'waitFor', value: '@x' },
  });

  await assert.rejects(
    withResult({ verb: 'check', dir, scenarioId: 's1', run: async () => { throw error; } }),
    (thrown) => thrown === error,
  );

  const written = JSON.parse(await readFile(join(dir, RESULT_NAME), 'utf8'));
  assert.equal(written.status, 'failed');
  assert.equal(written.error.message, 'krok #2 (waitFor @x): prvek se neobjevil.');
  assert.equal(written.error.hint, 'hint');
  assert.deepEqual(written.error.step, { index: 2, verb: 'waitFor', value: '@x' });
});
