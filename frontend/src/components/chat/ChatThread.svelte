<script>
  /**
   * Active conversation thread: persisted messages, the live streaming reply,
   * tool-call chips, an error banner, and the composer. Auto-scrolls to the
   * bottom as content grows.
   */
  import MessageBubble from './MessageBubble.svelte';
  import ChatInput from './ChatInput.svelte';
  import Markdown from './Markdown.svelte';
  import ToolCallChip from './ToolCallChip.svelte';
  import SectionCards from './SectionCards.svelte';
  import Icon from '../ui/Icon.svelte';
  import { iconClose } from '../../icons.js';
  import { chatStore } from '../../stores/chat.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { findSectionLabel } from '../../utils/navTree.js';
  import { translateError } from '../../i18n/errors.js';
  import { t } from '../../i18n/index.js';

  // showScopeChip: SectionAssistant (AI záložka wild shellu) chip skrývá —
  // scope tam dává záložka, chip by byl redundantní a ✕ by vlákno
  // desynchronizoval od záložky. Default zachovává ChatPanel/ChatView.
  let { showScopeChip = true } = $props();

  let scroller = $state(null);

  // Auto-scroll to bottom whenever the thread grows or text streams in.
  $effect(() => {
    // Touch the reactive deps so the effect re-runs on any of them.
    void chatStore.messages.length;
    void chatStore.streamingText;
    void chatStore.streamingTools.length;
    if (scroller) scroller.scrollTop = scroller.scrollHeight;
  });

  const isEmpty = $derived(
    chatStore.messages.length === 0 && !chatStore.streaming && !chatStore.loadingThread,
  );

  // „Přemýšlím…“ (D56): dokud nic nepřišlo, a znovu vždy, když model začal
  // thinking blok — i po čipech nástrojů, kde dřív bublina mlčela. Zhasne
  // s dalším textem, voláním nástroje nebo koncem odpovědi (store).
  const showThinking = $derived(
    chatStore.thinking || (!chatStore.streamingText && chatStore.streamingTools.length === 0),
  );

  // Scope na sekci (UI shells Fáze 5): draft → chip s ✕ (scope lze odebrat),
  // založená konverzace → statický chip. Label ze stromu navigace, fallback id.
  const scopeSection = $derived(chatStore.scopeSection);
  const scopeRemovable = $derived(chatStore.activeId == null);
  const scopeLabel = $derived(
    findSectionLabel(navigationStore.appNavTree ?? [], scopeSection),
  );
</script>

<div class="shpd-thread">
  <div class="shpd-thread__messages" bind:this={scroller}>
    {#if chatStore.loadingThread}
      <p class="shpd-thread__hint">{t('chat.loading')}</p>
    {:else if isEmpty}
      <p class="shpd-thread__hint">{t('chat.empty')}</p>
      {#if scopeSection}
        <!-- Karty sekce jako UI (D4) — jen v prázdné scoped konverzaci;
             po první zprávě isEmpty spadne a blok zmizí. -->
        <SectionCards section={scopeSection} />
      {/if}
    {/if}

    {#each chatStore.messages as message (message.id)}
      <MessageBubble {message} />
    {/each}

    {#if chatStore.streaming}
      <div class="shpd-msg shpd-msg--assistant">
        <div class="shpd-msg__bubble">
          {#if chatStore.streamingText}
            <Markdown text={chatStore.streamingText} />
          {/if}
          {#if chatStore.streamingTools.length}
            <div class="shpd-thread__tools">
              {#each chatStore.streamingTools as call}
                <ToolCallChip name={call.name} state="running" />
              {/each}
            </div>
          {/if}
          {#if showThinking}
            <span class="shpd-thread__typing">{t('chat.thinking')}</span>
          {/if}
        </div>
      </div>
    {/if}

    {#if chatStore.error}
      <div class="shpd-thread__error">{translateError(chatStore.error)}</div>
    {/if}
  </div>

  {#if scopeSection && showScopeChip}
    <div class="shpd-thread__scope">
      <span class="shpd-thread__scope-chip">
        {scopeLabel}
        {#if scopeRemovable}
          <button
            type="button"
            class="shpd-thread__scope-remove"
            title={t('chat.scope.remove')}
            aria-label={t('chat.scope.remove')}
            onclick={() => chatStore.clearPendingSection()}
          >
            <Icon icon={iconClose} size="sm" />
          </button>
        {/if}
      </span>
    </div>
  {/if}
  <ChatInput disabled={chatStore.streaming} onsend={(text) => chatStore.send(text)} />
</div>

<style>
  .shpd-thread {
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
    background-color: var(--shpd-color-bg-secondary);
  }
  .shpd-thread__messages {
    flex: 1;
    overflow-y: auto;
    padding: var(--shpd-space-lg);
  }
  .shpd-thread__hint {
    text-align: center;
    color: var(--shpd-color-text-secondary);
    margin-top: var(--shpd-space-xl);
  }
  .shpd-thread__typing {
    color: var(--shpd-color-text-secondary);
    font-style: italic;
  }
  .shpd-thread__typing:not(:first-child) {
    display: block;
    margin-top: var(--shpd-space-sm);
  }
  .shpd-thread__tools {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs);
    margin-top: var(--shpd-space-sm);
  }
  .shpd-thread__scope {
    padding: var(--shpd-space-xs) var(--shpd-space-md) 0;
  }
  .shpd-thread__scope-chip {
    display: inline-flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    padding: 2px var(--shpd-space-sm);
    border: 1px solid var(--shpd-color-border);
    border-radius: 999px;
    background-color: var(--shpd-color-bg);
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }
  .shpd-thread__scope-remove {
    display: inline-flex;
    align-items: center;
    padding: 0;
    background: none;
    border: none;
    color: var(--shpd-color-text-secondary);
    cursor: pointer;
  }
  .shpd-thread__scope-remove:hover {
    color: var(--shpd-color-text);
  }
  .shpd-thread__error {
    margin: var(--shpd-space-sm) 0;
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-radius: var(--shpd-radius-md);
    background-color: var(--shpd-color-danger);
    color: #fff;
    font-size: var(--shpd-font-size-sm);
  }
</style>
