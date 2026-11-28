import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  createPrintJobs, isRetryable, canPrintPos, visibleDetailActions, printJobErrorKey,
  printJobSuccessKey, printJobFailureTitleKey,
  RETRYABLE_BRIDGE_CODES, PRINT_ERROR_CODES, PRINT_POS_ACTION,
} from '../../../src/native/printJobs.js';
import { ERROR_CODES } from '../../../src/native/contract.js';
import cs from '../../../src/i18n/cs.js';
import en from '../../../src/i18n/en.js';

const API = 'http://127.0.0.1/test-test-test-test/api/v1';
const DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';
const JOB = '5d0c2b7e-11aa-4c3e-8f65-9a1b2c3d4e5f';
const JOB_PATH = `/_print-jobs/${JOB}`;
const RESULT_PATH = `${JOB_PATH}/result`;

const CREATED = {
  success: true,
  data: { jobId: JOB, peripheralId: 'mock-receipt', format: 'escpos', data: 'G0A=', warnings: [] },
};
const LOADED = {
  success: true,
  data: { jobId: JOB, kind: 'print', state: 'failed', peripheralId: 'mock-receipt', format: 'escpos', data: 'G0A=', attempts: 1, warnings: [] },
};
const REPORTED = { success: true, data: { state: 'sent', attempts: 1 } };
const REQUEST = { kind: 'print', printId: 'docs.cashRegister.receiptPos', recordId: 1059 };

function info(overrides = {}) {
  return {
    apiVersion: 1,
    dataSource: API,
    deviceId: DEVICE,
    mode: 'shared',
    capabilities: ['device', 'peripherals', 'print.escpos'],
    ...overrides,
  };
}

function bridgeError(code, details = null) {
  return Object.assign(new Error(code), { code, details });
}

/**
 * Falešný klient API a most: odpovědi podle cesty / metody, hodnota může být
 * funkce (kvůli pořadí pokusů) a `Error` se u mostu vyhodí. Volání se zapisují.
 */
function setup({ post = {}, get = {}, native = {}, deviceInfo = info() } = {}) {
  const calls = [];
  const answer = (table, key, args) => {
    const value = table[key];
    return typeof value === 'function' ? value(...args) : value;
  };
  const jobs = createPrintJobs({
    get: async (path) => {
      calls.push(['get', path]);
      return answer(get, path, [path]);
    },
    post: async (path, body) => {
      calls.push(['post', path, body]);
      return answer(post, path, [body]);
    },
    nativeCall: async (method, params) => {
      calls.push(['native', method, params]);
      const value = answer(native, method, [params]);
      if (value instanceof Error) throw value;
      return value;
    },
    nativeInfo: async () => {
      if (deviceInfo instanceof Error) throw deviceInfo;
      return deviceInfo;
    },
  });
  return { calls, jobs, order: () => calls.map((call) => `${call[0]} ${call[1]}`) };
}

// ── submitPrintJob ───────────────────────────────────────────────────────

test('tisk: úloha na serveru → most → výsledek', async () => {
  const { calls, jobs, order } = setup({
    post: { '/_print-jobs': CREATED, [RESULT_PATH]: REPORTED },
    native: { 'print.submit': { status: 'sent' } },
  });

  const result = await jobs.submitPrintJob(REQUEST);

  assert.deepEqual(result, { ok: true, jobId: JOB, warnings: [] });
  assert.deepEqual(order(), ['post /_print-jobs', 'native print.submit', `post ${RESULT_PATH}`]);
  assert.deepEqual(calls[0][2], { deviceId: DEVICE, ...REQUEST });
  assert.deepEqual(calls[1][2], { jobId: JOB, peripheralId: 'mock-receipt', format: 'escpos', data: 'G0A=' });
  assert.deepEqual(calls[2][2], { status: 'sent' });
});

test('tisk: zkušební účtenka a šuplík posílají jen druh, role jen když je zadaná', async () => {
  const { calls, jobs } = setup({
    post: { '/_print-jobs': CREATED, [RESULT_PATH]: REPORTED },
    native: { 'print.submit': { status: 'sent' } },
  });

  await jobs.submitPrintJob({ kind: 'test' });
  await jobs.submitPrintJob({ kind: 'drawer', role: 'receipt', printId: undefined, recordId: null });

  assert.deepEqual(calls[0][2], { deviceId: DEVICE, kind: 'test' });
  assert.deepEqual(calls[3][2], { deviceId: DEVICE, kind: 'drawer', role: 'receipt' });
});

test('tisk: varování úlohy se vracejí s úspěchem', async () => {
  const warnings = [{ severity: 'warning', code: 'ESCPOS_PROFILE_UNKNOWN', text: 'Unknown printer profile' }];
  const { jobs } = setup({
    post: { '/_print-jobs': { success: true, data: { ...CREATED.data, warnings } }, [RESULT_PATH]: REPORTED },
    native: { 'print.submit': { status: 'sent' } },
  });

  assert.deepEqual((await jobs.submitPrintJob(REQUEST)).warnings, warnings);
});

test('tisk: chyba serveru končí hned — most se nevolá a opakování nepomůže', async () => {
  const { jobs, order } = setup({
    post: { '/_print-jobs': { success: false, error: { code: 'NO_PERIPHERAL_FOR_ROLE', message: "Device has no printer with role 'receipt'" } } },
  });

  const result = await jobs.submitPrintJob(REQUEST);

  assert.deepEqual(result, {
    ok: false,
    jobId: null,
    stage: 'server',
    code: 'NO_PERIPHERAL_FOR_ROLE',
    message: "Device has no printer with role 'receipt'",
    details: null,
    retryable: false,
    request: REQUEST,
  });
  assert.deepEqual(order(), ['post /_print-jobs']);
});

test('tisk: výpadek spojení se serverem jde zkusit znovu, skončená session ne', async () => {
  const offline = setup({ post: { '/_print-jobs': { success: false, error: { code: 'NETWORK_ERROR', message: 'Network error' } } } });
  const failed = await offline.jobs.submitPrintJob(REQUEST);
  assert.equal(failed.stage, 'server');
  assert.equal(failed.jobId, null);
  assert.equal(failed.retryable, true);

  const loggedOut = setup({ post: { '/_print-jobs': null } });
  const expired = await loggedOut.jobs.submitPrintJob(REQUEST);
  assert.equal(expired.code, 'UNAUTHORIZED');
  assert.equal(expired.retryable, false);
});

test('tisk: bez info mostu se na server nejde', async () => {
  const { jobs, order } = setup({ deviceInfo: bridgeError('NOT_AVAILABLE') });

  const result = await jobs.submitPrintJob(REQUEST);

  assert.equal(result.stage, 'bridge');
  assert.equal(result.code, 'NOT_AVAILABLE');
  assert.equal(result.retryable, false);
  assert.deepEqual(order(), []);
});

test('tisk: chyba tiskárny se zapíše k úloze a jde zkusit znovu', async () => {
  const { calls, jobs, order } = setup({
    post: { '/_print-jobs': CREATED, [RESULT_PATH]: { success: true, data: { state: 'failed', attempts: 1 } } },
    native: { 'print.submit': bridgeError('PRINTER_ERROR', { paperOut: true }) },
  });

  const result = await jobs.submitPrintJob(REQUEST);

  assert.deepEqual(result, {
    ok: false,
    jobId: JOB,
    stage: 'bridge',
    code: 'PRINTER_ERROR',
    message: 'PRINTER_ERROR',
    details: { paperOut: true },
    retryable: true,
    request: REQUEST,
  });
  assert.deepEqual(order(), ['post /_print-jobs', 'native print.submit', `post ${RESULT_PATH}`]);
  assert.deepEqual(calls[2][2], { status: 'failed', errorCode: 'PRINTER_ERROR' });
});

test('tisk: chyba mostu mimo tiskárnu se zapíše, ale opakování nenabízí', async () => {
  for (const code of ['PERIPHERAL_NOT_FOUND', 'DUPLICATE_JOB', 'UNSUPPORTED_FORMAT', 'PAYLOAD_TOO_LARGE', 'NOT_SUPPORTED']) {
    const { calls, jobs } = setup({
      post: { '/_print-jobs': CREATED, [RESULT_PATH]: REPORTED },
      native: { 'print.submit': bridgeError(code) },
    });
    const result = await jobs.submitPrintJob(REQUEST);
    assert.equal(result.stage, 'bridge', code);
    assert.equal(result.retryable, false, code);
    assert.deepEqual(calls[2][2], { status: 'failed', errorCode: code });
  }
});

test('tisk: kód mostu, který server nepřijme, se hlásí jako INTERNAL_ERROR', async () => {
  for (const thrown of [bridgeError('weird code'), new Error('boom'), bridgeError('')]) {
    const { calls, jobs } = setup({
      post: { '/_print-jobs': CREATED, [RESULT_PATH]: REPORTED },
      native: { 'print.submit': thrown },
    });
    await jobs.submitPrintJob(REQUEST);
    assert.deepEqual(calls[2][2], { status: 'failed', errorCode: 'INTERNAL_ERROR' });
  }
});

test('tisk: chyba mostu zůstane chybou tisku, i když se nezapíše výsledek', async () => {
  const { jobs } = setup({
    post: { '/_print-jobs': CREATED, [RESULT_PATH]: { success: false, error: { code: 'NETWORK_ERROR', message: 'Network error' } } },
    native: { 'print.submit': bridgeError('TIMEOUT') },
  });

  const result = await jobs.submitPrintJob(REQUEST);

  assert.equal(result.stage, 'bridge');
  assert.equal(result.code, 'TIMEOUT');
  assert.equal(result.retryable, true);
});

test('tisk: selhání zápisu výsledku po odeslání — úloha zůstane neověřená a tisk se neopakuje', async () => {
  const { jobs, order } = setup({
    post: { '/_print-jobs': CREATED, [RESULT_PATH]: { success: false, error: { code: 'NETWORK_ERROR', message: 'Network error' } } },
    native: { 'print.submit': { status: 'sent' } },
  });

  const result = await jobs.submitPrintJob(REQUEST);

  assert.deepEqual(result, {
    ok: false,
    jobId: JOB,
    stage: 'report',
    code: 'NETWORK_ERROR',
    message: 'Network error',
    details: null,
    retryable: false,
    request: REQUEST,
  });
  assert.deepEqual(order(), ['post /_print-jobs', 'native print.submit', `post ${RESULT_PATH}`]);
  assert.equal(printJobErrorKey(result), 'native.print.error.reportFailed');
});

// ── Zkusit znovu ─────────────────────────────────────────────────────────

test('zkusit znovu: tatáž úloha ze serveru, mostu s reprint', async () => {
  const { calls, jobs, order } = setup({
    get: { [JOB_PATH]: LOADED },
    post: { [RESULT_PATH]: { success: true, data: { state: 'sent', attempts: 2 } } },
    native: { 'print.submit': { status: 'sent' } },
  });

  const result = await jobs.retryPrintJob(JOB, REQUEST);

  assert.deepEqual(result, { ok: true, jobId: JOB, warnings: [] });
  assert.deepEqual(order(), [`get ${JOB_PATH}`, 'native print.submit', `post ${RESULT_PATH}`]);
  assert.deepEqual(calls[1][2], { jobId: JOB, peripheralId: 'mock-receipt', format: 'escpos', data: 'G0A=', reprint: true });
  assert.deepEqual(calls[2][2], { status: 'sent' });
});

test('zkusit znovu: úloha, která nejde načíst, se netiskne', async () => {
  const { jobs, order } = setup({
    get: { [JOB_PATH]: { success: false, error: { code: 'JOB_NOT_FOUND', message: 'Print job not found' } } },
  });

  const result = await jobs.retryPrintJob(JOB, REQUEST);

  assert.equal(result.stage, 'server');
  assert.equal(result.code, 'JOB_NOT_FOUND');
  assert.equal(result.jobId, JOB);
  assert.equal(result.retryable, false);
  assert.deepEqual(order(), [`get ${JOB_PATH}`]);
});

test('zkusit znovu po chybě tiskárny: stejné jobId, druhý pokus projde', async () => {
  let attempt = 0;
  const { calls, jobs } = setup({
    get: { [JOB_PATH]: LOADED },
    post: { '/_print-jobs': CREATED, [RESULT_PATH]: REPORTED },
    native: { 'print.submit': () => (++attempt === 1 ? bridgeError('PRINTER_ERROR', { paperOut: true }) : { status: 'sent' }) },
  });

  const failed = await jobs.submitPrintJob(REQUEST);
  assert.equal(failed.retryable, true);

  const retried = await jobs.retryFailedPrintJob(failed);

  assert.deepEqual(retried, { ok: true, jobId: JOB, warnings: [] });
  const submits = calls.filter((call) => call[0] === 'native');
  assert.deepEqual(submits.map((call) => call[2].jobId), [JOB, JOB]);
  assert.deepEqual(submits.map((call) => call[2].reprint), [undefined, true]);
  assert.equal(calls.filter((call) => call[1] === '/_print-jobs').length, 1, 'Nová úloha nevznikla');
});

test('zkusit znovu po výpadku spojení se serverem: úloha nevznikla, posílá se znovu požadavek', async () => {
  let attempt = 0;
  const { jobs, order } = setup({
    post: {
      '/_print-jobs': () => (++attempt === 1 ? { success: false, error: { code: 'NETWORK_ERROR', message: 'Network error' } } : CREATED),
      [RESULT_PATH]: REPORTED,
    },
    native: { 'print.submit': { status: 'sent' } },
  });

  const failed = await jobs.submitPrintJob(REQUEST);
  const retried = await jobs.retryFailedPrintJob(failed);

  assert.equal(retried.ok, true);
  assert.deepEqual(order(), ['post /_print-jobs', 'post /_print-jobs', 'native print.submit', `post ${RESULT_PATH}`]);
});

// ── čisté funkce ─────────────────────────────────────────────────────────

test('opakování: jen chyby mostu a tiskárny z U40, ve fázi serveru jen výpadek spojení', () => {
  assert.deepEqual([...RETRYABLE_BRIDGE_CODES], ['PERIPHERAL_UNAVAILABLE', 'PRINTER_ERROR', 'TIMEOUT', 'NETWORK_ERROR']);
  for (const code of RETRYABLE_BRIDGE_CODES) {
    assert.equal(isRetryable('bridge', code), true, code);
    assert.equal(isRetryable('report', code), false, code);
  }
  for (const code of ['NO_PERIPHERAL_FOR_ROLE', 'ROLE_AMBIGUOUS', 'PERIPHERAL_NOT_PRESENT', 'PROFILE_NO_DRAWER', 'DEVICE_UNKNOWN', 'PRINTER_ERROR']) {
    assert.equal(isRetryable('server', code), false, code);
  }
  assert.equal(isRetryable('server', 'NETWORK_ERROR'), true);
  assert.equal(isRetryable('bridge', 'DUPLICATE_JOB'), false);
  assert.equal(isRetryable('bridge', undefined), false);
});

test('viditelnost: tisk na účtenkovou tiskárnu jen s mostem, capability a registrací v tomto zdroji dat', () => {
  assert.equal(canPrintPos({ available: true, info: info(), apiBase: API }), true);
  assert.equal(canPrintPos({ available: true, info: info({ mode: 'personal' }), apiBase: API }), true);

  assert.equal(canPrintPos({ available: false, info: info(), apiBase: API }), false, 'bez mostu');
  assert.equal(canPrintPos({ available: true, info: null, apiBase: API }), false, 'info ještě není');
  assert.equal(canPrintPos({ available: true, info: info({ mode: 'unregistered' }), apiBase: API }), false, 'neregistrováno');
  assert.equal(canPrintPos({ available: true, info: info({ capabilities: ['device', 'print.pdf'] }), apiBase: API }), false, 'bez print.escpos');
  assert.equal(canPrintPos({ available: true, info: info({ capabilities: null }), apiBase: API }), false);
  assert.equal(
    canPrintPos({ available: true, info: info(), apiBase: 'http://127.0.0.1/other-other-other-other/api/v1' }),
    false,
    'most patří jinému zdroji dat',
  );
});

test('viditelnost: akce printPos mizí bez tiskárny, ostatní akce zůstávají v pořadí', () => {
  const actions = [{ id: 'print' }, { id: PRINT_POS_ACTION }, { id: 'send' }, { id: 'docs.core.someAction' }];

  assert.deepEqual(visibleDetailActions(actions, { posPrint: true }), actions);
  assert.deepEqual(
    visibleDetailActions(actions, { posPrint: false }).map((action) => action.id),
    ['print', 'send', 'docs.core.someAction'],
  );
  assert.deepEqual(visibleDetailActions(null, { posPrint: true }), []);
  assert.deepEqual(visibleDetailActions(undefined, { posPrint: false }), []);
});

test('texty chyb: každý kód má text v obou jazycích, neznámý kód obecný', () => {
  for (const code of PRINT_ERROR_CODES) {
    const key = printJobErrorKey({ stage: 'server', code });
    assert.equal(key, `native.print.error.${code}`);
    assert.ok(cs[key], `cs: ${key}`);
    assert.ok(en[key], `en: ${key}`);
  }
  for (const key of ['native.print.error.unknown', 'native.print.error.reportFailed',
    'native.print.error.PRINTER_ERROR_paperOut', 'native.print.error.PRINTER_ERROR_coverOpen']) {
    assert.ok(cs[key], `cs: ${key}`);
    assert.ok(en[key], `en: ${key}`);
  }

  assert.equal(printJobErrorKey({ stage: 'bridge', code: 'SOMETHING_NEW' }), 'native.print.error.unknown');
  assert.equal(printJobErrorKey(null), 'native.print.error.unknown');
  assert.equal(printJobErrorKey({ stage: 'bridge', code: 'PRINTER_ERROR', details: { paperOut: true } }), 'native.print.error.PRINTER_ERROR_paperOut');
  assert.equal(printJobErrorKey({ stage: 'bridge', code: 'PRINTER_ERROR', details: { coverOpen: true } }), 'native.print.error.PRINTER_ERROR_coverOpen');
  assert.equal(printJobErrorKey({ stage: 'bridge', code: 'PRINTER_ERROR', details: null }), 'native.print.error.PRINTER_ERROR');
});

test('texty chyb: chyby print.submit z kontraktu mostu mají vlastní text', () => {
  for (const code of ['PERIPHERAL_NOT_FOUND', 'PERIPHERAL_UNAVAILABLE', 'UNSUPPORTED_FORMAT', 'DUPLICATE_JOB',
    'PAYLOAD_TOO_LARGE', 'PRINTER_ERROR', 'TIMEOUT']) {
    assert.ok(ERROR_CODES.includes(code), `kontrakt: ${code}`);
    assert.ok(PRINT_ERROR_CODES.includes(code), `text: ${code}`);
  }
});

test('texty: úspěch a titulek dialogu podle druhu úlohy a místa selhání', () => {
  assert.equal(printJobSuccessKey('print'), 'native.print.sent');
  assert.equal(printJobSuccessKey(undefined), 'native.print.sent');
  assert.equal(printJobSuccessKey('test'), 'native.print.sentTest');
  assert.equal(printJobSuccessKey('drawer'), 'native.print.sentDrawer');

  assert.equal(printJobFailureTitleKey({ stage: 'bridge', request: { kind: 'print' } }), 'native.print.failed.title');
  assert.equal(printJobFailureTitleKey({ stage: 'server', request: null }), 'native.print.failed.title');
  assert.equal(printJobFailureTitleKey({ stage: 'bridge', request: { kind: 'drawer' } }), 'native.print.failed.titleDrawer');
  assert.equal(printJobFailureTitleKey({ stage: 'report', request: { kind: 'drawer' } }), 'native.print.failed.titleReport');

  for (const key of ['native.print.sent', 'native.print.sentTest', 'native.print.sentDrawer', 'native.print.failed.title',
    'native.print.failed.titleDrawer', 'native.print.failed.titleReport', 'native.print.failed.code', 'native.print.retry',
    'native.print.testPrint', 'native.print.openDrawer']) {
    assert.ok(cs[key], `cs: ${key}`);
    assert.ok(en[key], `en: ${key}`);
  }
});
