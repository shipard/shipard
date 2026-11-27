import './styles/reset.css'
import './styles/variables.css'
import './styles/base.css'
import { mount } from 'svelte'
import App from './App.svelte'
import { layoutStore } from './stores/layout.svelte.js'
import { appInfoStore } from './stores/appInfo.svelte.js'
import { authStore } from './stores/auth.svelte.js'
import { accountPrefs } from './stores/accountPrefs.svelte.js'
import { avatarStore } from './stores/avatar.svelte.js'
import { loginNotice } from './stores/loginNotice.svelte.js'
import { authAction } from './stores/authAction.svelte.js'
import { opAuth } from './stores/opAuth.svelte.js'
import { navigationStore } from './stores/navigation.svelte.js'
import { parseOidcRedirect, parseOpAuth } from './api/oidc.js'
import { parseAuthAction } from './api/authActions.js'
import { parseReportDeepLink } from './api/reports.js'
import { exchangeOidc } from './api/auth.js'
import { nativeStore } from './stores/native.svelte.js'
import { NATIVE_MOCK_FLAG, isNativeAvailable } from './native/bridge.js'
import { API_BASE_URL } from './api/config.js'

// Inicializace mobilní detekce (matchMedia listener). Jednou na začátku.
layoutStore.initLayout()

// Načtení názvu/ikony aplikace — endpoint je veřejný, jede už před loginem
// (titulek tabu, favicon, název na login obrazovce).
appInfoStore.load()

// Simulace mostu nativní aplikace pro vývoj (#177 D29, U11): jen když na
// stránce skutečný most není a příznak v localStorage je "1" (zapíná ho
// stránka Most ve vývojářském dashboardu). Kód simulace je samostatný
// chunk — bez příznaku se nestahuje. Selhání start aplikace nezastaví.
async function installNativeMock() {
  try {
    if (isNativeAvailable() || localStorage.getItem(NATIVE_MOCK_FLAG) !== '1') return
    const { install } = await import('./native/mock/install.js')
    install({
      // Adresa API zdroje dat = identita zařízení pro tento zdroj dat (D33).
      apiBase: window.location.origin + API_BASE_URL,
      storage: localStorage,
      fetch: window.fetch.bind(window),
      crypto: window.crypto,
      target: window,
    })
  } catch {
    // simulace je pomůcka pro vývoj
  }
}

async function boot() {
  // Most nativní aplikace (docs/native-bridge.md): simulace musí být na
  // místě před prvním `info`; store se plní na pozadí, start na něm nečeká.
  await installNativeMock()
  nativeStore.load()

  // Snapshot query stringu — větve níž čistí URL přes replaceState, parsery
  // proto nesmí číst window.location.search až po nich.
  const search = window.location.search

  // Mailový link set-password (?auth_action=set-password&token=…): token
  // do in-memory store PŘED mountem, URL se hned čistí — token nesmí
  // zůstat v historii. App.svelte pak zobrazí SetPasswordScreen.
  const startupAction = parseAuthAction(search)
  if (startupAction) {
    history.replaceState(null, '', window.location.pathname)
    authAction.set(startupAction)
  }

  // OIDC návrat: ?login=oidc&code=… vyměnit za session PŘED mountem
  // (žádný flash login obrazovky), ?login_error=… předat login obrazovce.
  // URL se čistí hned — handoff kód je jednorázový a nesmí přežít reload.
  const oidcRedirect = parseOidcRedirect(search)
  if (!startupAction && oidcRedirect) {
    history.replaceState(null, '', window.location.pathname)
    if (oidcRedirect.kind === 'handoff') {
      try {
        const response = await exchangeOidc(oidcRedirect.code)
        if (response?.success) {
          authStore.setAuth(response)
          // Kombinovaný příchod ?login=oidc&code=…&op_auth=… (return_to
          // kontinuita): session + rozjednaná OP transakce → App zobrazí
          // rovnou OpAuthScreen. Při neúspěšném exchange se opAuth
          // nenastavuje — txn na hostingu doexpiruje sama.
          const opTxn = parseOpAuth(search)
          if (opTxn) {
            opAuth.set(opTxn)
          }
        } else {
          loginNotice.set('oidc_invalid_state')
        }
      } catch {
        loginNotice.set('oidc_provider_error')
      }
    } else {
      loginNotice.set(oidcRedirect.error)
    }
  }

  // Příchod od OIDC OP hostingu (?op_auth={txn}, session bridge D10):
  // txn do in-memory store PŘED mountem, URL se hned čistí. Schválení
  // (approve → window.location) dělá OpAuthScreen — se session hned,
  // bez session až po loginu (in-memory store login přežije).
  const opAuthTxn = parseOpAuth(search)
  if (!startupAction && !oidcRedirect && opAuthTxn) {
    history.replaceState(null, '', window.location.pathname)
    opAuth.set(opAuthTxn)
  }

  // Deep-link reportu (?report=…&fy=…, docs/reports.md D10): jen stash do
  // storu — URL se na rozdíl od auth větví NEČISTÍ, má zůstat sdílitelná
  // a přežít reload. In-memory stash přežije i login obrazovku (navigace
  // se načítá až po přihlášení).
  const reportLink = parseReportDeepLink(search)
  if (!startupAction && !oidcRedirect && !opAuthTxn && reportLink) {
    navigationStore.setPendingReportDeepLink(reportLink)
  }

  // Per-user preference (vzhled, jazyk) — jen když už jsme přihlášení
  // (reload s platným tokenem nebo čerstvý OIDC handoff). Fresh lokální
  // login je obsloužen v App.svelte onSuccess.
  if (authStore.isAuthenticated) {
    accountPrefs.load()
    avatarStore.load()
  }

  mount(App, {
    target: document.getElementById('app'),
  })
}

boot()
