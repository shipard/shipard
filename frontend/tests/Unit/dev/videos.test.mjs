import { test } from 'node:test';
import assert from 'node:assert/strict';
import { use } from '../../../src/dev/i18n/index.js';
import { blockerText, checkState, datasetsOf, formatBytes, formatDuration, videoStepLabel, videoStatusLabel, VIDEO_STEPS } from '../../../src/dev/videos/videos.js';

// Popisky se testují česky; v Node je navigator.language en-US.
use('cs');

test('formát velikosti a délky videa', () => {
  assert.equal(formatBytes(1907790), '1.8 MiB');
  assert.equal(formatBytes(524288), '512 KiB');
  assert.equal(formatBytes(12), '12 B');
  assert.equal(formatBytes(null), '—');
  assert.equal(formatDuration(17981), '0:18');
  assert.equal(formatDuration(65400), '1:05');
  assert.equal(formatDuration(undefined), '—');
});

test('popisky kroků a stavů ze slovníku, kroky v pořadí běhu', () => {
  assert.deepEqual(VIDEO_STEPS, ['build', 'reseed', 'check', 'record', 'compose']);
  assert.equal(videoStepLabel('build'), 'sestavení sady');
  assert.equal(videoStepLabel('compose'), 'skládání');
  assert.equal(videoStepLabel('unknown'), 'unknown');
  assert.equal(videoStatusLabel('failed'), 'nálezy');
  assert.deepEqual(checkState({ status: 'ok', at: 'x' }), { cls: 'ok', label: 'v pořádku' });
  assert.deepEqual(checkState({ status: 'failed' }), { cls: 'bad', label: 'nálezy' });
  assert.deepEqual(checkState({ status: 'cancelled' }), { cls: 'warn', label: 'zrušeno' });
  assert.equal(checkState(null), null);
});

test('důvod neaktivní akce v pořadí nástroje → scénář → sada → zdroj dat → úloha', () => {
  const row = { id: 's1', dataset: 'it-servis', datasetKnown: true, dataSource: { id: 'aaaa' }, activeJob: null, problem: null };
  assert.equal(blockerText(row, ['chybí ffmpeg', 'chybí node']), 'chybí ffmpeg chybí node');
  assert.equal(blockerText({ ...row, problem: 'chybí dataset' }, []), 'chybí dataset');
  assert.equal(blockerText({ ...row, datasetKnown: false }, []), 'sada it-servis není mezi demo sadami v demo/datasets/');
  assert.equal(blockerText({ ...row, dataSource: null }, []), 'sada it-servis nemá vyhrazený zdroj dat pro videa — založ ho výše');
  assert.equal(blockerText({ ...row, activeJob: { id: 'j1' } }, []), 'běžící úloha j1');
  assert.equal(blockerText(row, []), null);
  assert.equal(blockerText(row), null);
});

test('zdroje dat per sada ze seznamu scénářů — každá sada jednou', () => {
  const rows = [
    { id: 's1', dataset: 'it-servis', datasetTitle: 'Demo — IT servis', datasetKnown: true, dataSource: { id: 'aaaa' }, activeJob: null },
    { id: 's2', dataset: 'it-servis', datasetKnown: true, dataSource: { id: 'aaaa' }, activeJob: { id: 'j1' } },
    { id: 'broken', dataset: null },
    { id: 's3', dataset: 'other', datasetKnown: false, dataSource: null },
  ];
  assert.deepEqual(datasetsOf(rows), [
    { name: 'it-servis', title: 'Demo — IT servis', known: true, dataSource: { id: 'aaaa' }, activeJob: null },
    { name: 'other', title: null, known: false, dataSource: null, activeJob: null },
  ]);
  assert.deepEqual(datasetsOf(undefined), []);
});
