<script>
  import { get, post, del } from '../../api/client.js';
  import {
    runDueAlertChecks,
    snoozeAlert,
    dismissAlert,
    unsnoozeAlert,
    runAlertCheck,
  } from '../../api/alerts.js';
  import { reaccountDocument } from '../../api/accounting.js';
  import { recomposeFiling, generateFilingFiles, reloadFilingHeader, lockReportPeriod, accountFiling } from '../../api/vat.js';
  import { importStatement, reaccountTransaction } from '../../api/bank.js';
  import { inviteUser } from '../../api/security.js';
  import { fileFromMessage } from '../../api/registry.js';
  import ViewerRow from './ViewerRow.svelte';
  import ViewerGrid from './ViewerGrid.svelte';
  import ViewerDetail from './ViewerDetail.svelte';
  import ViewerDetailDrawer from './ViewerDetailDrawer.svelte';
  import ViewerToolbar from './ViewerToolbar.svelte';
  import ViewerFilters from './ViewerFilters.svelte';
  import SetPasswordPrompt from './SetPasswordPrompt.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import RegistryImportWizard from '../registry/RegistryImportWizard.svelte';
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { getViewerLayout, setViewerLayout } from '../../utils/viewerLayout.js';
  import { supportedFilters, initialFilterValues } from '../../utils/viewerFilters.js';
  import { iconTable, iconList } from '../../icons.js';
  import { untrack } from 'svelte';

  let { tab } = $props();

  // --- Meta state ---
  let meta = $state(null);
  let loadingMeta = $state(true);

  // --- View group tabs (docState skupiny nebo datové skupiny z backendu) ---
  // 'active' | 'archive' | 'trash' | 'all' | datové id (např. kód saldokonta).
  // Počáteční 'active' je jen pre-meta placeholder — skutečný default přijde
  // z meta.defaultViewGroup po fetchMeta() (init $effect níže).
  let activeViewGroup = $state('active');

  // --- Number series tabs (bottom bar) ---
  // `null` = no series filter (viewer doesn't expose series or list is empty).
  // Otherwise an int matching one of meta.numberSeries[].id.
  let activeSeriesId = $state(null);

  const VIEW_GROUP_LABEL_KEYS = {
    active:  'viewer.tab.active',
    archive: 'viewer.tab.archive',
    trash:   'viewer.tab.trash',
  };

  // Tabs to display: viewGroups from meta + always the "all" tab.
  // Položka je buď string (docState skupiny — i18n mapování tady), nebo
  // objekt {id, label} s labelem už lokalizovaným backendem (datové
  // skupiny, např. saldokonta v Saldo pohybech).
  let viewTabs = $derived(() => {
    const groups = meta?.viewGroups ?? [];
    const tabs = groups.map(vg => typeof vg === 'string'
      ? { id: vg, label: VIEW_GROUP_LABEL_KEYS[vg] ? t(VIEW_GROUP_LABEL_KEYS[vg]) : vg }
      : { id: vg.id, label: vg.label });
    tabs.push({ id: 'all', label: t('viewer.tab.all') });
    return tabs;
  });

  // Fixní viewGroup ze sidebar položky (tab.fixedViewGroup, např. saldokonto
  // Pohledávky) chip lištu skrývá — skupina je daná napevno, není co přepínat.
  let hasViewGroups = $derived(
    (tab.fixedViewGroup ?? null) == null && (meta?.viewGroups ?? []).length > 0
  );

  // --- Number series tabs ---
  // Lišta se ukáže jen když je víc než 1 řada; při jedné se filter stejně
  // aplikuje (přes activeSeriesId), ale single-tab by vizuálně nedával smysl.
  let numberSeries = $derived(meta?.numberSeries ?? []);
  let hasNumberSeriesTabs = $derived(numberSeries.length > 1);

  // --- Row list state ---
  let rows = $state([]);
  let hasMore = $state(false);
  let loadingRows = $state(false);
  let loadingMore = $state(false);
  let pageNumber = $state(0);

  // --- Layout (list | grid) — docs/viewer-grid.md §4.1 ---
  // activeLayout = zvolený layout (v F1 jen meta.defaultLayout, toggle je F2).
  // Efektivní layout degraduje na list na mobilu (D2) a když meta grid
  // nepodporuje. Tvary řádků obou layoutů se liší → změna efektivního
  // layoutu = reset page + refetch (layout-change $effect níže).
  let activeLayout = $state('list');
  let effectiveLayout = $derived(
    layoutStore.isMobile || !(meta?.layouts ?? []).includes('grid')
      ? 'list'
      : activeLayout
  );
  let isGrid = $derived(effectiveLayout === 'grid');
  // Součtový footer gridu — přichází jen s page 0, append ho nemění (D7).
  let footer = $state(null);
  // Předchozí efektivní layout — obyčejná (ne-reaktivní) proměnná.
  // null = init po přepnutí tabu ještě neproběhl; layout-change $effect
  // pak nesmí střílet (initial fetch řeší tab-change flow).
  let prevLayout = null;
  // Toggle list ↔ grid je na desktopu, když viewer podporuje oba layouty
  // (docs/viewer-grid.md §7.2, D10). Volba se persistuje per-DS/viewer.
  let hasLayoutToggle = $derived(
    !layoutStore.isMobile && (meta?.layouts ?? []).length > 1
  );

  // --- Řazení gridu (D9) — {column, dir} | null (výchozí řazení vieweru).
  // Cyklus asc → desc → výchozí; přežívá viewGroup/filtry/hledání,
  // resetuje se při přepnutí vieweru a při přechodu na list layout.
  let activeSort = $state(null);

  // Active search term used for API calls (updated after debounce)
  let activeSearch = $state('');

  // --- Custom filters (meta.filters) ---
  // Plain objekt id → string hodnota; prázdná hodnota = filtr neaktivní
  // (klíč se maže). Definice renderuje ViewerFilters; typy, které neumí
  // (historický 'enum'), se odfiltrují — bar se ukáže jen když zbude něco
  // k zobrazení. Výchozí hodnoty (`default` v definici) a precedence
  // pending filtrů z open_viewer řeší utils/viewerFilters.js.
  let activeFilters = $state({});
  let viewerFilters = $derived(supportedFilters(meta?.filters));

  // --- Detail state ---
  let selectedRowId = $state(null);
  let detail = $state(null);
  let detailToolbar = $state([]);
  let detailLoading = $state(false);

  // --- Form dialog state ---
  let formOpen = $state(false);
  let editRecordId = $state(null);
  let formDefaultData = $state({});
  // Pokud non-null, FormDialog otevírá formulář pro tuto tabulku místo
  // `meta.table`. Používá se pro custom detail akce `kind: 'open_form'`,
  // kde detail.action.target.table cílí na jinou tabulku než viewer
  // (např. alert v core_alerts_alerts otevírá form pro base_persons_persons).
  let formTable = $state(null);
  // Nenápadná informační notice v hlavičce FormDialogu (např. warning
  // DUPLICATE_IN_REGISTRY při zařazení zprávy do Spisovny). Null = nic.
  let formNotice = $state(null);

  // --- Search debounce ---
  let searchTimer = null;

  // --- Refs ---
  let listEl = $state(null);
  let searchInputEl = $state(null);

  // --- Derived ---
  // V grid layoutu zůstává horní toolbar v list kontextu (meta.toolbar) —
  // detail akce žijí v hlavičce draweru (docs/viewer-grid.md §4.1, §6).
  let toolbarActions = $derived(
    !isGrid && selectedRowId != null ? detailToolbar : (meta?.toolbar ?? [])
  );

  // --- Data fetching ---

  async function fetchMeta(viewerId) {
    loadingMeta = true;
    const result = await get(`/_ui/viewer/${viewerId}/meta`);
    if (result?.success) {
      meta = result.data;
      // Default to the first series (alphabetical). Generic viewers expose no
      // series → stays null and the number_series filter is not applied.
      const series = meta.numberSeries ?? [];
      activeSeriesId = series.length > 0 ? series[0].id : null;
    }
    loadingMeta = false;
  }

  /**
   * Fetch rows from the API.
   * Takes explicit parameters to avoid reading $state inside $effect.
   */
  async function fetchRowsExplicit(viewerId, search, viewGroup, seriesId, filterValues, page, layout = 'list', sort = null, append = false) {
    if (append) {
      loadingMore = true;
    } else {
      loadingRows = true;
    }

    let path = `/_ui/viewer/${viewerId}/rows?page=${page}`;
    // layout=grid jen explicitně — list query zůstává beze změny.
    // Sort má smysl jen v gridu (list má vlastní pevné řazení).
    if (layout === 'grid') {
      path += '&layout=grid';
      if (sort != null) {
        path += `&sort=${encodeURIComponent(`${sort.column}:${sort.dir}`)}`;
      }
    }
    if (search) {
      path += `&search=${encodeURIComponent(search)}`;
    }
    // Send viewGroup filter. Posilame i 'all' explicitne — backend ho
    // rozpozna a preskoci docState filtr. Kdyz se 'all' neposlal vubec,
    // backend spadl na default 'active' a zalozka Vse ukazovala jen
    // aktivni zaznamy (archiv/kos chybely i pri hledani).
    if (viewGroup) {
      path += `&filter[viewGroup]=${encodeURIComponent(viewGroup)}`;
    }
    // Number-series bottom-tab filter (per-type doc viewers).
    if (seriesId != null) {
      path += `&filter[number_series]=${encodeURIComponent(seriesId)}`;
    }
    // Custom filtry (ViewerFilters) — backend je parsuje generericky
    // jako filter[id]=value (ViewerController::rows).
    for (const [id, value] of Object.entries(filterValues ?? {})) {
      if (value === '' || value == null) continue;
      path += `&filter[${encodeURIComponent(id)}]=${encodeURIComponent(value)}`;
    }

    const result = await get(path);

    if (result?.success) {
      if (append) {
        rows = [...rows, ...result.data.rows];
      } else {
        rows = result.data.rows;
        // Footer přichází jen s page 0 (grid); list odpověď klíč nemá → null.
        footer = result.data.footer ?? null;
      }
      hasMore = result.data.hasMore;
    }

    loadingRows = false;
    loadingMore = false;
  }

  /** Convenience wrapper — call from event handlers, NOT from $effect */
  function fetchRows(append = false) {
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, pageNumber, effectiveLayout, activeSort, append);
  }

  async function fetchDetail(id) {
    detailLoading = true;
    detail = null;
    detailToolbar = meta?.toolbar ?? [];

    const result = await get(`/_ui/viewer/${tab.viewerId}/detail/${id}`);

    if (result?.success) {
      detail = result.data.detail;
      detailToolbar = result.data.toolbar ?? [];
    }

    detailLoading = false;
  }

  // --- Handlers ---

  function handleTabClick(viewGroup) {
    if (viewGroup === activeViewGroup) return;
    activeViewGroup = viewGroup;
    selectedRowId = null;
    detail = null;
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, viewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
  }

  function handleSeriesTabClick(seriesId) {
    if (seriesId === activeSeriesId) return;
    activeSeriesId = seriesId;
    selectedRowId = null;
    detail = null;
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, seriesId, activeFilters, 0, effectiveLayout, activeSort);
  }

  function handleFilterChange(filterId, value) {
    const next = { ...activeFilters };
    if (value === '' || value == null) {
      delete next[filterId];
    } else {
      next[filterId] = value;
    }
    // Změna rodiče závislého selectu (fiscal_year → fiscal_month) ruší
    // hodnotu potomka — jinak by zůstal aktivní filtr na měsíc cizího roku.
    for (const f of viewerFilters) {
      if (f.parentFilter === filterId) {
        delete next[f.id];
      }
    }
    activeFilters = next;
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, next, 0, effectiveLayout, activeSort);
  }

  function handleSearchInput(e) {
    const value = e.target.value;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      activeSearch = value;
      selectedRowId = null;
      detail = null;
      pageNumber = 0;
      fetchRowsExplicit(tab.viewerId, value, activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
    }, 300);
  }

  function handleSearchClear() {
    if (searchInputEl) {
      searchInputEl.value = '';
    }
    clearTimeout(searchTimer);
    activeSearch = '';
    selectedRowId = null;
    detail = null;
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, '', activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
  }

  function handleRowClick(row) {
    selectedRowId = row.id;
    fetchDetail(row.id);
  }

  // Dvojklik na grid řádek → edit akce, jen pokud ji detail toolbar nabízí
  // (u read-only viewerů jako deník je toolbar prázdný — nic se nestane).
  async function handleRowDblClick(row) {
    selectedRowId = row.id;
    await fetchDetail(row.id);
    if ((detailToolbar ?? []).some(a => a.id === 'edit')) {
      editRecordId = row.id;
      formOpen = true;
    }
  }

  // Infinite scroll gridu — scroll detekci má ViewerGrid uvnitř, sem jde
  // jen požadavek na další stránku (stejná logika jako handleScroll listu).
  function handleGridLoadMore() {
    pageNumber += 1;
    fetchRows(true);
  }

  // Klik na sortable hlavičku gridu — cyklus asc → desc → výchozí (null);
  // jiný sloupec začíná asc. Výběr/drawer se NEruší (řazení nemění
  // identitu záznamů); footer je na pořadí nezávislý.
  function handleSortChange(colId) {
    if (activeSort?.column === colId) {
      activeSort = activeSort.dir === 'asc' ? { column: colId, dir: 'desc' } : null;
    } else {
      activeSort = { column: colId, dir: 'asc' };
    }
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
  }

  // Toggle list ↔ grid (D10). Jen přepne stav a persistuje volbu — refetch
  // řeší stávající layout-change $effect (ruční fetch = dvojí fetch).
  function handleLayoutToggle() {
    const target = activeLayout === 'grid' ? 'list' : 'grid';
    if (target === 'list') {
      activeSort = null;
    }
    activeLayout = target;
    setViewerLayout(tab.viewerId, target);
  }

  function handleDrawerClose() {
    selectedRowId = null;
    detail = null;
  }

  function handleScroll() {
    if (!listEl || !hasMore || loadingMore || loadingRows) return;
    const { scrollTop, scrollHeight, clientHeight } = listEl;
    if (scrollHeight - scrollTop - clientHeight < 100) {
      pageNumber += 1;
      fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, pageNumber, effectiveLayout, activeSort, true);
    }
  }

  // --- Registry import wizard state ---
  let registryWizardOpen = $state(false);

  // --- Reanalyze dialog state ---
  let reanalyzeDialogOpen = $state(false);
  let reanalyzeProfileNdx = $state('');
  let reanalyzeProfiles = $state([]);
  let reanalyzeSubmitting = $state(false);

  // Import bankovního výpisu (akce import_statement)
  let importInProgress = $state(false);
  let importFileInput = $state(null);

  // Nastavení hesla SMTP senderu (detail akce setPassword)
  let passwordDialogOpen = $state(false);
  let passwordSubmitting = $state(false);

  function handleToolbarAction(actionId) {
    if (actionId === 'create') {
      editRecordId = null;
      // Per-type viewers (e.g. issued/received invoices) expose
      // newRecordDefaults so the form can pre-fill doc_type. On top of that,
      // when a specific number series is the active bottom tab, pre-fill it too
      // so the user doesn't have to pick it again in the form.
      const base = meta?.newRecordDefaults ?? {};
      formDefaultData = activeSeriesId != null
        ? { ...base, number_series: activeSeriesId }
        : base;
      formOpen = true;
    } else if (actionId === 'edit' && selectedRowId != null) {
      editRecordId = selectedRowId;
      formOpen = true;
    } else if (actionId === 'import_from_registry') {
      registryWizardOpen = true;
    } else if (actionId === 'reanalyze' && selectedRowId != null) {
      // Najdi action a vytáhni z meta.profiles seznam profilů.
      const action = (toolbarActions ?? []).find(a => a.id === 'reanalyze');
      reanalyzeProfiles = action?.meta?.profiles ?? [];
      reanalyzeProfileNdx = '';
      reanalyzeDialogOpen = true;
    } else if (actionId === 'fileToRegistry' && selectedRowId != null) {
      handleFileToRegistry();
    } else if (actionId === 'import_statement') {
      importFileInput?.click();
    } else if (actionId === 'runDue') {
      handleRunDue();
    }
  }

  // --- Zařazení zprávy do Spisovny (toolbar akce fileToRegistry) ---
  let fileToRegistryInProgress = $state(false);

  async function handleFileToRegistry() {
    if (selectedRowId == null || fileToRegistryInProgress) return;
    fileToRegistryInProgress = true;
    try {
      const result = await fileFromMessage(selectedRowId);
      if (!result?.success) {
        alert(t('viewer.fileToRegistry.failed', { msg: translateError(result?.error) }));
        return;
      }
      const { id, warning } = result.data ?? {};
      // Otevřít FormDialog nad novým Konceptem Spisovny (jiná tabulka než
      // viewer — stejný vzor jako custom akce open_form). Duplicitní příloha
      // ve Spisovně se ukáže jako nenápadná notice v dialogu, neblokuje.
      formTable = 'base_registry_documents';
      editRecordId = id ?? null;
      formDefaultData = {};
      formNotice = warning?.code === 'DUPLICATE_IN_REGISTRY'
        ? t('viewer.fileToRegistry.duplicate')
        : null;
      formOpen = true;
      // Zpráva mezitím přešla do Hotovo + dostala vazbu target_* — refetch.
      refreshAfterAction();
    } finally {
      fileToRegistryInProgress = false;
    }
  }

  let runDueInProgress = $state(false);

  async function handleRunDue() {
    if (runDueInProgress) return;
    runDueInProgress = true;
    try {
      const result = await runDueAlertChecks();
      if (!result?.success) {
        alert(translateError(result?.error) || 'Alerts run failed');
        return;
      }
      const d = result.data ?? {};
      if (d.checksRun === 0) {
        alert('Žádné kontroly nejsou naplánované ke spuštění.');
      } else {
        const parts = [
          `Spuštěno ${d.checksRun} kontrol`,
          `${d.totalFindings} nálezů (${d.newFindings} nových)`,
        ];
        if ((d.stats?.error ?? 0) > 0) parts.push(`${d.stats.error} chyba`);
        alert(parts.join(', '));
      }
      // Refresh rows so any newly created alerts appear.
      pageNumber = 0;
      fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
      if (selectedRowId != null) {
        fetchDetail(selectedRowId);
      }
    } finally {
      runDueInProgress = false;
    }
  }

  async function handleImportStatementFile(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || importInProgress) return;
    importInProgress = true;
    try {
      const result = await importStatement(file);
      if (!result?.success) {
        alert(translateError(result?.error) || 'Import výpisu selhal');
        return;
      }
      const d = result.data ?? {};
      const parts = [
        `Vytvořeno ${d.created ?? 0} transakcí`,
        `přeskočeno ${d.skipped ?? 0}`,
      ];
      const errs = (d.statements ?? []).filter((s) => s.error).map((s) => s.error);
      if (errs.length > 0) parts.push('chyby: ' + errs.join('; '));
      alert(parts.join(', '));
      refreshAfterAction();
    } finally {
      importInProgress = false;
    }
  }

  async function submitReanalyze() {
    if (selectedRowId == null || reanalyzeSubmitting) return;
    reanalyzeSubmitting = true;
    try {
      const body = {};
      if (reanalyzeProfileNdx !== '' && Number(reanalyzeProfileNdx) > 0) {
        body.profile_override_ndx = Number(reanalyzeProfileNdx);
      }
      const result = await post(`/_mail/messages/${selectedRowId}/reanalyze`, body);
      if (result?.success) {
        reanalyzeDialogOpen = false;
        // Refresh detail i list — zpráva mohla změnit stav
        pageNumber = 0;
        fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
        fetchDetail(selectedRowId);
      } else {
        alert(t('viewer.reanalyze.failed', { msg: translateError(result?.error) }));
      }
    } finally {
      reanalyzeSubmitting = false;
    }
  }

  function closeReanalyzeDialog() {
    if (reanalyzeSubmitting) return;
    reanalyzeDialogOpen = false;
  }

  async function submitSetPassword(password) {
    if (selectedRowId == null || passwordSubmitting) return;
    passwordSubmitting = true;
    try {
      const result = await post(`/_mail/senders/${selectedRowId}/password`, { password });
      if (result?.success) {
        passwordDialogOpen = false;
        refreshAfterAction();
      } else {
        alert(t('viewer.detail.setPasswordFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      passwordSubmitting = false;
    }
  }

  function closePasswordDialog() {
    if (passwordSubmitting) return;
    passwordDialogOpen = false;
  }

  function handleRegistryWizardClose() {
    registryWizardOpen = false;
  }

  function handleRegistryWizardSaved(personId) {
    // Refresh the list so the new record appears, and focus it. The list
    // is sorted by docState/name, so the new record may not be at the top
    // — fetchDetail still highlights it in the detail panel even if it's
    // scrolled out of view.
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
    if (personId != null) {
      selectedRowId = personId;
      fetchDetail(personId);
    }
  }

  function handleDetailRefresh() {
    if (selectedRowId != null) {
      fetchDetail(selectedRowId);
      // Také refresh list — apply/reject mohlo přepnout stav zprávy 30→40
      pageNumber = 0;
      fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
    }
  }

  function refreshAfterAction() {
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
    if (selectedRowId != null) {
      fetchDetail(selectedRowId);
    }
  }

  async function handleDetailAction(actionId, action, value) {
    if (selectedRowId == null) return;
    const recordId = selectedRowId;

    // Vestavěné alerts akce — identifikujeme podle id. Záměrně neřešíme
    // viewer/tabulku: id je sdílený slovník („snooze", „dismiss", „recheck",
    // „unsnooze") a jiný viewer ho zatím nepoužívá. Až bude víc konzumentů
    // se stejnými id, přesuneme dispatch na backend přes action.kind/target.
    if (actionId === 'snooze') {
      if (!value) return;
      const result = await snoozeAlert(recordId, value);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    if (actionId === 'dismiss') {
      const result = await dismissAlert(recordId);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    if (actionId === 'unsnooze') {
      const result = await unsnoozeAlert(recordId);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    if (actionId === 'recheck') {
      const checkId = action.meta?.checkId;
      if (!checkId) return;
      const result = await runAlertCheck(checkId);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    // Přeúčtovat doklad (DocsHeadsViewer, doklad ve stavu 40). Success
    // zahrnuje i výsledek „zaúčtováno s chybami" — refresh detailu ukáže
    // banner v tabu Zaúčtování.
    if (actionId === 'reaccount') {
      const result = await reaccountDocument(recordId);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    // Přeúčtovat bankovní transakci (BankTransactionsViewer, stav 40) —
    // jiný endpoint/payload než doklad. Success vč. „zaúčtováno s chybami".
    if (actionId === 'reaccountTransaction') {
      const result = await reaccountTransaction(recordId);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    // Přepočítat snapshot podání DPH (FilingsViewer, koncept ve stavu 10).
    // Doménová chyba sestavení (kód DPH bez mapování) přijde jako 422 —
    // uživatel ji musí vidět, snapshot zůstane nezměněný.
    if (actionId === 'recomposeFiling') {
      const result = await recomposeFiling(recordId);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    // Načíst hlavičku podání znovu z profilu podatele (FilingsViewer,
    // koncept ve stavu 10). Přepíše i ruční úpravy v tabu Hlavička, proto
    // potvrzení; přepočet snapshotu hlavičku schválně nechává být.
    if (actionId === 'reloadFilingHeader') {
      if (!confirm(t('viewer.detail.reloadFilingHeaderConfirm'))) return;
      const result = await reloadFilingHeader(recordId);
      if (result?.success) refreshAfterAction();
      else alert(translateError(result?.error));
      return;
    }
    // Vyrobit soubory podání pro daňový portál (FilingsViewer). Chyby
    // hlavičky přijdou jako 422 se seznamem polí — uživatel je doplní ve
    // formuláři, proto se vypisují i s názvem pole. Hotové soubory jsou
    // v záložce Přílohy.
    if (actionId === 'generateFilingFiles') {
      const result = await generateFilingFiles(recordId);
      if (result?.success) {
        const names = (result.data?.files ?? []).map((f) => f.name).join(', ');
        const warnings = result.data?.warnings ?? [];
        alert(
          t('viewer.detail.filingFilesCreated', { names })
          + (warnings.length ? `\n\n${warnings.join('\n')}` : ''),
        );
        refreshAfterAction();
      } else {
        const details = result?.error?.details ?? [];
        alert(
          translateError(result?.error)
          + (details.length ? `\n\n${details.map((d) => `• ${d.message}`).join('\n')}` : ''),
        );
      }
      return;
    }
    // Zaúčtovat podané přiznání DPH (FilingsViewer, #55 D28–D31): služba
    // založí účetní doklad jako koncept — po úspěchu se otevře rovnou ve
    // formuláři, uživatel ho zkontroluje a uzavře. Varování (chybějící
    // správce daně) se ukážou před otevřením; odmítnutí (živý doklad,
    // chybějící účet, zámek měsíce) přijde jako 422 s detaily.
    if (actionId === 'accountFiling') {
      const result = await accountFiling(recordId);
      if (result?.success) {
        const warnings = result.data?.warnings ?? [];
        alert(t('viewer.detail.filingAccounted') + (warnings.length ? `\n\n${warnings.join('\n')}` : ''));
        refreshAfterAction();
        const docId = result.data?.docId ?? null;
        if (docId != null) {
          formTable = 'docs_core_heads';
          editRecordId = docId;
          formDefaultData = {};
          formOpen = true;
        }
      } else {
        const details = (result?.error?.details ?? []).filter((d) => d.code !== 'existing');
        alert(
          translateError(result?.error)
          + (details.length ? `\n\n${details.map((d) => `• ${d.message}`).join('\n')}` : ''),
        );
      }
      return;
    }
    // Zamknout / odemknout instanci tvrzení DPH (#55 D25) — z detailu
    // instance (ReportPeriodsViewer) i podaného podání (FilingsViewer,
    // „Uzamknout tvrzení"); id instance nese action.target.periodId.
    // Potvrzení odemknutí řeší ViewerDetail přes action.confirm.
    if (actionId === 'lockReportPeriod' || actionId === 'unlockReportPeriod') {
      const periodId = action.target?.periodId ?? recordId;
      const result = await lockReportPeriod(periodId, actionId === 'lockReportPeriod');
      if (result?.success) {
        refreshAfterAction();
      } else {
        const details = result?.error?.details ?? [];
        alert(
          translateError(result?.error)
          + (details.length ? `\n\n${details.map((d) => `• ${d.message}`).join('\n')}` : ''),
        );
      }
      return;
    }
    // Nastavit heslo SMTP senderu (SendersViewer) — dialog, plaintext jde
    // jen na dedikovaný endpoint; sloupec je sensitive, CRUD ho nevidí.
    if (actionId === 'setPassword') {
      passwordDialogOpen = true;
      return;
    }
    // Smazat pravidlo štítku (TagRulesViewer) — bezstavová tabulka bez
    // koše, hard DELETE přes generický CRUD; unique(IČO) nesmí blokovat
    // budoucí re-learning (content-tag-ui D28).
    if (actionId === 'deleteTagRule') {
      if (!confirm(t('viewer.detail.deleteTagRuleConfirm'))) return;
      const result = await del(`/core_exchange_tag_rules/${recordId}`);
      if (result?.success) {
        selectedRowId = null;
        pageNumber = 0;
        fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
      } else {
        alert(translateError(result?.error));
      }
      return;
    }
    // Poslat pozvánku uživateli (UsersViewer, admin) — mail s linkem na
    // nastavení hesla. Opakované volání přepošle (starý token zaniká).
    if (actionId === 'invite') {
      const result = await inviteUser(recordId);
      if (result?.success) alert(t('viewer.detail.inviteSent'));
      else alert(t('viewer.detail.inviteFailed', { msg: translateError(result?.error) }));
      return;
    }

    // Custom akce — generická obsluha podle kind.
    if (action.kind === 'open_form') {
      const target = action.target ?? {};
      if (!target.table) return;
      formTable = target.table;
      editRecordId = target.mode === 'edit' ? (target.id ?? null) : null;
      formDefaultData = target.preset ?? {};
      formOpen = true;
      return;
    }
    if (action.kind === 'open_viewer') {
      const targetViewerId = action.viewerId ?? action.target?.viewerId;
      const targetRecordId = action.recordId ?? action.target?.recordId ?? null;
      // Volitelný chip a custom filtry cílového vieweru (saldokonto →
      // „Pohyby případu" otevře pohyby s partnerem / VS / SS).
      const targetViewGroup = action.viewGroup ?? action.target?.viewGroup ?? null;
      const targetFilters = action.filters ?? action.target?.filters ?? null;
      if (!targetViewerId) return;
      navigationStore.navigateToViewer(targetViewerId, targetRecordId, targetViewGroup, targetFilters);
      return;
    }

    console.warn('Unknown detail action', actionId, action);
  }

  function handleFormClose() {
    formOpen = false;
    editRecordId = null;
    formTable = null;
    formDefaultData = {};
    formNotice = null;
  }

  function handleFormSaved() {
    pageNumber = 0;
    fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, 0, effectiveLayout, activeSort);
    if (selectedRowId != null) {
      fetchDetail(selectedRowId);
    }
    // formTable/formDefaultData se NEresetují tady. Formulář může po Uložit
    // zůstat otevřený a tyto props ho parametrizují — přepisování `formDefaultData = {}`
    // tvoří novou object referenci, která spouští re-run `$effect`u v `FormEditor`u
    // a v kombinaci s probíhajícím `loadForm(table, newId)` způsobuje race condition
    // (form se vynuluje na prázdný nový záznam). Reset proběhne až v handleFormClose.
    // Viz docs/edit-forms.md sekce 19.
  }

  // Re-initialize ONLY when the navigation item changes.
  // IMPORTANT: this $effect must not read any $state other than the tab
  // props on the next lines. Trackuje IDENTITU POLOŽKY (tab.id), ne jen
  // tab.viewerId — dvě sidebar položky můžou sdílet viewer (Pohledávky /
  // Závazky nad economy.accbal.ledger, liší se jen fixedViewGroup)
  // a přepnutí mezi nimi musí viewer reinicializovat.
  $effect(() => {
    void tab.id;
    const viewerId = tab.viewerId;
    const fixedViewGroup = tab.fixedViewGroup ?? null;

    // Reset all state
    meta = null;
    rows = [];
    selectedRowId = null;
    detail = null;
    detailToolbar = [];
    activeSearch = '';
    // Pre-meta placeholder — bez meta se tab lišta nerenderuje; skutečný
    // default (meta.defaultViewGroup) se nastaví po fetchMeta() níže.
    activeViewGroup = 'active';
    activeSeriesId = null;
    activeFilters = {};
    pageNumber = 0;
    hasMore = false;
    activeLayout = 'list';
    activeSort = null;
    footer = null;
    // Sentinel: init nového vieweru běží — layout-change $effect nesmí
    // střílet, dokud fetchMeta().then nenastaví prevLayout.
    prevLayout = null;

    if (searchInputEl) {
      searchInputEl.value = '';
    }

    // Vyzvedneme pending record (z dashboard widget klikání) — pokud existuje,
    // po načtení řádků nastavíme selectedRowId a fetchneme detail. Konzumace
    // hned vrátí store do nuly, aby se efekt neaplikoval podruhé.
    //
    // POZOR: consumePendingRecordId() čte i zapisuje $state pendingRecordId.
    // Bez untrack() by se tím pendingRecordId stal závislostí tohoto $effectu
    // a jeho zápis na null by efekt znovu naplánoval — re-run by přepsal
    // activeViewGroup zpět na 'active', takže přepnutí na záložku „Vše" by se
    // potichu ztratilo (hledání ve „Vše" vracelo jen aktivní záznamy).
    // untrack() drží efekt závislý jen na tab.viewerId, jak slibuje komentář
    // výše. Stejný vzor jako FormEditor.svelte.
    const pendingRecord = untrack(() => navigationStore.consumePendingRecordId());

    // Pending viewGroup (digest karta → tab Archiv) — stejný jednorázový
    // kontrakt a untrack() důvody jako pendingRecord výše.
    const pendingViewGroup = untrack(() => navigationStore.consumePendingViewGroup());
    if (pendingViewGroup != null) {
      activeViewGroup = pendingViewGroup;
    }

    // Pending custom filtry (akce open_viewer s `filters`) — předvyplní
    // panel filtrů a jdou do prvního fetche; stejný jednorázový kontrakt.
    // Slévají se s výchozími hodnotami filtrů až po fetchMeta() níže
    // (defaulty zná jen meta); pending vítězí.
    const pendingFilters = untrack(() => navigationStore.consumePendingFilters());

    // Sequence: meta first (sets activeSeriesId from numberSeries), then rows
    // with that filter, then optional pending-record detail.
    fetchMeta(viewerId).then(() => {
      // Efektivní layout pro initial fetch. Čtení meta/isMobile tady už
      // netrackuje ($effect trackuje jen synchronní čtení), ale isMobile
      // bereme přes untrack pro jistotu konzistence s disciplínou souboru.
      // prevLayout se nastavuje PŘED activeLayout — až derived
      // effectiveLayout přepočítá a layout-change $effect se probudí,
      // uvidí shodu a neudělá druhý fetch.
      const isMobile = untrack(() => layoutStore.isMobile);
      const layouts = untrack(() => meta)?.layouts ?? [];
      // Persistovaná volba (toggle, D10) má přednost před defaultLayout;
      // hodnota mimo meta.layouts se ignoruje.
      const persisted = getViewerLayout(viewerId);
      const chosenLayout = persisted !== null && layouts.includes(persisted)
        ? persisted
        : (untrack(() => meta)?.defaultLayout ?? 'list');
      const layout = isMobile || !layouts.includes('grid') ? 'list' : chosenLayout;
      prevLayout = layout;
      activeLayout = chosenLayout;

      // Výchozí viewGroup zná až meta — docState viewery vrací 'active',
      // datové (LedgerViewer) kód první skupiny. Fixní skupina ze sidebar
      // položky přebíjí všechno (položka je napevno filtrovaná), jinak má
      // přednost pendingViewGroup (digest karta → tab Archiv). Stejnou
      // hodnotou nastavíme i activeViewGroup — všechny další fetche
      // (hledání, filtry, refresh) ji pak posílají samy; u fixní skupiny
      // ji nemá jak změnit ani uživatel (chip lišta je skrytá).
      const viewGroup = fixedViewGroup
        ?? pendingViewGroup
        ?? untrack(() => meta)?.defaultViewGroup
        ?? 'active';
      activeViewGroup = viewGroup;

      // Počáteční filtry = výchozí hodnoty z meta.filters (`default`)
      // přepsané pending filtry z akce open_viewer (pending > default).
      // Předáváme lokální hodnotu, protože tento $effect nesmí číst jiný
      // $state než tab.viewerId.
      const filters = initialFilterValues(untrack(() => meta)?.filters, pendingFilters);
      activeFilters = filters;
      fetchRowsExplicit(viewerId, '', viewGroup, activeSeriesId, filters, 0, layout).then(() => {
        if (pendingRecord != null) {
          selectedRowId = pendingRecord;
          fetchDetail(pendingRecord);
        }
      });
    });
  });

  // Změna efektivního layoutu po initu (v F1 jen resize přes mobile
  // breakpoint; v F2 přibude toggle). Tvary řádků layoutů se liší →
  // reset stránkování + výběru a refetch. Čte JEN effectiveLayout;
  // prevLayout je ne-reaktivní guard proti dvojímu fetchi při mountu
  // (init fetch jde z tab-change $effectu výše).
  $effect(() => {
    const layout = effectiveLayout;
    if (prevLayout === null || layout === prevLayout) {
      return;
    }
    prevLayout = layout;
    untrack(() => {
      // List má vlastní pevné řazení — sort patří jen gridu (D9).
      if (layout === 'list') {
        activeSort = null;
      }
      selectedRowId = null;
      detail = null;
      pageNumber = 0;
      fetchRowsExplicit(tab.viewerId, activeSearch, activeViewGroup, activeSeriesId, activeFilters, 0, layout, activeSort);
    });
  });

  // Publikování akcí do MobileTopBaru (jen mobil). Na desktopu se akce
  // renderují ve ViewerToolbar (beze změny), takže top bar nečteme.
  //
  // Reaktivně čte isMobile, selectedRowId, meta, detail, detailToolbar, tab —
  // přepočítá se při výběru řádku i při přepnutí mobil/desktop (žádoucí).
  //
  // Mapování handlerů: jak list akce (meta.toolbar), tak detail akce
  // (detailToolbar = result.data.toolbar) jdou přes `handleToolbarAction`,
  // přesně jako desktop ViewerToolbar (onAction={handleToolbarAction}).
  // Snooze/dismiss/recheck a kind akce NEJSOU v detailToolbaru — žijí v
  // `detail.actions` uvnitř ViewerDetail (na mobilu plná šířka detailu),
  // takže se do top baru vůbec nedostanou a zůstávají beze změny.
  $effect(() => {
    if (!layoutStore.isMobile) {
      layoutStore.clearScreenSurface();
      return;
    }

    if (selectedRowId == null) {
      // Seznam — akce z meta.toolbar (Přidat, Přidat z registru, …).
      const actions = (meta?.toolbar ?? []).map(a => ({
        id: a.id,
        label: a.label,
        icon: a.icon,
        variant: a.variant,
        onClick: () => handleToolbarAction(a.id),
      }));
      layoutStore.setScreenSurface({
        context: 'list',
        actions,
        title: tab.label ?? null,
        back: null,
      });
    } else {
      // Detail — akce z detailToolbar. První = hlavní (ikona), zbytek kebab.
      // `create` (Přidat) patří jen do seznamu — backend ho ale vrací i pro
      // vybraný řádek (viz TableViewer::getToolbarActions). Na mobilu ho
      // z detailu odfiltrujeme, ať hlavní akce je Otevřít (edit), ne Přidat.
      const actions = (detailToolbar ?? [])
        .filter(a => a.id !== 'create' && a.id !== 'add' && a.id !== 'new')
        .map(a => ({
          id: a.id,
          label: a.label,
          icon: a.icon,
          variant: a.variant,
          onClick: () => handleToolbarAction(a.id),
        }));
      layoutStore.setScreenSurface({
        context: 'detail',
        actions,
        title: detail?.title ?? tab.label ?? null,
        back: () => {
          selectedRowId = null;
          detail = null;
        },
      });
    }
  });

  // Úklid při unmountu — ať akce nezůstanou na další obrazovce.
  $effect(() => {
    return () => layoutStore.clearScreenSurface();
  });
</script>

{#if meta?.table || formTable}
  <FormDialog
    table={formTable ?? meta.table}
    recordId={editRecordId}
    open={formOpen}
    onClose={handleFormClose}
    onSaved={handleFormSaved}
    defaultData={formDefaultData}
    notice={formNotice}
  />
{/if}

<RegistryImportWizard
  open={registryWizardOpen}
  onClose={handleRegistryWizardClose}
  onSaved={handleRegistryWizardSaved}
/>

<div class="shpd-viewer">
  <!-- Skrytý file input pro import bankovního výpisu (akce import_statement) -->
  <input
    type="file"
    bind:this={importFileInput}
    onchange={handleImportStatementFile}
    accept=".xml,.gpc,.json,.sta,.txt,.csv"
    style="display: none"
  />

  {#if !layoutStore.isMobile}
    <!-- Na mobilu jsou akce v top baru (publikované přes layout store),
         takže ViewerToolbar se nerenderuje. Desktop beze změny. -->
    <ViewerToolbar actions={toolbarActions} onAction={handleToolbarAction} />
  {/if}

  <div
    class="shpd-viewer__body"
    class:shpd-viewer__body--mobile={layoutStore.isMobile}
    class:shpd-viewer__body--detail={layoutStore.isMobile && selectedRowId != null}
  >
    <!-- Left panel: tabs + search + row list.
         V grid layoutu panel zabírá celou šířku (tabulka je full-width,
         detail žije v draweru), taby/search/filtry zůstávají stejné. -->
    <div class="shpd-viewer__list-panel" class:shpd-viewer__list-panel--grid={isGrid}>

      <!-- Doc state tab bar (only shown when viewer supports viewGroups) -->
      {#if hasViewGroups && viewTabs().length > 0}
        <div class="shpd-viewer__tabs">
          {#each viewTabs() as vt (vt.id)}
            <button
              class="shpd-viewer__tab"
              class:shpd-viewer__tab--active={activeViewGroup === vt.id}
              onclick={() => handleTabClick(vt.id)}
              type="button"
            >
              {vt.label}
            </button>
          {/each}
        </div>
      {/if}

      <!-- Search (+ toggle layoutu, když viewer umí list i grid — D10) -->
      <div class="shpd-viewer__search">
        <div class="shpd-viewer__search-box">
          <input
            class="shpd-viewer__search-input"
            data-testid="viewer-search"
            type="text"
            placeholder={t('viewer.search.placeholder')}
            oninput={handleSearchInput}
            bind:this={searchInputEl}
          />
          {#if activeSearch}
            <button class="shpd-viewer__search-clear" onclick={handleSearchClear} aria-label={t('viewer.search.clear')}>×</button>
          {/if}
        </div>
        {#if hasLayoutToggle}
          <!-- Ikona ukazuje CÍLOVÝ layout (v gridu list a naopak). -->
          <Button
            iconOnly
            variant="ghost"
            size="sm"
            icon={isGrid ? iconList : iconTable}
            label={t(isGrid ? 'viewer.layout.showList' : 'viewer.layout.showGrid')}
            onclick={handleLayoutToggle}
          />
        {/if}
      </div>

      <!-- Custom filtry vieweru (meta.filters) — viz ViewerFilters.svelte -->
      {#if viewerFilters.length > 0}
        <ViewerFilters
          filters={viewerFilters}
          values={activeFilters}
          onChange={handleFilterChange}
        />
      {/if}

      <!-- Row list / grid -->
      {#if isGrid}
        <ViewerGrid
          columns={meta?.grid?.columns ?? []}
          showIndex={meta?.grid?.showIndex ?? true}
          {rows}
          {footer}
          {selectedRowId}
          {hasMore}
          {loadingRows}
          {loadingMore}
          sort={activeSort}
          onSortChange={handleSortChange}
          onRowClick={handleRowClick}
          onRowDblClick={handleRowDblClick}
          onLoadMore={handleGridLoadMore}
        />
      {:else}
      <div
        class="shpd-viewer__rows"
        data-testid="viewer-rows"
        bind:this={listEl}
        onscroll={handleScroll}
      >
        {#if loadingRows && rows.length === 0}
          <div class="shpd-viewer__status">
            <span class="shpd-viewer__spinner"></span>
            <span>{t('common.loading')}</span>
          </div>
        {:else if rows.length === 0}
          <div class="shpd-viewer__status">
            {t('common.empty')}
          </div>
        {:else}
          {#each rows as row, i (row.id)}
            <ViewerRow
              {row}
              index={i + 1}
              selected={selectedRowId === row.id}
              onclick={() => handleRowClick(row)}
            />
          {/each}

          {#if loadingMore}
            <div class="shpd-viewer__status">
              <span class="shpd-viewer__spinner"></span>
              <span>{t('common.loading')}</span>
            </div>
          {:else if !hasMore && rows.length > 0}
            <div class="shpd-viewer__status shpd-viewer__status--end">
              {t('viewer.endOfList')}
            </div>
          {/if}
        {/if}
      </div>
      {/if}

      <!-- Bottom bar: number-series tabs (shown only when >1 series) -->
      {#if hasNumberSeriesTabs}
        <div class="shpd-viewer__series-tabs">
          {#each numberSeries as ns (ns.id)}
            <button
              class="shpd-viewer__series-tab"
              class:shpd-viewer__series-tab--active={activeSeriesId === ns.id}
              onclick={() => handleSeriesTabClick(ns.id)}
              type="button"
            >
              {ns.name}
            </button>
          {/each}
        </div>
      {/if}
    </div>

    <!-- Right panel: detail. V grid layoutu se nerenderuje — detail
         žije v non-modálním draweru (ViewerDetailDrawer). -->
    {#if !isGrid}
      <div class="shpd-viewer__detail-panel" data-testid="viewer-detail">
        {#if selectedRowId != null}
          <ViewerDetail
            {detail}
            loading={detailLoading}
            onRefresh={handleDetailRefresh}
            onAction={handleDetailAction}
          />
        {:else}
          <div class="shpd-viewer__detail-empty">
            {t('viewer.selectRecord')}
          </div>
        {/if}
      </div>
    {/if}
  </div>
</div>

<!-- Detail drawer gridu — non-modální slide-over zprava (D4). Bez overlay,
     klik na jiný řádek přepíná detail v otevřeném draweru. -->
{#if isGrid && selectedRowId != null}
  <ViewerDetailDrawer
    {detail}
    loading={detailLoading}
    actions={detailToolbar}
    onToolbarAction={handleToolbarAction}
    onAction={handleDetailAction}
    onRefresh={handleDetailRefresh}
    onClose={handleDrawerClose}
  />
{/if}

<!-- Reanalyze dialog — sdílená Modal komponenta (../ui/Modal.svelte). -->
<Modal
  title={t('viewer.reanalyze.title')}
  open={reanalyzeDialogOpen}
  onClose={closeReanalyzeDialog}
  width="520px"
>
  <p>{t('viewer.reanalyze.body', { states: t('viewer.reanalyze.replaceableStates') })}</p>

  <label class="shpd-reanalyze__label">
    {t('viewer.reanalyze.profileLabel')}
    <select class="shpd-reanalyze__input" bind:value={reanalyzeProfileNdx}>
      <option value="">{t('viewer.reanalyze.defaultProfile')}</option>
      {#each reanalyzeProfiles as p (p.ndx)}
        <option value={String(p.ndx)}>{p.name} ({p.profile_id})</option>
      {/each}
    </select>
  </label>

  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" disabled={reanalyzeSubmitting} onclick={closeReanalyzeDialog} />
    <Button
      label={reanalyzeSubmitting ? t('viewer.reanalyze.submitting') : t('viewer.reanalyze.submit')}
      variant="primary"
      size="sm"
      disabled={reanalyzeSubmitting}
      onclick={submitReanalyze}
    />
  {/snippet}
</Modal>

<!-- Nastavení hesla SMTP senderu (detail akce setPassword) -->
<SetPasswordPrompt
  open={passwordDialogOpen}
  submitting={passwordSubmitting}
  onConfirm={submitSetPassword}
  onClose={closePasswordDialog}
/>

<style>
  .shpd-viewer {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  .shpd-viewer__body {
    display: flex;
    flex: 1;
    overflow: hidden;
  }

  /* --- Mobilní list/detail přepínání --- */
  /* Na mobilu je vidět jen jeden panel. Bez vybraného řádku seznam přes
     celou šířku; s vybraným řádkem detail přes celou šířku, seznam skrytý.
     Breakpoint 768px musí LADIT s MOBILE_BREAKPOINT v layout.svelte.js. */
  @media (max-width: 768px) {
    .shpd-viewer__body--mobile .shpd-viewer__list-panel {
      width: 100%;
      flex-shrink: 1;
      border-right: none;
    }

    .shpd-viewer__body--mobile .shpd-viewer__detail-panel {
      display: none;
    }

    /* Detail stav: seznam pryč, detail přes celou šířku. */
    .shpd-viewer__body--detail .shpd-viewer__list-panel {
      display: none;
    }

    .shpd-viewer__body--detail .shpd-viewer__detail-panel {
      display: block;
    }
  }

  /* Left panel */
  .shpd-viewer__list-panel {
    width: 400px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    border-right: 1px solid var(--shpd-color-border);
    overflow: hidden;
  }

  /* Grid layout — tabulka přes celou šířku body, detail v draweru. */
  .shpd-viewer__list-panel--grid {
    width: auto;
    flex: 1;
    border-right: none;
  }

  /* View group tabs. Datové skupiny (saldokonta, ~9 chipů) se do 400px
     panelu nevejdou — horizontální scroll bez zalomení se skrytým
     scrollbarem, vzor FeedFilter na dashboardu. */
  .shpd-viewer__tabs {
    display: flex;
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: none;
  }

  .shpd-viewer__tab {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    transition: color 0.12s, border-color 0.12s;
  }

  .shpd-viewer__tab:hover {
    color: var(--shpd-color-text);
  }

  .shpd-viewer__tab--active {
    color: var(--shpd-color-primary);
    border-bottom-color: var(--shpd-color-primary);
    font-weight: 600;
  }

  /* Search — flex řádek: input box (flex 1) + volitelný layout toggle. */
  .shpd-viewer__search {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-viewer__search-box {
    position: relative;
    flex: 1;
    min-width: 0;
  }

  .shpd-viewer__search-input {
    width: 100%;
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    padding-right: 28px;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .shpd-viewer__search-input:focus {
    border-color: var(--shpd-color-border-focus);
    box-shadow: 0 0 0 2px var(--shpd-color-focus-ring);
  }

  .shpd-viewer__search-clear {
    position: absolute;
    right: 4px;
    top: 50%;
    transform: translateY(-50%);
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: none;
    background: none;
    font-size: 1rem;
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    border-radius: var(--shpd-radius-sm);
  }

  .shpd-viewer__search-clear:hover {
    color: var(--shpd-color-text);
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-viewer__rows {
    flex: 1;
    overflow-y: auto;
  }

  /* Right panel */
  .shpd-viewer__detail-panel {
    flex: 1;
    overflow: hidden;
    background-color: var(--shpd-color-bg);
  }

  .shpd-viewer__detail-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  /* Status messages */
  .shpd-viewer__status {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-md);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-viewer__status--end {
    opacity: 0.6;
    font-style: italic;
  }

  /* Reanalyze dialog — jen styly form polí uvnitř.
     Modální shell (overlay, header, body, footer) je ve sdílené Modal komponentě. */
  .shpd-reanalyze__label {
    display: block;
    margin-top: var(--shpd-space-md);
    font-weight: 500;
  }

  .shpd-reanalyze__input {
    display: block;
    margin-top: 6px;
    padding: 6px 10px;
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    min-width: 280px;
    max-width: 100%;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text);
  }

  /* Spodní lišta záložek číselných řad. Ortogonální k viewGroup tabům nahoře —
     viewGroup filtruje docState, series filtruje number_series. V 400px panelu
     se 4+ řad začne tísnit, proto horizontální scroll; žádné wrapping. */
  .shpd-viewer__series-tabs {
    display: flex;
    flex-shrink: 0;
    border-top: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
  }

  .shpd-viewer__series-tab {
    padding: var(--shpd-space-xs) var(--shpd-space-md);
    border: none;
    border-top: 2px solid transparent;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    transition: color 0.12s, border-color 0.12s;
  }

  .shpd-viewer__series-tab:hover {
    color: var(--shpd-color-text);
  }

  .shpd-viewer__series-tab--active {
    color: var(--shpd-color-primary);
    border-top-color: var(--shpd-color-primary);
    font-weight: 600;
  }

  /* Spinner */
  .shpd-viewer__spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-viewer-spin 0.7s linear infinite;
    flex-shrink: 0;
  }

  @keyframes shpd-viewer-spin {
    to { transform: rotate(360deg); }
  }
</style>
