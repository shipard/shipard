<script>
  /**
   * Conversation list: new / rename / delete / switch. Ordered by `modified`
   * (the backend already returns newest-first). Rename and delete reuse the
   * shared Modal + Button primitives (the project has no ConfirmDialog).
   */
  import Button from '../ui/Button.svelte';
  import Modal from '../ui/Modal.svelte';
  import Input from '../ui/Input.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconAdd, iconEdit, iconDelete } from '../../icons.js';
  import { chatStore } from '../../stores/chat.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { findSectionLabel } from '../../utils/navTree.js';
  import { t } from '../../i18n/index.js';

  let { onselect } = $props();

  let renameId = $state(null);
  let renameText = $state('');
  let deleteId = $state(null);
  let busy = $state(false);

  function titleOf(c) {
    return c.title && c.title.trim() !== '' ? c.title : t('chat.untitled');
  }

  function openRename(c) {
    renameId = c.id;
    renameText = c.title ?? '';
  }

  async function confirmRename() {
    busy = true;
    await chatStore.rename(renameId, renameText.trim() === '' ? null : renameText.trim());
    busy = false;
    renameId = null;
  }

  async function confirmDelete() {
    busy = true;
    await chatStore.remove(deleteId);
    busy = false;
    deleteId = null;
  }

  function select(c) {
    chatStore.openConversation(c.id);
    onselect?.();
  }
</script>

<div class="shpd-convlist">
  <div class="shpd-convlist__header">
    <Button
      icon={iconAdd}
      label={t('chat.newConversation')}
      variant="primary"
      size="sm"
      onclick={() => { chatStore.newConversation(); onselect?.(); }}
    />
  </div>

  <div class="shpd-convlist__items">
    {#if chatStore.conversations.length === 0}
      <p class="shpd-convlist__empty">{t('chat.noConversations')}</p>
    {/if}
    {#each chatStore.conversations as c (c.id)}
      <div
        class="shpd-convlist__item"
        class:shpd-convlist__item--active={chatStore.activeId === c.id}
      >
        <button class="shpd-convlist__main" onclick={() => select(c)}>
          <span class="shpd-convlist__title">{titleOf(c)}</span>
          {#if c.section}
            <!-- Scope na sekci (UI shells Fáze 5) — jen zobrazení, výběr
                 tu není (D5). Label ze stromu navigace, fallback id. -->
            <span class="shpd-convlist__section">
              {findSectionLabel(navigationStore.appNavTree ?? [], c.section)}
            </span>
          {/if}
        </button>
        <div class="shpd-convlist__actions">
          <button class="shpd-convlist__icon" title={t('common.edit')} onclick={() => openRename(c)}>
            <Icon icon={iconEdit} size="sm" />
          </button>
          <button class="shpd-convlist__icon" title={t('common.delete')} onclick={() => (deleteId = c.id)}>
            <Icon icon={iconDelete} size="sm" />
          </button>
        </div>
      </div>
    {/each}
  </div>
</div>

<Modal title={t('chat.rename.title')} open={renameId !== null} onClose={() => (renameId = null)} width="420px">
  <Input bind:value={renameText} placeholder={t('chat.rename.placeholder')} />
  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" onclick={() => (renameId = null)} />
    <Button label={t('common.save')} variant="primary" size="sm" loading={busy} onclick={confirmRename} />
  {/snippet}
</Modal>

<Modal title={t('chat.delete.title')} open={deleteId !== null} onClose={() => (deleteId = null)} width="420px">
  <p>{t('chat.delete.confirm')}</p>
  {#snippet footer()}
    <Button label={t('common.cancel')} variant="secondary" size="sm" onclick={() => (deleteId = null)} />
    <Button label={t('common.delete')} variant="danger" size="sm" loading={busy} onclick={confirmDelete} />
  {/snippet}
</Modal>

<style>
  .shpd-convlist {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
  }
  .shpd-convlist__header {
    padding: var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
  }
  .shpd-convlist__items {
    flex: 1;
    overflow-y: auto;
  }
  .shpd-convlist__empty {
    padding: var(--shpd-space-lg);
    text-align: center;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }
  .shpd-convlist__item {
    display: flex;
    align-items: center;
    border-bottom: 1px solid var(--shpd-color-border-subtle);
  }
  .shpd-convlist__item--active {
    background-color: var(--shpd-color-bg-selected);
  }
  .shpd-convlist__main {
    flex: 1;
    min-width: 0;
    text-align: left;
    padding: var(--shpd-space-md);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--shpd-color-text);
  }
  .shpd-convlist__title {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .shpd-convlist__section {
    display: inline-block;
    margin-top: 0.125rem;
    padding: 0 var(--shpd-space-xs);
    border: 1px solid var(--shpd-color-border);
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-xs);
  }
  .shpd-convlist__actions {
    display: flex;
    gap: var(--shpd-space-xs);
    padding-right: var(--shpd-space-sm);
  }
  .shpd-convlist__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    background: none;
    border: none;
    border-radius: var(--shpd-radius-sm);
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
  }
  .shpd-convlist__icon:hover {
    background-color: var(--shpd-color-bg-hover);
    color: var(--shpd-color-text);
  }
</style>
