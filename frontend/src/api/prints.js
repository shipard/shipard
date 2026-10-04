/**
 * API helpers for the prints endpoints (docs/prints.md):
 *   GET  /_prints/{printId}/{recordId}[?language=cs]            — PDF of one record (inline)
 *   GET  /_prints/{printId}/{recordId}/send-draft[?language=cs] — proposal for sending by e-mail
 *   POST /_prints/{printId}/{recordId}/send                     — send the record by e-mail
 *
 * Auth goes through the Bearer header, so the PDF is fetched as a Blob and
 * shown / saved from an object URL — a plain <iframe src> would not carry it.
 */

import { get, getBlob, post } from './client.js';
import { fileNameFromContentDisposition } from '../utils/download.js';
import { parsePrintMessages } from '../utils/printMessages.js';

/**
 * @param {string} printId
 * @param {number|string} recordId
 * @param {?string} [language] Print language; omitted = the server picks it
 *   from the document's partner. The language actually used comes back in
 *   `language` (response header `Content-Language`).
 * @returns {Promise<{success: true, blob: Blob, fileName: string, language: ?string,
 *   messages: Array<{severity: string, code: string, text: string}>}
 *   |{success: false, error: {code: string, message: string}}|null>} null = 401
 */
export async function fetchPrintPdf(printId, recordId, language = null) {
  const query = language ? `?language=${encodeURIComponent(language)}` : '';
  const res = await getBlob(`/_prints/${encodeURIComponent(printId)}/${encodeURIComponent(recordId)}${query}`);
  if (res === null || !res.success) return res;

  return {
    success: true,
    blob: res.blob,
    fileName: fileNameFromContentDisposition(res.headers.get('Content-Disposition')) ?? 'document.pdf',
    language: res.headers.get('Content-Language'),
    messages: parsePrintMessages(res.headers.get('X-Print-Messages')),
  };
}

/**
 * Proposal for sending a record by e-mail (#90 D38): recipients with the
 * reason each address is there, allowed senders and the default one,
 * subject, body, language and attachments. Creates nothing.
 *
 * @param {string} printId
 * @param {number|string} recordId
 * @param {?string} [language] Message language; omitted = by the document's partner.
 * @returns {Promise<{success: boolean, data?: object, error?: object}|null>}
 */
export async function fetchSendDraft(printId, recordId, language = null) {
  const query = language ? `?language=${encodeURIComponent(language)}` : '';
  return await get(`/_prints/${encodeURIComponent(printId)}/${encodeURIComponent(recordId)}/send-draft${query}`);
}

/**
 * Send a record by e-mail — always creates a new message in Sent messages.
 *
 * @param {string} printId
 * @param {number|string} recordId
 * @param {{from: ?string, to: string[], cc: string[], subject: string, body: string,
 *   language: ?string, attachmentIds: number[]}} payload
 * @returns {Promise<{success: boolean, data?: {sentMessageId: number, transportState: string,
 *   messages: Array<{severity: string, code: string, text: string}>}, error?: object}|null>}
 */
export async function sendPrint(printId, recordId, payload) {
  return await post(`/_prints/${encodeURIComponent(printId)}/${encodeURIComponent(recordId)}/send`, payload);
}
