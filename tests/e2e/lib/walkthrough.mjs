// Čisté pomocné funkce proklikání sady (#103 F7a) — bez Playwrightu, aby
// šly testovat `node --test` (lib/walkthrough.test.mjs). Spec
// (dataset-walkthrough.spec.mjs) nad nimi staví průchod prohlížečem.
import fs from 'node:fs';
import path from 'node:path';

/** Rozpočet na jednu kartu a základ (dashboard, závěr) — timeout testu. */
export const PER_CARD_TIMEOUT_MS = 120_000;
export const BASE_TIMEOUT_MS = 60_000;

/** Prefix id karet návrhů zpráv (`MailSuggestionsSource`). */
export const CARD_ID_PREFIX = 'mail_suggestion:';

/** Stavy odznaku reference, které čekají na rozhodnutí (D93). */
export const UNDECIDED_STATUSES = new Set(['canCreate', 'ambiguous', 'notFound']);

/**
 * Vstupy z prostředí. Chybějící povinná proměnná = výjimka s jejím jménem.
 *
 * @param {Record<string, string | undefined>} env
 * @param {{defaultOutputDir?: string}} [options]
 */
export function readEnv(env, options = {}) {
  const required = (name) => {
    const value = env[name];
    if (typeof value !== 'string' || value.trim() === '') {
      throw new Error(`Chybí proměnná prostředí ${name} (nastavuje ji shpd-ds dataset-e2e).`);
    }
    return value.trim();
  };
  const appUrl = required('SHPD_E2E_APP_URL');
  if (!/^https?:\/\//.test(appUrl)) {
    throw new Error('SHPD_E2E_APP_URL musí být absolutní http(s) adresa aplikace zdroje dat.');
  }
  const expectedRaw = required('SHPD_E2E_EXPECTED_CARDS');
  if (!/^\d+$/.test(expectedRaw)) {
    throw new Error('SHPD_E2E_EXPECTED_CARDS musí být celé nezáporné číslo.');
  }
  const outputDir = (env.SHPD_E2E_OUTPUT_DIR ?? '').trim() || options.defaultOutputDir;
  if (!outputDir) {
    throw new Error('Chybí proměnná prostředí SHPD_E2E_OUTPUT_DIR.');
  }
  const progressFile = (env.SHPD_E2E_PROGRESS_FILE ?? '').trim();
  // Připíchnutá adresa hostu (`MAP <host> <ip>`, D97) — Chromium pak jméno
  // z adresy aplikace nepřekládá, dostane ověřenou IP od příkazu.
  const hostResolverRules = (env.SHPD_E2E_HOST_RESOLVER_RULES ?? '').trim();
  return {
    appUrl: appUrl.endsWith('/') ? appUrl : `${appUrl}/`,
    storageState: required('SHPD_E2E_STORAGE_STATE'),
    outputDir,
    expectedCards: Number(expectedRaw),
    progressFile: progressFile === '' ? null : progressFile,
    hostResolverRules: hostResolverRules === '' ? null : hostResolverRules,
  };
}

/** `mail_suggestion:17` → 17; jiný tvar → null. */
export function messageNdxFromCardId(cardId) {
  if (typeof cardId !== 'string' || !cardId.startsWith(CARD_ID_PREFIX)) return null;
  const rest = cardId.slice(CARD_ID_PREFIX.length);
  return /^\d+$/.test(rest) ? Number(rest) : null;
}

/** Název souboru trace selhané karty — bezpečný pro souborový systém i URL. */
export function traceFileName(cardId) {
  const safe = String(cardId).replace(/[^A-Za-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '');
  return `trace-${safe || 'card'}.zip`;
}

/** `data-pending-paths` markeru `review-state` (JSON pole cest) → pole; cokoli jiného → []. */
export function parsePendingPaths(attr) {
  if (typeof attr !== 'string' || attr === '') return [];
  try {
    const parsed = JSON.parse(attr);
    return Array.isArray(parsed) ? parsed.filter((p) => typeof p === 'string') : [];
  } catch {
    return [];
  }
}

export function isUndecided(status) {
  return UNDECIDED_STATUSES.has(status);
}

/** Cesta z testidu odznaku `review-ref:rows[3].item` → `rows[3].item`; jiný → null. */
export function refPathFromTestId(testid) {
  if (typeof testid !== 'string' || !testid.startsWith('review-ref:')) return null;
  const p = testid.slice('review-ref:'.length);
  return p === '' ? null : p;
}

/**
 * Selhání karty: krok + důvod (slovník v docs/test-corpus.md § 19) a doplňky
 * (cesta reference, HTTP stav, text alertu…).
 */
export class CardFailure extends Error {
  /**
   * @param {string} step    open_review | preview | decide | apply | after_apply | unknown
   * @param {string} reason  modal_timeout | load_error | ai_failed | no_create_offered | create_failed | decision_timeout | disabled | rejected:<code> | http_5xx | toast_timeout | feed_reload_timeout | card_not_removed | …
   * @param {Record<string, unknown>} [extra]
   */
  constructor(step, reason, extra = {}) {
    super(`${step}/${reason}${extra.path ? ` (${extra.path})` : ''}`);
    this.name = 'CardFailure';
    this.step = step;
    this.reason = reason;
    this.extra = extra;
  }

  /** Cizí výjimka (timeout Playwrightu, chyba lokátoru) → selhání kroku. */
  static from(error, step = 'unknown') {
    if (error instanceof CardFailure) return error;
    const message = error instanceof Error ? error.message : String(error);
    const reason = /Timeout|timed out/i.test(message) ? 'timeout' : 'error';
    return new CardFailure(step, reason, { message: message.split('\n')[0].slice(0, 500) });
  }

  toJSON() {
    return { step: this.step, reason: this.reason, ...this.extra };
  }
}

/**
 * Stav průchodu — zapisuje se po každé kartě do `walkthrough.json`
 * (poslední karta `current` zůstane při pádu testu), `card` události do
 * souboru průběhu úlohy dashboardu (I10).
 */
export class WalkthroughState {
  /**
   * @param {{expected: number, outputDir: string, progressFile?: string | null, now?: () => Date}} options
   */
  constructor({ expected, outputDir, progressFile = null, now = () => new Date() }) {
    this.expected = expected;
    this.outputDir = outputDir;
    this.progressFile = progressFile;
    this.now = now;
    this.startedAt = now().toISOString();
    this.finishedAt = null;
    this.complete = false;
    this.current = null;
    this.applied = [];
    this.failed = [];
    this.browserErrors = [];
    this.apiResponses = [];
    this.dialogs = [];
    this.processed = new Set();
  }

  get currentCardId() {
    return this.current?.cardId ?? null;
  }

  begin(cardId) {
    this.current = { cardId, messageNdx: messageNdxFromCardId(cardId), startedAt: this.now().toISOString(), startedMs: Date.now() };
  }

  applied_(savedDocId) {
    const cur = this.current;
    const entry = { cardId: cur.cardId, messageNdx: cur.messageNdx, savedDocId: savedDocId ?? null, durationMs: Date.now() - cur.startedMs };
    this.applied.push(entry);
    this.processed.add(cur.cardId);
    this.current = null;
    this.progress({ event: 'card', cardId: entry.cardId, messageNdx: entry.messageNdx, status: 'applied', savedDocId: entry.savedDocId, durationMs: entry.durationMs, done: this.applied.length + this.failed.length, total: this.expected });
    return entry;
  }

  fail(failure, traceFile = null) {
    const cur = this.current ?? { cardId: null, messageNdx: null, startedMs: Date.now() };
    const entry = { cardId: cur.cardId, messageNdx: cur.messageNdx, ...failure.toJSON(), trace: traceFile, durationMs: Date.now() - cur.startedMs };
    this.failed.push(entry);
    if (cur.cardId) this.processed.add(cur.cardId);
    this.current = null;
    this.progress({ event: 'card', cardId: entry.cardId, messageNdx: entry.messageNdx, status: 'failed', step: entry.step, reason: entry.reason, path: entry.path ?? null, durationMs: entry.durationMs, done: this.applied.length + this.failed.length, total: this.expected });
    return entry;
  }

  browserError(type, message) {
    this.browserErrors.push({ type, message: String(message).slice(0, 2000), cardId: this.currentCardId, at: this.now().toISOString() });
  }

  apiResponse(status, method, url) {
    this.apiResponses.push({ status, method, url, cardId: this.currentCardId, at: this.now().toISOString() });
  }

  dialog(type, message) {
    this.dialogs.push({ type, message: String(message).slice(0, 2000), cardId: this.currentCardId, at: this.now().toISOString() });
  }

  /** Poslední alert u aktuální karty — text do důvodu selhání apply. */
  lastDialogMessage() {
    const id = this.currentCardId;
    for (let i = this.dialogs.length - 1; i >= 0; i--) {
      if (this.dialogs[i].cardId === id) return this.dialogs[i].message;
    }
    return null;
  }

  finish(remainingCards) {
    this.finishedAt = this.now().toISOString();
    this.complete = true;
    this.remainingCards = remainingCards;
  }

  toJSON() {
    return {
      expected: this.expected,
      applied: this.applied,
      failed: this.failed,
      browserErrors: this.browserErrors,
      apiResponses: this.apiResponses,
      dialogs: this.dialogs,
      current: this.current ? { cardId: this.current.cardId, messageNdx: this.current.messageNdx, startedAt: this.current.startedAt } : null,
      remainingCards: this.remainingCards ?? null,
      startedAt: this.startedAt,
      finishedAt: this.finishedAt,
      complete: this.complete,
    };
  }

  /** Atomický zápis `walkthrough.json` (tmp + rename). */
  write() {
    fs.mkdirSync(this.outputDir, { recursive: true });
    const target = path.join(this.outputDir, 'walkthrough.json');
    const tmp = `${target}.tmp`;
    fs.writeFileSync(tmp, `${JSON.stringify(this.toJSON(), null, 2)}\n`);
    fs.renameSync(tmp, target);
    return target;
  }

  /** JSON řádka průběhu (`event`, `at`, …) — stejný tvar jako EvalProgressWriter. */
  progress(event) {
    if (!this.progressFile) return;
    const line = JSON.stringify({ event: event.event, at: this.now().toISOString(), ...event });
    try {
      fs.appendFileSync(this.progressFile, `${line}\n`);
    } catch {
      // průběh je informace, ne výsledek
    }
  }
}

/** Je selhání podle D95 (karta, chyba prohlížeče, 5xx)? Jen pro závěrečný souhrn specu. */
export function summarize(state) {
  const serverErrors = state.apiResponses.filter((r) => r.status >= 500);
  return {
    expected: state.expected,
    applied: state.applied.length,
    failed: state.failed.length,
    browserErrors: state.browserErrors.length,
    serverErrors: serverErrors.length,
    ok: state.failed.length === 0 && state.browserErrors.length === 0 && serverErrors.length === 0 && state.applied.length === state.expected,
  };
}
