/**
 * Chyba, za kterou může uživatel, ne kód — špatná volba na příkazové řádce,
 * chybějící položka v `.env`, scénář, který nesedí na aplikaci.
 *
 * CLI ji vypíše jako jednu větu a skončí nenulovým kódem. Stack trace by
 * v takové situaci jen zakrýval hlášku; ten se ukáže jen u chyb, které
 * `UserError` nejsou — tedy u skutečných pádů runneru.
 */
export class UserError extends Error {
  /**
   * @param {string} message
   * @param {string} [hint] Druhý řádek: co s tím má uživatel udělat.
   * @param {object} [details] Strojově čitelný kontext chyby — do
   *   `result.json` (např. `{ step: { index, verb, value } }` u chyby
   *   kroku scénáře). Do výpisu na terminál nejde, tam stačí hláška.
   */
  constructor(message, hint, details) {
    super(message);
    this.name = 'UserError';
    this.hint = hint;
    this.details = details;
  }
}
