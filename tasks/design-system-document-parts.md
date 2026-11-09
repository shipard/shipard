# Design system — sdílené části dokladu, detail dokladu na nich

**Stav:** hotovo — #139 task 1 (D44 obal tabulky, D45, D48, D50, D52, D55), 2026-11-09

## Kontext / Cíl

První task #139 (zastřešující #133). Detail dokladu (`DocumentDetail.svelte`)
a review modal (`DocumentExchangePreview.svelte`) kreslí totéž — karty stran,
řádky, rekapitulaci DPH a součty — každý vlastní kopií. Po pilotu #135 je
detail na typografických rolích, review ne. Než se review převede (task 2),
vzniknou sdílené prezentační části a detail dokladu se na ně přestaví —
vizuálně beze změny kromě nového složeného řádku na úzké šířce (D45).

**Cíl:**

1. `DetailHeader` v `components/ui/` vytažená z `ViewerDetail` (D48).
2. Sdílené části dokladu v `components/document/`: `PartyCard`, `DocRows`,
   `DocTotals` — prezentační, jeden tvar dat (hotové texty), obsah navíc
   přes snippety (D48, D50, D52).
3. Sdílený obal tabulky `TableScroll` v `components/ui/` — posun do strany,
   použitý v `DocRows` a `DocTotals` (D44). Ostatní tabulky převede #138
   task 3.
4. Řádky dokladu se složeným řádkem pod `36rem` kontejneru (D45).
5. Adaptér detailu (snake_case z backendu → tvar částí) jako čistá funkce
   s unit testy; `DocumentDetail` je jen skládá.

**Mimo rozsah:**

- review modal a náhled Spisovny — task 2 (`design-system-review-modal.md`);
  tady se jen navrhne API částí tak, aby je review mohl použít bez přestavby
  (snippety níže);
- `PropertyList` se snippetem hodnoty a „—“ pro prázdné pole (D51) — task 2;
- obal `TableScroll` u ostatních tabulek (obecný typ `table`, sub-tabulky,
  reporty) — #138 task 3;
- pevné rozměry v px (ikona hlavičky 40×40, `max-width` rekapitulace,
  `min-width` souhrnu) — #138 task 4 (D46); tady se jen přesunou beze změny;
- backend — kontrakt obsahu `document` se nemění.

## Návaznost

- **#133** — D7, D13–D17, D28, D29, D33, D39, D40, D43–D45, D48, D50, D52
- **#135** — pilot: `DocumentDetail` je referenční implementace §12
- **#137** — `SectionHeading` (snippet `aside`), `PropertyGroups`, `Card`,
  `Badge`, `Notice`
- **#138** task 1 — práh `36rem` už kontrola v pre-commitu povoluje
- **#139** task 2 — review a Spisovna na těchto částech

## Před implementací přečti

- `docs/design-system.md` §8 Karty, §8 Responzivita, §11 Typografie,
  §12 Detail záznamu (celé — Pořadí, Stavební prvky, Úzká šířka)
- `docs/frontend.md` → typ obsahu `document` (tabulka typů obsahu detailu)
- `frontend/src/components/viewer/DocumentDetail.svelte` — dnešní stav,
  komentáře proč
- `frontend/src/components/viewer/ViewerDetail.svelte` — hlavička
  (`.shpd-detail__header` a CSS k ní)
- `frontend/src/components/exchange/DocumentExchangePreview.svelte` —
  snippety `partyCard`, `rowMatchLine`, tabulka řádků a součty: **jen
  číst**, aby API částí pokrylo, co review potřebuje (tabulka snippetů níže)
- `frontend/src/components/ui/Card.svelte`, `SectionHeading.svelte`,
  `Badge.svelte`, `PropertyGroups.svelte` (vzor kontejneru na obalu)
- `scripts/check-css-units.py` — povolené prahy `@container`

## Rozhodnutí (potvrzená v #133)

- ✓ **D44** Každá tabulka v obsahu má vlastní obal s posunem do strany,
  vždy — sdílená komponenta. Stránka ani panel nikdy nerolují do strany.
- ✓ **D45** Řádky dokladu jsou jedna sdílená komponenta pro detail
  i review: sloupce a přepnutí na **složený řádek pod 36 rem** kontejneru,
  obsah buněk dodávají snippety. Složený řádek: nahoře pořadí, popis (se
  značkami) a celkem vpravo; pod tím „množství jednotka × cena · sazba“
  v roli meta (12 px, `text-secondary`) — v review navíc „· účet“ se
  značkou; u napárované položky třetí řádek s napárováním. Textový řádek
  jen popis. Ve složeném režimu bez hlavičky tabulky; na široké šířce
  hlavička jako detail dokladu (body, strong, bez verzálek). Hromadné akce
  review jsou v řádku nadpisu sekce (`SectionHeading` `aside`).
- ✓ **D48** Sdílené části dokladu v `components/document/`: `PartyCard`,
  `DocRows`, `DocTotals`; `DetailHeader` v `ui/` vytažená z `ViewerDetail`.
  Prezentační, jeden tvar dat (hotové texty); každý volající má adaptér
  (detail ze snake_case backendu, review z canonical). Interaktivní části
  review dodává review snippety.
- ✓ **D50** `PartyCard`: pořadí D16 (název → IČO / DIČ → adresa → účet →
  kontakty), hodnoty bez tučného (D15). Review navíc: stav strany vpravo
  v nadpisu karty, stav účtu za číslem, kontaktní osoba mezi kontakty
  (meta), „Založit z registru“ jako akce dole v kartě.
- ✓ **D52** `DocTotals`: rekapitulace DPH (tabulka v obalu D44) a souhrn
  Základ / DPH / Zaokrouhlení / Celkem (title, popisek D55) — tvar detailu. Review
  navíc: zdroj čísel v roli meta nad rekapitulací, kód DPH u sazby (meta),
  dvojice přenesené povinnosti; nesoulad s částkou dodavatele jako
  `Notice` warning pod souhrnem.
- ✓ **D55** Popisek řádku součtu je „Celkem“ (detail i review), ne
  „Celkem k úhradě“ — u pokladního dokladu, zaplacené faktury nebo
  interního dokladu by „k úhradě“ bylo zavádějící.

## Upřesnění tasku (potvrzená 2026-11-09)

- ✓ **`TableScroll` vzniká tady**, ne v #138 task 3 — `DocRows`
  a `DocTotals` ho potřebují (D44, D52) a #139 jde před #138 task 3,
  který ho jen rozšíří na ostatní tabulky.
- ✓ **Složený řádek čistě v CSS** — jedno DOM, ve složeném režimu se
  skryjí sloupce a ukáže řádek meta v buňce popisu; žádné měření šířky
  v JS. Cena: snippety buněk review jsou v DOM dvakrát (Pasti).
- ✓ **Štítek stavu položky v řádku = `Badge`** místo vlastní menší
  pilulky — o něco větší než dnes.

## 1. `DetailHeader` (`components/ui/DetailHeader.svelte`)

- Vytáhnout z `ViewerDetail` blok `.shpd-detail__header` i s CSS
  (`__header`, `__header-icon`, `__header-main`, `__title-row`, `__title`,
  `__subtitle`) — třídy přejmenovat na `shpd-detail-header__*`.
- Props: `title` (povinný), `subtitle` (volitelný), `badges`
  (`[{label, style}]`, kreslí `Badge`), `icon` (volitelný, už rozřešený —
  `ViewerDetail` předá `resolveIcon(detail.icon)`; review ikonu nemá, D49).
- `<h2>` titulku zůstává (review v modalu ho použije stejně).
- `ViewerDetail` hlavičku jen volá; podmínka `{#if detail.title}` zůstává
  v něm. Vzhled beze změny (40×40 ikona a `0.75rem` zůstávají v px / rem
  jako dnes — D46 je #138 task 4).

## 2. Tvar dat a adaptér detailu

Nový modul `components/document/documentParts.js` — čisté funkce, žádné
Svelte. Adaptér dostane `t` jako parametr (testy podstrčí identitu), aby
modul šel testovat v `node --test` bez i18n.

```js
// strana dokladu (PartyCard)
{ name, ids: [{label, value}], address, bank: {label, value} | null,
  contacts: [string] }
// řádek (DocRows)
{ key, pos, isText, description, quantity, unit, unitPrice, vat, total,
  ...extra }   // extra = co si volající nese pro snippety (item_id, item_state)
// součty (DocTotals)
{ recap: [{ key, rate, base, tax, total }],
  summary: { base, vat, rounding, total },   // texty i s měnou
  domestic }                                 // řádek v domácí měně | null
```

- `detailParty(party, t)` — `ids` v pořadí IČO, DIČ, VAT ID (VAT ID jen když
  se liší od DIČ, jako dnes), `address` přes dnešní `formatAddress`
  (`display_line` má přednost), `bank` z `account_number ?? iban`,
  `contacts` e-mail a telefon. `null` → `null` (karta ukáže „—“).
- `detailRows(rows)` — `pos` = `order_pos ?? i + 1`, `isText` = `kind === 0`,
  `vat` = `"21 %"` z `vat_pct` (null → `null`), prázdné hodnoty `null`
  (pomlčku kreslí komponenta, ne adaptér); `item_id` a `item_state`
  projdou v `extra`.
- `detailTotals(vatRecap, totals)` — hodnoty spojené s měnou jako dnes
  („1 000,00 CZK“), `rounding` jen když je nenulové (dnes `{#if
  totals.rounding}`), `domestic` = „základ + DPH = celkem měna“ z
  `totals.dom`.
- `rowMetaLine(row)` — text druhého řádku složeného režimu: „množství
  jednotka × cena · sazba“; chybějící části se vynechají i s oddělovačem
  (bez ceny: „2 ks · 21 %“), nic → `''`.
- Popisky (`IČO`, nadpisy sloupců, `Základ` …) přesunout z
  `viewer.document.*` do nového jmenného prostoru `document.*` (cs i en),
  nepoužívané `viewer.document.*` klíče smazat (grep). `exchange.preview.*`
  duplicity smaže task 2.

## 3. `PartyCard` (`components/document/PartyCard.svelte`)

Obsah karty podle D16 / D50 přenesený ze snippetu `partyCard` v
`DocumentDetail` (CSS beze změny vzhledu): `Card` s nadpisem `title`,
pod ním název (heading strong), řádek identifikátorů, adresa, účet,
kontakty (meta šedě s mezerou nad). Hodnoty bez `<strong>`.

| Prop / snippet | Detail | Review (task 2) |
|---|---|---|
| `title`, `party` | ano | ano |
| `name(party)` | odkaz na osobu (`button` jako dnes) | — (výchozí text) |
| `status()` | — | stav strany vpravo v nadpisu (`Card` `aside`) |
| `bankStatus()` | — | značka stavu účtu za číslem |
| `footer()` | — | „Založit z registru“ + chyba |

- Účet se ukáže i bez textu účtu, když je předaný `bankStatus` (review
  dnes kreslí odznak účtu i bez čísla, D98 importu) — `bank.value` pak „—“.
- Kontaktní osobu review předá jako první položku `contacts`
  („Kontaktní osoba: …“) — žádný zvláštní prop.

## 4. `TableScroll` (`components/ui/TableScroll.svelte`)

- Obal `overflow-x: auto`, `max-width: 100%`, obsah snippet `children`.
  Žádný vzhled navíc (čáry a odsazení dává tabulka).
- Komentář s D44: každá tabulka v obsahu má vlastní obal, stránka ani
  panel nerolují do strany; rozšíření na ostatní tabulky je #138 task 3.
- Bez `tabindex`: posuvný obal bez fokusovatelného obsahu dělá
  fokusovatelným prohlížeč sám (Chromium, Firefox) a obal s `tabindex`
  by přidal zastávku tabulátoru ke každé tabulce. Volitelný prop `label`
  → `role="region"` a `aria-label`.

## 5. `DocRows` (`components/document/DocRows.svelte`)

Sekce Řádky dokladu: `SectionHeading` (prop `title`, snippet `aside` →
`SectionHeading` `aside`) a tabulka v `TableScroll`.

**Široký režim** (kontejner ≥ 36 rem): sloupce Poz. · Popis · [Účet] ·
Množství · MJ · Cena za MJ · DPH · Celkem — hlavička body + strong bez
verzálek (dnešní CSS detailu), čísla vpravo `tabular-nums`. Textový řádek:
pořadí + popis přes zbytek.

**Složený režim** (`@container (max-width: 36rem)`): jedna tabulka, stejné
DOM, přepíná jen CSS (žádné měření v JS, žádné dvojí vykreslení tabulky):

- `thead` skrytý; skryté buňky Účet, Množství, MJ, Cena, DPH;
- zůstávají tři sloupce: pořadí · popis · celkem (vpravo);
- v buňce popisu pod textem prvek s `rowMetaLine` — v širokém režimu
  skrytý, ve složeném viditelný, role meta (`--shpd-font-size-meta`,
  `--shpd-color-text-secondary`);
- snippet `below` (napárování) je pod popisem v obou režimech.

Kontejner: vlastní obal komponenty na celou šířku, `container-type:
inline-size`, **pojmenovaný** (`container-name: doc-rows`), dotaz
`@container doc-rows (max-width: 36rem)` — uvnitř detailu je kontejnerem
i `.shpd-docdetail` a review bude mít vlastní. `TableScroll` je uvnitř
kontejneru.

| Snippet | Výchozí | Detail | Review (task 2) |
|---|---|---|---|
| `description(row, i)` | `row.description` | odkaz na položku + `Badge` stavu položky | text řádku, kód dodavatele, značka položky, ⟲ |
| `below(row, i)` | nic | — | řádek napárování (`rowMatchLine`) |
| `account(row, i)` | sloupec není | — | účet + značka; **zapne sloupec Účet** |
| `unit(row, i)` | `row.unit` | — | jednotka + značka |
| `vat(row, i)` | `row.vat` | — | sazba, kód (meta), značka kódu DPH |
| `meta(row, i)` | `rowMetaLine(row)` | — | řádek složeného režimu se „· účet“ a značkou |

- Prázdná hodnota buňky = „—“ (komponenta, jako dnes).
- Štítek stavu položky (archiv / koš) v detailu kreslí sdílený `Badge`
  (`style` = `item_state.style`, koš přeškrtnutý dělá `Badge` sám) místo
  vlastní třídy `shpd-docdetail__row-badge` (§12: štítek v detailu je
  sdílená komponenta). `Badge` je o něco větší pilulka než dnešní štítek
  v řádku — posoudit v prohlížeči.

## 6. `DocTotals` (`components/document/DocTotals.svelte`)

Rekapitulace DPH vlevo (tabulka v `TableScroll`, `max-width` a `flex`
jako dnes), souhrn vpravo: Základ, DPH, Zaokrouhlení (jen když je),
Celkem (title, čára nad; popisek „Celkem“, D55), řádek v domácí měně (meta šedě). Hodnoty strong
tokenem. CSS přenesené z `DocumentDetail` beze změny vzhledu.

| Snippet | Detail | Review (task 2) |
|---|---|---|
| `before()` | — | zdroj čísel (převzaté / přepočtené) nad rekapitulací, meta |
| `rate(r)` | výchozí `r.rate` | sazba, kód DPH (meta), „přenesená povinnost“ u dvojice |
| `after()` | — | `Notice` warning při nesouladu s částkou dodavatele |

Řádek rekapitulace smí nést vlastní data pro snippet (`...extra`, např.
příznak dvojice) — `DocTotals` je nečte.

Volitelný prop `totalTestId` → `data-testid` na řádku Celkem (review má
dnes `review-total`, čte ho proklikání sady #103).

## 7. `DocumentDetail` na sdílených částech

- Strany: dvě `PartyCard` v dnešní mřížce `.shpd-docdetail__parties`
  (dva sloupce, pod `30rem` pod sebe) se snippetem `name` (odkaz na osobu).
- Údaje: `PropertyGroups` beze změny.
- Řádky: `DocRows` se snippetem `description` (odkaz na položku, `Badge`).
- Součty: `DocTotals`.
- Z `DocumentDetail` zmizí CSS karet, tabulek a součtů (je v částech);
  zůstává kořen, mřížka stran a komentáře, proč.
- Data jdou přes adaptér z §2 (`$derived`).

## 8. Testy

- `frontend/tests/Unit/documentParts.test.mjs`: `detailParty` (pořadí
  `ids`, VAT ID shodné s DIČ vynechané, `display_line`, účet z
  `account_number` / `iban`, `null`), `detailRows` (textový řádek,
  `order_pos` chybí → pořadí, `vat` z `vat_pct`, `null` místo prázdných
  hodnot, `extra` projde), `detailTotals` (měna, zaokrouhlení 0 / prázdné
  vynechané, domácí měna), `rowMetaLine` (plný, bez ceny, bez jednotky,
  textový řádek → `''`).

## 9. Dokumentace

- `docs/design-system.md`:
  - §12 Stavební prvky: karta strany = `PartyCard`, řádky = `DocRows`
    (sloupce, složený řádek, snippety), součty = `DocTotals`, hlavička =
    `DetailHeader` — sdílené pro detail i review (D48); pravidlo
    „nový prvek dokladu je sdílená část, ne kopie“;
  - §12 Tabulka: obal `TableScroll` (D44);
  - §12 Úzká šířka: složený řádek pod `36rem` je hotový (pojmenovaný
    kontejner `doc-rows`, proč), věta „obal tabulky řeší #138 task 3“
    upravit — obal existuje, #138 ho rozšíří na ostatní tabulky;
  - §13 Soubory: `DetailHeader`, `TableScroll`, `components/document/*`,
    `documentParts.js`; řádek `DocumentDetail` (skládá sdílené části).
- `docs/frontend.md` → typ obsahu `document`: detail kreslí sdílené části
  dokladu přes adaptér `documentParts.js`; kontrakt beze změny.
- `tasks/README.md` — řádek v sekci Design system.

## Commity

1. `refactor(frontend): hlavička detailu jako DetailHeader (#139, D48)` — §1
2. `feat(frontend): sdílené části dokladu a obal tabulky, detail dokladu na nich (#139, D44, D48, D50, D52)`
   — §2, §3, §4, §6, §7 bez řádků, §8 bez `rowMetaLine`
3. `feat(frontend): sdílené řádky dokladu se složeným řádkem pod 36 rem (#139, D45, D48)`
   — §5, řádky v §7, `rowMetaLine` v §8
4. `docs: sdílené části dokladu (#139, D44, D45, D48)` — §9, `**Stav:**`
   tasku, `python3 scripts/tasks-index.py`

## Ověření

```bash
cd frontend && npm test && npm run check:i18n && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
grep -rn --include=*.svelte "shpd-docdetail__\(rows\|vat-recap\|totals\|party-\)" frontend/src
```

Poslední grep: v `DocumentDetail` nic z přesunutých tříd (jen `parties`).

V prohlížeči nad zdrojem dat ukázkové sady (třída `free`), detail faktury
přijaté i vydané:

- **beze změny proti dnešku** (okno 1440, panel vedle seznamu): hlavička,
  karty stran, údaje, tabulka řádků, rekapitulace a součty — porovnat se
  snímkem před změnou;
- **okno 1280** (panel ≈ 587 px): řádky jako tabulka;
- **drawer / okno 900** a **telefon 390**: složené řádky — pořadí, popis,
  celkem vpravo, pod popisem „množství jednotka × cena · sazba“ šedě, bez
  hlavičky tabulky; textový řádek jen popis a tabulka se nerozšiřuje;
- `ViewerDetailModal` (detail z jiného místa): stejné chování podle šířky
  modalu;
- doklad s řádkem na archivovanou / smazanou položku (štítek `Badge`,
  odkaz na položku), doklad v cizí měně (řádek v domácí měně),
  se zaokrouhlením, bez partnera (karta „—“, titulek číslo);
- klik na partnera a na položku otevře formulář jako dnes;
- při písmu prohlížeče 20 px se složený režim zapne dřív (rem), nic
  nepřetéká; tabulka v širokém režimu se při nedostatku místa posouvá
  ve svém obalu, ne panel.

## Hotovo když

- [x] `DetailHeader` v `ui/`, `ViewerDetail` ho používá, vzhled beze změny
- [x] `PartyCard`, `DocRows`, `DocTotals` v `components/document/`,
      `TableScroll` v `ui/`
- [x] `DocumentDetail` skládá sdílené části přes adaptér, vlastní CSS jen
      kořen a mřížka stran
- [x] složený řádek pod `36rem` (pojmenovaný kontejner), bez hlavičky
      tabulky, druhý řádek meta
- [x] snippety pro review podle tabulek v §3, §5, §6 (task 2 je jen použije)
- [x] unit testy `documentParts.test.mjs`, `npm test`, `check:i18n`, kontroly CSS
- [x] `docs/design-system.md` §12, §13, `docs/frontend.md`
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Odchylky od zadání a výsledek ověření (2026-11-09)

- `detailParty` nese navíc `person_id` (extra pro snippet `name` detailu —
  odkaz na osobu; v review `null`), jinak tvar dat podle §2.
- Odkazy na záznam v detailu mají jednu třídu `.shpd-docdetail__link`
  (písmo dědí z místa: název strany heading + strong, popis řádku body);
  štítek stavu položky je `Badge` v obalu `.shpd-docdetail__row-state`
  (jen mezera a zarovnání).
- Čísla v řádcích i rekapitulaci se nelámou (`white-space: nowrap`) —
  v úzkém obalu se tabulka posune (D44) místo rozpadu částky na dva
  řádky. Jediná viditelná změna na široké šířce: při 1280 px (panel
  587 px) se už neláme „21 %“ ani Celkem, zalamuje se popis; 1440 px je
  pixelově shodné s původním stavem.
- Celý jmenný prostor `viewer.document.*` přejmenovaný na `document.*`
  (i `meta.*` — nic jiného ho nepoužívalo), nový klíč `document.row.account`.
- `DocTotals`: `before` nad flex řádkem (rekapitulace + souhrn), `after`
  pod ním; bez `summary` jen rekapitulace; hodnota `null` → „—“ bez měny.
- Ověřeno v Chromiu (Playwright, zdroje dat `nham-q…` a `zey0-g…`):
  1440 / 1280 px pixelově shodné (kromě nowrap výše), 900 px (panel
  208 px) a 390 px složené řádky bez rolování panelu do strany, písmo
  20 px přepne složený režim už při 1280 px; fantomové sloupce textového
  řádku (`colspan` přes skryté buňky) mají nulovou šířku. **Neověřeno:**
  Firefox (na stroji není), `ViewerDetailModal`, doklad s archivovanou /
  smazanou položkou, v cizí měně a bez partnera (v sadách nebyl) —
  ruční ověření.

## Pasti

- **Nejbližší kontejner vyhrává.** `DocRows` je uvnitř `.shpd-docdetail`
  (kontejner pro strany) — dotaz musí být pojmenovaný, jinak se zeptá
  špatného předka. Kontejner na obalu na celou šířku, ne na tabulce.
- **Textový řádek ve složeném režimu.** Buňka s `colspan` přes skryté
  sloupce nesmí založit prázdné sloupce navíc a roztáhnout tabulku — ve
  složeném režimu jí dát `colspan` jen přes popis a celkem (nebo ověřit
  v prohlížeči, že skryté buňky sloupce nezakládají). Ověřit v Chromiu
  i Firefoxu.
- **Skryté buňky a snippety review.** Snippety `unit`, `vat`, `account`
  a `meta` jsou v DOM obojí (široké buňky i řádek složeného režimu), jen
  jedno je vidět. Pro task 2: interaktivní prvky a `data-testid` v review
  budou v DOM dvakrát — testy musí hledat viditelný prvek. Tady jen
  zmínit v komentáři komponenty.
- **Hodnoty jsou hotové texty.** Adaptér ani komponenta nic neformátují
  (čísla, měna, datum posílá backend zformátované) — `rowMetaLine` jen
  skládá texty.
- **Vzhled detailu se nemá měnit.** Přesun CSS 1 : 1; jediné záměrné
  změny jsou složený řádek a `Badge` u stavu položky. Rozměry v px nechat
  (D46 je #138 task 4).
- **JS řetězce** jen s ASCII uvozovkami; typografické „…“ jen v textech
  katalogu.
