<script>
  // Widget volby shellu pro user-scope settings page (account.shell) —
  // vázaný přímo na shellStore, každá volba je okamžité uložení + soft
  // swap shellu (D4), mimo tlačítko Uložit (vzor ThemeField/LanguageField).
  //
  // Segmenty: „Podle aplikace" (follow DS defaultu) / jména shellů
  // (override). Při follow se pod segmenty ukazuje nota s efektivním
  // DS defaultem. Mobil volbu ignoruje (resolver v AppShellu, D5) —
  // widget to nekomentuje, hint pole říká „na desktopu".
  import { shellStore } from '../../stores/shell.svelte.js';
  import { t } from '../../i18n/index.js';
  import ShellSegments from './ShellSegments.svelte';

  let { label = undefined } = $props();

  const FOLLOW = 'follow';

  const options = [
    { value: FOLLOW,    labelKey: 'shell.followApp' },
    { value: 'sidebar', labelKey: 'shell.option.sidebar' },
    { value: 'classic', labelKey: 'shell.option.classic' },
    { value: 'wild',    labelKey: 'shell.option.wild' },
  ];

  const current = $derived(shellStore.follow ? FOLLOW : shellStore.override);

  function select(value) {
    if (value === FOLLOW) {
      shellStore.setFollow(true);
    } else {
      shellStore.setOverride(value);
    }
  }

  // Jméno efektivního shellu do noty při follow (DS default ?? sidebar).
  const effectiveLabel = $derived(t(`shell.option.${shellStore.effective}`));
</script>

<div class="shpd-shell-field">
  <ShellSegments value={current} {options} onSelect={select} {label} />

  {#if shellStore.follow}
    <div class="shpd-shell-field__note">
      {t('shell.followsApp', { shell: effectiveLabel })}
    </div>
  {/if}
</div>

<style>
  .shpd-shell-field {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-sm);
  }

  .shpd-shell-field__note {
    font-size: var(--shpd-font-size-sm);
    color: var(--shpd-color-text-secondary);
  }
</style>
