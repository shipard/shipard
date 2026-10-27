import { test } from 'node:test';
import assert from 'node:assert/strict';
import { processLocaleEnv } from '../src/browser.mjs';

test('locale scénáře se překládá na POSIX locale procesu prohlížeče', () => {
  assert.deepEqual(processLocaleEnv('cs-CZ'), { LANG: 'cs_CZ.UTF-8', LC_ALL: 'cs_CZ.UTF-8', LANGUAGE: 'cs' });
  assert.deepEqual(processLocaleEnv(' en-US '), { LANG: 'en_US.UTF-8', LC_ALL: 'en_US.UTF-8', LANGUAGE: 'en' });
  assert.deepEqual(processLocaleEnv('de'), { LANG: 'de.UTF-8', LC_ALL: 'de.UTF-8', LANGUAGE: 'de' });
});
