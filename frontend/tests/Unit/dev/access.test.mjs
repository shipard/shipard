import { test } from 'node:test';
import assert from 'node:assert/strict';
import { accessEntries, currentEntry, entryUrl, httpsEntry, isHttps, normalizeOrigin, originHost } from '../../../src/dev/access.js';

const ACCESS = [
  { kind: 'http-ip', origin: 'http://10.0.0.7', preferred: false, current: false },
  { kind: 'https-ip', origin: 'https://10.0.0.7', preferred: false, current: false },
  { kind: 'https-host', origin: 'https://shpd.example.com', preferred: true, current: false },
];

test('odkaz na jiný vstup zachová cestu, dotaz i kotvu', () => {
  const location = { pathname: '/_dev/testing/datasets/cz-vat-payer', search: '?tab=runs', hash: '#last' };
  assert.equal(entryUrl('https://10.0.0.7', location), 'https://10.0.0.7/_dev/testing/datasets/cz-vat-payer?tab=runs#last');
  assert.equal(entryUrl('https://shpd.example.com/', location), 'https://shpd.example.com/_dev/testing/datasets/cz-vat-payer?tab=runs#last');
  assert.equal(entryUrl('https://[2001:db8::7]', { pathname: '/_dev/' }), 'https://[2001:db8::7]/_dev/');
});

test('odkaz na jiný vstup bez cesty míří na kořen', () => {
  assert.equal(entryUrl('http://10.0.0.7', {}), 'http://10.0.0.7/');
  assert.equal(entryUrl('http://10.0.0.7', null), 'http://10.0.0.7/');
  assert.equal(entryUrl('http://10.0.0.7', { pathname: '', search: '?x=1' }), 'http://10.0.0.7/?x=1');
  assert.equal(entryUrl('http://10.0.0.7', { pathname: '_dev/logs' }), 'http://10.0.0.7/_dev/logs');
});

test('origin se porovnává bez ohledu na velikost písmen, lomítko a výchozí port', () => {
  assert.equal(normalizeOrigin('HTTPS://Shpd.Example.com/'), 'https://shpd.example.com');
  assert.equal(normalizeOrigin('http://10.0.0.7:80'), 'http://10.0.0.7');
  assert.equal(normalizeOrigin('https://10.0.0.7:443'), 'https://10.0.0.7');
  assert.equal(normalizeOrigin('https://10.0.0.7:8443'), 'https://10.0.0.7:8443');
  assert.equal(normalizeOrigin('http://10.0.0.7:443'), 'http://10.0.0.7:443');
  assert.equal(normalizeOrigin(null), '');
});

test('aktuální vstup určuje origin prohlížeče, ne příznak ze serveru', () => {
  const stale = ACCESS.map((entry) => ({ ...entry, current: entry.kind === 'http-ip' }));
  const entries = accessEntries(stale, 'https://10.0.0.7');
  assert.deepEqual(entries.map((e) => [e.kind, e.current]), [['http-ip', false], ['https-ip', true], ['https-host', false]]);
  assert.equal(currentEntry(entries).kind, 'https-ip');
  assert.deepEqual(entries.map((e) => e.preferred), [false, false, true]);
});

test('adresa mimo vstupy serveru (localhost, tunel) je první řádek „other“', () => {
  const entries = accessEntries(ACCESS, 'http://localhost');
  assert.deepEqual(entries.map((e) => e.kind), ['other', 'http-ip', 'https-ip', 'https-host']);
  assert.deepEqual(entries[0], { kind: 'other', origin: 'http://localhost', preferred: false, current: true });
  assert.equal(entries.filter((e) => e.current).length, 1);

  // Stejná adresa na jiném portu je jiný origin.
  assert.equal(accessEntries(ACCESS, 'http://10.0.0.7:8080')[0].kind, 'other');
});

test('bez dat ze serveru zůstane jen aktuální adresa', () => {
  assert.deepEqual(accessEntries(null, 'http://localhost').map((e) => e.kind), ['other']);
  assert.deepEqual(accessEntries(undefined, ''), []);
  assert.deepEqual(accessEntries([{ kind: 'http-ip' }, null, { kind: 'https-ip', origin: '' }], 'http://10.0.0.7').map((e) => e.kind), ['other']);
  assert.equal(currentEntry([]), null);
  assert.equal(currentEntry(null), null);
});

test('https vstup pro odkaz z nezabezpečené stránky: preferovaný, jinak jméno před IP', () => {
  assert.equal(httpsEntry(ACCESS).kind, 'https-host');

  const ipPreferred = ACCESS.map((entry) => ({ ...entry, preferred: entry.kind === 'https-ip' }));
  assert.equal(httpsEntry(ipPreferred).kind, 'https-ip');

  // Preferovaný je http → bere se nejvyšší https vstup.
  const httpPreferred = ACCESS.map((entry) => ({ ...entry, preferred: entry.kind === 'http-ip' }));
  assert.equal(httpsEntry(httpPreferred).kind, 'https-host');
  assert.equal(httpsEntry(httpPreferred.filter((e) => e.kind !== 'https-host')).kind, 'https-ip');

  assert.equal(httpsEntry([ACCESS[0]]), null);
  assert.equal(httpsEntry([]), null);
  assert.equal(httpsEntry(null), null);
});

test('popisek adresy a schéma', () => {
  assert.equal(originHost('https://shpd.example.com:8443/'), 'shpd.example.com:8443');
  assert.equal(originHost('http://10.0.0.7'), '10.0.0.7');
  assert.equal(originHost('https://[2001:db8::7]'), '[2001:db8::7]');
  assert.equal(originHost(null), '');
  assert.equal(isHttps('https://10.0.0.7'), true);
  assert.equal(isHttps('HTTP://10.0.0.7'), false);
  assert.equal(isHttps(undefined), false);
});
