<script>
  // Přepínač Adresa v pruhu dashboardu (#183 D3, U10; vzor VersionMenu):
  // tlačítko s adresou, přes kterou je stránka otevřená (zámek = https),
  // a bublina se vstupy vývojového serveru z `/_dev/api/info` (`access`).
  // Aktuální vstup je označený, ostatní jsou odkazy na **stejnou cestu**
  // na jiném originu (entryUrl) — plná navigace, ne router. Pod seznamem
  // věta, že každá adresa má vlastní přihlášení i nastavení; u https na IP
  // nápověda k varování prohlížeče. Aktuální adresa mimo vstupy serveru
  // (`localhost`, jiný port) je v seznamu jako první řádek.
  // Bublina nad sdíleným Popover (Esc, klik mimo, fokus zpět na tlačítko).
  import { tick } from 'svelte';
  import Popover from '../../components/ui/Popover.svelte';
  import Icon from '../../components/ui/Icon.svelte';
  import { iconAddress, iconLock, iconChevronDown, iconConfirm } from '../../icons.js';
  import { devInfo } from '../info.svelte.js';
  import { accessEntries, currentEntry, entryUrl, isHttps, originHost } from '../access.js';
  import { t, label } from '../i18n/index.js';

  let open = $state(false);
  let buttonEl = $state(null);

  const here = window.location.origin;
  const entries = $derived(accessEntries(devInfo.value?.access, here));
  const current = $derived(currentEntry(entries));
  const hasHttpsIp = $derived(entries.some((entry) => entry.kind === 'https-ip'));
  const insecure = window.isSecureContext === false;

  /** Druh vstupu a příznaky pod adresou: „https na IP adrese · jsi tady · doporučená“. */
  function entryMeta(entry) {
    return [
      label('access.kind', entry.kind),
      entry.current ? t('access.menu.current') : null,
      entry.preferred ? t('access.menu.preferred') : null,
    ].filter(Boolean).join(' · ');
  }

  async function openBubble() {
    if (open) return;
    open = true;
    await tick();
  }

  function closeBubble(restoreFocus = true) {
    if (!open) return;
    open = false;
    if (restoreFocus) buttonEl?.focus();
  }

  function toggle() {
    if (open) closeBubble();
    else openBubble();
  }
</script>

<button
  type="button"
  class="dev-stripe__version-btn"
  bind:this={buttonEl}
  aria-haspopup="dialog"
  aria-expanded={open}
  aria-label={t('access.menu.ariaLabel', { origin: here })}
  title={t('access.menu.hint')}
  data-testid="address-menu"
  onclick={toggle}
>
  <Icon icon={isHttps(here) ? iconLock : iconAddress} size="sm" />
  <span class="dev-stripe__version">{originHost(here)}</span>
  {#if insecure}<span class="dev-stripe__badge">{t('access.badge.insecure')}</span>{/if}
  <span class="dev-stripe__caret"><Icon icon={iconChevronDown} size="sm" /></span>
</button>

{#if open}
  <Popover open={true} anchor={buttonEl} placement="bottom" width="27.5rem" onClose={() => closeBubble()} testid="address-menu-bubble">
    <div class="address" role="document">
      <h3 class="address__title">{t('access.menu.title')}</h3>
      <ul class="address__list">
        {#each entries as entry (entry.kind + entry.origin)}
          <li class="address__row" class:address__row--current={entry.current}>
            <span class="address__mark" aria-hidden="true">
              {#if entry.current}<Icon icon={iconConfirm} size="sm" />{/if}
            </span>
            <span class="address__body">
              {#if entry.current}
                <span class="address__origin" aria-current="true">{entry.origin}</span>
              {:else}
                <a class="address__origin" href={entryUrl(entry.origin, window.location)} data-testid="address-entry-{entry.kind}">{entry.origin}</a>
              {/if}
              <span class="dev-small dev-muted">{entryMeta(entry)}</span>
            </span>
          </li>
        {/each}
      </ul>
      {#if hasHttpsIp}
        <p class="dev-small dev-muted">{t('access.menu.httpsIpHint')}</p>
      {/if}
      {#if entries.length < 2}
        <p class="dev-small dev-muted">{t('access.menu.onlyOne')}</p>
      {:else}
        <p class="dev-small dev-muted">{t('access.menu.separate')}</p>
      {/if}
    </div>
  </Popover>
{/if}

<style>
  .address {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
    padding: var(--shpd-space-xs);
    color: var(--shpd-color-text);
    font-size: var(--shpd-font-size-sm);
  }
  .address p { margin: 0; }
  .address__title {
    margin: 0;
    padding-bottom: var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border-subtle);
    font-size: var(--shpd-font-size-base);
    font-weight: 600;
  }
  .address__list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
  }
  .address__row {
    display: flex;
    gap: var(--shpd-space-sm);
    align-items: baseline;
  }
  .address__mark {
    flex: none;
    width: 1rem;
    color: var(--shpd-color-success-text);
  }
  .address__body {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }
  .address__origin {
    font-family: var(--dev-font-mono);
    overflow-wrap: anywhere;
  }
  a.address__origin {
    color: var(--shpd-color-primary-text);
    text-decoration: none;
  }
  a.address__origin:hover { text-decoration: underline; }
  .address__row--current .address__origin { font-weight: 600; }
</style>
