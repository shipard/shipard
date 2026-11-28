/**
 * Tisk přes most nativní aplikace (#177 U40, U41; docs/native-bridge.md §5,
 * docs/prints.md kap. 14) — čisté funkce: tisková úloha na serveru → data
 * mostu (`print.submit`) → výsledek zpět na server.
 *
 * Bez importu `t()`, klienta API i mostu — vše jde parametrem, aby se dalo
 * testovat v Node. Vazbu na skutečného klienta a most drží `api/printJobs.js`.
 *
 * Výsledek každého pokusu:
 *   {ok: true, jobId, warnings}
 *   {ok: false, jobId, stage, code, message, details, retryable, request}
 * `stage` říká, kde se tisk zastavil:
 *   - `server` — úloha nevznikla (nebo se při Zkusit znovu nenačetla);
 *     nic se netisklo
 *   - `bridge` — most nebo tiskárna data nepřevzaly; výsledek je na serveru
 *     zapsaný jako chyba
 *   - `report` — data tiskárně **odešla**, jen se nepovedlo zapsat výsledek;
 *     úloha zůstává „neověřeno“ a tisk se kvůli tomu neopakuje
 */

/** Chyby mostu a tiskárny, po kterých má smysl zkusit to znovu (U40). */
export const RETRYABLE_BRIDGE_CODES = Object.freeze([
  'PERIPHERAL_UNAVAILABLE', 'PRINTER_ERROR', 'TIMEOUT', 'NETWORK_ERROR',
]);

/** Kódy, pro které má dialog vlastní text (`native.print.error.<kód>`). */
export const PRINT_ERROR_CODES = Object.freeze([
  // nastavení zařízení a tiskárny — bez opakování, text říká, co nastavit
  'DEVICE_UNKNOWN', 'DEVICE_REVOKED', 'NO_PERIPHERAL_FOR_ROLE', 'PERIPHERAL_NOT_PRESENT',
  'ROLE_AMBIGUOUS', 'PROFILE_NO_DRAWER',
  // tisk záznamu
  'PRINT_NOT_FOUND', 'PRINT_NOT_AVAILABLE', 'PRINT_DATA_MISSING', 'PRINT_FORMAT_UNSUPPORTED',
  'RECORD_NOT_FOUND', 'PAYLOAD_TOO_LARGE',
  // práva a stav zdroje dat
  'UNAUTHORIZED', 'FORBIDDEN', 'FORBIDDEN_ADMIN_ONLY', 'DS_READ_ONLY', 'JOB_NOT_FOUND',
  // most a tiskárna
  'NOT_AVAILABLE', 'NOT_REGISTERED', 'NOT_SUPPORTED', 'PERIPHERAL_NOT_FOUND', 'PERIPHERAL_UNAVAILABLE',
  'UNSUPPORTED_FORMAT', 'DUPLICATE_JOB', 'PRINTER_ERROR', 'TIMEOUT', 'NETWORK_ERROR',
]);

/** Kód chyby, jak ho přijme server ve výsledku (`errorCode`). */
const ERROR_CODE_PATTERN = /^[A-Z][A-Z0-9_]{0,63}$/;

/**
 * Má dialog nabídnout Zkusit znovu?
 *
 * Chyby nastavení (chybí role, tiskárna není hlášená…) opakování nespraví.
 * Ve fázi `server` se opakuje jen výpadek spojení — úloha ještě nevznikla.
 *
 * @param {'server'|'bridge'|'report'} stage
 * @param {string|null|undefined} code
 * @returns {boolean}
 */
export function isRetryable(stage, code) {
  if (stage === 'bridge') return RETRYABLE_BRIDGE_CODES.includes(code);
  if (stage === 'server') return code === 'NETWORK_ERROR';
  return false;
}

/**
 * Smí stránka tisknout na účtenkovou tiskárnu? Most musí nabízet
 * `print.escpos` a zařízení musí být registrované **v tomto zdroji dat**
 * (D33) — jinak server zařízení nezná a nemá kam úlohu směrovat.
 *
 * @param {object} input
 * @param {boolean} input.available  je na stránce most?
 * @param {object|null} input.info   poslední `info` mostu
 * @param {string} input.apiBase     adresa API zdroje dat stránky
 * @returns {boolean}
 */
export function canPrintPos({ available, info, apiBase }) {
  if (!available || info == null) return false;
  if (info.dataSource !== apiBase) return false;
  if (info.mode !== 'personal' && info.mode !== 'shared') return false;
  return Array.isArray(info.capabilities) && info.capabilities.includes('print.escpos');
}

/** Id akce detailu, kterou server posílá vždy a ukazuje se jen s tiskárnou (U40). */
export const PRINT_POS_ACTION = 'printPos';

/**
 * Akce detailu, které se mají vykreslit: `printPos` jen tam, kde stránka
 * tisknout smí — v obyčejném prohlížeči není vidět vůbec.
 *
 * @template {{id: string}} T
 * @param {T[]|null|undefined} actions
 * @param {{posPrint: boolean}} options
 * @returns {T[]}
 */
export function visibleDetailActions(actions, { posPrint }) {
  const list = Array.isArray(actions) ? actions : [];
  return posPrint ? list : list.filter((action) => action?.id !== PRINT_POS_ACTION);
}

/**
 * Klíč textu chyby pro dialog. Selhání zápisu výsledku má jeden text bez
 * ohledu na kód; chyba tiskárny se upřesní, když tiskárna řekla proč.
 *
 * @param {{stage?: string, code?: string|null, details?: object|null}|null|undefined} failure
 * @returns {string}
 */
export function printJobErrorKey(failure) {
  if (failure?.stage === 'report') return 'native.print.error.reportFailed';
  const code = failure?.code;
  if (code === 'PRINTER_ERROR') {
    if (failure?.details?.paperOut === true) return 'native.print.error.PRINTER_ERROR_paperOut';
    if (failure?.details?.coverOpen === true) return 'native.print.error.PRINTER_ERROR_coverOpen';
  }
  return `native.print.error.${PRINT_ERROR_CODES.includes(code) ? code : 'unknown'}`;
}

/**
 * Klíč textu úspěchu podle druhu úlohy.
 *
 * @param {string|null|undefined} kind
 * @returns {string}
 */
export function printJobSuccessKey(kind) {
  if (kind === 'test') return 'native.print.sentTest';
  if (kind === 'drawer') return 'native.print.sentDrawer';
  return 'native.print.sent';
}

/**
 * Klíč titulku dialogu chyby: selhání zápisu výsledku není chyba tisku
 * a šuplík není tisk.
 *
 * @param {{stage?: string, request?: {kind?: string}|null}|null|undefined} failure
 * @returns {string}
 */
export function printJobFailureTitleKey(failure) {
  if (failure?.stage === 'report') return 'native.print.failed.titleReport';
  return failure?.request?.kind === 'drawer' ? 'native.print.failed.titleDrawer' : 'native.print.failed.title';
}

/**
 * @param {object} deps
 * @param {(path: string) => Promise<object|null>} deps.get                 klient API (se session)
 * @param {(path: string, body: object) => Promise<object|null>} deps.post
 * @param {(method: string, params?: object) => Promise<object>} deps.nativeCall
 * @param {() => Promise<object>} deps.nativeInfo
 */
export function createPrintJobs({ get, post, nativeCall, nativeInfo }) {
  /**
   * Založí úlohu, předá ji mostu a zapíše výsledek.
   *
   * @param {{kind?: 'print'|'test'|'drawer', printId?: string, recordId?: number, role?: string}} request
   */
  async function submitPrintJob(request = {}) {
    let info;
    try {
      info = await nativeInfo();
    } catch (error) {
      return failure('bridge', null, error, request);
    }

    const body = { deviceId: info?.deviceId };
    for (const key of ['kind', 'printId', 'recordId', 'role']) {
      if (request[key] !== undefined && request[key] !== null) body[key] = request[key];
    }

    const created = await post('/_print-jobs', body);
    if (created?.success !== true) return serverFailure(null, created, request);

    return deliver(created.data ?? {}, false, request);
  }

  /**
   * Zkusit znovu: tatáž úloha, data ze serveru, mostu s `reprint: true`
   * (vědomé opakování — aplikace by stejné `jobId` jinak odmítla).
   *
   * @param {string} jobId
   * @param {object|null} request  původní požadavek, jen aby ho nesl další výsledek
   */
  async function retryPrintJob(jobId, request = null) {
    const loaded = await get(`/_print-jobs/${encodeURIComponent(jobId)}`);
    if (loaded?.success !== true) return serverFailure(jobId, loaded, request);

    return deliver(loaded.data ?? {}, true, request);
  }

  /**
   * Zkusit znovu po neúspěchu: úloha už existuje → tatáž úloha s `reprint`;
   * nevznikla (výpadek spojení se serverem) → nový pokus téhož požadavku.
   *
   * @param {{jobId: string|null, request: object|null}} failed
   */
  function retryFailedPrintJob(failed) {
    return failed?.jobId
      ? retryPrintJob(failed.jobId, failed.request ?? null)
      : submitPrintJob(failed?.request ?? {});
  }

  async function deliver(job, reprint, request) {
    const jobId = job.jobId;
    const params = { jobId, peripheralId: job.peripheralId, format: job.format, data: job.data };
    if (reprint) params.reprint = true;

    try {
      await nativeCall('print.submit', params);
    } catch (error) {
      const failed = failure('bridge', jobId, error, request);
      // Chyba se zapíše k úloze; když neprojde ani to, úloha zůstane
      // „neověřeno“ — uživatel se dozví původní chybu tisku.
      await post(resultPath(jobId), { status: 'failed', errorCode: reportableCode(failed.code) });
      return failed;
    }

    const reported = await post(resultPath(jobId), { status: 'sent' });
    if (reported?.success !== true) {
      return {
        ...serverFailure(jobId, reported, request),
        stage: 'report',
        retryable: false,
      };
    }

    return { ok: true, jobId, warnings: Array.isArray(job.warnings) ? job.warnings : [] };
  }

  return { submitPrintJob, retryPrintJob, retryFailedPrintJob };
}

function resultPath(jobId) {
  return `/_print-jobs/${encodeURIComponent(jobId)}/result`;
}

function reportableCode(code) {
  return typeof code === 'string' && ERROR_CODE_PATTERN.test(code) ? code : 'INTERNAL_ERROR';
}

function failure(stage, jobId, error, request) {
  const code = typeof error?.code === 'string' && error.code !== '' ? error.code : 'INTERNAL_ERROR';
  return {
    ok: false,
    jobId: jobId ?? null,
    stage,
    code,
    message: typeof error?.message === 'string' ? error.message : '',
    details: error?.details && typeof error.details === 'object' ? error.details : null,
    retryable: isRetryable(stage, code),
    request: request ?? null,
  };
}

/** Odpověď klienta API: `null` = session skončila (401 bez obnovy). */
function serverFailure(jobId, response, request) {
  const error = response === null || response === undefined
    ? { code: 'UNAUTHORIZED', message: 'Session expired' }
    : response.error ?? { code: 'INTERNAL_ERROR', message: 'Unexpected response' };
  return failure('server', jobId, error, request);
}
