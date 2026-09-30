/**
 * API helpers pro majetek — „Odpisy za období“ (docs/assets.md D33).
 *
 *   GET  /_assets/depreciation-run/options   nabídka dialogu (okruhy, období, četnost)
 *   GET  /_assets/depreciation-run/preview   karty s plánovaným odpisem + vyloučené
 *   POST /_assets/depreciation-run           potvrzení (idempotentní)
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
