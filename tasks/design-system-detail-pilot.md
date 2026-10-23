# Design system — pilot: detail dokladu a typografické role

**Stav:** hotovo — #135, implementace a docs 2026-10-23 (D13–D16); ověření v prohlížeči dle § Ověření

## Kontext / Cíl

Část #135 zastřešující issue #133. Detail faktury míchá šest velikostí
písma (11–20 px), čtyři tloušťky, verzálky na čtyřech úrovních a šedé
popisky s tučnými hodnotami — nic nevystupuje jako důležité a čte se
špatně. Detail se prošel po sekcích a návrh vedle současného stavu byl
odsouhlasen (komentář v #135, rozhodnutí D13–D16 v #133).

**Cíl:**

1. Typografické role jako tokeny — tři velikosti, dvě tloušťky, žádné
   verzálky (D6, D15).
2. Detail dokladu (`DocumentDetail.svelte`) podle návrhu.
3. Hlavička dokladu: titulkem je protistrana, číslo v podtitulku, bez
   štítku typu (D13, D14) — změna na backendu.
4. Sdílená hlavička, taby a obecné typy obsahu ve `ViewerDetail.svelte`
   podle stejných rolí — ať mají všechny detaily stejné písmo hned, ne
   až po převodu v #136.
5. Pravidla do `docs/design-system.md`: kapitoly Typografie a Detail
   záznamu.

**Mimo rozsah:**

- lišta akcí detailu (zůstává nad hlavičkou a beze změny);
- štítky typu v hlavičkách jiných viewerů a povinná hlavička — kontrakt
  detailu #136 (D13 tam platí pro všechny);
- přílohy v detailu jiných tabulek (#136, D8);
- složené řádky dokladu na úzké šířce a posun tabulek (#138, D7);
- písmo mimo detail (seznamy, formuláře, dashboard) — role se tam
  zavedou postupně, tento task je jen připraví jako tokeny;
- `AttachmentGrid.svelte` (dlaždice příloh beze změny).

## Návaznost

- **#133** — D1 (jednotky), D3 (container queries), D6 (16 px), D13–D16
- **#134** / `tasks/design-system-foundations.md` — tokeny v rem,
  `check-css-units.py`
- **#136** kontrakt detailu (hlavička a přílohy pro všechny viewery),
  **#138** responzivita

## Před implementací přečti

- `docs/design-system.md` — §1, §5 (badge, API kontrakt detailu), §6,
  kapitola Jednotky a mezery
- `frontend/src/components/viewer/DocumentDetail.svelte` — celý
- `frontend/src/components/viewer/ViewerDetail.svelte` — hlavička, taby,
  `renderContent` (`properties`, `table`, `heading`) a jejich CSS
- `frontend/src/styles/variables.css` — škála `--shpd-font-size-*`
- `modules/docs/core/src/DocsHeadsViewer.php` — `renderDetail()`,
  `buildDetailHeader()`, `buildDetailParties()`, `buildDetailMeta()`
- `tests/Unit/Module/Docs/Core/DocsHeadsViewerDetailTest.php`
- `frontend/src/i18n/cs.js`, `en.js` — klíče `viewer.document.*`

## Rozhodnutí (potvrzená v #133)

- ✓ **D13** Hlavička detailu neopakuje kontext — štítek typu záznamu
  z hlavičky pryč, typ nese ikona.
- ✓ **D14** Titulek hlavičky dokladu = protistrana, číslo dokladu
  v podtitulku; doklad bez partnera má titulkem číslo.
- ✓ **D15** Popisek a hodnota stejnou barvou i tloušťkou, liší se
  umístěním; údaje se tučně nezvýrazňují; verzálky jen ve větších
  nadpisech (v detailu žádné).
- ✓ **D16** (upřesnění D15 po návrhu) Název entity v kartě strany je
  tučně — má roli nadpisu obsahu karty. Adresa je běžný údaj (za IČO /
  DIČ, normální barvou); šedá a menší jsou jen kontakty (e-mail,
  telefon) a ostatní doplňky.

## 1. Typografické role (`variables.css`)

Role odkazují na existující škálu, žádná nová velikost nevzniká:

```css
/* Typografické role (#135, docs/design-system.md → Typografie).
   Komponenty používají role, ne škálu, tam kde role sedí. */
--shpd-font-size-title:   var(--shpd-font-size-lg);  /* 1.125rem — titulek detailu, Celkem */
--shpd-font-size-heading: var(--shpd-font-size-sm);  /* 0.875rem — nadpis sekce, název entity, hlavička sloupce */
--shpd-font-size-body:    var(--shpd-font-size-sm);  /* 0.875rem — popisky i hodnoty, řádky tabulek */
--shpd-font-size-meta:    var(--shpd-font-size-xs);  /* 0.75rem — doplňky, podtitul, štítek */
--shpd-font-weight-normal: 400;
--shpd-font-weight-strong: 600;
```

`heading` a `body` mají dnes stejnou velikost a liší se tloušťkou;
jsou to dvě role, aby šly v budoucnu rozdělit jedním místem.

| Role | Velikost | Tloušťka | Barva |
|---|---|---|---|
| title | 1.125rem | strong | text |
| heading | 0.875rem | strong | text |
| body | 0.875rem | normal | text |
| meta | 0.75rem | normal | text; šedá (`--shpd-color-text-secondary`) jen u kontaktů a doplňků |

Hodnoty upřesněné při ověření 2026-10-23: základní text 14 px místo 16 px
(D6 bylo přestřelené), ostatní role o stupeň škály níž, aby zůstal poměr.

## 2. Hlavička dokladu (backend, `DocsHeadsViewer::buildDetailHeader`)

- `title` = `partner_name` z `renderDetail()` (sloupec `partner` je
  protistrana v obou směrech). Prázdný / `null` (doklad bez partnera,
  visící FK) → číslo dokladu, jako dnes, a `'—'` když chybí i číslo.
- `subtitle` = číslo dokladu a text dokladu spojené ` · `
  (`FP-2026-0142 · Kancelářské potřeby`); chybí-li jedno, jen to druhé;
  chybí-li obojí, `null`. U dokladu bez partnera (titulkem je číslo)
  zůstává `subtitle` = jen text dokladu.
- `badges` = jen stav dokladu; štítek typu (`resolveDocTypeLabel`) se
  z hlavičky odstraní. Metodu nemazat, pokud ji používá něco dalšího.
- `icon` beze změny.
- `DocsHeadsViewerDetailTest`: přepsat
  `testHeaderCarriesTypeNumberTextAndStateBadge` a přidat případy:
  s partnerem, bez partnera, bez textu, bez čísla.

Titulek se propisuje i do horní lišty na mobilu (`Viewer.svelte`,
`layoutStore.setScreenSurface({ title: detail?.title })`) — tam bude
nově partner, což je žádoucí.

## 3. Detail dokladu (`DocumentDetail.svelte`)

Celý obsah na rolích z kroku 1; v souboru nezůstane žádný literál
`font-size`, žádné `text-transform: uppercase` ani `letter-spacing`.
Kořen `.shpd-docdetail`: `font-size: var(--shpd-font-size-body)`,
mezera mezi sekcemi `--shpd-space-lg`.

**Mrtvá hlavička.** Odstranit `header` (`$derived(content?.header)`),
blok `{#if header}` a CSS `.shpd-docdetail__header`, `__type-badge`,
`__title`, `__subtitle`, `__state-badge`. Backend `content.header`
neposílá (test to hlídá).

**Strany** (karty s rámečkem zůstávají, `padding` `0.75rem`). Pořadí
v kartě:

1. nadpis karty (Dodavatel / Odběratel) — heading;
2. název — heading (tučně), odkaz na osobu modře jako dnes;
3. IČO / DIČ / VAT ID — body, `IČO: 12345678`, bez `<strong>`;
4. adresa — body (nově za identifikátory, ne šedá);
5. bankovní účet — body, bez `<strong>`;
6. e-mail a telefon — meta, šedá, s malou mezerou nad (`--shpd-space-xs`).

**Údaje dokladu** ve dvou skupinách vedle sebe, každá s nadpisem
(heading) a mřížkou popisek–hodnota (`grid-template-columns:
max-content 1fr`, oba sloupce body):

- **Data**: Vystaveno, Splatnost, DUZP, Účetní datum (DUZP nově před
  účetním datem);
- **Platba**: Způsob platby, Měna, Kurz, Pokladna, Plátce, Variabilní
  symbol, Specifický symbol, Konstantní symbol.

`META_FIELDS` rozdělit na dvě pole; prázdné hodnoty se jako dnes
nevykreslí, prázdná skupina se nevykreslí celá. Nové klíče
`viewer.document.section.dates` (Data / Dates) a
`viewer.document.section.payment` (Platba / Payment) v `cs.js` i `en.js`.

**Řádky a rekapitulace DPH.** Nadpis „Řádky dokladu“ = heading.
Tabulky body; hlavička sloupců heading (`font-size` body,
`font-weight` strong, barva textu, bez verzálek), čára pod hlavičkou
`--shpd-color-border-strong`. Buňky `padding: 0.375rem
var(--shpd-space-xs)`. Štítek stavu položky v řádku = meta. Odkaz na
položku beze změny.

**Součty.** Struktura beze změny: hodnoty tučně, ale přes
`--shpd-font-weight-strong` (ne výchozích 700 z `<strong>`); řádek
Celkem = title. Řádek v domácí měně = meta, šedá. Rekapitulace vlevo
se nezvýrazňuje.

**Přílohy.** Nadpis skupiny = heading bez verzálek (kód zprávy jako
odkaz, datum normální tloušťkou). Přepínač Miniatury / Velké náhledy se
přesune do řádku s nadpisem první skupiny (vpravo), záporný okraj
`.shpd-docdetail__att-toolbar` zanikne. Přepínač má velikost meta.

**Úzká šířka — container query (D3).** Na `.shpd-docdetail`
`container-type: inline-size`. Pod `30rem` šířky kontejneru jdou
strany i skupiny údajů pod sebe. Nahrazuje `@media (max-width: 600px)`
u stran i oba identické bloky `@media (max-width: 768px)` u mřížky
údajů — oba se smažou.

## 4. Sdílený detail (`ViewerDetail.svelte`)

- **Hlavička**: titulek = title; podtitul = meta, barva textu (ne
  šedá); štítky `.shpd-detail__badge` = meta, normální tloušťka,
  `padding: 0.125rem 0.625rem`; odsazení hlavičky `0.75rem
  var(--shpd-space-md)`, mezera ikona–text `0.75rem`. Ikona beze změny.
- **Taby**: body (`1rem`), aktivní strong.
- **Obecný obsah** podle stejných rolí: `.shpd-detail__group-title`
  a `__content-heading` = heading; `.shpd-detail__prop-label`
  a `__prop-value` = body, popisek barvou textu (dnes šedý — D15);
  `.shpd-detail__table` = body, `__th` heading; Odeslaná pošta
  (`__sent-*`) = body / meta. Rozvržení se nemění.
- Literály `font-size` v souboru nahradit rolemi nebo tokeny škály
  (`12px`, `0.75rem`, `0.7em` u šipky dropdownu může zůstat jako em —
  řídí se textem tlačítka).
- **Lišta akcí** a tlačítka dropdownu beze změny.

## 5. Dokumentace

`docs/design-system.md`:

- nová kapitola **Typografie**: role (tabulka z kroku 1), pravidla D15 +
  D16, „jedna tučná úroveň“ (strong = 600, `<strong>` vždy s tokenem),
  verzálky jen ve větších nadpisech, šedá jen pro doplňky; nové
  komponenty používají role, ne literály;
- nová kapitola **Detail záznamu**: pořadí lišta akcí → hlavička →
  taby → obsah; hlavička (D13, D14 — titulek je to, podle čeho uživatel
  záznam pozná, štítky jen pro stav a vlastnosti, které nejsou zřejmé
  z kontextu); sekce s nadpisem; popisek–hodnota v mřížce; tabulky;
  součty; přílohy (D8 — konec Přehledu); úzká šířka přes container
  query; doklad jako referenční implementace (`DocumentDetail.svelte`);
- §5 API kontrakt: příklad `detail` s titulkem / podtitulkem podle D14
  a poznámkou, že štítek typu se neposílá (D13);
- §10 Soubory: `DocumentDetail.svelte`.

`help/`: projít stránky, které popisují detail dokladu (hlavička, číslo,
štítek typu) — `grep -rn "hlavič\|štítek" help/` — a upravit, pokud
hlavičku popisují.

## Commity

1. `feat(frontend): typografické role v tokenech (#135)` — krok 1
2. `feat(docs.core): hlavička detailu dokladu — partner jako titulek, bez štítku typu (#135)` — krok 2 + test
3. `feat(frontend): detail dokladu podle typografických rolí (#135)` — krok 3 + i18n
4. `feat(frontend): hlavička, taby a obecný obsah detailu podle rolí (#135)` — krok 4
5. `docs(design-system): typografie a detail záznamu (#135)` — krok 5 +
   `**Stav:**` + `python3 scripts/tasks-index.py`

## Ověření

- `vendor/bin/phpunit --filter DocsHeadsViewerDetailTest`
- `cd frontend && npm run build && npm test && npm run check:i18n`
- `python3 scripts/check-css-units.py`, `python3 scripts/check-css-tokens.py`
- `grep -nE "font-size: [0-9]|uppercase|letter-spacing" frontend/src/components/viewer/DocumentDetail.svelte` → nic
- V prohlížeči na vývojovém zdroji dat třídy `free` (světlý i tmavý
  vzhled): detail přijaté a vydané faktury, pokladního dokladu
  a interního dokladu bez partnera (titulek = číslo); detail v modalu
  (`ViewerDetailModal`, např. z dashboardu); detail zakázky a osoby
  (obecné `properties` s novým písmem); zúžení panelu detailu pod ~480 px
  → strany a skupiny údajů pod sebe; mobilní šířka → horní lišta
  s partnerem.

## Hotovo když

- [x] tokeny rolí ve `variables.css`
- [x] hlavička dokladu: partner, číslo v podtitulku, jen štítek stavu; testy
- [x] `DocumentDetail.svelte` podle kroku 3, bez mrtvé hlavičky, bez
      literálů písma, verzálek a duplicitních `@media`
- [x] `ViewerDetail.svelte` podle kroku 4
- [x] kapitoly Typografie a Detail záznamu v `docs/design-system.md`,
      §5 a §10 aktualizované, `help/` zkontrolované
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Poznámky k implementaci

- **Práh container query 30 rem (480 px), ne 40 rem** — panel vedle
  seznamu má obsah široký šířka okna − 693 px a drawer 528 px, takže
  640 px stohovalo strany i skupiny údajů na běžných šířkách okna
  (ověření 2026-10-23).
- **Základní text 14 px, ne 16 px** — při ověření v prohlížeči bylo 16 px
  (D6) přestřelené; role se změnily jen ve `variables.css` (title `lg`,
  heading a body `sm`, meta `xs`), komponenty beze změny. 14 px se drží
  všude dál.

- Kapitoly v `docs/design-system.md` jsou **§11 Typografie** a **§12 Detail
  záznamu** (po #134 je §10 Jednotky a mezery); Soubory jsou §13, Plánovaná
  rozšíření §14. Zadání psalo „§10 Soubory“ podle staršího číslování.
- `help/` hlavičku detailu dokladu nikde nepopisuje (projito `hlavič`,
  `štítek`, `detail`, `Přehled`, `typ dokladu`) — bez změny.
- Ve `ViewerDetail.svelte` dostala role i karta návrhu v tabu Návrh
  (`.shpd-extracted__*`: shrnutí body, doplňky a štítky meta) — jinak by
  v jednom detailu zůstaly dvě sady písma. Lišta akcí a tlačítka dropdownu
  zůstávají na škále `sm` podle zadání.
- Položky v kartě strany jsou pod sebou s mezerou `--shpd-space-xs`
  (flex sloupec), kontakty mají o `--shpd-space-xs` navíc.

## Pasti

- **`<strong>` má výchozí tloušťku 700** — vždy přes
  `--shpd-font-weight-strong`, jinak se vrátí čtvrtá tloušťka.
- **`container-type: inline-size`** dává prvku šířku nezávislou na
  obsahu. `.shpd-docdetail` je blok na celou šířku obsahu detailu, tam to
  nevadí; nepřidávat ho na prvky, které se mají smrštit na obsah.
- **Container query neplatí pro prvek samotný**, jen pro jeho potomky —
  pravidla `@container` cílí na `__parties` a skupiny údajů, ne na
  `.shpd-docdetail`.
- **Změny ve `ViewerDetail` jsou vidět ve všech detailech** — záměr (jedno
  písmo všude), ale ověřit aspoň detail zakázky, osoby a upozornění.
- **Popisky stran a údajů jsou na frontendu (`t()`)**, ne ze serveru —
  nové klíče do obou slovníků, jinak `check:i18n` selže.
- **Podtitul skládá server** (číslo · text), frontend ho jen vypisuje —
  neskládat ho v `ViewerDetail`.
- **Lišta akcí se nemění**, ani její pořadí nad hlavičkou.
