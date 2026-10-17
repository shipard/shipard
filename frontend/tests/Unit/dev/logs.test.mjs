import { test } from 'node:test';
import assert from 'node:assert/strict';
import { use } from '../../../src/dev/i18n/index.js';
import { filterEntries, dsOptions, shortDs, DEFAULT_LEVELS, DS_NONE } from '../../../src/dev/logs.js';

// Popisky se testují česky; v Node je navigator.language en-US.
use('cs');

const DS_A = 'aaaa-aaaa-aaaa-aaaa';
const DS_B = 'bbbb-bbbb-bbbb-bbbb';

const entries = [
  { ts: '2026-10-16T10:00:00+00:00', level: 'info', ds: DS_A, msg: 'start' },
  { ts: '2026-10-16T10:00:01+00:00', level: 'warn', ds: DS_A, msg: 'slow query' },
  { ts: '2026-10-16T10:00:02+00:00', level: 'error', ds: DS_B, msg: 'boom', exception: { class: 'RuntimeException', message: 'Disk full' } },
  { ts: '2026-10-16T10:00:03+00:00', level: 'debug', ds: null, msg: 'cron tick' },
];

test('výchozí filtr = warn + error, nejnovější první', () => {
  const out = filterEntries(entries, { levels: DEFAULT_LEVELS, ds: '', search: '' });
  assert.deepEqual(out.map((e) => e.msg), ['boom', 'slow query']);
});

test('filtr zdroje dat včetně „(žádný)“', () => {
  const all = { debug: true, info: true, warn: true, error: true };
  assert.deepEqual(filterEntries(entries, { levels: all, ds: DS_A, search: '' }).map((e) => e.msg), ['slow query', 'start']);
  assert.deepEqual(filterEntries(entries, { levels: all, ds: DS_NONE, search: '' }).map((e) => e.msg), ['cron tick']);
});

test('fulltext nad zprávou i zprávou výjimky, bez ohledu na velikost písmen', () => {
  const all = { debug: true, info: true, warn: true, error: true };
  assert.deepEqual(filterEntries(entries, { levels: all, ds: '', search: 'DISK' }).map((e) => e.msg), ['boom']);
  assert.deepEqual(filterEntries(entries, { levels: all, ds: '', search: 'tick' }).map((e) => e.msg), ['cron tick']);
  assert.deepEqual(filterEntries(entries, { levels: all, ds: '', search: 'nic' }), []);
});

test('nabídka zdrojů dat: Vše, (žádný), ID seřazená, vybraný bez záznamů zůstává', () => {
  assert.deepEqual(dsOptions(entries, ''), [
    { value: '', label: 'Vše' },
    { value: DS_NONE, label: '(žádný)' },
    { value: DS_A, label: DS_A },
    { value: DS_B, label: DS_B },
  ]);
  const withMissing = dsOptions(entries.slice(0, 2), 'zzzz-zzzz-zzzz-zzzz');
  assert.deepEqual(withMissing, [
    { value: '', label: 'Vše' },
    { value: DS_A, label: DS_A },
    { value: 'zzzz-zzzz-zzzz-zzzz', label: 'zzzz-zzzz-zzzz-zzzz (bez záznamů)' },
  ]);
  assert.deepEqual(dsOptions([], DS_NONE), [
    { value: '', label: 'Vše' },
    { value: DS_NONE, label: '(žádný)' },
  ]);
});

test('zkrácené ID do čipu', () => {
  assert.equal(shortDs(DS_A), 'aaaa…');
  assert.equal(shortDs('abc'), 'abc');
  assert.equal(shortDs(null), '');
});
