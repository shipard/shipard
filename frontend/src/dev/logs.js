/**
 * Čisté funkce stránky Logy — filtrování záznamů a nabídka zdrojů dat.
 * Záznam = řádek `shipard.log` (ErrorLogger): `{ts, level, ds, request, msg,
 * ctx, exception?{class, message, at, trace[], previous?}}`.
 * Testy: tests/Unit/dev/logs.test.mjs.
 */

export const LEVELS = ['debug', 'info', 'warn', 'error'];

/** Výchozí filtr úrovní — jako původní stránka: jen warn + error. */
export const DEFAULT_LEVELS = { debug: false, info: false, warn: true, error: true };

/** Hodnota filtru „záznamy bez zdroje dat“. */
export const DS_NONE = '__null__';

/** Maximální `limit` API (`DevDashboardController::getLogs`). */
export const MAX_LIMIT = 2000;

/**
 * Vyfiltruje a otočí na nejnovější první.
 * @param {Array<object>} entries  v pořadí ze souboru (nejstarší první)
 * @param {{levels: Record<string, boolean>, ds: string, search: string}} filter
 */
export function filterEntries(entries, { levels, ds, search }) {
  const needle = (search ?? '').trim().toLowerCase();
  const out = [];
  for (let i = entries.length - 1; i >= 0; i--) {
    const e = entries[i];
    if (levels && levels[e.level] === false) continue;
    if (ds === DS_NONE) {
      if (e.ds) continue;
    } else if (ds) {
      if (e.ds !== ds) continue;
    }
    if (needle !== '') {
      const hay = `${e.msg ?? ''}\n${e.exception?.message ?? ''}`.toLowerCase();
      if (!hay.includes(needle)) continue;
    }
    out.push(e);
  }
  return out;
}

/**
 * Nabídka selectu zdrojů dat: Vše · (žádný) · ID podle výskytu v záznamech;
 * vybraná hodnota bez záznamů zůstane v nabídce jako „<id> (bez záznamů)“,
 * aby filtr z `?ds=` nezmizel.
 * @param {Array<object>} entries
 * @param {string} selected
 * @returns {Array<{value: string, label: string}>}
 */
export function dsOptions(entries, selected) {
  const ids = new Set();
  let hasNone = false;
  for (const e of entries) {
    if (e.ds) ids.add(e.ds);
    else hasNone = true;
  }
  const options = [{ value: '', label: 'Vše' }];
  if (hasNone || selected === DS_NONE) options.push({ value: DS_NONE, label: '(žádný)' });
  for (const id of [...ids].sort()) options.push({ value: id, label: id });
  if (selected && selected !== DS_NONE && !ids.has(selected)) {
    options.push({ value: selected, label: `${selected} (bez záznamů)` });
  }
  return options;
}

/** Zkrácené ID zdroje dat do čipu: `abcd…`. */
export function shortDs(ds) {
  if (!ds) return '';
  return ds.length > 4 ? `${ds.slice(0, 4)}…` : ds;
}
