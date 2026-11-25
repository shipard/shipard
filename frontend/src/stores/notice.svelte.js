// Globální oznámení (toast) — jedna zpráva naráz, auto-dismiss.
//
// Minimální infra: app dosud měla jen lokální toasty (Dashboard). Tohle
// je pro zprávy, které vznikají mimo komponenty — první uživatel je
// api/client.js při 403 DS_READ_ONLY (#56 fáze 2): jakýkoli pokus
// o zápis na read-only DS dostane jednotný text místo generické chyby.
// Renderuje GlobalToast v AppShellu.

// Druhý uživatel: výsledek akce záznamu z modulu (#172 D14) — zpráva nese
// úroveň `success` / `info` / `warning`, kterou toast ukáže značkou.

let current = $state(null); // { message, level } | null
let timer = null;

function show(message, { timeoutMs = 6000, level = null } = {}) {
  clearTimeout(timer);
  current = { message, level };
  timer = setTimeout(dismiss, timeoutMs);
}

function dismiss() {
  clearTimeout(timer);
  timer = null;
  current = null;
}

export const noticeStore = {
  get current() { return current; },
  show,
  dismiss,
};
