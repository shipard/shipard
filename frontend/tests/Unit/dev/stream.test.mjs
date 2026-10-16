import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseStepLines, classifyLine } from '../../../src/dev/stream.js';

test('rozpozná [STEP], [ERROR], [DONE] s JSON i prostý text', () => {
  assert.deepEqual(classifyLine('[STEP] Creating data source...'), { kind: 'step', text: 'Creating data source...' });
  assert.deepEqual(classifyLine('[ERROR] ds-create failed (exit 1)'), { kind: 'error', text: 'ds-create failed (exit 1)' });
  assert.deepEqual(classifyLine('[DONE] {"id":"abcd-1234-efgh-5678","url":"/abcd-1234-efgh-5678/app/"}'), {
    kind: 'done',
    text: '{"id":"abcd-1234-efgh-5678","url":"/abcd-1234-efgh-5678/app/"}',
    payload: { id: 'abcd-1234-efgh-5678', url: '/abcd-1234-efgh-5678/app/' },
  });
  assert.deepEqual(classifyLine('  Directory: /opt/x'), { kind: 'text', text: '  Directory: /opt/x' });
});

test('[DONE] s nevalidním nebo prázdným JSON má payload null', () => {
  assert.equal(classifyLine('[DONE] not json').payload, null);
  assert.equal(classifyLine('[DONE]').payload, null);
  assert.equal(classifyLine('[DONE]').kind, 'done');
});

test('parseStepLines vrátí úplné řádky, vynechá prázdné a nechá zbytek', () => {
  const { lines, rest } = parseStepLines('[STEP] a\n\nplain\r\n[DONE] {"message":"ok"}\n[ERR');
  assert.deepEqual(lines.map((l) => l.kind), ['step', 'text', 'done']);
  assert.equal(lines[1].text, 'plain');
  assert.deepEqual(lines[2].payload, { message: 'ok' });
  assert.equal(rest, '[ERR');
});

test('řádek rozdělený hranicí chunku se dokončí dalším chunkem', () => {
  let { lines, rest } = parseStepLines('[STEP] Runn');
  assert.equal(lines.length, 0);
  ({ lines, rest } = parseStepLines(rest + 'ing ds-upgrade...\n'));
  assert.deepEqual(lines, [{ kind: 'step', text: 'Running ds-upgrade...' }]);
  assert.equal(rest, '');
});
