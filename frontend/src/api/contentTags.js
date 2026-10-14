/**
 * API helpers for content tag endpoints (tasks/content-tag-ui.md):
 *   POST /_exchange/content-tags/materialize — create the accounting item
 *        for a content tag (dashboard card "Nová kategorie", settings page)
 *   GET  /_exchange/content-tags/overview    — taxonomy mapping state +
 *        reverse account→tag suggestions (settings page)
 *   POST /_exchange/content-tags/tag-items   — bulk-tag existing items
 */

import { get, post } from './client.js';

/**
 * @param {string} tag  Content tag key (e.g. 'vehicle.fuel')
 * @param {string|null} [account]  Explicit account number (tag without an offer entry)
 * @returns {Promise<{success: boolean, data?: {itemId: number, code: string, name: string}, error?: object}>}
 */
export async function materializeContentTag(tag, account = null) {
  const body = account ? { tag, account } : { tag };
  return await post('/_exchange/content-tags/materialize', body);
}

/**
 * @returns {Promise<{success: boolean, data?: {available: boolean, chartVariant: string|null,
 *   tags: Array<object>, untagged: Array<object>}, error?: object}>}
 */
export async function fetchContentTagsOverview() {
  return await get('/_exchange/content-tags/overview');
}

/**
 * @param {Array<{id: number, tags: string[]}>} items
 * @returns {Promise<{success: boolean, data?: {updated: Array<object>, failed: Array<object>}, error?: object}>}
 */
export async function tagContentItems(items) {
  return await post('/_exchange/content-tags/tag-items', { items });
}
