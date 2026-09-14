// Navigation store — manages navigation mode and the active item per mode.
// Modes: 'app' | 'settings' | 'account'.
// Each mode remembers its own activeItem so switching app→settings→app
// (nebo app→account→app) returns the user to where they were.
//
// `pendingRecordId` je jednorázový hint pro Viewer.svelte: když dashboard
// widget zavolá navigateToViewer(viewerId, recordId), Viewer si ho po mountu
// vyzvedne a předvybere ten záznam. State se konzumuje (resetuje) ve Vieweru,
// ne ve storu — viewer ji vyčistí po prvním použití, aby se neaplikovala
// znovu při následujících tab switchích na stejný viewer.

import { get } from '../api/client.js';
import { findLeafById, findRootSectionId } from '../utils/navTree.js';
import { recordRecent } from '../utils/recents.js';
import { authStore } from './auth.svelte.js';

let mode = $state('app');
let appActiveItem      = $state(null);
let settingsActiveItem = $state(null);
let accountActiveItem  = $state(null);
let pendingRecordId    = $state(null);
// Jednorázový hint jako pendingRecordId, ale pro tab (viewGroup) vieweru —
// digest karta otevírá došlou poštu rovnou na tabu Archiv.
let pendingViewGroup   = $state(null);
// Jednorázový hint pro custom filtry vieweru ({filterId: value}) — akce
// „Pohyby případu" ze saldokonta otevírá pohyby s předvyplněným partnerem,
// VS a SS. Viewer je převezme do activeFilters (viditelné, uživatel je
// může uvolnit) a použije pro první fetch.
let pendingFilters     = $state(null);
// Jednorázový hint pro ReportsPage — parametry z deep-link URL
// (?report=…&fy=…). Konzumuje se po resolvu katalogu, manuální navigace
// ho vyprazdňuje jako ostatní pendingy.
let pendingReportParams = $state(null);
// Deep-link stash z main.js (před mountem, přežije login obrazovku) —
// nereaktivní: čte se jednou po loadu app navigace.
let pendingReportDeepLink = null;
// App navigační strom — načítá ho loadAppNavTree() (volá AppShell při
// vstupu do app módu; strom potřebují oba shelly i paleta). Účel: derivace
// `activeSection` (žádný zápis při navigaci → nemůže zestárnout, funguje
// i pro navigateToViewer z dashboardu a deep linky) a render navigace.
let appNavTree = $state(null);
// Chyba posledního loadu app stromu — {code: 'unauthenticated'|'failed',
// message: string|null} nebo null. Lokalizaci dělá komponenta.
let appNavError = $state(null);

// Aktivní položka pro aktuální mód — jedno místo pro tří-cestnou volbu,
// sdílené gettery i navigate().
function currentActiveItem() {
  if (mode === 'settings') return settingsActiveItem;
  if (mode === 'account')  return accountActiveItem;
  return appActiveItem;
}

const DASHBOARD_ITEM = Object.freeze({
  id: 'dashboard',
  label: 'Dashboard',
  type: 'dashboard',
  table: null,
  viewerId: null,
  filter: null,
});

function navigate(item) {
  const normalized = {
    id: item.id,
    label: item.label,
    type: item.type,
    table: item.table,
    viewerId: item.viewerId,
    pageId: item.pageId ?? null,
    panelId: item.panelId ?? null,
    // Parametry panel komponenty ze serveru (reporty: {reportId}) — jedna
    // generická komponenta obslouží víc sidebar položek.
    panelParams: item.panelParams ?? null,
    filter: item.filter ?? null,
    // Napevno daný viewGroup vieweru (sidebar položky saldokont) — viewer
    // pak skryje chip lištu a všechny fetche filtruje touto hodnotou.
    fixedViewGroup: item.fixedViewGroup ?? null,
  };
  // Manuální navigace mimo dashboard widget — pending hinty vyprší.
  pendingRecordId = null;
  pendingViewGroup = null;
  pendingFilters = null;
  pendingReportParams = null;
  if (mode === 'settings') {
    settingsActiveItem = normalized;
  } else if (mode === 'account') {
    accountActiveItem = normalized;
  } else {
    appActiveItem = normalized;
    // Recents pro command paletu — jen app mód a jen položky s id
    // (ad-hoc cíle navigateToViewer/navigateToPanel sem nevedou; paleta
    // nabízí jen to, co umí znovu otevřít). Učí se tak i z běžné
    // navigace sidebarem, ne jen z výběrů v paletě.
    if (normalized.id != null) {
      recordRecent(authStore.user?.id, {
        id: normalized.id,
        label: normalized.label,
        icon: item.icon ?? null,
        type: normalized.type,
      });
    }
  }
}

/**
 * Naviguj na konkrétní viewer a (volitelně) předvyber záznam v něm.
 * Volá se z dashboard widget rows a z akcí `open_viewer` detailu.
 *
 * Pokud viewerId neodpovídá žádné položce sidebar navigation tree,
 * activeItem se přesto nastaví — Viewer si table načte sám podle viewerId.
 * `recordId` se uloží do pendingRecordId, Viewer.svelte ho po mountu
 * vyzvedne a předvybere řádek; `viewGroup` a `filters` ({filterId: value})
 * stejně jednorázově nastaví chip a custom filtry cílového vieweru.
 */
function navigateToViewer(viewerId, recordId = null, viewGroup = null, filters = null) {
  pendingRecordId = recordId;
  pendingViewGroup = viewGroup;
  pendingFilters = filters != null && typeof filters === 'object' && Object.keys(filters).length > 0
    ? { ...filters }
    : null;
  const item = {
    id: 'viewer:' + viewerId,
    label: viewerId,
    type: 'viewer',
    table: null,
    viewerId,
    filter: null,
    fixedViewGroup: null,
  };
  if (mode === 'settings') {
    settingsActiveItem = item;
  } else if (mode === 'account') {
    accountActiveItem = item;
  } else {
    appActiveItem = item;
  }
}

/**
 * Naviguj na panel v Nastavení (akce `open_panel` z karty feedu).
 *
 * enterSettings() je nutné: dashboard běží v režimu `app` a navigate()
 * zapisuje podle aktuálního mode — bez přepnutí by panel skončil
 * v appActiveItem a viditelně by se nestalo nic. `id` musí být
 * 'panel:' + panelId, přesně jak ho skládá SettingsController, jinak se
 * položka v sidebaru nezvýrazní. Label bere lokalizovaný z akce karty,
 * degradace na panelId jako u navigateToViewer.
 */
function navigateToPanel(panelId, label = null) {
  enterSettings();
  settingsActiveItem = {
    id: 'panel:' + panelId,
    label: label ?? panelId,
    type: 'panel',
    table: null,
    viewerId: null,
    pageId: null,
    panelId,
    filter: null,
    fixedViewGroup: null,
  };
}

/**
 * Viewer.svelte po mountu vyzvedne pendingRecordId, pokud existuje,
 * a vynuluje ho — aby další navigace bez recordId nedostala stale hodnotu.
 */
function consumePendingRecordId() {
  const id = pendingRecordId;
  pendingRecordId = null;
  return id;
}

/**
 * Viewer.svelte při navigaci vyzvedne pendingViewGroup (tab, na kterém se
 * má otevřít) a vynuluje ho — stejný jednorázový kontrakt jako recordId.
 */
function consumePendingViewGroup() {
  const group = pendingViewGroup;
  pendingViewGroup = null;
  return group;
}

/**
 * Viewer.svelte při navigaci vyzvedne pendingFilters (custom filtry, se
 * kterými se má otevřít) a vynuluje je — stejný jednorázový kontrakt.
 */
function consumePendingFilters() {
  const filters = pendingFilters;
  pendingFilters = null;
  return filters;
}

/** main.js: stash deep-linku reportu před mountem (URL se nečistí). */
function setPendingReportDeepLink(link) {
  pendingReportDeepLink = link;
}

/**
 * ReportsPage po resolvu katalogu vyzvedne parametry z deep-linku —
 * stejný jednorázový kontrakt jako pendingRecordId.
 */
function consumePendingReportParams() {
  const params = pendingReportParams;
  pendingReportParams = null;
  return params;
}

/**
 * Aktivace deep-linku reportu — volá Sidebar po loadu app navigace, PŘED
 * ensureDefaultActiveItem. Najde leaf `report:<id>` ve stromu; nalezený
 * nastaví jako activeItem PŘÍMO (ne přes navigate() — ten by právě
 * nastavené pendingReportParams smazal). Neznámé id → normální start.
 * Jednorázové: stash se čistí při prvním volání bez ohledu na výsledek.
 *
 * @returns {boolean} true když byl report leaf aktivován
 */
function activateReportDeepLink(navTree) {
  const link = pendingReportDeepLink;
  pendingReportDeepLink = null;
  if (!link || mode !== 'app' || appActiveItem !== null) {
    return false;
  }
  const leaf = findLeafById(navTree, 'report:' + link.reportId);
  if (!leaf) {
    return false;
  }
  pendingReportParams = link.params;
  appActiveItem = {
    id: leaf.id,
    label: leaf.label,
    type: leaf.type,
    table: leaf.table ?? null,
    viewerId: leaf.viewerId ?? null,
    pageId: leaf.pageId ?? null,
    panelId: leaf.panelId ?? null,
    panelParams: leaf.panelParams ?? null,
    filter: leaf.filter ?? null,
    fixedViewGroup: leaf.fixedViewGroup ?? null,
  };
  return true;
}

/** Po loadu app navigace — viz komentář u `appNavTree`. */
function setAppNavTree(tree) {
  appNavTree = tree;
}

/**
 * Fetch app navigačního stromu (GET /_ui/navigation) + navazující side
 * effecty v závazném pořadí: strom do stavu (kvůli derivaci activeSection
 * PŘED aktivací položky), deep-link reportu, default položka. Dřív žilo
 * v Sidebar.svelte — hoist sem, protože strom potřebují všechny shelly
 * (SidebarShell i ClassicShell) a fetch nesmí záviset na tom, který je
 * zrovna namontovaný. Při refetchi (návrat do app módu) se starý strom
 * drží až do příchodu nového — žádný flash prázdné navigace.
 */
async function loadAppNavTree() {
  appNavError = null;
  try {
    const response = await get('/_ui/navigation');
    if (response === null) {
      appNavError = { code: 'unauthenticated', message: null };
      return;
    }
    if (!response.success) {
      appNavError = { code: 'failed', message: response.error?.message ?? null };
      return;
    }
    appNavTree = response.data;
    activateReportDeepLink(appNavTree);
    ensureDefaultActiveItem(appNavTree);
  } catch {
    appNavError = { code: 'failed', message: null };
  }
}

/**
 * Nastav výchozí activeItem v app módu — voláno po loadu navigace, pokud
 * zatím není nic vybraného. Výchozí = první root-level leaf stromu (uzel
 * s `type`; sekce bez `type` se přeskočí) — na hosting DS portál, jinde
 * Dashboard (D6). Fallback při prázdném/chybějícím stromu: Dashboard.
 * Settings/account mód tím neovlivňujeme.
 */
function ensureDefaultActiveItem(navTree = null) {
  if (mode !== 'app' || appActiveItem !== null) {
    return;
  }
  const leaf = Array.isArray(navTree) ? navTree.find((n) => n?.type) : null;
  if (!leaf) {
    appActiveItem = { ...DASHBOARD_ITEM };
    return;
  }
  appActiveItem = {
    id: leaf.id,
    label: leaf.label,
    type: leaf.type,
    table: leaf.table ?? null,
    viewerId: leaf.viewerId ?? null,
    pageId: leaf.pageId ?? null,
    panelId: leaf.panelId ?? null,
    panelParams: leaf.panelParams ?? null,
    filter: leaf.filter ?? null,
    fixedViewGroup: leaf.fixedViewGroup ?? null,
  };
}

function enterSettings() {
  mode = 'settings';
}

function exitSettings() {
  mode = 'app';
}

function enterAccount() {
  mode = 'account';
}

function exitAccount() {
  mode = 'app';
}

// Společný návrat do aplikace z libovolného sekundárního módu
// (settings i account) — back-bar v sidebaru volá tento helper.
function exitToApp() {
  mode = 'app';
}

export const navigationStore = {
  get mode()       { return mode; },
  get activeItem() { return currentActiveItem(); },
  get activeId()   { return currentActiveItem()?.id ?? null; },
  // Sekce úrovně 1, do níž patří aktivní leaf — čistá derivace ze stromu.
  // Mimo app mód, pro `_top`/root leafy (dashboard, chat) a před loadem
  // stromu vrací null.
  get activeSection() {
    if (mode !== 'app' || !appActiveItem || !appNavTree) return null;
    return findRootSectionId(appNavTree, appActiveItem.id);
  },
  // App strom pro shelly a command paletu (zdroj app položek bez
  // dalšího fetche). null = ještě nenačten (loading stav).
  get appNavTree() { return appNavTree; },
  get appNavError() { return appNavError; },
  get pendingRecordId() { return pendingRecordId; },
  get pendingViewGroup() { return pendingViewGroup; },
  get pendingFilters() { return pendingFilters; },
  navigate,
  navigateToViewer,
  navigateToPanel,
  setAppNavTree,
  loadAppNavTree,
  consumePendingRecordId,
  consumePendingViewGroup,
  consumePendingFilters,
  setPendingReportDeepLink,
  consumePendingReportParams,
  activateReportDeepLink,
  ensureDefaultActiveItem,
  enterSettings,
  exitSettings,
  enterAccount,
  exitAccount,
  exitToApp,
};
