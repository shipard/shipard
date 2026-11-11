/**
 * API helpers for document actions outside CRUD and the edit form.
 *
 * Backend endpoint (see `modules/docs/core/src/Corrective/CorrectiveDocController.php`):
 *   POST /_docs/corrective  body {"docId": N}  → {docId: <nový koncept>}
 */

import { post } from './client.js';

/**
 * Vystavit opravný doklad k potvrzené faktuře vydané (#92 D6, task B U8):
 * služba založí koncept s převrácenými řádky, odkazem na původní doklad,
 * datem plnění a kurzem původního dokladu; důvod opravy doplní uživatel.
 * Chybové kódy: BAD_REQUEST, NOT_FOUND, CORRECTIVE_SOURCE_INVALID (422 —
 * jiný typ nebo stav než V pořádku), CORRECTIVE_APPLY_FAILED (422,
 * details[] = hlášky applieru); read-only režim 403 DS_READ_ONLY.
 */
export async function createCorrectiveDoc(docId) {
  return await post('/_docs/corrective', { docId });
}
