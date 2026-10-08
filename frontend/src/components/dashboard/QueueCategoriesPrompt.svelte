<script>
  /**
   * Předkrok sériového průchodu frontou (tasks/dashboard-queue-walkthrough.md,
   * D8; Issue #35): před otevřením první zprávy nabídne založení chybějících
   * otagovaných položek, aby návrhy „jen štítek" prošly v průchodu už jako
   * plná trojice (povýšení se projeví přirozeně — preview se počítá čerstvě).
   *
   * Řádek per content_tag karta; labely tlačítek posílá server v akcích
   * (jedno „Založit položku", nebo dvě „Jako materiál/zboží" s volbou účtu).
   * Materializace běží tady (lokální busy per řádek), úspěch → řádek zmizí
   * + onMaterialized(cardId) ať si rodič optimisticky odklidí kartu (P8);
   * chyba → inline hláška u řádku. Bez toastu a bez load() — feed se
   * přepočítá až na konci průchodu.
   */
  import Modal from '../ui/Modal.svelte';
  import Button from '../ui/Button.svelte';
  import { materializeContentTag } from '../../api/contentTags.js';
  import { t } from '../../i18n/index.js';
  import { translateError } from '../../i18n/errors.js';

  let {
    open = false,
    cards = [],
    onMaterialized = () => {},
    onContinue = () => {},
    onClose = () => {},
  } = $props();

  // Id karet už založených v tomto otevření; busyId disabluje tlačítka
  // řádku s běžící materializací; errors: cardId → text chyby.
  let doneIds = $state([]);
  let busyId = $state(null);
  let errors = $state({});

  // Reset při každém otevření (znovupoužití napříč průchody).
  $effect(() => {
    if (open) {
      doneIds = [];
      busyId = null;
      errors = {};
    }
  });

  let remaining = $derived(cards.filter((c) => !doneIds.includes(c.id)));

  async function materialize(card, action) {
    if (busyId !== null) return;
    busyId = card.id;
    errors = { ...errors, [card.id]: null };
    try {
      const result = await materializeContentTag(action.target.tag, action.target.account ?? null);
      if (result?.success) {
        doneIds = [...doneIds, card.id];
        onMaterialized(card.id);
      } else {
        errors = { ...errors, [card.id]: translateError(result?.error) };
      }
    } finally {
      busyId = null;
    }
  }
</script>

<Modal title={t('dashboard.queue.precheckTitle')} {open} {onClose} width="560px">
  <div class="shpd-queue-precheck">
    {#if remaining.length === 0}
      <p class="shpd-queue-precheck__empty">{t('dashboard.queue.precheckEmpty')}</p>
    {:else}
      <ul class="shpd-queue-precheck__list">
        {#each remaining as card (card.id)}
          <li class="shpd-queue-precheck__row">
            <div class="shpd-queue-precheck__texts">
              <span class="shpd-queue-precheck__title">{card.title}</span>
              {#if card.subtitle}
                <span class="shpd-queue-precheck__subtitle">{card.subtitle}</span>
              {/if}
              {#if errors[card.id]}
                <span class="shpd-queue-precheck__error" role="alert">{errors[card.id]}</span>
              {/if}
            </div>
            <div class="shpd-queue-precheck__actions">
              {#each card.actions ?? [] as action (action.id)}
                {#if action.kind === 'materialize_content_tag'}
                  <Button
                    label={action.label}
                    variant={action.primary ? 'primary' : 'secondary'}
                    size="sm"
                    disabled={busyId !== null}
                    onclick={() => materialize(card, action)}
                  />
                {/if}
              {/each}
            </div>
          </li>
        {/each}
      </ul>
    {/if}
  </div>

  {#snippet footer()}
    <Button
      label={remaining.length === 0
        ? t('dashboard.queue.precheckContinue')
        : t('dashboard.queue.precheckContinueWithout')}
      variant="primary"
      size="sm"
      disabled={busyId !== null}
      onclick={onContinue}
    />
  {/snippet}
</Modal>

<style>
  .shpd-queue-precheck__empty {
    margin: 0;
    color: var(--shpd-color-text-secondary);
    font-size: var(--shpd-font-size-sm);
  }

  .shpd-queue-precheck__list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-queue-precheck__row {
    display: flex;
    align-items: center;
    gap: var(--shpd-space-md);
    padding: var(--shpd-space-sm) 0;
    border-bottom: 1px solid var(--shpd-color-border);
  }

  .shpd-queue-precheck__row:last-child {
    border-bottom: none;
  }

  .shpd-queue-precheck__texts {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .shpd-queue-precheck__title {
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    color: var(--shpd-color-text);
  }

  .shpd-queue-precheck__subtitle {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-queue-precheck__error {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-danger);
  }

  .shpd-queue-precheck__actions {
    flex-shrink: 0;
    display: flex;
    gap: var(--shpd-space-sm);
  }
</style>
