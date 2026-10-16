/**
 * Čisté výpočty nad souborem běhu `shpd.eval.run.v1` pro obrazovky
 * Testování (docs/test-corpus.md § 18): metriky po skupinách s rozpětím
 * opakování, nejčastější odchylky, stabilita, popisky a formát metrik,
 * barva podle počtu odchylek. Testy: tests/Unit/dev/metrics.test.mjs.
 */
import { formatNumber, formatPercent, formatUsd, formatMs } from '../format.js';

/** Skupiny polí v pořadí EvalFieldGroups + Celkem. */
export const FIELD_GROUPS = [
  ['header', 'Hlavička'],
  ['supplier', 'Dodavatel'],
  ['customer', 'Odběratel'],
  ['vat', 'DPH'],
  ['rows', 'Řádky'],
  ['vatRecap', 'Rekapitulace'],
  ['totals', 'Součty'],
  ['tag', 'Štítek dokladu'],
  ['rowExceptions', 'Výjimky štítků řádků'],
  ['classification', 'Klasifikace zprávy'],
  ['all', 'Celkem'],
];

export const DOCUMENT_METRICS = [
  ['documents.clean', 'Doklady bez odchylky'],
  ['documents.amountsCorrect', 'Správné částky'],
  ['documents.rowCountOk', 'Správný počet řádků'],
  ['documents.classificationOk', 'Správná klasifikace'],
  ['documents.tagOk', 'Správný štítek'],
];

export const COST_METRICS = [
  ['calls.total', 'Volání'],
  ['calls.ok', 'Úspěšná'],
  ['calls.failed', 'Selhaná'],
  ['cost.usd', 'Cena běhu (extrakce)'],
  ['cost.perDocument', 'Cena na doklad (extrakce)'],
  ['tags.calls', 'Volání štítků (LLM)'],
  ['tags.usd', 'Cena štítků'],
  ['cost.totalUsd', 'Cena běhu celkem'],
  ['cost.totalPerDocument', 'Cena na doklad celkem'],
  ['tokens.input', 'Tokeny vstup'],
  ['tokens.output', 'Tokeny výstup'],
  ['tokens.inputPerDocument', 'Vstup na doklad'],
  ['tokens.outputPerDocument', 'Výstup na doklad'],
  ['time.ms', 'Čas běhu'],
  ['time.perDocumentMs', 'Čas na doklad'],
];

export const STATE_LABELS = {
  match: 'shoda',
  matchNormalized: 'po normalizaci',
  missing: 'chybí',
  extra: 'navíc',
  wrong: 'špatně',
  notCompared: 'neporovnáno',
  failed: 'selhalo',
  ok: 'OK',
};

const FIELD_LABELS = Object.fromEntries(FIELD_GROUPS);

/** Popisek metriky; neznámý klíč zůstane klíčem. */
export function metricLabel(key) {
  const fixed = Object.fromEntries([...DOCUMENT_METRICS, ...COST_METRICS]);
  if (fixed[key]) return fixed[key];
  const m = /^fields\.([a-zA-Z]+)\.(n|hit|normalized|missing|extra|wrong)$/.exec(key);
  if (m) {
    const group = FIELD_LABELS[m[1]] ?? m[1];
    const part = { n: 'polí', hit: 'shoda', normalized: 'po normalizaci', missing: 'chybí', extra: 'navíc', wrong: 'špatně' }[m[2]];
    return `${group} — ${part}`;
  }
  if (key.startsWith('calls.failed.')) return `Selhání: ${key.slice('calls.failed.'.length)}`;
  return key;
}

/** Druh hodnoty metriky podle klíče: percent | usd | ms | int | number. */
export function metricKind(key) {
  if (key.startsWith('cost.')) return 'usd';
  if (key.startsWith('time.')) return 'ms';
  if (key.startsWith('documents.')) return 'percent';
  if (key.startsWith('fields.')) return /\.(n|missing|extra|wrong)$/.test(key) ? 'int' : 'percent';
  if (key.startsWith('calls.') || key.startsWith('tokens.')) return 'int';
  return 'number';
}

export function formatMetric(key, value, digits) {
  if (value === null || value === undefined) return '—';
  switch (metricKind(key)) {
    case 'percent': return formatPercent(value, digits ?? 1);
    case 'usd': return formatUsd(value, digits ?? 4);
    case 'ms': return formatMs(value);
    case 'int': return formatNumber(value, Number.isInteger(value) ? 0 : (digits ?? 2));
    default: return formatNumber(value, digits ?? 2);
  }
}

/** Rozdíl metriky se znaménkem ve stejném druhu. */
export function formatMetricDelta(key, delta) {
  if (delta === null || delta === undefined) return '—';
  if (Math.abs(delta) < 1e-9) return '±0';
  const sign = delta > 0 ? '+' : '−';
  const abs = Math.abs(delta);
  switch (metricKind(key)) {
    case 'percent': return `${sign}${formatNumber(abs * 100, 1)} b.`;
    case 'usd': return `${sign}${formatNumber(abs, 4)} USD`;
    case 'ms': return `${sign}${formatMs(abs)}`;
    case 'int': return `${sign}${formatNumber(abs, Number.isInteger(abs) ? 0 : 2)}`;
    default: return `${sign}${formatNumber(abs, 2)}`;
  }
}

/** „94,1 % (93,8–94,4)“ — průměr s rozpětím opakování, bez rozpětí jen průměr. */
export function rangeText(avg, min, max, kind = 'percent') {
  if (avg === null || avg === undefined) return '—';
  const f = kind === 'percent'
    ? (v) => formatNumber(v * 100, 1)
    : (v) => formatNumber(v, Number.isInteger(v) ? 0 : 2);
  const main = kind === 'percent' ? `${f(avg)} %` : f(avg);
  if (min === null || min === undefined || max === null || max === undefined || (min === max && min === avg)) return main;
  return `${main} (${f(min)}–${f(max)})`;
}

/**
 * Řádky tabulky metrik polí po skupinách.
 * @param {Record<string, number>} metrics
 * @param {Record<string, {min:number,max:number}>|null} range
 */
export function fieldMetricRows(metrics, range) {
  const r = range ?? {};
  return FIELD_GROUPS
    .filter(([id]) => metrics[`fields.${id}.n`] !== undefined || metrics[`fields.${id}.hit`] !== undefined)
    .map(([id, label]) => ({
      id,
      label,
      n: metrics[`fields.${id}.n`] ?? null,
      hit: { avg: metrics[`fields.${id}.hit`] ?? null, min: r[`fields.${id}.hit`]?.min ?? null, max: r[`fields.${id}.hit`]?.max ?? null },
      normalized: metrics[`fields.${id}.normalized`] ?? null,
      missing: metrics[`fields.${id}.missing`] ?? null,
      extra: metrics[`fields.${id}.extra`] ?? null,
      wrong: metrics[`fields.${id}.wrong`] ?? null,
    }));
}

/** Řádky metrik s rozpětím pro dané klíče (doklady, cena a čas). */
export function metricRows(keys, metrics, range) {
  const r = range ?? {};
  return keys
    .filter(([key]) => metrics[key] !== undefined)
    .map(([key, label]) => ({ key, label, value: metrics[key], min: r[key]?.min ?? null, max: r[key]?.max ?? null }));
}

/**
 * Nejčastější odchylky: počet úspěšných volání s odchylkou na uzlu a podíl
 * z úspěšných volání.
 * @param {Record<string, number>} deviationsByField
 */
export function topDeviations(deviationsByField, callsOk, limit = 15) {
  return Object.entries(deviationsByField ?? {})
    .map(([node, count]) => ({ node, count, share: callsOk > 0 ? count / callsOk : null }))
    .sort((a, b) => b.count - a.count || a.node.localeCompare(b.node))
    .slice(0, limit);
}

/** Nestabilní uzly (podíl < 1) od nejhoršího; `null` mapa = jedno opakování. */
export function unstableNodes(stability, limit = 15) {
  if (!stability) return null;
  return Object.entries(stability)
    .filter(([, ratio]) => ratio < 1)
    .map(([node, ratio]) => ({ node, ratio }))
    .sort((a, b) => a.ratio - b.ratio || a.node.localeCompare(b.node))
    .slice(0, limit);
}

/** Úroveň podle počtu odchylek: ok (0) · warn (1–3) · bad (4+) · failed (null). */
export function deviationLevel(count) {
  if (count === null || count === undefined) return 'failed';
  if (count === 0) return 'ok';
  if (count <= 3) return 'warn';
  return 'bad';
}

/** Metriky do přehledu porovnání (EvalRunComparison::HEADLINE_METRICS); ostatní za rozbalovátkem. */
export function isHeadlineMetric(key) {
  return /^(fields\.[a-zA-Z]+\.hit|fields\.all\.normalized|documents\.|calls\.(total|ok|failed)$|cost\.|tokens\.|time\.)/.test(key);
}

/** Součet pole čísel s vynecháním null; prázdné → null. */
export function sumOf(values) {
  let sum = null;
  for (const v of values) {
    if (v === null || v === undefined) continue;
    sum = (sum ?? 0) + v;
  }
  return sum;
}

/** Nejvyšší počet opakování mezi běhy (sloupce matice). */
export function maxRepeat(runs) {
  return runs.reduce((m, r) => Math.max(m, r.repeat ?? 1, ...(r.repeats ?? []).map((x) => x.repeat)), 1);
}
