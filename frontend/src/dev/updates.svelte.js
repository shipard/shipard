/**
 * Stav aktualizací pro štítek v pruhu (#129 D18–D21, U3, U7): `GET
 * /_dev/api/updates` při startu, každých 5 s dokud běží kontrola nebo úloha
 * `update`, po návratu na kartu (focus) nejvýš jednou za minutu. Selhání
 * dotazu nechává poslední stav — během aktualizace se přepisuje PHP
 * i autoloader a chvilkové chyby jsou normální. Když běžící úloha zmizí
 * z odpovědi, doptá se na její konečný stav: hotovo → výzva **Obnovit
 * stránku** (build přepsal soubory dashboardu), jinak hlášení o selhání.
 */
import { getJson, postJson, ApiError } from './api.js';
import { isActiveStatus } from './testing/jobs.js';

const POLL_MS = 5000;
const FOCUS_MIN_MS = 60_000;

let status = $state(null); // {state, stale, checking, head, blockers, canUpdate}
let job = $state(null); // běžící úloha update
let finishedJob = $state(null); // poslední doběhlá úloha update z této relace (hotovo / selhání)
let reloadNeeded = $state(false);
let busy = $state(false);
let actionError = $state(null); // {text, code, jobId} z posledního Aktualizovat / Zkontrolovat

let intervalId = null;
let onFocus = null;
let lastLoadAt = 0;
let loading = false;

async function load() {
  if (loading) return;
  loading = true;
  const previous = job;
  try {
    const d = await getJson('/updates');
    const { job: current = null, ...rest } = d;
    status = rest;
    job = current;
    lastLoadAt = Date.now();
    if (previous && isActiveStatus(previous.status) && !(current && current.id === previous.id)) {
      await settle(previous.id);
    }
  } catch {
    // poslední stav zůstává
  } finally {
    loading = false;
  }
}

/** Úloha zmizela z odpovědi — její konec čteme z API úloh. */
async function settle(jobId) {
  try {
    const d = await getJson(`/testing/jobs/${encodeURIComponent(jobId)}`);
    finishedJob = d.job ?? null;
    if (finishedJob?.status === 'finished') reloadNeeded = true;
  } catch {
    finishedJob = { id: jobId, status: 'unknown' };
  }
}

function tick() {
  if (typeof document !== 'undefined' && document.hidden) return;
  if (status?.checking || (job && isActiveStatus(job.status))) load();
}

export const updates = {
  get status() {
    return status;
  },
  get job() {
    return job;
  },
  get finishedJob() {
    return finishedJob;
  },
  get reloadNeeded() {
    return reloadNeeded;
  },
  get busy() {
    return busy;
  },
  get actionError() {
    return actionError;
  },
  load,
  startPolling() {
    if (intervalId !== null) return;
    load();
    intervalId = setInterval(tick, POLL_MS);
    onFocus = () => {
      if (Date.now() - lastLoadAt >= FOCUS_MIN_MS) load();
    };
    window.addEventListener('focus', onFocus);
  },
  stopPolling() {
    if (intervalId !== null) clearInterval(intervalId);
    intervalId = null;
    if (onFocus) window.removeEventListener('focus', onFocus);
    onFocus = null;
  },
  /** „Zkontrolovat teď“ — kontrola na pozadí bez ohledu na stáří cache. */
  async checkNow() {
    busy = true;
    actionError = null;
    try {
      await postJson('/updates/check');
      if (status) status = { ...status, checking: true };
      setTimeout(load, 1500);
    } catch (e) {
      actionError = describe(e);
    } finally {
      busy = false;
    }
  },
  /** Aktualizovat — úloha `update`; pojistky znovu hlídá server (409 UPDATE_BLOCKED / JOB_RUNNING). */
  async run() {
    busy = true;
    actionError = null;
    finishedJob = null;
    try {
      const d = await postJson('/updates/run');
      job = d.job ?? null;
      return job;
    } catch (e) {
      actionError = describe(e);
      load();
      return null;
    } finally {
      busy = false;
    }
  },
  reloadPage() {
    location.reload();
  },
};

function describe(e) {
  if (e instanceof ApiError) {
    return { text: `${e.message} (${e.code})`, code: e.code, jobId: e.details?.jobId ?? null, blockers: e.details?.blockers ?? null };
  }
  return { text: e instanceof Error ? e.message : String(e), code: null, jobId: null, blockers: null };
}
