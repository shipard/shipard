<script>
  // Karta strany dokladu (#139 D48, D50): sdílená prezentační část pro
  // detail dokladu i review (task 2 #139). Obsah podle D16 / D50: nadpis
  // karty (`Card` `title`), pod ním název entity (heading strong — detail
  // dává snippetem `name(party)` odkaz na osobu), řádek identifikátorů
  // (IČO / DIČ / VAT ID), adresa a účet jako běžné údaje barvou textu bez
  // tučného (D15), kontakty meta šedě s mezerou nad. Jeden tvar dat
  // z adaptéru (`documentParts.js`): hotové texty, popisky v `ids`
  // a `bank` nese adaptér; `party === null` → „—“.
  //
  // Review přidává snippety: `status()` = stav strany vpravo v nadpisu
  // karty (`Card` `aside`), `bankStatus()` = značka stavu účtu za číslem
  // (účet se pak ukáže i bez čísla — hodnota „—“, D98 importu), `footer()`
  // = „Založit z registru“ + chyba dole v kartě. Kontaktní osobu review
  // předá jako první položku `contacts` („Kontaktní osoba: …“), žádný
  // zvláštní prop. CSS přenesené z `DocumentDetail` beze změny vzhledu
  // (docs/design-system.md §12 Karta).
  import { t } from '../../i18n/index.js';
  import Card from '../ui/Card.svelte';

  let {
    title = '',
    party = null,
    name = undefined,
    status = undefined,
    bankStatus = undefined,
    footer = undefined,
  } = $props();

  const DASH = '—';

  let showBank = $derived(party !== null && (party.bank != null || bankStatus !== undefined));
</script>

<Card {title} aside={status}>
  {#if party}
    <div class="shpd-party-card__body">
      {#if party.name}
        <!-- Název entity má roli nadpisu obsahu karty (D16); odkaz na
             záznam dodává snippet `name`, tlačítko dědí písmo obalu. -->
        <div class="shpd-party-card__name">
          {#if name}{@render name(party)}{:else}{party.name}{/if}
        </div>
      {/if}
      {#if party.ids?.length > 0}
        <div class="shpd-party-card__ids">
          {#each party.ids as id}
            <span>{id.label}: {id.value}</span>
          {/each}
        </div>
      {/if}
      {#if party.address}
        <div class="shpd-party-card__address">{party.address}</div>
      {/if}
      {#if showBank}
        <div class="shpd-party-card__bank">
          <span>{party.bank?.label ?? t('document.party.bankAccount')}: {party.bank?.value ?? DASH}</span>
          {@render bankStatus?.()}
        </div>
      {/if}
      {#if party.contacts?.length > 0}
        <div class="shpd-party-card__contact">
          {#each party.contacts as contact}<span>{contact}</span>{/each}
        </div>
      {/if}
    </div>
  {:else}
    <div class="shpd-party-card__empty">{DASH}</div>
  {/if}
  {#if footer}
    <div class="shpd-party-card__footer">{@render footer()}</div>
  {/if}
</Card>

<style>
  .shpd-party-card__body {
    display: flex;
    flex-direction: column;
    gap: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-body);
  }

  /* Název entity má roli nadpisu obsahu karty (D16). */
  .shpd-party-card__name {
    font-size: var(--shpd-font-size-heading);
    font-weight: var(--shpd-font-weight-strong);
  }

  /* IČO / DIČ, adresa a účet jsou běžné údaje — body, barvou textu, bez
     zvýraznění (D15); dědí z těla karty. */
  .shpd-party-card__ids,
  .shpd-party-card__bank {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
  }

  /* Kontakty jsou doplněk — meta, šedé, s malou mezerou nad (D16). */
  .shpd-party-card__contact {
    display: flex;
    flex-wrap: wrap;
    gap: var(--shpd-space-xs) var(--shpd-space-md);
    margin-top: var(--shpd-space-xs);
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  .shpd-party-card__empty {
    color: var(--shpd-color-text-secondary);
    font-style: italic;
  }

  /* Patička karty (review: založení z registru) — pod tělem s mezerou. */
  .shpd-party-card__footer {
    margin-top: var(--shpd-space-sm);
  }
</style>
