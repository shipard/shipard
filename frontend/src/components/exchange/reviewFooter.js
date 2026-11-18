/**
 * Patička kontroly návrhu na telefonu (DocumentExchangePreviewModal,
 * #155 D113): viditelná zůstává jen hlavní akce — Vystavit a uzavřít,
 * u dokumentu Spisovny Zařadit — a vedlejší akce jdou do kebabu
 * (`ui/KebabMenu`). Tady je jejich výčet a pořadí; čistá funkce bez
 * Svelte, popisky a volání doplní modal.
 *
 * Pořadí: Vystavit koncept (doklad; Spisovna ho nemá), Zamítnout
 * (destruktivní — v menu červeně), Přeskočit (jen ve frontě). Neaktivní
 * akce se nevynechává, jen je neaktivní — stejně jako tlačítko na
 * desktopu, a `testid` je tentýž jako u něj.
 *
 * @param {object} state
 * @param {boolean} state.isRegistry  návrh míří do Spisovny
 * @param {boolean} state.inQueue     průchod frontou (Přeskočit)
 * @param {boolean} state.canApply    brána Použít (reviewGate.js)
 * @param {boolean} state.hasData     náhled je načtený
 * @param {boolean} state.loading     náhled se načítá
 * @returns {{id: 'applyDraft'|'reject'|'skip', labelKey: string, testid: string, danger: boolean, disabled: boolean}[]}
 */
export function mobileKebabActions({ isRegistry, inQueue, canApply, hasData, loading }) {
  const actions = [];
  if (!isRegistry) {
    actions.push({
      id: 'applyDraft',
      labelKey: 'exchange.preview.actions.apply',
      testid: 'review-apply-draft',
      danger: false,
      disabled: !canApply,
    });
  }
  actions.push({
    id: 'reject',
    labelKey: 'exchange.preview.actions.reject',
    testid: 'review-reject',
    danger: true,
    disabled: !hasData,
  });
  if (inQueue) {
    actions.push({
      id: 'skip',
      labelKey: 'exchange.preview.actions.skip',
      testid: 'review-skip',
      danger: false,
      disabled: loading,
    });
  }
  return actions;
}
