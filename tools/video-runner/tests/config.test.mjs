import assert from 'node:assert/strict';
import { test } from 'node:test';

import { loadConfig, parseFixedTime } from '../src/config.mjs';
import { UserError } from '../src/errors.mjs';

test('pevný čas: prázdné = null', () => {
  assert.equal(parseFixedTime(undefined), null);
  assert.equal(parseFixedTime(''), null);
  assert.equal(parseFixedTime('   '), null);
});

test('pevný čas: ISO s posunem dá Date v UTC', () => {
  const date = parseFixedTime('2026-10-27T09:30:00+01:00');
  assert.ok(date instanceof Date);
  assert.equal(date.toISOString(), '2026-10-27T08:30:00.000Z');
});

test('pevný čas: neplatná hodnota je UserError s hintem', () => {
  assert.throws(
    () => parseFixedTime('včera ráno'),
    (error) => error instanceof UserError
      && /VIDEO_FIXED_TIME není platný čas/.test(error.message)
      && /ISO 8601 s posunem/.test(error.hint),
  );
});

test('loadConfig čte VIDEO_FIXED_TIME a VIDEO_HOST_RESOLVER_RULES z prostředí', () => {
  const saved = {
    VIDEO_FIXED_TIME: process.env.VIDEO_FIXED_TIME,
    VIDEO_HOST_RESOLVER_RULES: process.env.VIDEO_HOST_RESOLVER_RULES,
  };
  try {
    process.env.VIDEO_FIXED_TIME = '2026-10-27T09:30:00+01:00';
    process.env.VIDEO_HOST_RESOLVER_RULES = '  MAP demo.example.dev 127.0.0.1  ';

    const config = loadConfig();
    assert.equal(config.fixedTime.toISOString(), '2026-10-27T08:30:00.000Z');
    assert.equal(config.hostResolverRules, 'MAP demo.example.dev 127.0.0.1');

    process.env.VIDEO_FIXED_TIME = '';
    process.env.VIDEO_HOST_RESOLVER_RULES = '';
    const bare = loadConfig();
    assert.equal(bare.fixedTime, null);
    assert.equal(bare.hostResolverRules, null);
  } finally {
    for (const [name, value] of Object.entries(saved)) {
      if (value === undefined) delete process.env[name];
      else process.env[name] = value;
    }
  }
});
