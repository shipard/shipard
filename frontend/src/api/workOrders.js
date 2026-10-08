/**
 * API helpers pro zakázky — periodická fakturace (docs/work-orders.md D24).
 *
 * Backend (modules/economy/workOrders/src/WorkOrdersInvoicingController.php):
 *   POST /_work-orders/invoice-run        {workOrder}  Vystavit dlužná období
 *   POST /_work-orders/periods/regenerate {period}     Přegenerovat koncept období
 *   POST /_work-orders/periods/restore    {period}     Obnovit zastavené období
 */

import { post } from './client.js';

/** Vystaví všechna dlužná období zakázky (i přes pojistku dohánění). Vrací RunReport. */
export async function issueDuePeriods(workOrderId) {
  return await post('/_work-orders/invoice-run', { workOrder: workOrderId });
}

/** Přegeneruje koncept období z aktuální zakázky — doklad si drží id. */
export async function regeneratePeriod(periodId) {
  return await post('/_work-orders/periods/regenerate', { period: periodId });
}

/** Obnoví zastavené období (doklad v koši): vrátí ho do naplánováno a hned vystaví. */
export async function restorePeriod(periodId) {
  return await post('/_work-orders/periods/restore', { period: periodId });
}
