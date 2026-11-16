<script>
  // Formulářová komponenta `docParties` (#150 D85): fakturační údaje
  // v tabu formuláře dokladu — dvě karty strany `PartyCard` jako v detailu
  // dokladu, se stejnými nadpisy a stejným adaptérem (`detailParty`).
  // Params `{supplier, customer}` jsou zmrazené snapshoty stran ve tvaru
  // obsahu `document` detailu, bez `person_id` — formulář ukazuje zmrazené
  // údaje, jméno není odkaz. Strana bez snapshotu = karta „—“.
  //
  // Rozložení podle `DocumentDetail`: dva sloupce, pod 30 rem kontejneru
  // pod sebou (práh D3). Obal je kontejner pro @container; každá karta má
  // výšku podle svého obsahu (`align-items: start`). Komponenta je
  // v `flowFormComponents` — sloupec formuláře se kvůli ní neroztahuje.
  import { t } from '../../i18n/index.js';
  import PartyCard from '../document/PartyCard.svelte';
  import { detailParty } from '../document/documentParts.js';

  let { params = {} } = $props();

  let supplier = $derived(detailParty(params.supplier ?? null, t));
  let customer = $derived(detailParty(params.customer ?? null, t));
</script>

<div class="shpd-form-doc-parties">
  <div class="shpd-form-doc-parties__grid">
    <PartyCard title={t('document.section.supplier')} party={supplier} />
    <PartyCard title={t('document.section.customer')} party={customer} />
  </div>
</div>

<style>
  .shpd-form-doc-parties {
    container-type: inline-size;
  }

  .shpd-form-doc-parties__grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--shpd-space-md);
    align-items: start;
  }

  @container (max-width: 30rem) {
    .shpd-form-doc-parties__grid {
      grid-template-columns: 1fr;
    }
  }
</style>
