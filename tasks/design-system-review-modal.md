# Design system — review modal a náhled Spisovny na sdílených částech

**Stav:** hotovo — #139 task 2 (D49–D55), 2026-11-09; navazuje na `design-system-document-parts.md`

## Kontext / Cíl

Druhý task #139 (zastřešující #133). Task 1 vytvořil sdílené části dokladu
(`DetailHeader`, `PartyCard`, `DocRows`, `DocTotals`, `TableScroll`)
a detail dokladu na nich. Review modal (`DocumentExchangePreview.svelte`)
a náhled Spisovny (`RegistryExtractedPreview.svelte`) mají dál vlastní
kopie karet, mřížky údajů, tabulek a součtů s vlastním písmem — dnes
v `components/exchange/` desítky deklarací `font-size` mimo typografické
role (literály `0.6875rem`, `0.8125rem`, `0.625rem`, `1.5rem` a škálové
tokeny `xs` / `sm` / `lg` místo rolí), verzálky a tloušťky 500 / 600 / 700.

**Cíl:**

1. Review dokladu na sdílených částech přes adaptér z canonical —
   hlavička (D49), strany (D50), údaje (D51), řádky (D45), součty (D52,
   D55), hlášky (D53).
2. Náhled Spisovny na týchž stavebních prvcích (D49, D54).
3. Typografie celé review (modal, náhled, rozhodovací panely) jen
   v rolích (D54).
4. Úklid kopií CSS a textů; proklikání sady (#103) dál projde.

Vzhled review se mění záměrně — má vypadat jako detail téhož dokladu.
Chování (rozhodnutí, ukládání, Použít, rychlé založení z registru) se
nemění.

**Mimo rozsah:**

- logika review (`reviewGate.js`, `rowMatch.js`, `enrichBadge.js`,
  `_resolve`, ukládání rozhodnutí) — jen se přesouvá, kde se kreslí;
- obsah rozhodovacích panelů (`ResolveDecisionPanel`,
  `VatCodeDecisionPanel`) a průvodce registrem — jen typografie;
- shell modalu (`Modal`, split PDF / údaje, taby na mobilu, patička)
  — jen typografie vlastních tříd modalu;
- rozměry v px (`Popover width="400px"`, `min-height` stavů) — #138
  task 4 (D46);
- backend a kontrakt `/preview`.

## Návaznost

- **#133** — D15, D16, D39, D40, D43, D45, D48–D55
- **#139** task 1 — `tasks/design-system-document-parts.md` (API částí,
  tabulky snippetů v §3, §5, §6)
- **#137** — `Notice`, `Badge`, `PropertyGroups`, `PropertyList`,
  `SectionHeading`, `Card`
- **#103** — proklikání sady v prohlížeči (`tests/e2e/`), čte
  `data-testid` review

## Před implementací přečti

- `tasks/design-system-document-parts.md` — celé, hlavně tabulky snippetů
  a Pasti (duplicitní buňky ve složeném režimu)
- `docs/design-system.md` §8 Hlášky a pruhy, §11 Typografie, §12 Detail
  záznamu (po tasku 1 včetně sdílených částí dokladu)
- `docs/dashboard.md` §6.3 review modal
- `docs/exchange-format.md` → Self-party flow (`selfParty`)
- `frontend/src/components/exchange/DocumentExchangePreview.svelte`,
  `RegistryExtractedPreview.svelte`, `DocumentExchangePreviewModal.svelte`
- `frontend/src/components/ui/Notice.svelte`, `PropertyList.svelte`,
  `propertyGroups.js`
- `tests/e2e/dataset-walkthrough.spec.mjs` — jak hledá odznaky
  `review-ref:*` a `review-total`

## Rozhodnutí (potvrzená v #133)

- ✓ **D49** Hlavička review = `DetailHeader` (titulek, štítky v řádku
  titulku, podtitul), bez ikony. Doklad: titulek protistrana (D14; bez ní
  číslo), podtitul „číslo · text“. Štítky: **typ výsledku analýzy**
  (`primary` — „Faktura přijatá“, u Spisovny „Spisovna · druh“)
  a **souhrnný stav kontroly vždy** — „Vše napárováno“ (`success`) /
  „Vyžaduje pozornost“ (`warning`). Review je místo kontroly výsledku
  analýzy — typ říká, co analýza určila, stav je výsledek kontroly, ne
  stav záznamu. Spisovna: titulek název dokumentu, podtitul protistrana ·
  IČO, bez štítku stavu.
- ✓ **D50** `PartyCard`: pořadí D16, hodnoty bez tučného (D15). Review
  navíc: stav strany vpravo v nadpisu karty, stav účtu za číslem,
  kontaktní osoba mezi kontakty (meta), „Založit z registru“ jako akce
  dole v kartě.
- ✓ **D51** Údaje v review = `PropertyGroups` jako detail, skupiny Data /
  Platba / DPH (režim, místo plnění). Prázdné pole review ukazuje „—“
  (kontroluje se vytěžení), detail ho dál vynechává. `PropertyList`
  dostane volitelný snippet hodnoty — výběr DPH se zdrojem v roli meta.
- ✓ **D52** `DocTotals` — review navíc: zdroj čísel (převzaté /
  přepočtené) v roli meta nad rekapitulací, kód DPH u sazby (meta),
  dvojice přenesené povinnosti; nesoulad s částkou dodavatele jako
  `Notice` warning pod souhrnem.
- ✓ **D53** Hlášky review = `Notice` (D40): problémy z vytěžení **nahoře
  pod hlavičkou** (seznam, tón podle nejvyšší závažnosti, cesta v roli
  meta), selhání AI (danger, surový výstup rozbalovací), chyba rychlého
  založení.
- ✓ **D54** Typografie review modalu a náhledu Spisovny: jen role, bez
  literálů, verzálek a tlouštěk 500 / 700; stavové značky (kolečko se
  znakem) zůstávají jako ovládací prvek, znak v roli meta. Náhled
  Spisovny: hlavička D49, shrnutí jako text přes celou šířku, pole druhu
  a navržený šanon v `PropertyList`. Obě větve sdílejí stavební prvky
  a vzhled; obsah se liší podle cíle analýzy.
- ✓ **D55** Popisek řádku součtu je „Celkem“ (detail i review).
- ✓ D45 — viz task 1; hromadné akce review jsou v řádku nadpisu sekce
  (`SectionHeading` `aside`), ne v hlavičce sloupců.

## 1. Adaptér review (`components/exchange/reviewParts.js`)

Čisté funkce bez Svelte, `t` jako parametr (jako `documentParts.js`
v tasku 1). Hodnoty zformátuje adaptér dnešními helpery (`formatMoney`,
`formatDate`, `formatAddress` — přesunout z komponenty sem, ať jsou
testovatelné), komponenty dostanou hotové texty v tvaru z tasku 1 §2.

- `counterparty(canonical)` — `selfParty === 'customer'` → `supplier`,
  `'supplier'` → `customer`, jinak `null` (titulek pak číslo, D14).
- `reviewHeader(canonical, summary, t)` → `{ title, subtitle, badges }`:
  titulek název protistrany, bez ní `docNumber` (bez obojího „—“);
  podtitul „číslo · text“ (prázdné části i s oddělovačem vynechat; když
  je číslo v titulku, podtitul jen text); štítky typ (`primary`,
  `exchange.preview.docType.*`) a stav (`summary.status === 'ok'` →
  `success` „Vše napárováno“, jinak `warning` „Vyžaduje pozornost“;
  bez `summary` štítek stavu chybí).
- `registryHeader(canonical, t)` → titulek `title` (bez něj „—“),
  podtitul „protistrana · IČO 12345678“, štítek `primary`
  „Spisovna · druh“ (nový klíč s parametrem, druh z
  `registry.docKind.*`, neznámý druh = surový název jako dnes).
- `reviewParty(party, t)` → tvar `PartyCard`; kontaktní osoba jako první
  položka `contacts` („Kontaktní osoba: …“), účet `iban ?? accountNumber`.
- `reviewRows(canonical, resolve, computedRowPrices, t)` → tvar `DocRows`
  (`description` = `_resolve.rows[i].rowText ?? item.name`, ceny
  z přepočtu, sazba z efektivního kódu DPH); v `extra` index řádku a co
  potřebují snippety.
- `reviewTotals(...)` → tvar `DocTotals` (rekapitulace s `vatCode`
  a příznakem dvojice v `extra`).
- `issueNotice(issues)` → `{ tone, items }` — tón podle nejvyšší
  závažnosti (`error` → `danger`, `warning` → `warning`, jinak `info`),
  položky seřazené od nejzávažnější, `{ text: message, meta: path }`.

Unit testy `frontend/tests/Unit/reviewParts.test.mjs`: protistrana podle
`selfParty` a bez něj, titulek a podtitul (bez čísla, bez textu, bez
protistrany), štítky se `summary` i bez, Spisovna (neznámý druh, bez
protistrany, bez IČO), kontaktní osoba, tón a řazení hlášek.

## 2. Hlavička

- Doklad: `DetailHeader` (bez ikony) z `reviewHeader` na místě dnešní
  `<header class="shpd-exchange__header">`. Typ a souhrnný stav jsou
  `Badge` — třídy `__type-badge` a `__summary-badge*` zmizí.
- Spisovna: `DetailHeader` z `registryHeader` (§7).
- Titulek modalu („Kontrola dokladu“, `Modal`) a zdrojová zpráva
  v podtitulku modalu zůstávají — hlavička obsahu je pod nimi.

## 3. Hlášky (`Notice`)

- **`Notice` položka s meta:** položka smí být `{ text, meta }` — `meta`
  za textem v roli meta (`--shpd-font-size-meta`,
  `--shpd-color-text-secondary`) — cesta jako `rows[2].vatCode` už není
  v `<code>`. Stávající `{label, text}` beze změny. Komentář komponenty
  a §8 Hlášky v design-system.
- **Problémy z vytěžení:** `Notice` z `issueNotice` hned pod hlavičkou,
  nadpis „Upozornění“ (`exchange.preview.section.issues`). Sekce
  `__issues` na konci a glyfy závažnosti zmizí.
- **Selhání AI:** `Notice` danger — nadpis a zpráva z dnešních textů,
  položky `_validationIssues` (`{ text: message, meta: path }`), pod ní
  `<details>` se surovým výstupem; `<pre>` vzhledem jako blok `code`
  detailu (§12: mono, meta, `bg-secondary`, zaoblení md, rolování).
  Vlastní CSS `__ai-failed*` zmizí.
- **Chyba rychlého založení:** `Notice` danger ve `footer` karty strany
  pod tlačítkem (místo `__party-registry-error`).

## 4. Strany

`PartyCard` ×2 v dnešní mřížce `.shpd-exchange__parties` (dva sloupce,
pod `30rem` pod sebe — beze změny) se snippety:

- `status` — `statusBadge(partyResolve, path, 'party')`;
- `bankStatus` — `statusBadge(bankResolve, bankPath, 'bankAccount')`
  (účet se ukáže i bez čísla, když existuje rozhodnutí účtu — task 1 §3);
- `footer` — „+ Založit z registru: …“ jako dnes (podmínky beze změny),
  tlačítko `Button` `size="sm"` `variant="secondary"` místo vlastní třídy,
  pod ním `Notice` chyby (§3).

## 5. Údaje

- **`PropertyList` / `PropertyGroups`:**
  - položka smí nést `snippet` (Svelte snippet `(item)`) — kreslí se
    místo `value` a položka se snippetem je vždy viditelná;
  - prop `emptyValue` (výchozí `null` = prázdnou položku vynechat jako
    dnes; review předá `'—'` — položka zůstane s pomlčkou, skupina se
    pak nikdy nevynechá);
  - `visibleItems` / `visibleGroups` dostanou volbu `emptyValue`, testy
    v `propertyGroups.test.mjs` doplnit.
- **Review:** `PropertyGroups` místo `__meta-grid`, `emptyValue="—"`:
  - **Data** — vystavení, splatnost, účetní datum, DUZP, datum povinnosti
    DPH;
  - **Platba** — měna, způsob úhrady, platební reference;
  - **DPH** — režim a místo plnění se snippetem `vatChoiceField` (select
    a zdroj / „výchozí“ v roli meta, `__field-hint`).
- Snippet `field` a třídy `__field*`, `__meta-grid` zmizí.

## 6. Řádky a součty

**`DocRows`** (`title` „Řádky dokladu“ — sdílený klíč z tasku 1):

- `aside` — souhrn obohacení („⟲ n řádků“, meta) a hromadné akce jako
  **textová tlačítka** `Button size="sm" variant="secondary"`:
  „Založit položky (n)“ (`data-testid="review-ref-bulk:item"` zůstává)
  a „Kód DPH u řádků (n)“ — nové klíče cs / en. Kolečka v hlavičce
  sloupců zmizí; na úzké šířce se `aside` zalomí pod nadpis.
- `description` — text řádku, kód dodavatele (meta), `statusBadge` položky,
  ⟲ (`enrichBadge`) u řádku bez napárování;
- `below` — `rowMatchLine` (napárování);
- `account` — účet (`__row-code`, meta) + `statusBadge` účtu; zapne
  sloupec jen při `hasAccountColumn`;
- `unit` — jednotka + `statusBadge`;
- `vat` — sazba, kód DPH (meta), `vatCodeBadge`;
- `meta` — řádek složeného režimu: „množství jednotka × cena · sazba kód“
  a za ním „· účet“ se značkou účtu, značka jednotky a kódu DPH u svých
  částí (D45). Textová část přes `rowMetaLine` z tasku 1, značky
  snippety.

**`DocTotals`** (`totalTestId="review-total"`):

- `before` — zdroj čísel (převzaté / přepočtené / bez přepočtu + důvod)
  v roli meta;
- `rate(r)` — sazba, kód DPH (meta), „přenesená povinnost“ u dvojice
  (meta, ne zvláštní třída řádku);
- `after` — při `totalMismatch` `Notice` warning „Celkem se liší od
  částky na dokladu dodavatele (…)“ (nový klíč s částkou); řádek
  „Na dokladu dodavatele“ ze souhrnu zmizí.
- Popisek „Celkem“ (D55) — sdílený z tasku 1.

## 7. Náhled Spisovny

`RegistryExtractedPreview` na stavebních prvcích detailu (D54):

- `DetailHeader` z `registryHeader` (§1);
- shrnutí (`summary`) jako prostý text přes celou šířku — odstavec jako
  blok `text` detailu (body, `pre-wrap`); levý pruh a pozadí zmizí;
- `PropertyGroups`: skupina „Údaje dokumentu“ s poli druhu (lokalizované
  popisky, `formatValue` jako dnes, prázdná pole vynechaná jako dnes)
  a „Návrh šanonu“ jako položka (vlastní skupina, nebo poslední položka
  téže — podle D31 / D32: dvojice jen věcně související);
- vlastní tabulka `__fields` a třídy `__kind-badge`, `__title`, `__party`,
  `__summary`, `__binder*` zmizí; obal s odsazením a mezerami jako
  `.shpd-exchange`.

## 8. Typografie zbytku (D54)

V `components/exchange/` po tasku jen role (`--shpd-font-size-title /
heading / body / meta`, `--shpd-font-weight-normal / strong`), žádné
literály velikosti, škálové tokeny (`--shpd-font-size-xs / sm / base /
lg / xl`) jako velikost písma, `text-transform: uppercase` ani tloušťky
500 / 600 / 700 literálem:

- **kořen `.shpd-exchange`** — body (dnes `sm` = 14 px, tedy beze změny
  velikosti, jen role);
- **stavové značky** (`__status`, kolečko se znakem) — zůstávají
  ovládacím prvkem, znak v roli meta normální tloušťkou (dnes 10 px /
  700); velikost kolečka podle znaku, ověřit, že se v řádku nezvětší
  výška;
- **`__row-code`, `__row-match`, `__recap-source`, `__enrich*`** — meta;
- **`DocumentExchangePreviewModal`** — kód zdrojové zprávy a odkaz
  strong tokenem, počítadlo fronty meta, taby na mobilu jako taby detailu
  (body, aktivní strong), načítání a chyba body;
- **`ResolveDecisionPanel`, `VatCodeDecisionPanel`, `PdfViewerPanel`** —
  popisky sekcí panelu bez verzálek v roli meta strong, texty body,
  doplňky meta; tloušťky tokeny.

## 9. Úklid

- Z `DocumentExchangePreview` zmizí snippet `partyCard` a CSS stran,
  údajů, tabulek, součtů, hlavičky a hlášek — zůstává kořen, mřížka
  stran, stavové značky, napárování, výběr DPH.
- `exchange.preview.*` klíče, které nahradily sdílené (`row.*`,
  `totals.*`, `field.companyId` / `taxId` / `vatId` / `bankAccount`,
  `section.supplier` / `customer` / `rows`), smazat z cs i en (grep, i
  dynamické `t(\`exchange.preview.…${}\`)`), `registry.preview.party`,
  `companyId`, pokud je nic nepoužívá.
- `npm run check:i18n` — parita cs / en.

## 10. Proklikání sady (#103)

Ve složeném režimu jsou značky jednotky, kódu DPH a účtu v DOM dvakrát
(široká buňka i řádek meta, vidět je jedna — task 1 Pasti). Review modal
při 1280 px má obsah pod 36 rem, takže test vidí složené řádky.

- `tests/e2e/dataset-walkthrough.spec.mjs` → `findUndecidedBadge`:
  hledat jen viditelné odznaky (`[data-testid^="review-ref:"]:visible`),
  cesty deduplikovat, lokátor kliknutí také `:visible` / `.filter({
  visible: true })`.
- `review-total`, `review-ref-bulk:item`, `review-ref-dialog`,
  `review-ref-create:*`, `review-state`, `review-preview` zůstávají.
- `tests/e2e/lib` — upravit jen, pokud se mění pomocná funkce
  (+ `npm run test:unit`).

## 11. Dokumentace

- `docs/design-system.md`:
  - §12 nová podsekce **Review dokladu a náhled Spisovny** (D49–D55):
    stejné části jako detail (adaptér `reviewParts.js`), co review
    přidává snippety (stavy, napárování, výběry, hromadné akce v `aside`),
    hlavička se dvěma štítky a proč (rozdíl proti D13 / D20), „—“ pro
    prázdné pole a proč, hlášky nahoře, Spisovna;
  - §8 Hlášky a pruhy: položka `Notice` s `meta`;
  - §12 Stavební prvky → Popisek–hodnota: `snippet` a `emptyValue`;
  - §13 Soubory: `reviewParts.js`, řádek `DocumentExchangePreview`
    a `RegistryExtractedPreview` (na sdílených částech).
- `docs/dashboard.md` §6.3: odstavec, že náhled kreslí sdílené části
  dokladu (odkaz na design-system §12).
- `tasks/README.md` — řádek v sekci Design system.
- #139: zaškrtnout checklist, komentář s výsledkem ověření.

## Commity

1. `feat(frontend): Notice s meta položkou, PropertyList se snippetem a pomlčkou (#139, D51, D53)`
   — §3 (jen `Notice`), §5 (jen `ui/`), testy
2. `feat(frontend): review dokladu na sdílených částech (#139, D49–D53, D55)`
   — §1, §2, §3–§6 v review, §9 pro review, `reviewParts.test.mjs`
3. `feat(frontend): náhled Spisovny na sdílených částech (#139, D49, D54)` — §7
4. `style(frontend): typografie review jen v rolích (#139, D54)` — §8
5. `test(e2e): proklikání sady hledá jen viditelné odznaky (#139)` — §10
6. `docs: review dokladu a náhled Spisovny (#139, D49–D55)` — §11,
   `**Stav:**`, `python3 scripts/tasks-index.py`

Commit 5 může jít hned za 2, pokud proklikání bez něj padá.

## Ověření

```bash
cd frontend && npm test && npm run check:i18n && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
grep -nE --include=*.svelte -r "font-size: [0-9]|font-size: var\(--shpd-font-size-(xs|sm|base|lg|xl)\)|uppercase|font-weight: [0-9]" frontend/src/components/exchange
```

Poslední grep nic nenajde.

V prohlížeči nad zdrojem dat ukázkové sady (třída `free`), review z feedu
dashboardu:

- **faktura přijatá, vše napárováno:** titulek dodavatel, podtitul
  „číslo · text“, štítky „Faktura přijatá“ a „Vše napárováno“; vedle
  detailu téhož dokladu (po Použít) stejné písmo, karty stran, údaje,
  řádky a součty;
- **faktura s nenapárovanou stranou / položkou:** „Vyžaduje pozornost“,
  `Notice` s problémy nahoře, stav strany v nadpisu karty, „Založit
  z registru“ dole v kartě, hromadné akce v řádku nadpisu Řádky;
  rozhodnutí přes značky, výběr DPH a hromadné akce fungují a ukládají
  se jako dnes;
- **faktura vydaná:** titulek odběratel;
- **přepočtená rekapitulace / nesoulad s dodavatelem:** zdroj čísel nad
  rekapitulací, `Notice` warning pod souhrnem;
- **prázdná pole** v Data / Platba / DPH ukazují „—“;
- **Spisovna:** titulek název dokumentu, podtitul protistrana · IČO,
  štítek „Spisovna · druh“ bez stavu, shrnutí přes celou šířku, pole
  a šanon v seznamu vlastností;
- **selhání AI** (pokud je v sadě nebo na dev serveru zpráva ve stavu
  selhání): `Notice` danger, surový výstup se rozbalí;
- **šířky:** okno 1280 (půlka modalu pod 36 rem → složené řádky,
  D43 poznámka), 1600 (tabulka, sloupec Účet), telefon 390 (taby PDF /
  údaje, strany pod sebou, složené řádky);
- popovery rozhodnutí se otevírají u viditelné značky a nejsou ořezané
  kontejnerem;
- **proklikání sady:** `shpd-ds dataset-e2e <adresář sady>` (`docs/cli.md`)
  nad ukázkovou sadou projde jako před změnou.

## Hotovo když

- [x] review dokladu na `DetailHeader`, `PartyCard`, `PropertyGroups`,
      `DocRows`, `DocTotals` přes `reviewParts.js` s unit testy
- [x] hlášky jako `Notice` (problémy nahoře, selhání AI, chyba založení,
      nesoulad součtu)
- [x] hromadné akce jako textová tlačítka v řádku nadpisu Řádky
- [x] náhled Spisovny na `DetailHeader`, textu a `PropertyGroups`
- [x] grep z Ověření v `components/exchange/` prázdný
- [x] kopie CSS a textů pryč, `check:i18n` zelený
- [x] proklikání sady projde
- [x] `docs/design-system.md` §8, §12, §13, `docs/dashboard.md` §6.3
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`, checklist #139

## Odchylky od zadání a výsledek ověření (2026-11-09)

- Popisky údajů review jdou přes sdílené klíče detailu (`document.section.
  dates` / `payment`, `document.meta.*` — „Datumy“ → „Data“); zůstaly jen
  `field.vatObligationDate`, `field.vatMode`, `field.vatPlace`, nová
  skupina `section.vat`. Smazáno 39 nepoužívaných klíčů
  `exchange.preview.{section,field,row,totals}.*` a `registry.preview.party`;
  `field.contactName` → „Kontaktní osoba“.
- Sazba v řádcích review „21 %“ s mezerou (jako detail); textový řádek
  = canonical `rowKind: "text"`; „Návrh šanonu“ je poslední položka skupiny
  „Údaje dokumentu“ (jediná položka vedle dlouhé skupiny by porušila D31).
- Řádek `meta` složeného režimu se skládá v `DocumentExchangePreview`
  (stejné oddělovače jako `rowMetaLine`), aby značky jednotky, kódu DPH
  a účtu stály u svých částí; `rowMetaLine` samotný by je nepustil dovnitř.
- `SectionHeading`: řádek s `aside` má `flex-wrap`, na úzké šířce se
  hromadné akce zalomí pod nadpis (bez toho se nadpis lámal do dvou řádků).
- `DetailHeader` sedí uvnitř odsazení `.shpd-exchange` (čára pod ní přes
  vnitřní šířku panelu), ne na okraj panelu jako v detailu.
- Tloušťka 500 (text výsledku, tlačítko Vytvořit, procento) nahrazená
  normální tloušťkou, aktivní tab strong tokenem; velikost koleček značek
  18 / 14 px zůstává, výška řádků tabulky se nezměnila (ověřeno měřením).
- Ověřeno v Chromiu (Playwright, zdroje dat `nham-q…` a `zey0-g…`): review
  z feedu při 1600 (tabulka, sloupec Účet), 1280 (složené řádky) a 390 px
  (taby, strany pod sebou, složené řádky, `aside` pod nadpisem); panel
  náhledu neroluje do strany; popovery rozhodnutí i kódu DPH se otevírají
  u viditelné značky; žádná chyba stránky. **Neověřeno:** náhled Spisovny
  (v sadách není karta Spisovny — jen kompilace a unit testy adaptéru),
  selhání AI (žádná zpráva ve stavu selhání), Firefox, ukládání
  rozhodnutí a hromadných akcí ručně — čeká na Annu. Proklikání sady:
  viz níže.
- Proklikání sady `cz/association` nad `nham-q…` (`shpd-ds dataset-e2e`):
  v pořádku — 16 z 16 karet vystaveno (přeseedování 15 s, proklikání
  31 s), `dataset-verify` 6 / 6 kontrol v pořádku, žádná chyba
  prohlížeče; jediná odpověď 4xx je `GET /_app/avatar` (uživatel `e2e`
  bez avataru, nesouvisí).

## Pasti

- **Duplicitní značky ve složeném režimu.** Interaktivní značky jednotky,
  kódu DPH a účtu jsou v DOM dvakrát; obě musí volat totéž rozhodnutí
  (stejná `path`) a popover se kotví k té, na kterou se kliklo
  (`event.currentTarget`, jako dnes). Testy a lokátory jen viditelné
  prvky.
- **Snippety z jiné komponenty.** `statusBadge`, `vatCodeBadge`,
  `rowMatchLine` a `vatChoiceField` jsou snippety `DocumentExchangePreview`
  předávané do sdílených částí — čtou stav review přes uzávěr; nepřesouvat
  stav do sdílených částí (D48: jsou prezentační).
- **`emptyValue` a viditelnost.** S `'—'` se položka nikdy nevynechá — v
  detailu (výchozí `null`) se nesmí nic změnit; test na obojí.
- **Protistrana bez `selfParty`.** Dokument bez `selfParty` (Jiný
  dokument, neúplné vytěžení) má titulkem číslo, ne náhodnou stranu.
- **Text „Vše napárováno“** je souhrnný stav kontroly, ne stav záznamu —
  neschovávat ho jako „V pořádku“ v detailu (D20 se tu neuplatní, D49).
- **Stavové značky a výška řádku.** Znak v roli meta je větší než dnešních
  10 px; kolečko nesmí zvětšit výšku řádku tabulky ani řádku nadpisu
  karty — ověřit v obou režimech řádků.
- **JS řetězce** jen s ASCII uvozovkami; „…“ jen v katalogu textů.
