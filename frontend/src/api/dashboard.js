import { get, post, put } from './client.js';
import { API_BASE_URL } from './config.js';
import { language } from '../i18n/index.js';
import { parseSseFrames } from './sse.js';

const TOKEN_KEY = 'shpd_token';

/**
 * Fetch dashboard data — feed akčních karet + AI summary counts.
 * Vrací `{ generatedAt, summary, cards }` nebo null při selhání.
 * Se `section` vrací server jen karty té sekce (`{ generatedAt, cards }`,
 * bez summary/readySummary/capabilities) — blok karet v scoped chatu.
 */
export async function fetchDashboard(section = null) {
  const query = section ? `?section=${encodeURIComponent(section)}` : '';
  const res = await get(`/_ui/dashboard${query}`);
  return res?.success ? res.data : null;
}

/**
 * Přepne workflow stav zprávy došlé pošty. Tělo jen s docState = state
 * transition (FormController), takže projde i pro read-only stavy.
 * Používají to feed akce trash_message (90) / archive_message (80).
 */
export async function setMessageDocState(messageNdx, docState) {
  return put(`/_ui/form/core_mail_incoming_messages/save/${messageNdx}`, { docState });
}

/**
 * „Archivovat vše“ v sekci Ostatní (tasks/mail-other-attention.md D5):
 * server odklidí všechny čekající řádky Ostatní s pozorností info / promo
 * (i nad stropem 30 karet) a vrátí jejich id pro Vrátit v toastu.
 *
 * @returns {Promise<{success: boolean, data?: {archived: number[], count: number}, error?: object}>}
 */
export async function archiveInformational() {
  return await post('/_mail/messages/archive-informational', {});
}

/**
 * Vrátit po Archivovat vše — jen zprávy archivované ručně (bez pravidla),
 * zpět do Nové bez nové analýzy.
 *
 * @param {number[]} ids
 * @returns {Promise<{success: boolean, data?: {restored: number}, error?: object}>}
 */
export async function restoreArchived(ids) {
  return await post('/_mail/messages/restore-archived', { ids });
}

/**
 * Streams the AI feed summary over SSE (GET /_ui/dashboard/summary).
 *
 * Follows the direct-fetch pattern from `chat.js` (bearer token +
 * Accept-Language, `parseSseFrames`). Events: `text {delta}` (cache miss
 * only), `done {text, cached}` (`text` null = empty feed / degradation),
 * `error {message}`.
 *
 * @param {{
 *   onDelta?: (delta: string) => void,
 *   onDone?: (text: string|null, cached: boolean) => void,
 *   onError?: (message: string) => void,
 * }} handlers
 * @returns {{ close: () => void }} handle — close() aborts the stream
 *   (component unmount / dashboard refresh); no handler fires after that.
 */
export function streamDashboardSummary(handlers = {}) {
  const { onDelta, onDone, onError } = handlers;
  const controller = new AbortController();

  (async () => {
    const token = localStorage.getItem(TOKEN_KEY);

    let res;
    try {
      res = await fetch(`${API_BASE_URL}/_ui/dashboard/summary`, {
        headers: {
          'Accept-Language': language.current,
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        signal: controller.signal,
      });
    } catch {
      if (!controller.signal.aborted) onError?.('network');
      return;
    }

    if (!res.ok || !res.body) {
      onError?.(`HTTP ${res.status}`);
      return;
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buf = '';

    try {
      for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += decoder.decode(value, { stream: true });

        const { frames, rest } = parseSseFrames(buf);
        buf = rest;

        for (const frame of frames) {
          let data;
          try {
            data = JSON.parse(frame.data);
          } catch {
            continue; // skip malformed frame, keep streaming
          }
          if (frame.event === 'text') {
            onDelta?.(data.delta ?? '');
          } else if (frame.event === 'done') {
            onDone?.(data.text ?? null, data.cached ?? false);
          } else if (frame.event === 'error') {
            onError?.(data.message ?? '');
          }
        }
      }
    } catch {
      if (!controller.signal.aborted) onError?.('stream interrupted');
    }
  })();

  return { close: () => controller.abort() };
}
