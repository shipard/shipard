<script>
  /**
   * AI asistent sekce — plná plocha AI záložky wild shellu (D4/R4).
   * Kompozice nad <ChatThread /> (vzor ChatPanel): hlavička s názvem
   * sekce + Nová konverzace (+) / Otevřít v Chatu (⧉), scope chip se
   * nekreslí — scope dává záložka (showScopeChip false).
   *
   * Používá tentýž chatStore singleton jako ChatPanel a sekce Chat —
   * souběh s otevřeným panelem zobrazuje totéž vlákno (přijatelné v1,
   * stejná sémantika jako „Otevřít v Chatu").
   */
  import { untrack } from 'svelte';
  import ChatThread from './ChatThread.svelte';
  import Button from '../ui/Button.svelte';
  import { chatStore } from '../../stores/chat.svelte.js';
  import { navigationStore } from '../../stores/navigation.svelte.js';
  import { findSectionLabel } from '../../utils/navTree.js';
  import { iconOpenExternal, iconAdd } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  let { section } = $props(); // id sekce, nikdy null (domeček AI nemá, D3)

  const title = $derived(findSectionLabel(navigationStore.appNavTree ?? [], section));

  // Aktivace (D4): navaž na nejnovější scoped konverzaci sekce, jinak
  // prázdný draft (SectionCards + input kreslí ChatThread). Jediná
  // tracked závislost je prop `section` — chatStore se čte jen v untrack
  // a async pokračování, takže refetch listu ze send() efekt nespustí.
  let resolvedFor = null;   // guard — obyčejná proměnná, nesmí trackovat
  let activationToken = 0;  // rychlé přepínání sekcí zahodí stale výsledek

  $effect(() => {
    const s = section;
    if (s === resolvedFor) return;
    resolvedFor = s;
    untrack(() => { activate(s); });
  });

  async function activate(s) {
    // Návrat na záložku s vláknem/draftem sekce → nic (drží se
    // rozepsaný draft i otevřené vlákno, žádný refetch).
    if (chatStore.scopeSection === s) return;
    const token = ++activationToken;
    await chatStore.loadConversations();
    if (token !== activationToken) return;
    // Seznam je řazen modified DESC → první match = nejnovější.
    const latest = chatStore.conversations.find((c) => c.section === s);
    if (latest) chatStore.openConversation(latest.id);
    else chatStore.newConversation(s);
  }

  // Vzor ChatPanel.openFull — activeId v singletonu vlákno přenese;
  // navigace na Chat leaf pak přes R3 srovná prohlížení shellu.
  function openFull() {
    navigationStore.navigate({ id: 'chat', label: 'Chat', type: 'chat', table: null, viewerId: null });
  }
</script>

<div class="shpd-assistant">
  <header class="shpd-assistant__header">
    <span class="shpd-assistant__title">{title}</span>
    <div class="shpd-assistant__actions">
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
        onclick={() => chatStore.newConversation(section)}
      />
    </div>
  </header>
  <div class="shpd-assistant__body">
    <ChatThread showScopeChip={false} />
  </div>
</div>

<style>
  .shpd-assistant {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow: hidden;
  }

  .shpd-assistant__header {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-assistant__title {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-assistant__actions {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-xs);
    flex-shrink: 0;
  }

  .shpd-assistant__body {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
  }
</style>
