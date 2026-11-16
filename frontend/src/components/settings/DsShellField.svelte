<script>
  // Controlled widget DS defaultu shellu (app.shell, Nastavení aplikace).
  // Záměrně NEpíše do shellStore — to je user override; hodnota se mění
  // lokálně a ukládá tlačítkem Uložit stránky (vzor DsThemeField).
  // `onchange` emituje vždy celý objekt {shell, params}.
  import { KNOWN_SHELLS, DEFAULT_SHELL } from '../../utils/shell.js';
  import ShellSegments from './ShellSegments.svelte';

  let { value, onchange, label = undefined } = $props();

  const options = [
    { value: 'sidebar', labelKey: 'shell.option.sidebar' },
    { value: 'classic', labelKey: 'shell.option.classic' },
    { value: 'wild',    labelKey: 'shell.option.wild' },
  ];

  // Chybějící/neznámá hodnota se zobrazí jako default sidebar.
  const current = $derived(
    KNOWN_SHELLS.includes(value?.shell) ? value.shell : DEFAULT_SHELL
  );

  function select(shell) {
    // params v1 bez UI — nesou se dál, ať je budoucí volby nepřepíšou.
    onchange?.({ shell, params: value?.params ?? {} });
  }
</script>

<ShellSegments value={current} {options} onSelect={select} {label} />
