/**
 * API helpers pro majetek — „Odpisy za období“ (docs/assets.md D33)
 * a „Odpisy a zaúčtování za období“ (D50–D55).
 *
 *   GET  /_assets/depreciation-run/options   nabídka dialogu (okruhy, období, četnost, poslední zaúčtování)
 *   GET  /_assets/depreciation-run/preview   karty s plánovaným odpisem + vyloučené
 *   POST /_assets/depreciation-run           potvrzení (idempotentní)
 *
 *   GET  /_assets/posting/preview            účetní okruh: nové odpisy, události, souhrn účtů
 *   POST /_assets/posting                    odpisy + účetní doklad za období
 *   POST /_assets/posting/cancel             zrušení zaúčtování posledního období
 */

import { get, post } from './client.js';

export async function depreciationRunOptions() {
  return await get('/_assets/depreciation-run/options');
}

/**
 * @param {'tax'|'acc'} scope
 * @param {number} period id účetního roku (nebo měsíce při měsíční četnosti účetních odpisů)
 * @param {number|null} asset jen jedna karta
 */
export async function depreciationRunPreview(scope, period, asset = null) {
  const params = new URLSearchParams({ scope, period: String(period) });
  if (asset != null) params.set('asset', String(asset));
  return await get(`/_assets/depreciation-run/preview?${params.toString()}`);
}

export async function depreciationRun(scope, period, asset = null) {
  const body = { scope, period };
  if (asset != null) body.asset = asset;
  return await post('/_assets/depreciation-run', body);
}

/** @param {number} period id účetního roku (nebo měsíce při měsíční četnosti) */
export async function postingPreview(period) {
  return await get(`/_assets/posting/preview?period=${encodeURIComponent(String(period))}`);
}

export async function postingRun(period) {
  return await post('/_assets/posting', { period });
}

export async function postingCancel(period) {
  return await post('/_assets/posting/cancel', { period });
}
