/**
 * Klient JSON API dev dashboardu (`/_dev/api/…`). Bez autentizace — ochrana
 * je mód development + důvěryhodná síť, stejně jako dřív. Obálka odpovědí
 * je `src/Api/Response.php`: `{success, data}` / `{success:false, error:{code,
 * message}}`; streamované akce vrací `text/plain` řádky (stream.js).
 */
import { parseStepLines, classifyLine } from './stream.js';

export const API_BASE = '/_dev/api';

export class ApiError extends Error {
  /**
   * @param {string} code
   * @param {string} message
   * @param {number} status
   */
  constructor(code, message, status, details = null) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    /** Doplňující data chyby ze serveru (`error.details`), např. `{jobId}` u 409 JOB_RUNNING. */
    this.details = details;
  }
}

async function parseEnvelope(res) {
  let payload = null;
  try {
    payload = await res.json();
  } catch {
    payload = null;
  }
  if (!res.ok || !payload || payload.success !== true) {
    const err = payload && payload.error ? payload.error : {};
    throw new ApiError(err.code ?? `HTTP_${res.status}`, err.message ?? `HTTP ${res.status}`, res.status, err.details ?? null);
  }
  return payload.data;
}

/** @param {string} path  relativně k API_BASE, včetně query */
export async function getJson(path) {
  const res = await fetch(API_BASE + path, { headers: { Accept: 'application/json' } });
  return parseEnvelope(res);
}

export async function postJson(path, body) {
  const res = await fetch(API_BASE + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body ?? {}),
  });
  return parseEnvelope(res);
}

export async function putJson(path, body) {
  const res = await fetch(API_BASE + path, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(body ?? {}),
  });
  return parseEnvelope(res);
}

/** Absolutní URL endpointu (pro `<iframe src>`, `<a href>`). */
export function apiUrl(path) {
  return API_BASE + path;
}

/**
 * Streamovaná akce: POST a čtení řádků po kouscích. Chyba před začátkem
 * streamu (400 validace, 404) přijde jako ApiError z JSON obálky. Každý
 * řádek jde do `onLine({kind, text, payload})`; vrací souhrn s posledním
 * `done` a `error` řádkem (jeden z nich, nebo žádný = stream skončil bez
 * značky).
 *
 * @param {string} path
 * @param {{method?: string, body?: unknown, onLine?: (line: object) => void, signal?: AbortSignal}} [options]
 * @returns {Promise<{done: object|null, error: object|null}>}
 */
export async function streamLines(path, { method = 'POST', body, onLine, signal } = {}) {
  const init = { method, signal, headers: {} };
  if (body !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  const res = await fetch(API_BASE + path, init);
  if (!res.ok) {
    await parseEnvelope(res); // vyhodí ApiError
  }
  const summary = { done: null, error: null };
  const handle = (line) => {
    if (line.kind === 'done') summary.done = line;
    else if (line.kind === 'error') summary.error = line;
    if (onLine) onLine(line);
  };

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let rest = '';
  for (;;) {
    const { value, done } = await reader.read();
    if (done) break;
    rest += decoder.decode(value, { stream: true });
    const parsed = parseStepLines(rest);
    rest = parsed.rest;
    parsed.lines.forEach(handle);
  }
  rest += decoder.decode();
  if (rest.trim() !== '') handle(classifyLine(rest.replace(/\r$/, '')));
  return summary;
}
