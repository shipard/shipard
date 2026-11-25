/**
 * Akce záznamu s obsluhou v modulu (`recordActions`, #172 D12–D15).
 *
 * Backend endpoint (`src/Api/Controller/RecordActionController.php`):
 *   POST /_ui/record-action/{table}/{recordId}/{globalActionId}
 *     JSON {"values": {...}}, nebo multipart: část `values` (JSON)
 *     a soubory `files[<id pole>]`  → {message, level}
 *
 * Chybové kódy: RECORD_ACTION_NOT_FOUND (404), FORBIDDEN* (403),
 * RECORD_NOT_FOUND (404), RECORD_ACTION_NOT_AVAILABLE (409 — záznam se od
 * vykreslení detailu změnil), RECORD_ACTION_UNKNOWN_FIELD (400),
 * RECORD_ACTION_INVALID (422, details[] {field, code, message} — chyby
 * polí dialogu), RECORD_ACTION_FAILED (500); zdroj dat jen pro čtení 403
 * DS_READ_ONLY.
 */

import { post, postForm } from './client.js';

/**
 * @param {{table: string, recordId: number}} target `action.target` z detailu
 * @param {string} actionId globální id akce (`action.id`)
 * @param {{values: object}|FormData} body tělo z `requestBody()` (recordActionRequest.js)
 */
export function runRecordAction(target, actionId, body) {
  const path = `/_ui/record-action/${encodeURIComponent(target.table)}`
    + `/${encodeURIComponent(target.recordId)}/${encodeURIComponent(actionId)}`;
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
  return isForm ? postForm(path, body) : post(path, body);
}
