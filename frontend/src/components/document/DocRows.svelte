<script>
  // Řádky dokladu (#139 D45, D48): jedna sdílená komponenta pro detail
  // dokladu i review (task 2 #139). Sekce = `SectionHeading` (`title`,
  // snippet `aside` → hromadné akce review v řádku nadpisu) a tabulka
  // v obalu `TableScroll` (D44). Jeden tvar dat z adaptéru
  // (`documentParts.js`): hotové texty, prázdná hodnota null → „—“ kreslí
  // komponenta; obsah buněk navíc dodávají snippety.
  //
  // Široký režim (kontejner ≥ 36 rem): sloupce Poz. · Popis · [Účet] ·
  // Množství · MJ · Cena za MJ · DPH · Celkem — hlavička body + strong bez
  // verzálek, čísla vpravo `tabular-nums` (CSS přenesené z detailu beze
  // změny); textový řádek = pořadí + popis přes zbytek.
  //
  // Složený režim (`@container doc-rows (max-width: 36rem)`): totéž DOM,
  // přepíná jen CSS — `thead` a buňky Účet / Množství / MJ / Cena / DPH
  // (`__wide`) `display: none`, zůstávají pořadí · popis · celkem vpravo;
  // v buňce popisu se pod textem ukáže řádek meta („množství jednotka ×
  // cena · sazba“ z `rowMetaLine`, nebo snippet `meta`), v širokém režimu
  // skrytý. Žádné měření šířky v JS, žádné dvojí vykreslení tabulky.
  // Kontejner je kořen komponenty na celou šířku a je **pojmenovaný**
  // (`container-name: doc-rows`): uvnitř detailu je kontejnerem
  // i `.shpd-docdetail` (strany pod sebe pod 30 rem) a nepojmenovaný dotaz
  // by se ptal nejbližšího předka; review má vlastní kontejner.
  //
  // Textový řádek má `colspan` přes všechny sloupce za pořadím (6, s Účtem
  // 7) v obou režimech — ve složeném režimu span zasahuje i skryté
  // sloupce, které už nemají žádnou vlastní buňku; Chromium jim dává
  // nulovou šířku, tabulka se neroztáhne (ověřeno měřením, viz task).
  //
  // Snippety (výchozí = text z řádku): `description(row, i)` — detail dává
  // odkaz na položku + `Badge` stavu, review text řádku, kód dodavatele,
  // značku položky a ⟲; `below(row, i)` — pod popisem v obou režimech
  // (řádek napárování); `account(row, i)` — přítomnost **zapíná sloupec
  // Účet**; `unit(row, i)`, `vat(row, i)` — hodnota se značkou;
  // `meta(row, i)` — řádek složeného režimu se „· účet“ a značkou.
  //
  // Past pro task 2: snippety `unit`, `vat`, `account` a `meta` jsou v DOM
  // dvakrát (široké buňky i řádek složeného režimu), vidět je jen jedno —
  // interaktivní prvky a `data-testid` review tak budou v DOM dvakrát,
  // testy musí hledat viditelný prvek.
  import { t } from '../../i18n/index.js';
  import SectionHeading from '../ui/SectionHeading.svelte';
  import TableScroll from '../ui/TableScroll.svelte';
  import { rowMetaLine } from './documentParts.js';

  let {
    title = '',
    rows = [],
    headingLevel = 3,
    aside = undefined,
    description = undefined,
    below = undefined,
    account = undefined,
    unit = undefined,
    vat = undefined,
    meta = undefined,
  } = $props();

  const DASH = '—';

  let textSpan = $derived(account ? 7 : 6);
</script>

{#snippet cellDescription(row, i)}
  {#if description}{@render description(row, i)}{:else}{row.description}{/if}
{/snippet}

<section class="shpd-doc-rows">
  <SectionHeading level={headingLevel} {aside}>{title}</SectionHeading>
  <TableScroll>
    <table class="shpd-doc-rows__table">
      <thead>
        <tr>
          <th>{t('document.row.position')}</th>
          <th>{t('document.row.description')}</th>
          {#if account}
            <th class="shpd-doc-rows__wide">{t('document.row.account')}</th>
          {/if}
          <th class="num shpd-doc-rows__wide">{t('document.row.quantity')}</th>
          <th class="shpd-doc-rows__wide">{t('document.row.unit')}</th>
          <th class="num shpd-doc-rows__wide">{t('document.row.unitPrice')}</th>
          <th class="num shpd-doc-rows__wide">{t('document.row.vat')}</th>
          <th class="num">{t('document.row.total')}</th>
        </tr>
      </thead>
      <tbody>
        {#each rows as row, i (row.key ?? i)}
          <tr>
            {#if row.isText}
              <!-- Textový řádek — jen popis přes zbytek šířky. -->
              <td>{row.pos}</td>
              <td colspan={textSpan}>{@render cellDescription(row, i)}</td>
            {:else}
              {@const metaText = meta ? '' : rowMetaLine(row)}
              <td>{row.pos}</td>
              <td>
                {@render cellDescription(row, i)}
                {#if meta || metaText !== ''}
                  <div class="shpd-doc-rows__meta">
                    {#if meta}{@render meta(row, i)}{:else}{metaText}{/if}
                  </div>
                {/if}
                {@render below?.(row, i)}
              </td>
              {#if account}
                <td class="shpd-doc-rows__wide">{@render account(row, i)}</td>
              {/if}
              <td class="num shpd-doc-rows__wide">{row.quantity ?? DASH}</td>
              <td class="shpd-doc-rows__wide">{#if unit}{@render unit(row, i)}{:else}{row.unit ?? DASH}{/if}</td>
              <td class="num shpd-doc-rows__wide">{row.unitPrice ?? DASH}</td>
              <td class="num shpd-doc-rows__wide">{#if vat}{@render vat(row, i)}{:else}{row.vat ?? DASH}{/if}</td>
              <td class="num">{row.total ?? DASH}</td>
            {/if}
          </tr>
        {/each}
      </tbody>
    </table>
  </TableScroll>
</section>

<style>
  /* Kořen = pojmenovaný kontejner na celou šířku (viz komentář nahoře);
     mezeru mezi sekcemi dává rodič. */
  .shpd-doc-rows {
    container-type: inline-size;
    container-name: doc-rows;
    font-size: var(--shpd-font-size-body);
  }

  .shpd-doc-rows__table {
    width: 100%;
    border-collapse: collapse;
  }

  .shpd-doc-rows__table th,
  .shpd-doc-rows__table td {
    padding: 0.375rem var(--shpd-space-xs);
    border-bottom: 1px solid var(--shpd-color-border);
    text-align: left;
  }

  /* Hlavička sloupce — body + strong: stejná velikost jako řádky, silnější,
     barvou textu, bez verzálek (není heading — ten je od #137 task 2b
     větší než řádky); čára pod ní silnější než mezi řádky. */
  .shpd-doc-rows__table th {
    font-size: var(--shpd-font-size-body);
    font-weight: var(--shpd-font-weight-strong);
    color: var(--shpd-color-text);
    border-bottom-color: var(--shpd-color-border-strong);
  }

  /* Čísla vpravo a nikdy zalomená — v úzkém obalu se tabulka posune
     (TableScroll, D44), částka se nerozpadne na dva řádky. */
  .shpd-doc-rows__table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }

  /* Řádek složeného režimu — role meta šedě; v širokém režimu skrytý. */
  .shpd-doc-rows__meta {
    display: none;
    font-size: var(--shpd-font-size-meta);
    color: var(--shpd-color-text-secondary);
  }

  /* Složený řádek pod 36 rem kontejneru (D45): bez hlavičky tabulky,
     bez širokých sloupců, meta řádek pod popisem; pořadí a celkem drží
     první řádek popisu. */
  @container doc-rows (max-width: 36rem) {
    .shpd-doc-rows__table thead,
    .shpd-doc-rows__table .shpd-doc-rows__wide {
      display: none;
    }

    .shpd-doc-rows__table td {
      vertical-align: top;
    }

    .shpd-doc-rows__meta {
      display: block;
    }
  }
</style>
