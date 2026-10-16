import { get } from './client.js';

/**
 * Přehled firmy — GET /_ui/overview (tasks/company-overview.md, #120).
 * Vrací celou obálku `{success, data|error}`: 403 FORBIDDEN (ne-admin)
 * i chyba sítě se překládají přes translateError.
 *
 * data: `{asOf, sections: [{id, title, state, items: [{id, label, kind,
 * amount: {value, currency}|null, asOf, state, note, dueDate, link}],
 * messages: [{severity, code, text, link}]}]}`.
 *
 * @returns {Promise<{success: boolean, data?: object, error?: object}|null>}
 */
export function fetchOverview() {
  return get('/_ui/overview');
}
