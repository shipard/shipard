/**
 * Chat API client.
 *
 * CRUD goes through the buffered `client.js` helpers (auth, 401-refresh, JSON).
 * The streamed turn endpoint can't use them — it needs a raw `fetch` whose body
 * is read incrementally — so it follows the direct-fetch pattern from
 * `attachments.js` (bearer token + Accept-Language), and parses SSE frames via
 * the pure `parseSseFrames`.
 *
 * Backend (see src/Api/Controller/ChatController.php):
 *   GET    /_chat/conversations
 *   POST   /_chat/conversations
 *   GET    /_chat/conversations/{id}
 *   PATCH  /_chat/conversations/{id}
 *   DELETE /_chat/conversations/{id}
 *   POST   /_chat/conversations/{id}/messages   (SSE stream)
 */

import { get, post, patch, del } from './client.js';
import { API_BASE_URL } from './config.js';
import { language } from '../i18n/index.js';
import { parseSseFrames } from './sse.js';

const TOKEN_KEY = 'shpd_token';

export function listConversations() {
  return get('/_chat/conversations');
}

export function createConversation(title = null, section = null) {
  return post('/_chat/conversations', { title, section });
}

export function getConversation(id) {
  return get(`/_chat/conversations/${id}`);
}

export function renameConversation(id, title) {
  return patch(`/_chat/conversations/${id}`, { title });
}

export function deleteConversation(id) {
  return del(`/_chat/conversations/${id}`);
}

/**
 * Streams an assistant reply over SSE.
 *
 * @param {number} conversationId
 * @param {string} text
 * @param {{
 *   onTextDelta?: (text: string) => void,
 *   onToolCall?: (call: {name: string, arguments: object}) => void,
 *   onThinking?: () => void,
 *   onComplete?: (payload: object) => void,
 *   onError?: (err: {code: string, message: string}) => void,
 * }} handlers
 *   `onThinking` = model začal přemýšlet (thinking blok, D56) — bez textu;
 *   trvá do dalšího text-delta / tool-call / konce odpovědi.
 */
export async function sendMessageStream(conversationId, text, handlers = {}) {
  const { onTextDelta, onToolCall, onThinking, onComplete, onError } = handlers;
  const token = localStorage.getItem(TOKEN_KEY);

  let res;
  try {
    res = await fetch(`${API_BASE_URL}/_chat/conversations/${conversationId}/messages`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept-Language': language.current,
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: JSON.stringify({ text }),
    });
  } catch {
    onError?.({ code: 'NETWORK_ERROR', message: 'network' });
    return;
  }

  if (!res.ok || !res.body) {
    // Mid-stream token refresh is out of scope (v1) — surface a clear 401.
    if (res.status === 401) {
      onError?.({ code: 'UNAUTHORIZED', message: `HTTP ${res.status}` });
      return;
    }
    // Server-side refusal before the stream starts (NO_BACKEND,
    // AI_NOT_CONFIGURED, …) comes as a JSON
    // error body — pass code/message/details on. The AI setup codes carry
    // a message already localized by the server (core.ai.texts, the same
    // sentence as in Settings → AI → Functions), so translateError() falls
    // back to it on purpose; a body that is not JSON degrades to STREAM_ERROR.
    const err = res.ok ? null : await readJsonError(res);
    onError?.(err ?? { code: 'STREAM_ERROR', message: `HTTP ${res.status}` });
    return;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buf = '';
  // Stream musí skončit terminálním frame (message-complete | error) — konec
  // bez něj (backend umřel před prvním eventem, prázdné tělo) by jinak byl
  // tichý: žádný callback, spinner navěky.
  let terminated = false;
  const markTerminated = () => { terminated = true; };

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
        dispatchFrame(frame.event, data, { onTextDelta, onToolCall, onThinking, onComplete, onError, markTerminated });
      }
    }
  } catch {
    onError?.({ code: 'STREAM_ERROR', message: 'stream interrupted' });
    return;
  }

  if (!terminated) {
    onError?.({ code: 'STREAM_ERROR', message: 'stream ended unexpectedly' });
  }
}

/**
 * `{code, message, details?}` from a JSON error response, or null when the
 * body is not the API's error envelope.
 */
async function readJsonError(res) {
  try {
    const json = await res.json();
    const error = json?.error;
    if (!error || typeof error.code !== 'string') return null;
    return { code: error.code, message: error.message ?? '', details: error.details };
  } catch {
    return null;
  }
}

function dispatchFrame(event, data, { onTextDelta, onToolCall, onThinking, onComplete, onError, markTerminated }) {
  switch (event) {
    case 'text-delta':
      onTextDelta?.(data.text ?? '');
      break;
    case 'tool-call':
      onToolCall?.({ name: data.name, arguments: data.arguments ?? {} });
      break;
    case 'thinking':
      onThinking?.();
      break;
    case 'message-complete':
      markTerminated?.();
      onComplete?.(data);
      break;
    case 'error':
      markTerminated?.();
      onError?.({ code: data.code ?? 'LLM_ERROR', message: data.message ?? '' });
      break;
    // unknown events ignored
  }
}
