// App info store — název, zkrácený název, ikona a logo zdroje dat.
//
// Plní se z veřejného GET /_app/info (funguje bez tokenu), takže se volá
// už při bootu v main.js — login obrazovka zobrazí název/logo a favicon
// se nastaví před přihlášením. Znovu se volá po uložení stránky Aplikace
// nebo změně branding obrázků.
//
// apply() propisuje shortName do document.title a ikonu do <link rel="icon">.
// Text v sidebaru/headeru čte store reaktivně přes getter.
//
// Vedle brandingu nese i DS-wide výchozí vzhled (`theme`, klíč app.theme)
// a výchozí shell (`shell`, klíč app.shell). Po loadu se tlačí do
// themeStore.setDsDefault() / shellStore.setDsDefault() — push směr
// appInfo → theme/shell, aby tyto stores neimportovaly appInfo (žádný
// kruhový import). DS-wide hodnoty logicky patří k brandingu.

import { getAppInfo, brandingUrl } from '../api/app.js';
import { themeStore } from './theme.svelte.js';
import { shellStore } from './shell.svelte.js';

const DEFAULT_NAME = 'Shipard';

// Stav DS (#56 fáze 2). `unavailable` = boot dostal 503 DS_UNAVAILABLE
// (zavřený DS) → App ukáže StatusScreen místo loginu/aplikace; nese
// efektivní stav z error details (`_state`). `readOnly` = dsState
// `read_only` → banner v ContentArea, toast při 403 řeší client.js.
let unavailable = $state(null); // { state } | null
let readOnly = $state(false);

// Režim pojistky odchozí pošty (#95 D7): `redirect` | `allowlist` | `drop`
// = pošta nejde skutečným příjemcům → MailSafetyNotice; `off` / null nic.
let mailSafetyMode = $state(null);

let info = $state({
  name: null,
  shortName: null,
  icon: null,        // { url, hash } | null
  companyLogo: null, // { url, hash } | null
  theme: null,       // { mode, custom } | null — DS default vzhledu
  shell: null,       // { shell, params } | null — DS default shellu
  auth: null,        // { local, providers: [{id, label}] } | null — pro login obrazovku
});

async function load() {
  try {
    const response = await getAppInfo();
    if (response?.success && response.data) {
      info = {
        name: response.data.name ?? null,
        shortName: response.data.shortName ?? null,
        icon: response.data.icon ?? null,
        companyLogo: response.data.companyLogo ?? null,
        theme: response.data.theme ?? null,
        shell: response.data.shell ?? null,
        auth: response.data.auth ?? null,
      };
      // DS defaulty → theme/shell store (efektivní hodnoty pro
      // follow-uživatele).
      themeStore.setDsDefault(info.theme);
      shellStore.setDsDefault(info.shell);
      readOnly = response.data.dsState === 'read_only';
      mailSafetyMode = response.data.mailSafety?.mode ?? null;
      unavailable = null;
    } else if (response?.error?.code === 'DS_UNAVAILABLE') {
      const detail = response.error.details?.find((d) => d.field === '_state');
      unavailable = { state: detail?.code ?? 'suspended' };
    }
  } catch {
    // Endpoint nedostupný (např. server down) — zůstanou defaulty.
  }
  apply();
}

function apply() {
  document.title = info.shortName ?? DEFAULT_NAME;

  let link = document.querySelector('link[rel="icon"]');
  if (info.icon) {
    if (!link) {
      link = document.createElement('link');
      link.rel = 'icon';
      document.head.appendChild(link);
    }
    link.href = brandingUrl('icon', info.icon.hash);
  } else if (link) {
    // Ikona odebrána — vrátíme browser default.
    link.remove();
  }
}

export const appInfoStore = {
  get name()        { return info.name; },
  get shortName()   { return info.shortName; },
  get icon()        { return info.icon; },
  get companyLogo() { return info.companyLogo; },
  get theme()       { return info.theme; },
  get shell()       { return info.shell; },
  get auth()        { return info.auth; },
  get unavailable() { return unavailable; },
  get readOnly()    { return readOnly; },
  get mailSafetyMode() { return mailSafetyMode; },
  load,
  apply,
};
