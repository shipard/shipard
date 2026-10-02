/**
 * API helper for the prints endpoint (docs/prints.md):
 *   GET /_prints/{printId}/{recordId}   — PDF of one record (inline)
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
 * @returns {Promise<{success: true, blob: Blob, fileName: string,
 *   messages: Array<{severity: string, code: string, text: string}>}
 *   |{success: false, error: {code: string, message: string}}|null>} null = 401
 */
export async function fetchPrintPdf(printId, recordId) {
  const res = await getBlob(`/_prints/${encodeURIComponent(printId)}/${encodeURIComponent(recordId)}`);
  if (res === null || !res.success) return res;

  return {
    success: true,
    blob: res.blob,
    fileName: fileNameFromContentDisposition(res.headers.get('Content-Disposition')) ?? 'document.pdf',
    messages: parsePrintMessages(res.headers.get('X-Print-Messages')),
  };
}
