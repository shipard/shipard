<script>
  import { applyMessage, rejectMessage } from '../../api/exchange.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import Button from '../ui/Button.svelte';
  import Popover from '../ui/Popover.svelte';
  import DocumentExchangePreviewModal from '../exchange/DocumentExchangePreviewModal.svelte';
  import RejectReasonPrompt from '../dashboard/RejectReasonPrompt.svelte';
  import AttachmentGrid from './AttachmentGrid.svelte';
  import { attachmentViewStore } from '../../stores/attachmentView.svelte.js';
  import DocumentDetail from './DocumentDetail.svelte';
  import Icon from '../ui/Icon.svelte';
  import SandboxedHtml from '../ui/SandboxedHtml.svelte';
  import DocumentLockBanner from '../ui/DocumentLockBanner.svelte';
  import { resolveIcon } from '../../icons.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  // hideSingleTabBar: opt-in pro hostitele s jediným tabem (ViewerDetailModal) —
  // skryje tab lištu, když není mezi čím přepínat. Default false, aby se
  // nezměnil vzhled stávajících hostitelů (inline panel, drawer).
  let { detail = null, loading = false, onRefresh, onAction = null, hideSingleTabBar = false } = $props();

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
      <!-- Header (title + badges) — zobrazuje se jen pokud backend
           posílá detail.title. Bez něj se hlavička přeskočí a layout
           je stejný jako předtím. -->
      <div class="shpd-detail__header">
        {#if detail.icon}
          <div class="shpd-detail__header-icon">
            <Icon icon={resolveIcon(detail.icon)} />
          </div>
        {/if}
        <div class="shpd-detail__header-main">
          <div class="shpd-detail__title-row">
            <h2 class="shpd-detail__title">{detail.title}</h2>
            {#each detail.badges ?? [] as badge}
              <span class="shpd-detail__badge shpd-detail__badge--{badge.style ?? 'neutral'}">
                {badge.label}
              </span>
            {/each}
          </div>
          {#if detail.subtitle}
            <div class="shpd-detail__subtitle">{detail.subtitle}</div>
          {/if}
        </div>
      </div>
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

    {#snippet renderContent(content)}
      {#if content?.type === 'properties'}
        {#each content.groups ?? [] as group}
          <div class="shpd-detail__group">
            <h4 class="shpd-detail__group-title">{group.title}</h4>
            <dl class="shpd-detail__props">
              {#each group.items ?? [] as item}
                <div class="shpd-detail__prop">
                  <dt class="shpd-detail__prop-label">{item.label}</dt>
                  <dd class="shpd-detail__prop-value">{item.value}</dd>
                </div>
              {/each}
            </dl>
          </div>
        {/each}

      {:else if content?.type === 'table'}
        <div class="shpd-detail__table-wrap">
          <table class="shpd-detail__table">
            <thead>
              <tr>
                {#each content.columns ?? [] as col (col.id)}
                  <th class="shpd-detail__th" class:shpd-detail__th--num={col.align === 'right'}>{col.label}</th>
                {/each}
              </tr>
            </thead>
            <tbody>
              {#if (content.rows ?? []).length === 0}
                <tr>
                  <td class="shpd-detail__empty-cell" colspan={content.columns?.length ?? 1}>
                    {t('common.empty')}
                  </td>
                </tr>
              {:else}
                <!-- row._class (error | total | muted) — klasifikace řádku od
                     backendu: chybové a součtové řádky účetního deníku,
                     tlumené plánované řádky (odpisy majetku). -->
                {#each content.rows ?? [] as row}
                  <tr
                    class:shpd-detail__tr--error={row._class === 'error'}
                    class:shpd-detail__tr--total={row._class === 'total'}
                    class:shpd-detail__tr--muted={row._class === 'muted'}
                  >
                    <!-- col.link + row._action: buňka je odkaz, klik spustí
                         akci řádku (typicky open_detail dokladu). Bez
                         onAction (read-only modal) zůstává prostý text. -->
                    {#each content.columns ?? [] as col (col.id)}
                      <td class="shpd-detail__td" class:shpd-detail__td--num={col.align === 'right'}>
                        {#if col.link && row._action && onAction}
                          <button type="button" class="shpd-detail__cell-link" onclick={() => handleAction(row._action)}>
                            {row[col.id] ?? '—'}
                          </button>
                        {:else}
                          {row[col.id] ?? '—'}
                        {/if}
                      </td>
                    {/each}
                  </tr>
                {/each}
              {/if}
            </tbody>
          </table>
        </div>

      {:else if content?.type === 'html'}
        <div class="shpd-detail__html">
          {@html content.html}
        </div>

      {:else if content?.type === 'untrusted-html'}
        <SandboxedHtml html={content.html} title={t('viewer.detail.mailBody')} />

      {:else if content?.type === 'proposal'}
        <!-- Dokumentový návrh poslední analýzy (message-centric) — jedna
             karta, nebo prázdný stav s klasifikací zprávy. -->
        <div class="shpd-extracted">
          {#if !content.proposal}
            <div class="shpd-extracted__empty">
              {t('viewer.detail.noProposal', {
                type: content.classification?.primary_type_label ?? '',
              })}
            </div>
          {:else}
            {@const doc = content.proposal}
            <div class="shpd-extracted__card">
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
            </div>
          {/if}
        </div>

      {:else if content?.type === 'attachments'}
        <div class="shpd-detail__att-groups">
          {#each content.groups ?? [] as group (group.message_ndx)}
            <div class="shpd-detail__att-group">
              <h4 class="shpd-detail__group-title">
                {#if content.sourceViewerId}
                  <button
                    type="button"
                    class="shpd-detail__att-msglink"
                    onclick={() => navigationStore.navigateToViewer(content.sourceViewerId, group.message_ndx)}
                  >
                    #{group.message_id}
                  </button>
                {:else}
                  <span>#{group.message_id}</span>
                {/if}
                {#if group.received_at}
                  <span class="shpd-detail__att-msgdate">· {group.received_at}</span>
                {/if}
              </h4>

              <AttachmentGrid attachments={group.attachments} />
            </div>
          {/each}
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
              ? t('viewer.document.attachments.viewGrid')
              : t('viewer.document.attachments.viewFull')}
          </button>
        </div>
        <AttachmentGrid attachments={content.attachments ?? []} mode={attachmentViewStore.mode} />

      {:else if content?.type === 'document'}
        <DocumentDetail content={content} onAction={onAction} />

      {:else if content?.type === 'heading'}
        <h3 class="shpd-detail__content-heading">{content.text}</h3>

      {:else if content?.type === 'composite'}
        <!-- Composite = seznam bloků, každý blok je libovolný známý content
             typ. Rekurzivní render přes tentýž snippet. -->
        {#each content.blocks ?? [] as block}
          {@render renderContent(block)}
        {/each}
      {/if}
    {/snippet}

    <!-- Tab content -->
    <div class="shpd-detail__content">
      {@render renderContent(activeContent)}
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
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-detail__spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid var(--shpd-color-border);
    border-top-color: var(--shpd-color-primary);
    border-radius: 50%;
    animation: shpd-detail-spin 0.7s linear infinite;
  }

  @keyframes shpd-detail-spin {
    to { transform: rotate(360deg); }
  }

  /* Header (title + badges) — zobrazuje se jen když backend
     pošle detail.title. Drobný panel nad taby. */
  .shpd-detail__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    flex-shrink: 0;
  }

  /* Ikona vlevo v hlavicce (typ zaznamu) - stejny vizual jako
     shpd-modal__header-icon ve formularovem modalu. */
  .shpd-detail__header-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    font-size: 1.75em;
    color: var(--shpd-color-text-secondary);
  }

  .shpd-detail__header-main {
    flex: 1;
    /* min-width: 0 kvuli ellipsis uvnitr flex containeru */
    min-width: 0;
  }

  .shpd-detail__title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--shpd-space-sm);
  }

  .shpd-detail__title {
    margin: 0;
    font-size: var(--shpd-font-size-lg);
    font-weight: 600;
    color: var(--shpd-color-text);
    line-height: 1.3;
  }

  .shpd-detail__subtitle {
    margin-top: 2px;
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-detail__badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1.4;
    white-space: nowrap;
  }

  /* Stylové varianty badge.
     Sjednoceno s doc-state škálou v ViewerRow + accent pro brand. */
  /* Typové varianty (mimo doc-state) — mají vlastní barevnou logiku.
   * neutral, primary, accent, success, warning, danger se používají pro
   * libovolné typové badge (typ záznamu, VIP, atd.). Sdílí paletu
   * s doc-state tokeny tam, kde to barevně sedí. */
  .shpd-detail__badge--neutral   { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-detail__badge--primary   { background: var(--shpd-color-primary-soft); color: var(--shpd-color-primary); }
  .shpd-detail__badge--accent    { background: var(--shpd-color-accent-soft); color: var(--shpd-color-accent-hover); }
  .shpd-detail__badge--success   { background: var(--shpd-color-state-done-bg); color: var(--shpd-color-state-done-text); }
  .shpd-detail__badge--warning   { background: var(--shpd-color-state-concept-bg); color: var(--shpd-color-state-concept-text); }
  .shpd-detail__badge--danger    { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }

  /* Doc-state varianty — sdílejí tokeny s ostatními badge
   * (FormStateBadge, extracted__badge), aby stejný stav vypadal
   * všude stejně. */
  .shpd-detail__badge--concept   { background: var(--shpd-color-state-concept-bg);   color: var(--shpd-color-state-concept-text); }
  .shpd-detail__badge--edit      { background: var(--shpd-color-state-edit-bg);      color: var(--shpd-color-state-edit-text); }
  .shpd-detail__badge--confirmed { background: var(--shpd-color-state-confirmed-bg); color: var(--shpd-color-state-confirmed-text); }
  .shpd-detail__badge--archive   { background: var(--shpd-color-state-archive-bg);   color: var(--shpd-color-state-archive-text); }
  .shpd-detail__badge--trash     { background: var(--shpd-color-state-trash-bg);     color: var(--shpd-color-state-trash-text); text-decoration: line-through; }
  .shpd-detail__badge--cancelled { background: var(--shpd-color-state-cancelled-bg); color: var(--shpd-color-state-cancelled-text); }
  .shpd-detail__badge--done      { background: var(--shpd-color-state-done-bg);      color: var(--shpd-color-state-done-text); }
  .shpd-detail__badge--error     { background: var(--shpd-color-state-error-bg);     color: var(--shpd-color-state-error-text); }

  /* Actions bar — řádek per-record akcí nad taby (snooze/dismiss/recheck +
     custom akce alertů). Stejná vizuální logika jako header: padding sm/md,
     border-bottom, wrap při velkém počtu tlačítek. */
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
    gap: 4px;
    padding: 4px 10px;
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
    color: var(--shpd-color-on-primary, #fff);
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
    min-width: 140px;
    padding: 4px 0;
  }

  .shpd-detail__action-menu-item {
    text-align: left;
    padding: 6px 14px;
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
    font-size: var(--shpd-font-size-sm);
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
    font-weight: 600;
  }

  /* Content area */
  .shpd-detail__content {
    flex: 1;
    overflow-y: auto;
    padding: var(--shpd-space-md);
  }

  .shpd-detail__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  /* Properties content */
  .shpd-detail__group {
    margin-bottom: var(--shpd-space-lg);
  }

  .shpd-detail__group-title {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text);
    padding-bottom: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-detail__props {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
  }

  .shpd-detail__prop {
    display: contents;
  }

  .shpd-detail__prop-label {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
    white-space: nowrap;
  }

  .shpd-detail__prop-value {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
  }

  /* Table content */
  .shpd-detail__table-wrap {
    overflow-x: auto;
  }

  .shpd-detail__table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-detail__th {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    text-align: left;
    font-weight: 600;
    color: var(--shpd-color-text);
    border-bottom: 2px solid var(--shpd-color-border);
    white-space: nowrap;
  }

  .shpd-detail__td {
    padding: var(--shpd-space-xs) var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    color: var(--shpd-color-text);
  }

  .shpd-detail__empty-cell {
    padding: var(--shpd-space-md);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  /* Číselné sloupce (columns[].align === 'right') — stejná konvence jako
     .num v DocumentDetail. */
  .shpd-detail__th--num,
  .shpd-detail__td--num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  /* Buňka-odkaz (col.link + row._action) — vypadá jako odkaz, chová se
     jako tlačítko. Dědí písmo i zarovnání buňky. */
  .shpd-detail__cell-link {
    padding: 0;
    border: none;
    background: transparent;
    font: inherit;
    color: var(--shpd-color-primary);
    cursor: pointer;
    text-align: inherit;
  }

  .shpd-detail__cell-link:hover {
    color: var(--shpd-color-primary-hover);
    text-decoration: underline;
  }

  /* Klasifikace řádků (row._class) — chybové a součtové řádky. */
  .shpd-detail__tr--error > .shpd-detail__td {
    background: var(--shpd-color-state-error-bg);
    color: var(--shpd-color-state-error-text);
  }

  .shpd-detail__tr--total > .shpd-detail__td {
    font-weight: 600;
    border-top: 2px solid var(--shpd-color-border);
    border-bottom: none;
  }

  .shpd-detail__tr--muted > .shpd-detail__td {
    color: var(--shpd-color-text-secondary);
  }

  /* Heading blok (composite) - vizualne sjednoceno s group-title */
  .shpd-detail__content-heading {
    margin: var(--shpd-space-lg) 0 var(--shpd-space-sm);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text);
    padding-bottom: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-detail__content-heading:first-child {
    margin-top: 0;
  }

  /* HTML content */
  .shpd-detail__html {
    font-size: var(--shpd-font-size-sm);
    line-height: 1.5;
  }

  /* Extracted documents tab */
  .shpd-extracted {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-md);
  }

  .shpd-extracted__empty {
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
    font-style: italic;
    text-align: center;
    padding: var(--shpd-space-md);
  }

  .shpd-extracted__card {
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-md);
    padding: var(--shpd-space-md);
    background-color: var(--shpd-color-bg);
  }

  .shpd-extracted__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-extracted__type {
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-extracted__badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: var(--shpd-radius-sm);
    font-size: 12px;
    font-weight: 500;
  }

  /* Stejné tokeny jako shpd-detail__badge — stejný stav, stejná barva.
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
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-extracted__summary {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text);
    margin-bottom: var(--shpd-space-sm);
  }

  .shpd-extracted__meta {
    font-size: 12px;
    color: var(--shpd-color-text-secondary);
    margin-top: 4px;
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
    padding: 2px 10px;
    font: inherit;
    font-size: 0.75rem;
    cursor: pointer;
  }

  .shpd-detail__att-toggle:hover {
    color: var(--shpd-color-text);
    border-color: var(--shpd-color-text-secondary);
  }

  .shpd-detail__att-groups {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-lg);
  }

  .shpd-detail__att-msglink {
    border: none;
    background: none;
    padding: 0;
    font: inherit;
    font-weight: 600;
    color: var(--shpd-color-primary);
    cursor: pointer;
  }

  .shpd-detail__att-msglink:hover {
    text-decoration: underline;
  }

  .shpd-detail__att-msgdate {
    font-weight: 400;
    color: var(--shpd-color-text-secondary);
  }

  /* Grid náhledů (karty s thumbnail/ikonou) je ve sdílené AttachmentGrid
     komponentě (./AttachmentGrid.svelte). */
</style>
