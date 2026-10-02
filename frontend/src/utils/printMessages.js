// Měkká hlášení builderu tisku (docs/prints.md) — PDF odpověď je nese
// v hlavičce `X-Print-Messages` jako procentově kódované JSON pole.

/**
 * Chybějící nebo poškozená hlavička znamená „žádná hlášení", nikdy chybu —
 * PDF je hotové a náhled se má ukázat i tak.
 *
 * @param {string|null|undefined} header
 * @returns {Array<{severity: string, code: string, text: string}>}
 */
export function parsePrintMessages(header) {
  if (!header) return [];
  try {
    const parsed = JSON.parse(decodeURIComponent(header));
    return Array.isArray(parsed)
      ? parsed.filter((m) => m && typeof m.code === 'string' && typeof m.text === 'string')
      : [];
  } catch {
    return [];
  }
}
