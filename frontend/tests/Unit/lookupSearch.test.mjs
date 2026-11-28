import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  buildLookupSearchUrl,
  lookupLimitFor,
  createLookupSearch,
  LOOKUP_SEARCH_DELAY,
} from '../../src/utils/lookupSearch.js';

test('buildLookupSearchUrl: text, filtr bez prázdných hodnot, nepovinný limit', () => {
  assert.equal(
    buildLookupSearchUrl({ table: 'base_places_places', filter: null }, 'kan'),
    '/_ui/lookup/base_places_places/search?q=kan',
  );
  assert.equal(
    buildLookupSearchUrl({ table: 't', filter: { person: 42, kind: null, type: undefined, flag: false } }, ''),
    '/_ui/lookup/t/search?q=&filter%5Bperson%5D=42&filter%5Bflag%5D=false',
  );
  assert.equal(
    buildLookupSearchUrl({ table: 't' }, 'a b', { limit: 23 }),
    '/_ui/lookup/t/search?q=a+b&limit=23',
  );
});

test('lookupLimitFor: výchozí limit plus vybrané, do stropu endpointu', () => {
  assert.equal(lookupLimitFor(0), 20);
  assert.equal(lookupLimitFor(3), 23);
  assert.equal(lookupLimitFor(30), 50);
  assert.equal(lookupLimitFor(500), 50);
  assert.equal(lookupLimitFor(-1), 20);
  assert.equal(lookupLimitFor(undefined), 20);
});

/** Řadič s ručně ovládanými časovači a odpověďmi. */
function harness({ lookup = { table: 't', filter: null }, limit = null } = {}) {
  const states = [];
  const requests = [];
  const timers = [];
  const search = createLookupSearch({
    get: (url) => new Promise((resolve) => requests.push({ url, resolve })),
    getLookup: () => lookup,
    getLimit: () => limit,
    onState: (patch) => states.push(patch),
    setTimer: (fn, delay) => {
      const timer = { fn, delay, cleared: false };
      timers.push(timer);
      return timer;
    },
    clearTimer: (timer) => { timer.cleared = true; },
  });
  const fire = (index) => { if (!timers[index].cleared) timers[index].fn(); };
  return { search, states, requests, timers, fire };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('run: hlásí začátek a výsledek', async () => {
  const h = harness({ limit: 23 });
  const done = h.search.run('al');
  assert.deepEqual(h.states, [{ loading: true, failure: null }]);
  assert.equal(h.requests[0].url, '/_ui/lookup/t/search?q=al&limit=23');

  h.requests[0].resolve({ success: true, data: { items: [{ id: 1, primary: 'Alpha' }] } });
  await done;
  assert.deepEqual(h.states[1], { loading: false, items: [{ id: 1, primary: 'Alpha' }], failure: null });
});

test('run: chyba serveru a nedostupná síť', async () => {
  const h = harness();
  const first = h.search.run('a');
  const failure = { success: false, error: { message: 'boom' } };
  h.requests[0].resolve(failure);
  await first;
  assert.deepEqual(h.states.at(-1), { loading: false, items: [], failure });

  const second = h.search.run('b');
  h.requests[1].resolve(null);
  await second;
  assert.deepEqual(h.states.at(-1), { loading: false, items: [], failure: { network: true } });
});

test('run: odpověď staršího dotazu se zahodí', async () => {
  const h = harness();
  const first = h.search.run('a');
  const second = h.search.run('ab');
  h.requests[1].resolve({ success: true, data: { items: [{ id: 2 }] } });
  h.requests[0].resolve({ success: true, data: { items: [{ id: 1 }] } });
  await Promise.all([first, second]);

  const results = h.states.filter((s) => s.items !== undefined);
  assert.deepEqual(results, [{ loading: false, items: [{ id: 2 }], failure: null }]);
});

test('run: bez tabulky nic nehledá', async () => {
  const h = harness({ lookup: null });
  await h.search.run('a');
  assert.deepEqual(h.states, [{ loading: false, items: [], failure: null }]);
  assert.equal(h.requests.length, 0);
});

test('schedule: hledá až po zpoždění, další psaní čekání ruší', async () => {
  const h = harness();
  h.search.schedule('a');
  h.search.schedule('al');
  assert.equal(h.timers.length, 2);
  assert.equal(h.timers[0].delay, LOOKUP_SEARCH_DELAY);
  assert.equal(h.timers[0].cleared, true);
  assert.equal(h.requests.length, 0, 'před uplynutím zpoždění se nehledá');

  h.fire(0);
  h.fire(1);
  await flush();
  assert.deepEqual(h.requests.map((r) => r.url), ['/_ui/lookup/t/search?q=al']);
});

test('cancel: čekající hledání se neprovede', async () => {
  const h = harness();
  h.search.schedule('a');
  h.search.cancel();
  h.fire(0);
  await flush();
  assert.equal(h.requests.length, 0);
  h.search.cancel();
});
