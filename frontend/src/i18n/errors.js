// Maps server error responses to user-facing localized strings.
//
// Server returns `{code, message, details?}` on failure. We try to translate
// `code` via the dictionary (`error.<CODE>` keys); if the code is unknown,
// we fall back to the server's `message` (English by convention) so the
// user still sees something rather than a raw key. Some codes deliberately
// have no dictionary entry because the server already localizes the message
// in the request language — the AI setup errors of the assistant
// (AI_NOT_CONFIGURED: the same sentence as in Settings → AI → Functions,
// from the core.ai.texts catalog).
//
// Validation errors carry `details[]` — currently we surface only the first
// detail's `field` / `value` as ICU params for translations that want them.
// Validation-error display is handled separately in FormEditor.svelte, which
// has its own banner with two levels: form-level errors (`_form` / unknown
// field) and field-level errors (matched against the form's columns), the
// latter also shown next to the input. translateError() is unaffected — it
// still maps the top-level `error.code` → text and is used for the generic
// (non-VALIDATION) error banner / `alert()` line.

import { t } from './index.js';

/**
 * @param {{code?: string, message?: string, details?: Array}|null|undefined} error
 * @returns {string} Localized error message, with sensible fallbacks.
 */
export function translateError(error) {
  if (!error) return t('common.unknownError');

  const code = error.code;
  if (!code) {
    return error.message ?? t('common.unknownError');
  }

  const params = {
    message: error.message ?? '',
    field: error.details?.[0]?.field ?? '',
    value: error.details?.[0]?.value ?? '',
  };

  const key = `error.${code}`;
  const translated = t(key, params);

  // t() returns the key string when the dictionary has no entry — that's
  // our "unknown code" signal. Fall back to the server message (which the
  // backend writes in English) rather than show "error.SOMETHING_OBSCURE"
  // to the user.
  if (translated === key) {
    return error.message ?? t('common.unknownError');
  }
  return translated;
}
