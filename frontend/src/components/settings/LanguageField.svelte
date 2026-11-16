<script>
  // Řízený widget jazyka pro settings page (field type `language`). Vázaný
  // na language store — volba volá language.setMode(), který zapíše volbu na
  // server (per-user) a reloadne stránku (jazyk se aplikuje přes bootstrap).
  // Přepínač je sdílený SegmentedControl (#149 D77); `label` = popisek
  // skupiny pro čtečku (popisek pole ze stránky nastavení).
  import { language, t } from '../../i18n/index.js';
  import SegmentedControl from '../ui/SegmentedControl.svelte';

  let { label = undefined } = $props();

  const options = [
    { value: 'cs',   label: t('sidebar.language.cs') },
    { value: 'en',   label: t('sidebar.language.en') },
    { value: 'auto', label: t('sidebar.language.auto') },
  ];
</script>

<SegmentedControl
  {options}
  value={language.mode}
  {label}
  onchange={(value) => language.setMode(value)}
/>
