import { test } from 'node:test';
import assert from 'node:assert/strict';
import { emptyProgress, reduceProgress, suggestedCap, isActiveStatus, jobStatusClass, stoppedByLabel } from '../../../src/dev/testing/jobs.js';

test('redukce událostí průběhu je append-only a drží posledních 5 volání', () => {
  let s = reduceProgress(emptyProgress(), [
    { event: 'started', calls: 8, maxCostUsd: 0.5 },
    { event: 'call', messageId: 'M1', done: 1, total: 8, spentUsd: 0.02 },
  ]);
  assert.equal(s.total, 8);
  assert.equal(s.maxCostUsd, 0.5);
  assert.equal(s.done, 1);
  assert.equal(s.spentUsd, 0.02);
  assert.equal(s.lastCalls.length, 1);

  const more = [];
  for (let i = 2; i <= 8; i++) more.push({ event: 'call', messageId: `M${i}`, done: i, total: 8, spentUsd: i * 0.02 });
  s = reduceProgress(s, more);
  assert.equal(s.done, 8);
  assert.deepEqual(s.lastCalls.map((c) => c.messageId), ['M4', 'M5', 'M6', 'M7', 'M8']);

  s = reduceProgress(s, [{ event: 'finished', file: '/x/2026-10-16-baseline.json', spentUsd: 0.17, stoppedBy: 'maxCost' }]);
  assert.equal(s.finished.file, '/x/2026-10-16-baseline.json');
  assert.equal(s.spentUsd, 0.17);
  assert.equal(stoppedByLabel(s.finished.stoppedBy), 'zastaveno stropem útraty');
  assert.equal(stoppedByLabel(null), null);

  s = reduceProgress(s, [{ event: 'error', message: 'boom' }, { event: 'unknown' }, null]);
  assert.equal(s.error, 'boom');
});

test('bez `done` v události se počítá průběžně', () => {
  const s = reduceProgress(emptyProgress(), [{ event: 'call' }, { event: 'call' }]);
  assert.equal(s.done, 2);
  assert.equal(s.total, null);
});

test('strop útraty 1,5× odhad, nahoru na cent, nejméně cent', () => {
  assert.equal(suggestedCap(0.1), 0.15);
  assert.equal(suggestedCap(0.0333), 0.05);
  assert.equal(suggestedCap(0.001), 0.01);
  assert.equal(suggestedCap(0), null);
  assert.equal(suggestedCap(null), null);
});

test('stavy úloh', () => {
  assert.equal(isActiveStatus('cancelling'), true);
  assert.equal(isActiveStatus('failed'), false);
  assert.equal(jobStatusClass('finished'), 'ok');
  assert.equal(jobStatusClass('failed'), 'bad');
  assert.equal(jobStatusClass('cancelled'), 'warn');
  assert.equal(jobStatusClass('running'), 'neutral');
});
