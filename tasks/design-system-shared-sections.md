# Design system — sdílený nadpis sekce a seznam vlastností

**Stav:** hotovo — #137 task 1 (D28, D29, doplnění D31, D32), 2026-10-26

## Kontext / Cíl

První task #137 (zastřešující #133). Vizuální audit detailů proti pilotu
(#135, výsledek v #137) našel tři rozdíly mezi obecným detailem
(`ViewerDetail.svelte`) a detailem dokladu (`DocumentDetail.svelte`):

- nadpis sekce — obecný detail s linkou pod nadpisem, doklad bez ní;
- skupiny vlastností — obecný detail pod sebou na plnou šířku, doklad ve
  dvou sloupcích;
- dlouhé hodnoty — doklad zalamuje, obecný seznam ne.

Oba seznamy popisek–hodnota jsou skoro stejný kód ve dvou kopiích. Návrh
odsouhlasený na náhledu detailu zakázky: nadpis bez linky, skupiny ve dvou
sloupcích, poznámka přes celou šířku, na úzkém panelu jeden sloupec.

**Cíl:**

1. Sdílené komponenty v `frontend/src/components/ui/`: nadpis sekce,
   seznam vlastností, mřížka skupin.
2. Obecný detail i detail dokladu na nich; staré třídy a duplicitní CSS
   pryč.
3. Příznak `wide` na skupině vlastností; skupiny s dlouhým textem ho
   dostanou.
4. Pravidlo do `docs/design-system.md` §12 a kontrakt do
   `docs/frontend.md`.

**Mimo rozsah:**

- `Card` / `CardGrid`, Přehled firmy, dashboard feed (task 2 #137) —
  karty stran dokladu zůstávají, jak jsou, jen nadpis karty dostane
  sdílený nadpis;
- HTML se vzhledem z backendu (stav zaúčtování, prázdný stav, prostý text)
  — task 3 #137;
- karta návrhu a karta chyby — task 4 #137;
- stránky Nastavení, reporty, formuláře — převádějí se postupně, až na ně
  dojde;
- nové breakpointy a layout tokeny (#138, D11) — práh úzkého kontejneru
  zůstává 30 rem z pilotu.

## Návaznost

- **#133** — D2, D3, D15, D16, D28, D29
- **#135** — pilot: `DocumentDetail.svelte`, typografické role, úzká šířka
  přes `@container`
- **#136** — builder detailu, `propertiesTab()`
- **#139** — review modal; použije tytéž komponenty

## Před implementací přečti

- `docs/design-system.md` §11 Typografie, §12 Detail záznamu (Stavební
  prvky obsahu, Úzká šířka)
- `docs/frontend.md` → Formát detail panelu (typ `properties`)
- `frontend/src/components/viewer/ViewerDetail.svelte` — `renderContent`
  (`properties`, `heading`, `composite`), sekce Odeslaná pošta, CSS
  `.shpd-detail__group*`, `__props`, `__prop*`, `__content-heading`
- `frontend/src/components/viewer/DocumentDetail.svelte` — `META_GROUPS`,
  `metaGroups`, `.shpd-docdetail__meta*`, `__fields`, `__field-*`,
  `__section-heading`, `@container (max-width: 30rem)`
- `frontend/src/components/viewer/DetailAttachments.svelte` — nadpis
  skupiny s přepínačem náhledů v řádku
- `src/Core/Viewer/TableViewer.php` — `propertiesTab()`

## Rozhodnutí (potvrzená v #133)

- ✓ **D28** Nadpis sekce je v obsahu detailu jeden (skupina vlastností,
  blok `heading`, přílohy, Odeslaná pošta): heading strong barvou textu,
  bez linky, podle pilotu. Sekce od sebe odděluje mezera.
- ✓ **D29** Seznam vlastností je jedna sdílená komponenta pro detail
  dokladu i obecný detail: popisek–hodnota v mřížce `max-content 1fr`,
  dlouhé hodnoty se zalamují. Skupiny ve dvou sloupcích po řádcích
  v pořadí od backendu, pod 30 rem kontejneru v jednom. Skupina s dlouhým
  textem jde přes celou šířku (příznak `wide`), stejně jako tabulky
  a ostatní bloky. Výšku řádku určuje vyšší skupina.

## 1. Komponenty

`frontend/src/components/ui/`:

- **`SectionHeading.svelte`** — nadpis sekce podle D28: font-size
  `--shpd-font-size-heading`, `--shpd-font-weight-strong`,
  `line-height: 1.4`, barva textu, `margin: 0 0 var(--shpd-space-xs)`,
  bez linky a bez vlastního horního odsazení (mezeru nad sekcí dává
  rodič). Props: `level` (`3` výchozí, `4`), obsah jako `children`
  snippet (nadpis přílohy obsahuje odkaz a datum), volitelný snippet
  `aside` vpravo ve stejném řádku (přepínač náhledů příloh).
- **`PropertyList.svelte`** — `<dl>` jedné skupiny: mřížka
  `max-content 1fr`, mezera `--shpd-space-xs` / `--shpd-space-md`, popisek
  i hodnota body barvou textu (D15), hodnota `overflow-wrap: anywhere`.
  Props `items: {label, value}[]`. Položka s prázdnou hodnotou se
  nevykreslí; položka s prázdným popiskem (`label: ''`, např. text
  poznámky) má hodnotu přes oba sloupce.
- **`PropertyGroups.svelte`** — skupiny vedle sebe: obal
  `container-type: inline-size`, uvnitř mřížka
  `repeat(2, minmax(0, 1fr))`, mezera `--shpd-space-md`
  `--shpd-space-lg`, `align-items: start`; skupina `SectionHeading` +
  `PropertyList`; `wide: true` → `grid-column: 1 / -1`;
  `@container (max-width: 30rem)` → jeden sloupec. Props
  `groups: {title, items, wide?}[]`, `headingLevel`. Skupina bez
  vykreslitelné položky se nevykreslí.

Výběr vykreslitelných skupin a položek dej do čisté funkce
(`frontend/src/components/ui/propertyGroups.js`, např.
`visibleGroups(groups)`) s unit testem v `frontend/tests/Unit/`
(`npm test`): prázdná hodnota, prázdná skupina, prázdný popisek, `wide`.
Hodnota `0` není prázdná.

## 2. Obecný detail (`ViewerDetail.svelte`)

- `properties` → `PropertyGroups` (`headingLevel` 4, jako dnes).
- `heading` → `SectionHeading` (level 3). Blok, který není první
  v `composite`, má nad sebou `--shpd-space-lg` — mezeru dává obal bloků,
  ne nadpis (D28: sekce odděluje mezera).
- Nadpis sekce Odeslaná pošta → `SectionHeading`.
- Smazat CSS `.shpd-detail__group`, `__group-title`, `__props`, `__prop`,
  `__prop-label`, `__prop-value`, `__content-heading` a ověřit grepem,
  že je nic jiného nepoužívá (`--include=*.svelte --include=*.js`).

## 3. Detail dokladu (`DocumentDetail.svelte`)

- Skupiny údajů (`META_GROUPS` → `metaGroups`) → `PropertyGroups`;
  popisky přeložit (`t(labelKey)`) před předáním, komponenta dostává
  hotové texty.
- Nadpisy sekcí (Řádky, nadpis karty strany) → `SectionHeading`.
- Smazat `.shpd-docdetail__meta`, `__meta-group`, `__fields`,
  `__field-label`, `__field-value`, `__section-heading`; z
  `@container (max-width: 30rem)` zůstávají jen strany
  (`.shpd-docdetail__parties`). Vzhled detailu dokladu se nemění — je to
  vzor, ze kterého komponenty vznikají.

## 4. Přílohy (`DetailAttachments.svelte`)

Nadpis skupiny → `SectionHeading` s přepínačem náhledů ve snippetu
`aside`; `.shpd-detail-att__heading` pryč, vzhled beze změny.

## 5. Široké skupiny (backend)

- Kontrakt: skupina typu `properties` smí nést `wide: true`
  (`docs/frontend.md` → Formát detail panelu). `propertiesTab()` ho
  propouští beze změny.
- Příznak dostanou skupiny s volným textem — projdi viewery
  (`grep -rln --include=*Viewer*.php` na `'title' =>` skupin a na
  položky s `'label' => ''`); známé:
  - `WorkOrdersViewer` — Poznámka (Interní poznámka),
  - `AssetsViewer` — Poznámka,
  - `RegistryDocumentsViewer` — Poznámka,
  - `AlertsViewer` — skupina se zprávou upozornění,
  - `TasksViewer` — skupina s popisem úkolu,
  - `PrintTextsViewer` — Text.
  Skupina, kde je jen krátký text (štítek, kód), `wide` nedostane.
- Testy viewerů, které ověřují skupiny Přehledu, doplnit o `wide` u
  upravených skupin.

## 6. Dokumentace

- `docs/design-system.md` §12:
  - Stavební prvky obsahu: **Nadpis sekce** (D28) a **Popisek–hodnota**
    (D29) přepsat na sdílené komponenty — skupiny ve dvou sloupcích po
    řádcích, `wide` přes celou šířku, prázdný popisek = hodnota přes oba
    sloupce; věta „Obecné typy … používají tytéž role“ nahradit tím, že
    obecný detail i doklad kreslí tytéž komponenty.
  - Úzká šířka: kontejnerem je i `PropertyGroups`, ne jen
    `.shpd-docdetail`.
  - Pravidlo: nový nadpis sekce nebo seznam popisek–hodnota v detailu
    = sdílená komponenta, ne vlastní třída.
- `docs/design-system.md` §13 Soubory: tři komponenty.
- `docs/frontend.md` → Formát detail panelu: `wide` u skupiny
  `properties`.

## Commity

1. `feat(frontend): sdílený nadpis sekce a seznam vlastností (#137)` —
   komponenty, čistá funkce + unit test, `ViewerDetail`, `DocumentDetail`,
   `DetailAttachments`, úklid CSS
2. `feat: široké skupiny vlastností — poznámky a popisy přes celou šířku (#137)`
   — `wide` ve viewerech, testy
3. `docs: sdílený nadpis sekce a seznam vlastností (#137)` — §12, §13,
   `frontend.md`, `**Stav:**` tasku, `python3 scripts/tasks-index.py`

## Ověření

```bash
cd frontend && npm test && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
vendor/bin/phpunit --filter '<testy upravených viewerů>|DetailContractTest'
```

V prohlížeči, široký panel i úzký (drawer, zúžené okno pod 30 rem
kontejneru):

- faktura vydaná a přijatá — vzhled beze změny proti dnešku;
- zakázka — skupiny ve dvou sloupcích, Poznámka přes celou šířku, nadpis
  nad tabulkou podzakázek bez linky s mezerou nad sebou;
- karta majetku — šest skupin různé délky: posoudit nerovnoměrné dvojice
  (D29 je nechává na posouzení po nasazení — výsledek napiš do #137);
- číselník s jedinou skupinou, uživatel, upozornění, úkol s popisem;
- dlouhá hodnota bez mezer (IBAN u bankovního účtu, e-mail) se zalomí;
- dokument Spisovny — Poznámka přes celou šířku, přílohy s přepínačem
  náhledů v řádku nadpisu;
- Odeslaná pošta pod Přehledem.

## Hotovo když

- [x] `SectionHeading`, `PropertyList`, `PropertyGroups` v
  `components/ui/`, čistá funkce s unit testem
- [x] obecný detail, detail dokladu a přílohy na nich, staré třídy pryč
- [x] `wide` v kontraktu a u skupin s volným textem
- [x] `docs/design-system.md` §12, §13, `docs/frontend.md`
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Pasti

- **`@container` platí pro potomky.** `container-type` patří na obal
  mřížky, ne na mřížku samotnou; obal na celou šířku. Prvek s
  `container-type: inline-size` se nesmršťuje podle obsahu — nedávat ho
  do flex řádku, kde má být úzký.
- **Pořadí skupin je od backendu** a mřížka ho čte po řádcích. Nepřehazovat
  skupiny ve frontendu ani nepoužít CSS `columns` (změní pořadí čtení).
- **Vzhled dokladu se nemění** — porovnat před a po; rozdíl je chyba
  komponenty, ne návrhu.
- **Hodnota `0`** (počet, pořadí) není prázdná hodnota.
- **px v CSS** zastaví pre-commit (`check-css-units.py`) — mezery jen
  tokeny.

## Doplnění po prokliku (D31, D32)

Při prokliku hotového tasku přibyla v #133 dvě rozhodnutí:

- ✓ **D31** Dvojice skupin v řádku patří věcně k sobě a jsou podobně
  dlouhé; skupina, která u části záznamů mizí, stojí na konci, nebo její
  položky patří do stálé skupiny. Karta majetku: skupina Identifikace
  zaniká, Zkrácený název (dál jen když se liší od názvu) je první položka
  Zařazení; pořadí Vlastnictví, Pořízení a vyřazení, Zařazení, Odpisy
  (podmíněná, proto na konci dvojic), Poznámka (`wide`). Text
  `group.identity` zůstává — používají ho Typy majetku a Účetní skupiny.
  Hlídá review.
- ✓ **D32** Skupina, která by v řádku zůstala sama (poslední lichá, nebo
  skupina před `wide`), jde přes celou šířku: `visibleGroups()` dopočítá
  `full` kurzorem sloupce nad viditelnými skupinami (`wide` kurzor
  nuluje), `PropertyGroups` kreslí celou šířku podle `full`, ne podle
  `wide`. Unit test: jediná skupina, lichý počet, skupina před `wide`,
  `wide` uprostřed, sudý počet beze změny.

Commity: `feat(frontend): skupina sama v řádku přes celou šířku (#137, D32)`,
`refactor(economy.assets): pořadí skupin karty majetku (#137, D31)`,
`docs: řazení skupin vlastností (#137, D31, D32)`.
