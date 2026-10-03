/**
 * API helper for the prints endpoint (docs/prints.md):
 *   GET /_prints/{printId}/{recordId}[?language=cs]   — PDF of one record (inline)
 *
 * Auth goes through the Bearer header, so the PDF is fetched as a Blob and
 * shown / saved from an object URL — a plain <iframe src> would not carry it.
 */

import { getBlob } from './client.js';
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
