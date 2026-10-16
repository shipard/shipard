/**
 * Čistý parser řádkového protokolu streamovaných akcí dev dashboardu
 * (DevDashboardController: `emitStep` / `emitError` / `emitDone`):
 *
 *   [STEP] Creating data source...\n      → krok
 *   <surový výstup příkazu>\n            → text
 *   [ERROR] ds-create failed (exit 1)\n  → chyba, konec
 *   [DONE] {"id":"…","url":"/…/app/"}\n → hotovo s JSON payloadem
 *
 * `parseStepLines` vytáhne z bufferu všechny ÚPLNÉ řádky a vrátí zbytek —
 * řádek rozdělený hranicí chunku zůstane v `rest`. Prázdné řádky se
 * vynechávají (stejně jako v původní stránce). Bez závislosti na prohlížeči,
 * testy v tests/Unit/dev/stream.test.mjs. Vzor: api/sse.js.
 */

/**
 * @param {string} line  bez koncového `\n`
 * @returns {{kind: 'step'|'error'|'done'|'text', text: string, payload?: unknown}}
 */
export function classifyLine(line) {
  if (line.startsWith('[STEP] ')) return { kind: 'step', text: line.slice(7) };
  if (line.startsWith('[ERROR] ')) return { kind: 'error', text: line.slice(8) };
  if (line.startsWith('[DONE] ') || line === '[DONE]') {
    const raw = line.slice(7);
    let payload = null;
    try {
      payload = raw === '' ? null : JSON.parse(raw);
    } catch {
      payload = null;
    }
    return { kind: 'done', text: raw, payload };
  }
  return { kind: 'text', text: line };
}

/**
 * @param {string} buffer  předchozí `rest` + nový chunk
 * @returns {{lines: Array<ReturnType<typeof classifyLine>>, rest: string}}
 */
export function parseStepLines(buffer) {
  const lines = [];
  let idx;
  while ((idx = buffer.indexOf('\n')) !== -1) {
    const raw = buffer.slice(0, idx).replace(/\r$/, '');
    buffer = buffer.slice(idx + 1);
    if (raw === '') continue;
    lines.push(classifyLine(raw));
  }
  return { lines, rest: buffer };
}
