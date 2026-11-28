/**
 * Tiskové úlohy pro účtenkové tiskárny (#177 task 4b, docs/prints.md kap. 14).
 *
 * Backend endpointy (`src/Api/Controller/PrintJobsController.php`):
 *   POST /_print-jobs                 {kind?, printId?, recordId?, deviceId, role?}
 *                                     → 201 {jobId, peripheralId, format, data, warnings}
 *   GET  /_print-jobs/{jobId}         → {jobId, kind, state, peripheralId, format, data, attempts, warnings}
 *   POST /_print-jobs/{jobId}/result  {status, errorCode?} → {state, attempts}
 *
 * Volání se neposílají jednotlivě — celý postup (úloha → most → výsledek)
 * drží `native/printJobs.js`; tady je jen jeho vazba na klienta API a most.
 */

import { get, post } from './client.js';
import { nativeCall, nativeInfo } from '../native/bridge.js';
import { createPrintJobs } from '../native/printJobs.js';

const printJobs = createPrintJobs({ get, post, nativeCall, nativeInfo });

/** Založí úlohu, předá ji mostu a zapíše výsledek. */
export const submitPrintJob = printJobs.submitPrintJob;
/** Tatáž úloha znovu (`reprint`). */
export const retryPrintJob = printJobs.retryPrintJob;
/** Zkusit znovu po neúspěchu — podle toho, jestli úloha vznikla. */
export const retryFailedPrintJob = printJobs.retryFailedPrintJob;
