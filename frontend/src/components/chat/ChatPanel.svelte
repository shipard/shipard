<script>
  /**
   * Boční AI chat panel — non-modální overlay zprava, mountovaný v AppShellu
   * (přežije navigaci na viewer/formulář). Obálka nad <ChatThread />: hlavička
   * s titulkem aktivní konverzace + Otevřít v Chatu (⧉) / Nová konverzace (+)
   * / ×. Historie konverzací žije v sekci Chat, panel žádný list nemá.
   *
   * Zavírání v1 jen přes × — Esc listener by kolidoval s Esc ve
   * FormDialogu/Modalu otevřeném nad panelem.
   */
  import ChatThread from './ChatThread.svelte';
  import Button from '../ui/Button.svelte';
  import { chatStore } from '../../stores/chat.svelte.js';
  import { chatPanelStore } from '../../stores/chatPanel.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { layoutStore } from '../../stores/layout.svelte.js';
  import { iconOpenExternal, iconAdd, iconClose } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  // Titulek = title aktivní konverzace; list se naplní, protože send() po
  // lazy create volá loadConversations(). Prázdný/chybějící title → fallback.
  const title = $derived(
    chatStore.conversations.find((c) => c.id === chatStore.activeId)?.title
      || t('chat.panel.title'),
  );

  // Nav položka Chatu je hardcoded v NavigationController se stejným tvarem —
  // id 'chat' zajistí zvýraznění v sidebaru. activeId ve store zůstává,
  // ChatView otevře totéž vlákno.
  function openFull() {
    navigationStore.navigate({ id: 'chat', label: 'Chat', type: 'chat', table: null, viewerId: null });
    chatPanelStore.close();
  }
</script>

<!-- Non-modální panel → aside s implicitní rolí complementary, ne dialog. -->
<aside
  class="shpd-chat-panel"
  class:shpd-chat-panel--mobile={layoutStore.isMobile}
  aria-label={t('chat.panel.title')}
>
  <header class="shpd-chat-panel__header">
    <span class="shpd-chat-panel__title">{title}</span>
    <div class="shpd-chat-panel__actions">
      <Button
        variant="ghost"
        size="sm"
        icon={iconOpenExternal}
        iconOnly
        label={t('chat.panel.openFull')}
        onclick={openFull}
      />
      <Button
        variant="ghost"
        size="sm"
        icon={iconAdd}
        iconOnly
        label={t('chat.panel.new')}
        onclick={() => chatStore.newConversation(navigationStore.activeSection)}
      />
      <Button
        variant="ghost"
        size="sm"
        icon={iconClose}
        iconOnly
        label={t('chat.panel.close')}
        onclick={() => chatPanelStore.close()}
      />
    </div>
  </header>
  <div class="shpd-chat-panel__body">
    <ChatThread />
  </div>
</aside>

<style>
  .shpd-chat-panel {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: min(30rem, 90vw);
    z-index: 80; /* pod drawerem 90/100, ThemePanelem 200, Modalem 1000 */
    display: flex;
    flex-direction: column;
    background: var(--shpd-color-bg);
    border-left: 1px solid var(--shpd-color-border);
    box-shadow: var(--shpd-shadow-lg, -4px 0 16px rgba(0, 0, 0, 0.15));
    animation: shpd-chat-panel-in 0.22s ease;
  }

  @keyframes shpd-chat-panel-in {
    from { transform: translateX(100%); }
    to { transform: translateX(0); }
  }

  /* Mobil: fullscreen pod top barem (drawer zůstává nad panelem). */
  .shpd-chat-panel--mobile {
    top: var(--shpd-header-height);
    left: 0;
    width: auto;
    border-left: none;
  }

  .shpd-chat-panel__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-chat-panel__title {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-chat-panel__actions {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
  }

  .shpd-chat-panel__body {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
  }
</style>
