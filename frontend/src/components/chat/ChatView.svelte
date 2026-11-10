<script>
  /**
   * Chat view: conversation list (left) + active thread (right). On mobile the
   * two panes toggle — selecting a conversation shows the thread; a back button
   * returns to the list.
   */
  import ConversationList from './ConversationList.svelte';
  import ChatThread from './ChatThread.svelte';
  import Button from '../ui/Button.svelte';
  import { iconChevronLeft } from '../../icons.js';
  import { chatStore } from '../../stores/chat.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { t } from '../../i18n/index.js';

  // Mobile: which pane is visible. Desktop ignores this (both shown).
  let showThread = $state(false);

  $effect(() => {
    chatStore.loadConversations();
  });
</script>

<div class="shpd-chat" class:shpd-chat--mobile={layoutStore.isMobile}>
  <aside
    class="shpd-chat__list"
    class:shpd-chat__pane--hidden={layoutStore.isMobile && showThread}
  >
    <ConversationList onselect={() => (showThread = true)} />
  </aside>

  <section
    class="shpd-chat__thread"
    class:shpd-chat__pane--hidden={layoutStore.isMobile && !showThread}
  >
    {#if layoutStore.isMobile}
      <div class="shpd-chat__mobile-bar">
        <Button icon={iconChevronLeft} label={t('chat.backToList')} variant="ghost" size="sm" onclick={() => (showThread = false)} />
      </div>
    {/if}
    <ChatThread />
  </section>
</div>

<style>
  .shpd-chat {
    display: flex;
    height: 100%;
    overflow: hidden;
    background-color: var(--shpd-color-bg);
  }
  .shpd-chat__list {
    width: 20rem;
    flex-shrink: 0;
    border-right: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
    overflow: hidden;
  }
  .shpd-chat__thread {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .shpd-chat__mobile-bar {
    padding: var(--shpd-space-sm);
    border-bottom: 1px solid var(--shpd-color-border);
    background-color: var(--shpd-color-bg);
  }

  .shpd-chat--mobile .shpd-chat__list {
    width: 100%;
  }
  .shpd-chat__pane--hidden {
    display: none;
  }
</style>
