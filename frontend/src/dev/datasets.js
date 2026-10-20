/**
 * Sady k seedu (`GET /_dev/api/datasets`, #131): testovací z tests/datasets
 * i demo z demo/datasets — pro pole Data formuláře Nový zdroj dat a dialog
 * Nahrát datovou sadu. Server vrací `{name, title, kind, country, profile,
 * description}`; `name` je název z manifestu — tentýž, který zdroj dat po
 * seedu drží (`dataset.name`), proto se jím sada vybírá i posílá zpět.
 * Sekce Testování má vlastní seznam (`testing/api.js` → `/testing/datasets`)
 * jen nad testovacími sadami.
 */
import { getJson, postJson } from './api.js';
import { t } from './i18n/index.js';

const enc = encodeURIComponent;

export const datasetsApi = {
  list: () => getJson('/datasets'),
  // Nahrát sadu do volného zdroje dat jako úlohu `reseed`; `replaceDataset` jen jako true —
  // výměna držené sady za jinou chce výslovné potvrzení (D66), jinak server vrátí 409 DATASET_MISMATCH
  reseed: (dataSource, dataset, { replaceDataset = false } = {}) =>
    postJson(`/data-sources/${enc(dataSource)}/reseed`, { dataset, ...(replaceDataset ? { replaceDataset: true } : {}) }),
};

/** Skupiny výběru v pevném pořadí; klíče slovníku jako literály kvůli check:i18n. */
const GROUPS = [
  { kind: 'test', labelKey: 'datasets.group.test' },
  { kind: 'demo', labelKey: 'datasets.group.demo' },
];

/**
 * Sady seskupené pro `<optgroup>`: testovací, pak demo; prázdná skupina
 * se vynechá, pořadí sad uvnitř zůstává ze serveru.
 *
 * @param {Array<{kind: string}>} datasets
 * @returns {Array<{kind: string, label: string, items: Array}>}
 */
export function groupDatasets(datasets) {
  return GROUPS
    .map(({ kind, labelKey }) => ({ kind, label: t(labelKey), items: datasets.filter((d) => d.kind === kind) }))
    .filter((g) => g.items.length > 0);
}

/** Popisek sady: titulek — název · země (· profil jen u testovacích sad; demo sady profil nemají). */
export function datasetLabel(d) {
  const parts = [d.country ?? t('dsLoad.noCountry')];
  if (d.kind !== 'demo') parts.push(d.profile ?? t('dsLoad.noProfile'));
  return `${d.title} — ${d.name} · ${parts.join(' · ')}`;
}
