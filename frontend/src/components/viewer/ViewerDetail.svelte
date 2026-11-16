<script>
  import { applyMessage, rejectMessage } from '../../api/exchange.js';
  import Button from '../ui/Button.svelte';
  import Popover from '../ui/Popover.svelte';
  import DocumentExchangePreviewModal from '../exchange/DocumentExchangePreviewModal.svelte';
  import RejectReasonPrompt from '../dashboard/RejectReasonPrompt.svelte';
  import AttachmentGrid from './AttachmentGrid.svelte';
  import FailureCard from './FailureCard.svelte';
  import Card from '../ui/Card.svelte';
  import { attachmentViewStore } from '../../stores/attachmentView.svelte.js';
  import DocumentDetail from './DocumentDetail.svelte';
  import DetailAttachments from './DetailAttachments.svelte';
  import { contentHasAttachments } from './detailContent.js';
  import DetailHeader from '../ui/DetailHeader.svelte';
  import SandboxedHtml from '../ui/SandboxedHtml.svelte';
  import DocumentLockBanner from '../ui/DocumentLockBanner.svelte';
  import SectionHeading from '../ui/SectionHeading.svelte';
  import SpanBadge from './SpanBadge.svelte';
  import { resolveIcon } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';
  import ContentBlock from '../content/ContentBlock.svelte';

  // hideSingleTabBar: opt-in pro hostitele s jediným tabem (ViewerDetailModal) —
  // skryje tab lištu, když není mezi čím přepínat. Default false, aby se
  // nezměnil vzhled stávajících hostitelů (inline panel, drawer).
  // busyActionId: id akce detailu, která právě běží — její tlačítko je
  // zablokované (dvojklik na Vystavit opravný doklad by založil dva
  // koncepty, #92 task B U8). Nastavuje hostitel (Viewer.svelte).
  let { detail = null, loading = false, onRefresh, onAction = null, hideSingleTabBar = false, busyActionId = null } = $props();

  // Otevřený dropdown — { actionId, anchor }. Per-action, takže různá tlačítka
  // se navzájem zavírají (otevření nového dropdownu shodí předchozí).
  let dropdownOpen = $state(null);

  function openDropdown(action, event) {
    dropdownOpen = { actionId: action.id, anchor: event.currentTarget };
  }

  function closeDropdown() {
    dropdownOpen = null;
  }

  function handleAction(action, value = null) {
    // Confirm se aplikuje jen na single-shot tlačítka (button / open_form /
    // open_viewer). Pro dropdown je výběr položky sám o sobě explicitní akcí,
    // další confirm by byl zbytečný a otravný (viz task §6).
    if (action.kind !== 'dropdown' && action.confirm && !window.confirm(action.confirm)) return;
    onAction?.(action.id, action, value);
  }

  function handleDropdownPick(action, item) {
    closeDropdown();
    handleAction(action, item.value);
  }

  // Zpráva ze sekce Odeslaná pošta → formulář zprávy (Odeslat znovu,
  // Archivovat, Smazat) přes generickou akci `open_form` hostitele.
  function openSentMessage(messageId) {
    onAction?.('openSentMessage', {
      id: 'openSentMessage',
      kind: 'open_form',
      target: { table: 'core_mail_sent_messages', mode: 'edit', id: messageId },
    });
  }

  let activeTabId = $state(null);

  // Auto-select first tab when detail changes
  $effect(() => {
    if (detail?.tabs?.length > 0) {
      activeTabId = detail.tabs[0].id;
    } else {
      activeTabId = null;
    }
  });

  let activeContent = $derived(
    detail?.tabs?.find(t => t.id === activeTabId)?.content ?? null
  );

  // Sdílená sekce příloh (#136 D21) a Odeslaná pošta (D22) se kreslí jen
  // pod prvním tabem (Přehled); detail bez tabů je nemá kam jinam dát.
  let isOverviewTab = $derived(
    !(detail?.tabs?.length > 0) || activeTabId === detail.tabs[0].id
  );

  // Viewer, který přílohy deklaruje ve vlastním tabu (obsah typu
  // `attachments`, i jako blok composite), je v Přehledu nedostane podruhé.
  let hasAttachmentsTab = $derived(
    (detail?.tabs ?? []).some(tab => contentHasAttachments(tab.content))
  );

  let overviewAttachmentGroups = $derived(
    hasAttachmentsTab ? [] : (detail?.attachments?.groups ?? [])
  );

  // --- Návrh (message-centric) — Exchange preview modal ---
  // Visual split-view (PDF + canonical); drží messageNdx zprávy s návrhem.
  let previewModalNdx = $state(null);

  // --- Návrh — reject dialog state ---
  // Reason drží sdílená RejectReasonPrompt komponenta; tady jen messageNdx.
  let rejectDialogNdx = $state(null);
  let rejectSubmitting = $state(false);

  // Právě běžící apply — disabluje tlačítka karty návrhu.
  let actionInFlightNdx = $state(null);

  function openRejectDialog(messageNdx) {
    rejectDialogNdx = messageNdx;
  }

  function closeRejectDialog() {
    rejectDialogNdx = null;
  }

  async function submitReject(reason) {
    if (!rejectDialogNdx || rejectSubmitting) return;
    rejectSubmitting = true;
    try {
      const result = await rejectMessage(rejectDialogNdx, reason);
      if (result?.success) {
        closeRejectDialog();
        onRefresh?.();
      } else {
        alert(t('viewer.detail.rejectFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      rejectSubmitting = false;
    }
  }

  function openPreviewModal(messageNdx) {
    previewModalNdx = messageNdx;
  }

  function closePreviewModal() {
    previewModalNdx = null;
  }

  // Apply návrhu — safe mode, server doplní rozhodnutí uložená v review
  // modalu (#87 B D17); 422 unresolved_required nebo validation_failed
  // (např. kód DPH bez volby) z přímého apply (tlačítko Použít na kartě)
  // → fall-through do review modalu, kde uživatel reference / volby dořeší.
  async function applyProposal(messageNdx) {
    if (actionInFlightNdx !== null) return;
    actionInFlightNdx = messageNdx;
    try {
      const result = await applyMessage(messageNdx, null);
      if (result?.success) {
        onRefresh?.();
        const savedDocId = result.data?.savedDocId ?? 0;
        if (savedDocId) {
          onAction?.('openCreatedDoc', {
            id: 'openCreatedDoc',
            kind: 'open_form',
            target: { table: 'docs_core_heads', mode: 'edit', id: savedDocId },
          });
        }
      } else if (result?.error?.code === 'unresolved_required' || result?.error?.code === 'validation_failed') {
        openPreviewModal(messageNdx);
      } else {
        alert(t('viewer.detail.applyFailed', { msg: translateError(result?.error) }));
      }
    } finally {
      actionInFlightNdx = null;
    }
  }

  // Apply from modal. `userActions` (flat {path: action} map) drives backend
  // strict-mode resolution. On success for the docs target the created
  // Koncept opens right away in the Viewer's FormDialog via the generic
  // `open_form` detail-action branch (same pattern as fileToRegistry).
  // „Vystavit a uzavřít“ (applyOptions {targetDocState: 40}) skips the
  // FormDialog — the document is final, just close + refresh (D5; the
  // viewer has no toast infra).
  async function handleApplyFromModal(messageNdx, userActions = null, target = 'docs', applyOptions = null) {
    if (actionInFlightNdx !== null) return;
    actionInFlightNdx = messageNdx;
    try {
      const result = await applyMessage(messageNdx, userActions, applyOptions);
      if (result?.success) {
        const savedDocId = result.data?.savedDocId ?? 0;
        const finalized = applyOptions?.targetDocState === 40;
        closePreviewModal();
        onRefresh?.();
        if (!finalized && target !== 'registry' && savedDocId) {
          onAction?.('openCreatedDoc', {
            id: 'openCreatedDoc',
            kind: 'open_form',
            target: { table: 'docs_core_heads', mode: 'edit', id: savedDocId },
          });
        }
      } else {
        // unresolved_required after a successful preview means DB state
        // shifted between preview and apply (rare race). Keep the modal
        // open so the user sees the current state.
        if (result?.error?.code === 'unresolved_required') {
          alert(t('exchange.preview.apply.error.unresolved'));
        } else {
          alert(t('viewer.detail.applyFailed', { msg: translateError(result?.error) }));
        }
      }
    } finally {
      actionInFlightNdx = null;
    }
  }

  // Reject from modal — closes the preview modal first so the reject
  // dialog isn't obscured underneath.
  function handleRejectFromModal(messageNdx) {
    closePreviewModal();
    openRejectDialog(messageNdx);
  }
</script>

<div class="shpd-detail">
  {#if loading}
    <div class="shpd-detail__loading">
      <span class="shpd-detail__spinner"></span>
      <span>{t('common.loading')}</span>
    </div>
  {:else if detail?.tabs?.length > 0}
    {#snippet renderAction(action)}
      {#if action.kind === 'dropdown'}
        <button
          type="button"
          class="shpd-detail__action-dropdown"
          class:shpd-detail__action-dropdown--primary={(action.variant ?? 'secondary') === 'primary'}
          class:shpd-detail__action-dropdown--danger={action.variant === 'danger'}
          class:shpd-detail__action-dropdown--success={action.variant === 'success'}
          onclick={(e) => openDropdown(action, e)}
        >
          {action.label} <span class="shpd-detail__action-caret">▾</span>
        </button>
      {:else}
        <Button
          label={action.label}
          variant={action.variant ?? 'secondary'}
          size="sm"
          disabled={busyActionId === action.id}
          onclick={() => handleAction(action)}
        />
      {/if}
    {/snippet}

    {#if (detail?.actions ?? []).length > 0}
      <div class="shpd-detail__actions">
        {#each detail.actions as action (action.id)}
          {@render renderAction(action)}
        {/each}
      </div>
    {/if}

    {#if detail.title}
      <!-- Hlavička (titulek, štítky, podtitul) = sdílená DetailHeader
           (#139 D48) — zobrazuje se jen pokud backend posílá detail.title.
           Bez něj se hlavička přeskočí a layout je stejný jako předtím. -->
      <DetailHeader
        title={detail.title}
        subtitle={detail.subtitle ?? ''}
        badges={detail.badges ?? []}
        icon={detail.icon ? resolveIcon(detail.icon) : undefined}
      />
    {/if}

    <!-- Zámek záznamu (documentLockProviders, #55 D24) — server ho
         posílá v detail.lock; toolbar Otevřít už vyřadil. -->
    <DocumentLockBanner lock={detail.lock ?? null} />

    <!-- Tab bar -->
    {#if !(hideSingleTabBar && detail.tabs.length <= 1)}
      <div class="shpd-detail__tabs">
        {#each detail.tabs as tab (tab.id)}
          <button
            class="shpd-detail__tab"
            class:shpd-detail__tab--active={activeTabId === tab.id}
            onclick={() => activeTabId = tab.id}
            type="button"
          >
            {tab.label}
          </button>
        {/each}
      </div>
    {/if}

    <!-- Jednoduché bloky obsahu kreslí sdílená `ContentBlock` (#150 D83),
         včetně `composite`; bloky vázané na viewer (stav, akce, přílohy
         detailu) jí hostitel dodává tímto snippetem — volá ho pro každý
         typ, který sama nezná, i uvnitř `composite`. -->
    {#snippet viewerBlock(content)}
      {#if content?.type === 'untrusted-html'}
        <SandboxedHtml html={content.html} title={t('viewer.detail.mailBody')} />

      {:else if content?.type === 'failure'}
        <!-- Lidská hláška selhání mimo tab Návrh — předzpracování v tabu Obsah
             (tasks/mail-preprocess-error-messages.md D3a): varianta ze serveru
             (warning = stav „Hotovo s chybami", info = selhaný import ISDOC). -->
        <FailureCard failure={content.failure} variant={content.failure?.variant ?? 'error'} />

      {:else if content?.type === 'proposal'}
        <!-- Dokumentový návrh poslední analýzy (message-centric) — jedna
             karta, nebo prázdný stav s klasifikací zprávy. Při stavu
             „Analýza selhala" posílá server content.failure — karta
             selhání nahrazuje prázdný stav a stojí nad případným starším
             návrhem; návrh s nevalidním výstupem nese vlastní doc.failure.
             content.preprocessWarning (předzpracování ve stavu „Hotovo
             s chybami", D3b) stojí úplně nahoře — návrh i klasifikace vznikly
             bez dokumentu, který mělo předzpracování vytvořit. -->
        <div class="shpd-extracted">
          {#if content.preprocessWarning}
            <FailureCard
              failure={{
                kind: content.preprocessWarning.kind,
                title: content.preprocessWarning.title,
                description: content.preprocessWarning.text,
              }}
              variant="warning"
            />
          {/if}
          {#if content.failure}
            <FailureCard failure={content.failure} />
          {/if}
          {#if !content.proposal}
            {#if !content.failure}
              <div class="shpd-extracted__empty">
                {t('viewer.detail.noProposal', {
                  type: content.classification?.primary_type_label ?? '',
                })}
              </div>
            {/if}
          {:else}
            {@const doc = content.proposal}
            <!-- Karta návrhu = sdílená Card (#137 D33). -->
            <Card as="div">
              <div class="shpd-extracted__header">
                <span class="shpd-extracted__type">{doc.proposed_type_label}</span>
                {#if doc.ai_failed}
                  <span class="shpd-extracted__badge shpd-extracted__badge--error">
                    {t('viewer.detail.proposalFailed')}
                  </span>
                {:else if doc.resolution !== null}
                  <span class="shpd-extracted__badge shpd-extracted__badge--{doc.resolution_style}">
                    {doc.resolution_label}
                  </span>
                {:else if doc.band}
                  <span class="shpd-extracted__badge shpd-extracted__badge--{doc.band === 'ready' ? 'done' : doc.band === 'low' ? 'edit' : 'confirmed'}">
                    {t(`viewer.detail.band.${doc.band}`)}
                  </span>
                {/if}
                {#if doc.confidence !== null}
                  <span class="shpd-extracted__confidence">{(doc.confidence * 100).toFixed(0)}%</span>
                {/if}
              </div>

              {#if doc.ai_failed && doc.failure}
                <div class="shpd-extracted__failure">
                  <FailureCard failure={doc.failure} />
                </div>
              {/if}

              {#if doc.summary}
                <div class="shpd-extracted__summary">{doc.summary}</div>
              {/if}

              {#if (doc.secondary_findings ?? []).length > 0}
                <div class="shpd-extracted__meta">
                  {#each doc.secondary_findings as finding}
                    <div>+ {finding.type_label}{finding.note ? ` — ${finding.note}` : ''}</div>
                  {/each}
                </div>
              {/if}

              {#if doc.resolution !== null && doc.resolved_at}
                <div class="shpd-extracted__meta">{t('viewer.detail.resolvedAt', { date: doc.resolved_at })}</div>
              {/if}
              {#if doc.rejected_reason}
                <div class="shpd-extracted__meta">{t('viewer.detail.rejected', { reason: doc.rejected_reason })}</div>
              {/if}

              <div class="shpd-extracted__actions">
                {#if doc.can_apply}
                  <Button
                    label={t('viewer.detail.apply')}
                    variant="success"
                    size="sm"
                    disabled={actionInFlightNdx === doc.messageNdx}
                    onclick={() => applyProposal(doc.messageNdx)}
                  />
                {/if}
                {#if !doc.ai_failed}
                  <Button
                    label={t('viewer.detail.showDetail')}
                    variant="secondary"
                    size="sm"
                    onclick={() => openPreviewModal(doc.messageNdx)}
                  />
                {/if}
                {#if doc.can_reject}
                  <Button
                    label={t('viewer.detail.reject')}
                    variant="danger"
                    size="sm"
                    disabled={actionInFlightNdx === doc.messageNdx}
                    onclick={() => openRejectDialog(doc.messageNdx)}
                  />
                {/if}
              </div>
            </Card>
          {/if}
        </div>

      {:else if content?.type === 'attachment-grid'}
        <!-- Plochý grid příloh (bez per-message skupin) - blok composite
             obsahu, např. sekce Přílohy v tabu Obsah došlé pošty. -->
        <div class="shpd-detail__att-toolbar">
          <button
            type="button"
            class="shpd-detail__att-toggle"
            onclick={() => attachmentViewStore.toggle()}
          >
            {attachmentViewStore.mode === 'full'
              ? t('viewer.attachments.viewGrid')
              : t('viewer.attachments.viewFull')}
          </button>
        </div>
        <AttachmentGrid attachments={content.attachments ?? []} mode={attachmentViewStore.mode} />

      {:else if content?.type === 'attachments'}
        <!-- Vlastní tab Přílohy (#136 D21) — sdílená sekce příloh nad
             detail.attachments.groups; v Přehledu se pak nekreslí. -->
        <DetailAttachments groups={detail?.attachments?.groups ?? []} onAction={onAction} />

      {:else if content?.type === 'document'}
        <DocumentDetail content={content} onAction={onAction} />
      {/if}
    {/snippet}

    <!-- Tab content -->
    <div class="shpd-detail__content">
      <ContentBlock block={activeContent} onAction={onAction ? handleAction : null} other={viewerBlock} />

      {#if isOverviewTab}
        <!-- Přílohy záznamu (#136 D21) — sdílená sekce na konci Přehledu
             (roluje s obsahem), pokud je viewer nedal do vlastního tabu. -->
        {#if overviewAttachmentGroups.length > 0}
          <div class="shpd-detail__attachments">
            <DetailAttachments groups={overviewAttachmentGroups} onAction={onAction} />
          </div>
        {/if}

        <!-- Odeslaná pošta u záznamu (#90 D45) — zprávy, které na záznam
             ukazují; jen na konci Přehledu, za přílohami (#136 D22). Server
             je posílá u libovolné tabulky. -->
        {#if (detail.sentMessages ?? []).length > 0}
          <section class="shpd-detail__sent" data-testid="detail-sent-messages">
            <SectionHeading level={4}>{t('sentMessage.sectionTitle')}</SectionHeading>
            {#each detail.sentMessages as message (message.id)}
              <div class="shpd-detail__sent-message">
                <button
                  type="button"
                  class="shpd-detail__sent-head"
                  title={t('sentMessage.open')}
                  onclick={() => openSentMessage(message.id)}
                >
                  <span class="shpd-detail__sent-date">{message.createdAt}</span>
                  <span class="shpd-detail__sent-to">{(message.to ?? []).length > 0 ? message.to.join(', ') : '—'}</span>
                  <SpanBadge style={message.transport?.stateStyle ?? 'neutral'} text={message.transport?.stateLabel ?? ''} />
                  {#if message.transport?.safety}
                    <SpanBadge style={message.transport.safety.style} text={message.transport.safety.label} />
                  {/if}
                </button>
                {#if (message.attachments ?? []).length > 0}
                  <AttachmentGrid attachments={message.attachments} />
                {/if}
              </div>
            {/each}
          </section>
        {/if}
      {/if}
    </div>

    <!-- Dropdown menu pro detail.actions kind=dropdown — Popover anchorovaný
         na trigger button. Otvírání řídí `dropdownOpen`. -->
    {#if dropdownOpen !== null}
      {@const openAction = (detail?.actions ?? []).find(a => a.id === dropdownOpen.actionId)}
      {#if openAction}
        <Popover
          open={true}
          anchor={dropdownOpen.anchor}
          placement="bottom"
          onClose={closeDropdown}
        >
          <div class="shpd-detail__action-menu">
            {#each openAction.items ?? [] as item}
              <button
                type="button"
                class="shpd-detail__action-menu-item"
                onclick={() => handleDropdownPick(openAction, item)}
              >
                {item.label}
              </button>
            {/each}
          </div>
        </Popover>
      {/if}
    {/if}

    <!-- Full-screen Exchange preview (PDF + canonical split). -->
    <DocumentExchangePreviewModal
      open={previewModalNdx !== null}
      messageNdx={previewModalNdx}
      onClose={closePreviewModal}
      onApply={handleApplyFromModal}
      onReject={handleRejectFromModal}
    />

    <!-- Reject reason dialog — sdílená komponenta (Feed i ViewerDetail). -->
    <RejectReasonPrompt
      open={rejectDialogNdx !== null}
      submitting={rejectSubmitting}
      title={t('viewer.detail.rejectTitle')}
      reasonLabel={t('viewer.detail.rejectReasonLabel')}
      placeholder={t('viewer.detail.rejectReasonPlaceholder')}
      confirmLabel={t('viewer.detail.reject')}
      onConfirm={submitReject}
      onClose={closeRejectDialog}
    />
  {:else}
    <div class="shpd-detail__empty">
      {t('viewer.detail.empty')}
    </div>
  {/if}
</div>

<style>
  .shpd-detail {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }

  .shpd-detail__loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xl);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-meta);
  }

  .shpd-detail__spinner {
    display: inline-block;
    width: 1.125rem;
    height: 1.125rem;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-detail-spin 0.7s linear infinite;
  }

  @keyframes shpd-detail-spin {
    to { transform: rotate(360deg); }
  }

  /* Actions bar — řádek per-record akcí nad taby (snooze/dismiss/recheck +
     custom akce alertů). Stejná vizuální logika jako header: padding sm/md,
     border-bottom, wrap při velkém počtu tlačítek. */
  /* Sekce Přílohy (#136 D21) a Odeslaná pošta (D22) na konci Přehledu —
     uvnitř rolovací plochy, obě stejně oddělené od obsahu nad sebou. Čáru
     a odsazení dává hostitel, ne DetailAttachments — ve vlastním tabu
     Přílohy by čára nahoře neměla co oddělovat. */
  .shpd-detail__attachments,
  .shpd-detail__sent {
    margin-top: var(--shpd-space-lg);
    padding-top: var(--shpd-space-md);
    border-top: 1px solid var(--shpd-color-border);
  }

  .shpd-detail__sent-message + .shpd-detail__sent-message {
    margin-top: var(--shpd-space-md);
  }

  .shpd-detail__sent-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--shpd-space-sm);
    width: 100%;
    margin-bottom: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) 0;
    border: 0;
    background: none;
    font: inherit;
    font-size: var(--shpd-font-size-body);
    color: var(--shpd-color-text);
    text-align: left;
    cursor: pointer;
  }

  .shpd-detail__sent-head:hover .shpd-detail__sent-to {
    text-decoration: underline;
  }

  .shpd-detail__sent-date {
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-detail__sent-to {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .shpd-detail__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
  }

  /* Dropdown trigger button — vizuálně stejný jako Button variant="secondary",
     ale nativní <button> protože potřebujeme `event.currentTarget` jako anchor
     pro Popover. */
  .shpd-detail__action-dropdown {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: var(--shpd-space-xs) 0.625rem;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    line-height: 1.4;
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    cursor: pointer;
    transition: background-color 0.15s, border-color 0.15s;
  }

  .shpd-detail__action-dropdown:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  .shpd-detail__action-dropdown--primary {
    background-color: var(--shpd-color-primary);
    color: var(--shpd-color-text-on-fill);
    border-color: var(--shpd-color-primary);
  }
  .shpd-detail__action-dropdown--primary:hover {
    background-color: var(--shpd-color-primary-hover, var(--shpd-color-primary));
  }

  .shpd-detail__action-dropdown--danger {
    background-color: var(--shpd-color-state-cancelled-bg);
    color: var(--shpd-color-state-cancelled-text);
    border-color: var(--shpd-color-state-cancelled-bg);
  }

  .shpd-detail__action-dropdown--success {
    background-color: var(--shpd-color-state-done-bg);
    color: var(--shpd-color-state-done-text);
    border-color: var(--shpd-color-state-done-bg);
  }

  .shpd-detail__action-caret {
    font-size: 0.7em;
    opacity: 0.7;
  }

  /* Dropdown menu — položky v Popoveru. */
  .shpd-detail__action-menu {
    display: flex;
    flex-direction: column;
    min-width: 8.75rem;
    padding: var(--shpd-space-xs) 0;
  }

  .shpd-detail__action-menu-item {
    text-align: left;
    padding: 0.375rem 0.875rem;
    border: none;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    cursor: pointer;
  }

  .shpd-detail__action-menu-item:hover {
    background-color: var(--shpd-color-bg-hover);
  }

  /* Tabs */
  .shpd-detail__tabs {
    display: flex;
    border-bottom: 1px solid var(--shpd-color-border);
    flex-shrink: 0;
  }

  .shpd-detail__tab {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border: none;
    border-bottom: 2px solid transparent;
    background: none;
    font-family: inherit;
    font-size: var(--shpd-font-size-body);
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
    transition: color 0.15s, border-color 0.15s;
  }

  .shpd-detail__tab:hover {
    color: var(--shpd-color-text);
  }

  .shpd-detail__tab--active {
    color: var(--shpd-color-primary);
    border-bottom-color: var(--shpd-color-primary);
    font-weight: var(--shpd-font-weight-strong);
  }

  /* Content area */
  .shpd-detail__content {
    flex: 1;
    overflow-y: auto;
    padding: var(--shpd-space-md);
  }

  /* Prázdný stav panelu (žádný vybraný záznam) — text body šedě uprostřed
     plochy. Stejný vzhled má blok `empty` ve sdílené `ContentBlock`. */
  .shpd-detail__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    font-size: var(--shpd-font-size-body);
    line-height: 1.5;
    color: var(--shpd-color-text-secondary);
  }

  /* Jednoduché bloky obsahu (vlastnosti, tabulka, hláška, štítky, prázdný
     stav, text, kód, nápověda, composite) kreslí sdílená `ContentBlock`
     (#150 D83) i se svým CSS; tady zůstávají jen bloky vázané na viewer. */

  /* Extracted documents tab */
  .shpd-extracted {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-extracted__empty {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-meta);
    font-style: italic;
    text-align: center;
    padding: var(--shpd-space-md);
  }

  /* Obal karty návrhu kreslí sdílená Card (#137 D33). Karta selhání uvnitř
     (nevalidní výstup) vnější okraj nemá — mezeru pod ní dává tenhle obal. */
  .shpd-extracted__failure {
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-extracted__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-extracted__type {
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text);
  }

  .shpd-extracted__badge {
    display: inline-block;
    padding: 0.125rem var(--shpd-space-sm);
    border-radius: var(--shpd-radius-sm);
    font-size: var(--shpd-font-size-meta);
    font-weight: var(--shpd-font-weight-normal);
  }

  /* Stejné tokeny jako ui/Badge.svelte — stejný stav, stejná barva.
   * Pozn.: confirmed je tady tichá šedá (sjednoceno se zbytkem systému),
   * dříve byla zelená — oprava při přechodu na tokeny. */
  .shpd-extracted__badge--done      { background: var(--shpd-color-state-done-bg);      color: var(--shpd-color-state-done-text); }
  .shpd-extracted__badge--confirmed { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-extracted__badge--edit      { background: var(--shpd-color-state-edit-bg);      color: var(--shpd-color-state-edit-text); }
  .shpd-extracted__badge--archive   { background: var(--shpd-color-state-archive-bg);   color: var(--shpd-color-state-archive-text); }
  .shpd-extracted__badge--error     { background: var(--shpd-color-state-error-bg);     color: var(--shpd-color-state-error-text); }
  .shpd-extracted__badge--concept   { background: var(--shpd-color-state-concept-bg);   color: var(--shpd-color-state-concept-text); }

  .shpd-extracted__confidence {
    margin-left: auto;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-meta);
  }

  .shpd-extracted__summary {
    font-size: var(--shpd-font-size-body);
    color: var(--shpd-color-text);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-extracted__meta {
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
    margin-top: var(--shpd-space-xs);
  }

  .shpd-extracted__actions {
    display: flex;
    gap: var(--shpd-space-sm);
    margin-top: var(--shpd-space-md);
    flex-wrap: wrap;
  }

  /* Tlačítkové styly tady byly dříve hardcoded přes !important.
     Teď používáme <Button variant="success|danger|secondary"> komponentu,
     která čerpá z brand palety v variables.css. */

  /* Reject-reason form pole se přesunula do sdílené RejectReasonPrompt
     komponenty (../dashboard/RejectReasonPrompt.svelte). Modální shell je ve
     sdílené Modal komponentě (../ui/Modal.svelte). */

  /* Attachments content — přílohy zdrojových zpráv (read-only grid),
     seskupené per zpráva. Vizuálně sladěno s AttachmentPanel kartami. */
  .shpd-detail__att-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-detail__att-toggle {
    border: 1px solid var(--shpd-color-border);
    background: var(--shpd-color-bg);
    color: var(--shpd-color-text-secondary);
    border-radius: var(--shpd-radius-md);
    padding: 0.125rem 0.625rem;
    font: inherit;
    font-size: var(--shpd-font-size-meta);
    cursor: pointer;
  }

  .shpd-detail__att-toggle:hover {
    color: var(--shpd-color-text);
    border-color: var(--shpd-color-text-secondary);
  }

  /* Grid náhledů (karty s thumbnail/ikonou) je ve sdílené AttachmentGrid
     komponentě (./AttachmentGrid.svelte). */
</style>
