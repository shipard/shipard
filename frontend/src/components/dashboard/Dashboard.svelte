<script>
  import { onMount, tick } from 'svelte';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import { fetchDashboard, setMessageDocState } from '../../api/dashboard.js';
  import {
    applyMessage,
    rejectMessage,
    reanalyzeMessage,
  } from '../../api/exchange.js';
  import { confirmSenderRule, rejectSenderRule, undoAutoArchive } from '../../api/mail.js';
  import { materializeContentTag } from '../../api/contentTags.js';
  import { iconRefresh, iconUpload } from '../../icons.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import Button from '../ui/Button.svelte';
  import FormDialog from '../form/FormDialog.svelte';
  import DocumentExchangePreviewModal from '../exchange/DocumentExchangePreviewModal.svelte';
  import AiSummaryCard from './AiSummaryCard.svelte';
  import ChatLauncher from './ChatLauncher.svelte';
  import Feed from './Feed.svelte';
  import FeedFilter from './FeedFilter.svelte';
  import MailUploadModal from './MailUploadModal.svelte';
  import QueueCategoriesPrompt from './QueueCategoriesPrompt.svelte';
  import RejectReasonPrompt from './RejectReasonPrompt.svelte';
  import ViewerDetailModal from '../viewer/ViewerDetailModal.svelte';

  const HEADS_TABLE = 'docs_core_heads';
  const REGISTRY_TABLE = 'base_registry_documents';

  let data = $state(null);
  let loading = $state(true);
  let error = $state(null);

  // Filtr feedu — čistě klientský, drží se jen ve stavu komponenty (neresetuje
  // se v load(), takže přežije manuální Obnovit; reload aplikace → 'all').
  // Karty bez `category` (např. „…a další") se zobrazují jen v záložce Vše.
  let feedFilter = $state('all');

  const CATEGORIES = ['invoices', 'registry', 'other'];

  let feedCounts = $derived.by(() => {
    const c = { all: data?.cards?.length ?? 0, invoices: 0, registry: 0, other: 0 };
    for (const card of data?.cards ?? []) {
      if (CATEGORIES.includes(card.category)) c[card.category]++;
    }
    return c;
  });

  let feedUrgent = $derived.by(() => {
    const u = { invoices: false, registry: false, other: false };
    for (const card of data?.cards ?? []) {
      if (card.kind === 'urgent' && CATEGORIES.includes(card.category)) u[card.category] = true;
    }
    return u;
  });

  let filteredCards = $derived(
    feedFilter === 'all'
      ? (data?.cards ?? [])
      : (data?.cards ?? []).filter((c) => c.category === feedFilter),
  );

  // Karta s právě běžící inline akcí (reanalyze, koš/archiv, pravidla)
  // → disabluje její tlačítka.
  let busyCardId = $state(null);

  // Review modal („Zkontrolovat“) a reject prompt drží messageNdx.
  let previewNdx = $state(null);
  let rejectNdx = $state(null);
  let rejectSubmitting = $state(false);

  // Sériový průchod frontou (Issue #32/1, tasks/dashboard-queue-walkthrough.md).
  // null = běžný single-message režim. list je snapshot messageNdx pořízený
  // při startu — nezávislý na data.cards (ty se během průchodu optimisticky
  // mažou přes dropCardByMessage) i na kartách přibylých mezitím.
  let queue = $state(null);
  // Předkrok „Nová kategorie" (D8) — {cards, list} pro QueueCategoriesPrompt;
  // null = zavřeno. Snapshot zpráv (list) se bere už při kliknutí na tlačítko,
  // předkrok ho jen podrží do „Pokračovat".
  let queuePrecheck = $state(null);

  // Karty způsobilé pro průchod (D1): jen návrhy přijatých faktur (target
  // docs), pásma ready + review. Prefix id je nejpřesnější rozlišení zdroje —
  // content_tag karty mají také category invoices, ale prefix je odfiltruje;
  // urgent/info karty neprojdou prefixem resp. target filtrem.
  let queueableCards = $derived(
    (data?.cards ?? []).filter(
      (c) =>
        c.id?.startsWith('mail_suggestion:')
        && c.category === 'invoices'
        && c.context?.target === 'docs',
    ),
  );

  // Form modal — alert open_form, toast „Otevřít“ (registry) a vystavená
  // faktura po apply z review modalu. wasSaved viz handleFormClose.
  let formModal = $state({ open: false, table: '', recordId: null, wasSaved: false });

  // Read-only detail modal — open_detail akce karet („Otevřít e-mail").
  // Čtení nic nemění, zavření na rozdíl od handleFormClose nevolá load().
  let detailModal = $state({ open: false, viewerId: '', recordId: null, tabId: null });

  // Minimální lokální toast (app nemá toast infra). kind: 'applied' → Otevřít.
  // docTable řídí, kterou tabulku „Otevřít“ otevře — dnes jen Spisovna;
  // vystavená faktura (docs) se místo toastu otevírá rovnou ve FormDialogu.
  let toast = $state({ visible: false, kind: null, message: '', docId: null, docTable: null });
  let toastTimer = null;

  // Ruční nahrání (tasks/mail-dashboard-upload.md) — modal otevírá tlačítko
  // Nahrát i drop na plochu dashboardu; drop nikdy neukládá rovnou (D1).
  let uploadModal = $state({ open: false, files: [] });
  let dragActive = $state(false);

  // Capabilities ze serveru (07b D9) — skrývají ovládání funkcí, které na
  // DS neexistují nebo uživateli nepatří (upload bez core.mail, chat bez
  // core.chat / pro ne-admina na hosting DS). Default true = zpětná
  // kompatibilita se starším serverem bez pole (jen přechodně při deployi).
  const caps = $derived(data?.capabilities ?? { mailUpload: true, chat: true });

  async function load() {
    loading = true;
    error = null;
    try {
      const result = await fetchDashboard();
      if (result) {
        data = result;
      } else {
        error = t('dashboard.error.failed');
      }
    } catch (err) {
      error = t('dashboard.error.failed');
      console.error('Dashboard load failed:', err);
    } finally {
      loading = false;
    }
  }

  // ── Toast ─────────────────────────────────────────────────────────────────

  function showToast(next) {
    clearTimeout(toastTimer);
    toast = { visible: true, docId: null, docTable: null, ...next };
    toastTimer = setTimeout(dismissToast, 8000);
  }

  function dismissToast() {
    clearTimeout(toastTimer);
    toast = { visible: false, kind: null, message: '', docId: null, docTable: null };
  }

  function openCreatedDoc() {
    const docId = toast.docId;
    const docTable = toast.docTable ?? HEADS_TABLE;
    dismissToast();
    if (docId) {
      formModal = { open: true, table: docTable, recordId: docId, wasSaved: false };
    }
  }

  // ── Optimistické odebrání karty ─────────────────────────────────────────────

  function dropCardById(cardId) {
    if (data?.cards) data.cards = data.cards.filter((c) => c.id !== cardId);
  }

  function dropCardByMessage(messageNdx) {
    if (data?.cards) data.cards = data.cards.filter((c) => c.context?.messageNdx !== messageNdx);
  }

  // ── Akce karet ──────────────────────────────────────────────────────────────

  function handleCardAction(card, action) {
    const target = action.target ?? {};
    switch (action.kind) {
      case 'apply_message':
        return applyFlow(card, target.messageNdx);
      case 'review_message':
        previewNdx = target.messageNdx;
        return;
      case 'reject_message':
        rejectNdx = target.messageNdx;
        return;
      case 'reanalyze':
        return reanalyzeFlow(target.messageNdx, card.id);
      case 'trash_message':
        return messageStateFlow(target.messageNdx, 90, card.id);
      case 'archive_message':
        return messageStateFlow(target.messageNdx, 80, card.id);
      case 'confirm_sender_rule':
        return senderRuleFlow(confirmSenderRule, target.ruleId, card.id);
      case 'reject_sender_rule':
        return senderRuleFlow(rejectSenderRule, target.ruleId, card.id);
      case 'undo_auto_archive':
        return undoAutoArchiveFlow(target.date ?? null, card.id);
      case 'materialize_content_tag':
        return materializeTagFlow(target.tag, target.account ?? null, card.id);
      case 'open_viewer':
        return navigationStore.navigateToViewer(target.viewerId, target.recordId ?? null, target.viewGroup ?? null, target.filters ?? null);
      case 'open_detail':
        detailModal = {
          open: true,
          viewerId: target.viewerId,
          recordId: target.recordId,
          tabId: target.tabId ?? null,
        };
        return;
      case 'open_panel':
        return navigationStore.navigateToPanel(target.panelId, action.label ?? null);
      case 'open_form':
        formModal = {
          open: true,
          table: target.table,
          recordId: target.recordId ?? target.id ?? null,
          wasSaved: false,
        };
        return;
      default:
        console.warn('Unknown card action kind:', action.kind);
    }
  }

  // Jednoklik apply z karty (pásmo ready) — safe mode bez userActions.
  // 422 unresolved_required → fall-through do review modalu, kde uživatel
  // reference dořeší; ostatní chyby → alert.
  async function applyFlow(card, messageNdx) {
    if (busyCardId !== null || !messageNdx) return;
    busyCardId = card.id;
    try {
      const result = await applyMessage(messageNdx, null);
      if (result?.success) {
        finishApply(messageNdx, result.data?.savedDocId ?? 0, card.context?.target ?? 'docs');
      } else if (result?.error?.code === 'unresolved_required') {
        previewNdx = messageNdx;
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  // Společné dokončení apply (jednoklik i modal): docs → vystavený Koncept
  // se rovnou otevře ve FormDialogu (kontrola + případné uzavření);
  // registry → toast „Zařazeno… [Otevřít]“ (u Spisovny není co uzavírat).
  // `finalized` = modalové „Vystavit a uzavřít“ (targetDocState 40): doklad
  // je hotový, místo FormDialogu jen toast s odkazem Otevřít.
  function finishApply(messageNdx, docId, target, finalized = false) {
    // Batch mód (D2, D9): žádný FormDialog ani toast, jen počítadla a posun
    // na další zprávu — previewNdx se nesmí nulovat (modal by flicknul
    // zavřít/otevřít a $effect by shodil data). load() až ve finishQueue().
    // Registry větev se sem nemůže trefit — fronta je jen docs (D1).
    if (queue) {
      dropCardByMessage(messageNdx);
      if (finalized) {
        queue.counts.closed += 1;
      } else {
        queue.counts.draft += 1;
      }
      advanceQueue();
      return;
    }
    previewNdx = null;
    dropCardByMessage(messageNdx);
    if (target === 'registry') {
      showToast({
        kind: 'applied',
        message: t('dashboard.toast.appliedRegistry', { id: docId }),
        docId,
        docTable: REGISTRY_TABLE,
      });
    } else if (finalized && docId) {
      showToast({
        kind: 'applied',
        message: t('dashboard.toast.appliedFinal', { id: docId }),
        docId,
        docTable: HEADS_TABLE,
      });
    } else if (docId) {
      formModal = { open: true, table: HEADS_TABLE, recordId: docId, wasSaved: false };
    }
    load();
  }

  async function reanalyzeFlow(messageNdx, cardId) {
    if (busyCardId !== null || !messageNdx) return;
    busyCardId = cardId;
    try {
      const result = await reanalyzeMessage(messageNdx);
      if (result?.success) {
        dropCardById(cardId);
        load();
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  // Potvrzení / zamítnutí návrhu pravidla odesílatele z review karty.
  async function senderRuleFlow(apiCall, ruleId, cardId) {
    if (busyCardId !== null || !ruleId) return;
    busyCardId = cardId;
    try {
      const result = await apiCall(ruleId);
      if (result?.success) {
        dropCardById(cardId);
        load();
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  // „Vrátit vše" z digest karty — obnoví dnešní auto-archiv vč. re-queue analýzy.
  async function undoAutoArchiveFlow(date, cardId) {
    if (busyCardId !== null) return;
    busyCardId = cardId;
    try {
      const result = await undoAutoArchive(date);
      if (result?.success) {
        dropCardById(cardId);
        showToast({
          kind: 'reverted',
          message: t('dashboard.toast.autoArchiveReverted', { count: result.data?.restored ?? 0 }),
        });
        load();
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  // „Založit položku" z karty Nová kategorie (content-tag-ui D25/D26) —
  // po založení se feed přepočítá query-driven (karta zmizí sama), toast
  // s „Otevřít" vede do formu nové položky.
  async function materializeTagFlow(tag, account, cardId) {
    if (busyCardId !== null || !tag) return;
    busyCardId = cardId;
    try {
      const result = await materializeContentTag(tag, account);
      if (result?.success) {
        dropCardById(cardId);
        showToast({
          kind: 'applied',
          message: t('dashboard.toast.itemCreated', {
            code: result.data?.code ?? '',
            name: result.data?.name ?? '',
          }),
          docId: result.data?.itemId ?? null,
          docTable: 'economy_items',
        });
        load();
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  // Jednoklik Koš (90) / Archiv (80) z karty „Není faktura".
  async function messageStateFlow(messageNdx, docState, cardId) {
    if (busyCardId !== null || !messageNdx) return;
    busyCardId = cardId;
    try {
      const result = await setMessageDocState(messageNdx, docState);
      if (result?.success) {
        dropCardById(cardId);
        load();
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      busyCardId = null;
    }
  }

  // ── Review modal ─────────────────────────────────────────────────────────────

  // Apply z review modalu (target přichází z modalu, který ho má z preview
  // endpointu). `applyOptions` {targetDocState: 40} = „Vystavit a uzavřít“.
  async function handleApplyFromModal(messageNdx, userActions = null, target = 'docs', applyOptions = null) {
    const result = await applyMessage(messageNdx, userActions, applyOptions);
    if (result?.success) {
      const finalized = applyOptions?.targetDocState === 40;
      finishApply(messageNdx, result.data?.savedDocId ?? 0, target, finalized);
    } else {
      alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
    }
  }

  function handleRejectFromModal(messageNdx) {
    // Batch mód (D7): prompt se otevře nad preview modalem (modal stack),
    // previewNdx zůstává — zrušení promptu vrací na tutéž zprávu.
    if (!queue) previewNdx = null;
    rejectNdx = messageNdx;
  }

  // ── Reject prompt ────────────────────────────────────────────────────────────

  async function submitRejectFlow(reason) {
    const ndx = rejectNdx;
    if (!ndx || rejectSubmitting) return;
    rejectSubmitting = true;
    try {
      const result = await rejectMessage(ndx, reason);
      if (result?.success) {
        rejectNdx = null;
        dropCardByMessage(ndx);
        if (queue) {
          // Batch mód: bez load() (P4), jen počítadlo + posun dál.
          queue.counts.rejected += 1;
          advanceQueue();
        } else {
          load();
        }
      } else {
        alert(t('dashboard.card.actionFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      rejectSubmitting = false;
    }
  }

  // ── Sériový průchod frontou (Issue #32/1) ──────────────────────────────────

  // Start průchodu: snapshot fronty (D1) — chronologicky od nejstarší dle
  // timestamp (ATOM řetězce se řadí lexikograficky; null na konec, mezi
  // sebou v pořadí feedu — sort je stabilní). Existují-li ve feedu karty
  // „Nová kategorie", předřadí se předkrok (D8); snapshot zpráv se ale bere
  // už teď — materializace štítků ho nemění.
  // kindFilter (Issue #32/2, D9): \u201eProj\u00edt" z ready pruhu omez\u00ed snapshot na
  // p\u00e1smo ready. Stejn\u00fd filtr projde i tagCards p\u0159edkroku \u2014 content_tag
  // karty jsou review, tak\u017ee p\u0159edkrok u ready pr\u016fchodu dostane pr\u00e1zdn\u00fd
  // seznam a p\u0159irozen\u011b se neuk\u00e1\u017ee (logika se neobch\u00e1z\u00ed).
  function startQueue(kindFilter = null) {
    const source = kindFilter
      ? queueableCards.filter((c) => c.kind === kindFilter)
      : queueableCards;
    const list = [...source]
      .sort((a, b) => (a.timestamp ?? '\uffff').localeCompare(b.timestamp ?? '\uffff'))
      .map((c) => c.context.messageNdx);
    if (list.length === 0) return;
    const tagCards = (data?.cards ?? []).filter(
      (c) => c.id?.startsWith('content_tag:') && (!kindFilter || c.kind === kindFilter),
    );
    if (tagCards.length > 0) {
      queuePrecheck = { cards: tagCards, list };
    } else {
      openQueueAt(list, 0);
    }
  }

  function openQueueAt(list, index) {
    queue = { list, index, counts: { closed: 0, draft: 0, rejected: 0, skipped: 0 } };
    previewNdx = list[index];
  }

  // Posun na další zprávu — previewNdx se mezi položkami nenuluje (P1),
  // modal zůstává otevřený a jeho $effect zajistí reload + reset userActions.
  function advanceQueue() {
    if (queue.index + 1 === queue.list.length) {
      finishQueue();
      return;
    }
    queue.index += 1;
    previewNdx = queue.list[queue.index];
  }

  // Konec průchodu (doběhnutí i předčasné zavření modalu): jeden souhrnný
  // toast za zpracované položky (D2; bez akce Otevřít — kind !== 'applied')
  // a jeden load(). Při nule zpracovaných (okamžité zavření) žádný toast.
  function finishQueue() {
    const message = queueSummaryText(queue.counts);
    previewNdx = null;
    queue = null;
    if (message !== '') {
      showToast({ kind: 'queueSummary', message });
    }
    load();
  }

  // Souhrn „Uzavřeno X · Konceptů Y · …" — nulové části se vynechávají.
  function queueSummaryText(counts) {
    const parts = [];
    if (counts.closed > 0) parts.push(t('dashboard.toast.queueClosed', { n: counts.closed }));
    if (counts.draft > 0) parts.push(t('dashboard.toast.queueDraft', { n: counts.draft }));
    if (counts.rejected > 0) parts.push(t('dashboard.toast.queueRejected', { n: counts.rejected }));
    if (counts.skipped > 0) parts.push(t('dashboard.toast.queueSkipped', { n: counts.skipped }));
    return parts.join(' · ');
  }

  // Přeskočit (D4) — posun bez verdiktu, karta zůstává ve feedu.
  function handleQueueSkip() {
    queue.counts.skipped += 1;
    advanceQueue();
  }

  // Zavření preview modalu: v batch módu = konec průchodu se souhrnem
  // za dosud zpracované; jinak běžné zavření.
  function handlePreviewClose() {
    if (queue) {
      finishQueue();
    } else {
      previewNdx = null;
    }
  }

  async function handlePrecheckContinue() {
    const list = queuePrecheck.list;
    queuePrecheck = null;
    // Nechat modal předkroku odregistrovat z modal stacku, než se otevře
    // review modal — jinak by dostal depth 1 a s ním nested shrink
    // (30 px/strana), tj. byl by menší než při otevření ze „Zkontrolovat".
    await tick();
    openQueueAt(list, 0);
  }

  // ── Ruční nahrání ────────────────────────────────────────────────────────────

  function closeUploadModal() {
    uploadModal = { open: false, files: [] };
  }

  function handleUploaded(count) {
    closeUploadModal();
    showToast({ kind: 'uploaded', message: t('dashboard.toast.uploaded', { count }) });
    load();
  }

  // Drag textu apod. overlay nespouští — reagujeme jen na soubory.
  function dragHasFiles(event) {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
  }

  function handleDashboardDragOver(event) {
    if (!caps.mailUpload || uploadModal.open || !dragHasFiles(event)) return;
    event.preventDefault();
    dragActive = true;
  }

  function handleDashboardDragLeave(event) {
    // Ignoruj přechody mezi dětmi plochy — jen skutečné opuštění (vč. okna).
    if (!(event.relatedTarget instanceof Node) || !event.currentTarget.contains(event.relatedTarget)) {
      dragActive = false;
    }
  }

  function handleDashboardDrop(event) {
    if (!caps.mailUpload || uploadModal.open || !dragHasFiles(event)) return;
    event.preventDefault();
    dragActive = false;
    const dropped = Array.from(event.dataTransfer?.files ?? []);
    if (dropped.length > 0) uploadModal = { open: true, files: dropped };
  }

  function handleFormSaved() {
    formModal.wasSaved = true;
  }

  function handleFormClose() {
    const shouldRefetch = formModal.wasSaved;
    formModal = { open: false, table: '', recordId: null, wasSaved: false };
    if (shouldRefetch) load();
  }

  onMount(load);
</script>

<!-- svelte-ignore a11y_no_static_element_interactions -->
<div
  class="shpd-dashboard"
  data-testid="dashboard"
  ondragover={handleDashboardDragOver}
  ondragleave={handleDashboardDragLeave}
  ondrop={handleDashboardDrop}
>
  <header class="shpd-dashboard__header">
    <h1 class="shpd-dashboard__title">{t('dashboard.title')}</h1>
    <div class="shpd-dashboard__actions">
      {#if caps.mailUpload}
        <Button
          variant="ghost"
          size="sm"
          icon={iconUpload}
          label={t('dashboard.upload.button')}
          onclick={() => (uploadModal = { open: true, files: [] })}
        />
      {/if}
      <Button
        variant="ghost"
        size="sm"
        icon={iconRefresh}
        label={t('dashboard.refresh')}
        onclick={load}
        disabled={loading}
      />
    </div>
  </header>

  {#if dragActive}
    <div class="shpd-dashboard__drop-overlay" aria-hidden="true">
      <span class="shpd-dashboard__drop-overlay-text">{t('dashboard.upload.dropOverlay')}</span>
    </div>
  {/if}

  {#if loading && !data}
    <div class="shpd-dashboard__loading">{t('common.loading')}</div>
  {:else if error && !data}
    <div class="shpd-dashboard__error">{error}</div>
  {:else if data}
    <AiSummaryCard summary={data.summary} />

    {#if (data.cards?.length ?? 0) > 0}
      <div class="shpd-dashboard__feed-toolbar">
        <div class="shpd-dashboard__feed-filter">
          <FeedFilter
            value={feedFilter}
            counts={feedCounts}
            urgent={feedUrgent}
            onChange={(v) => (feedFilter = v)}
          />
        </div>
        <!-- Projít frontu (D3) — jen na záložkách Vše a Faktury a jen když
             je co procházet; na Spisovna/Ostatní by bylo zavádějící
             (registry je mimo scope průchodu). -->
        {#if (feedFilter === 'all' || feedFilter === 'invoices') && queueableCards.length > 0}
          <div class="shpd-dashboard__feed-queue">
            <Button
              variant="primary"
              size="sm"
              label={t('dashboard.queue.button', { n: queueableCards.length })}
              onclick={() => startQueue()}
            />
          </div>
        {/if}
      </div>
    {/if}

    <Feed
      cards={filteredCards}
      readySummary={data.readySummary ?? null}
      {busyCardId}
      onCardAction={handleCardAction}
      onWalkthrough={() => startQueue('ready')}
      emptyText={feedFilter !== 'all' && (data.cards?.length ?? 0) > 0
        ? t('dashboard.feed.emptyCategory')
        : null}
    />
  {/if}

  {#if caps.chat}
    <ChatLauncher />
  {/if}
</div>

<DocumentExchangePreviewModal
  open={previewNdx !== null}
  messageNdx={previewNdx}
  queue={queue ? { index: queue.index, total: queue.list.length } : null}
  onClose={handlePreviewClose}
  onApply={handleApplyFromModal}
  onReject={handleRejectFromModal}
  onSkip={handleQueueSkip}
/>

<QueueCategoriesPrompt
  open={queuePrecheck !== null}
  cards={queuePrecheck?.cards ?? []}
  onMaterialized={dropCardById}
  onContinue={handlePrecheckContinue}
  onClose={() => (queuePrecheck = null)}
/>

{#if caps.mailUpload}
  <MailUploadModal
    open={uploadModal.open}
    initialFiles={uploadModal.files}
    onClose={closeUploadModal}
    onUploaded={handleUploaded}
  />
{/if}

<RejectReasonPrompt
  open={rejectNdx !== null}
  submitting={rejectSubmitting}
  title={t('dashboard.reject.title')}
  reasonLabel={t('dashboard.reject.label')}
  placeholder={t('dashboard.reject.placeholder')}
  confirmLabel={t('dashboard.reject.confirm')}
  onConfirm={submitRejectFlow}
  onClose={() => (rejectNdx = null)}
/>

{#if formModal.open}
  <FormDialog
    table={formModal.table}
    recordId={formModal.recordId}
    open={formModal.open}
    onSaved={handleFormSaved}
    onClose={handleFormClose}
  />
{/if}

<ViewerDetailModal
  open={detailModal.open}
  viewerId={detailModal.viewerId}
  recordId={detailModal.recordId}
  tabId={detailModal.tabId}
  onClose={() => (detailModal = { open: false, viewerId: '', recordId: null, tabId: null })}
/>

{#if toast.visible}
  <div class="shpd-toast" role="status" data-testid="toast">
    <span class="shpd-toast__msg">{toast.message}</span>
    {#if toast.kind === 'applied'}
      <button type="button" class="shpd-toast__action" data-testid="toast-open" onclick={openCreatedDoc}>{t('dashboard.toast.open')}</button>
    {/if}
    <button type="button" class="shpd-toast__close" onclick={dismissToast} aria-label={t('common.cancel')}>×</button>
  </div>
{/if}

<style>
  .shpd-dashboard {
    padding: var(--shpd-space-lg);
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
    /* min-height + margin-top:auto na launcheru drží ChatLauncher u spodní
       hrany viewportu i při krátkém obsahu; při dlouhém obsahu ho
       position:sticky nechá „plavat" nad kartami během scrollu. */
    min-height: 100%;
    box-sizing: border-box;
    /* Kotva pro drop overlay ručního nahrání */
    position: relative;
  }

  .shpd-dashboard__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .shpd-dashboard__actions {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
  }

  /* Poloprůhledný overlay během dragu souborů nad plochou dashboardu.
     pointer-events: none — dragleave/drop dál cílí na .shpd-dashboard. */
  .shpd-dashboard__drop-overlay {
    position: absolute;
    inset: 0;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    background: color-mix(in srgb, var(--shpd-color-primary) 8%, transparent);
    outline: 2px dashed var(--shpd-color-primary);
    outline-offset: -8px;
    border-radius: var(--shpd-radius-md);
  }

  .shpd-dashboard__drop-overlay-text {
    padding: var(--shpd-space-sm) var(--shpd-space-lg);
    background: var(--shpd-color-bg);
    border: 1px solid var(--shpd-color-primary);
    border-radius: var(--shpd-radius-md);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-md, 1rem);
    box-shadow: var(--shpd-shadow-lg, 0 4px 16px rgba(0, 0, 0, 0.25));
  }

  .shpd-dashboard__title {
    margin: 0;
    font-size: var(--shpd-font-size-xl);
    color: var(--shpd-color-text);
  }

  /* Řádek filtr + Projít frontu. Filtr dostává flex:1 + min-width:0, aby
     jeho vnitřní overflow-x fungoval a tlačítko se nezmenšovalo. */
  .shpd-dashboard__feed-toolbar {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
  }

  .shpd-dashboard__feed-filter {
    flex: 1;
    min-width: 0;
  }

  .shpd-dashboard__feed-queue {
    flex-shrink: 0;
  }

  .shpd-dashboard__loading,
  .shpd-dashboard__error {
    padding: var(--shpd-space-xl);
    text-align: center;
    color: var(--shpd-color-text-secondary);
  }

  /* Minimální toast — fixed dole na střed, auto-dismiss ~8 s.
     Bottom offset ~72px (výška launcheru + mezera) — vyskakuje nad
     ChatLauncherem, nepřekrývají se. */
  .shpd-toast {
    position: fixed;
    left: 50%;
    bottom: calc(var(--shpd-space-lg) + 72px);
    transform: translateX(-50%);
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    max-width: min(90vw, 560px);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background: var(--shpd-color-text);
    color: var(--shpd-color-bg);
    border-radius: var(--shpd-radius-md);
    box-shadow: var(--shpd-shadow-lg, 0 4px 16px rgba(0, 0, 0, 0.25));
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-toast__msg {
    flex: 1;
    min-width: 0;
  }

  .shpd-toast__action {
    flex-shrink: 0;
    border: none;
    background: none;
    color: var(--shpd-color-bg);
    font: inherit;
    font-weight: 600;
    text-decoration: underline;
    cursor: pointer;
    padding: 0 var(--shpd-space-xs);
  }

  .shpd-toast__close {
    flex-shrink: 0;
    border: none;
    background: none;
    color: var(--shpd-color-bg);
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 var(--shpd-space-xs);
    opacity: 0.7;
  }

  .shpd-toast__close:hover {
    opacity: 1;
  }
</style>
