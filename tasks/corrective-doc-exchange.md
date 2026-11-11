# Task: Opravný daňový doklad — task B: výměnný formát a akce „Vystavit opravný doklad“

**Stav:** naplánováno — zadání 2026-11-10 (D1–D11 zamčené v #92); čeká na task A

**Issue:** #92

**Cíl:** Opravný doklad umí projít výměnným formátem (blok `correction`
v canonical, applier, export, datové sady) a z detailu faktury vydané jde
jedním tlačítkem založit jako koncept: řádky původní faktury se záporným
znaménkem, odkaz na původní doklad, datum plnění, kurz a partner.

Navazuje na `tasks/corrective-doc-core.md` (sloupce, pravidla dokumentu,
formulář). Task C (`corrective-doc-outputs.md`) přidá tisk a ISDOC.

## Před implementací přečti

- `tasks/corrective-doc-core.md` — D1–D5, U1–U7 (sloupce, validace,
  datum sazby, kurz)
- `docs/exchange-format.md` — struktura canonical, `_resolve`
  a `userAction`, `applyOptions`, tabulka issues, import mód
- `docs/datasets.md` — co musí dump → seed zachovat
- `CLAUDE.md` → Dokladový systém: „Generované doklady — vždy přes
  `DocumentApplier`“
- `modules/economy/workOrders/src/Invoicing/InvoiceBuilder.php` — vzor
  sestavení canonical z databáze (`_resolve` s `useExisting:{id}` pro
  partnera a položky, `applyOptions.targetDocState`, `numberSeriesId`,
  `importOwnBankAccount`, `source.kind`) a `InvoicingRunFactory`
  (vytvoření applieru)
- `modules/core/exchange/src/Document/DocumentApplier.php` — `transform()`
  (~ř. 1700), řada z `applyOptions` (~ř. 700), `exchangeRateDecision()`
  (~ř. 3013), odvození kódů DPH k datu (`VatCodeDerivation`,
  `Resolve/VatCodeResolver`)
- `modules/core/exchange/src/Export/DocumentExporter.php` —
  `exportDocument()` (~ř. 228), mapování řádků
- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` + ručně
  udržovaný `.json` (`SchemaDriftTest`) + kopie v
  `modules/core/mail/profiles/czech_general.jsonc` (`ProfileSchemaDriftTest`)
- `modules/docs/core/config/sourceKinds.jsonc`
- `src/Api/Router.php` — vzor akční routy (`/_vat/filing-account`,
  ~ř. 453), `public/index.php` (dispatch), `src/Api/ReadOnlyPolicy.php`
- `frontend/src/components/viewer/Viewer.svelte` — `handleDetailAction`,
  větev `accountFiling` (~ř. 810): služba založí koncept, po úspěchu se
  otevře ve formuláři; `frontend/src/api/vat.js` (vzor volání)
- `modules/docs/core/src/DocsHeadsViewer.php` — `detailActions()` (~ř. 361)

## Rozhodnutí k designu (potvrzená)

- ✓ **D6** — Akce „Vystavit opravný doklad“ v detailu faktury založí
  koncept přes `DocumentApplier`: řádky se záporným množstvím, odkaz,
  datum plnění, kurz a partner z původního dokladu. Ruční cesta (task A)
  zůstává.
- ✓ **D10** — Výměnný formát a import: nový blok s údaji opravy. Ze
  starého systému přijde jen příznak, bez čísla původního dokladu
  a důvodu.

Ostatní rozhodnutí (D1–D5, D7–D9, D11) viz task A.

**Upřesnění při psaní tasku** (implementační, v duchu D6 a D10):

- **U1 — Blok `correction`.** Nové volitelné pole canonical na nejvyšší
  úrovni; objekt = opravný doklad, `null` nebo chybějící klíč = běžný:

  ```jsonc
  "correction": {
      "originalDocNumber":    "FV26-00123",   // string | null, max 40
      "originalTaxPointDate": "2026-03-15",   // date | null
      "reason":               "Sleva za vadu" // string | null
  }
  ```

  Objekt se samými `null` je platný (import ze starého Shipardu — jen
  příznak).
- **U2 — Applier.** Blok se bere jen u typu dokladu s příznakem
  `corrective` (task A U2); u jiného typu ho applier ignoruje a přidá
  varování `correction_not_supported`. Mapování: `corrective` = 1,
  `corrected_doc_number`, `corrected_doc_date`, `correction_reason`.
- **U3 — Dohledání původního dokladu.** Pořadí:
  1. `_resolve.correction.userAction` = `useExisting:{id}` — odkaz přímo
     (použije akce z U6);
  2. jinak podle `originalDocNumber`: právě jeden doklad téhož typu ve
     stavu V pořádku s tímto číslem → `corrected_doc`;
  3. nenalezeno → bez odkazu, číslo zůstane textem, informace
     `correction_original_not_found`; víc shod → bez odkazu, varování
     `correction_original_ambiguous`.
  Číslo a datum pak podle odkazu přepíše dokument (task A U3).
- **U4 — Datum sazby a kurz.** Odvození kódů a sazeb DPH v applieru
  používá u opravného dokladu datum původního plnění (z odkazu, jinak
  `originalTaxPointDate`), stejné pravidlo jako task A U6.
  `exchangeRateDecision()` u opravného dokladu kurz z lístku nedoplňuje:
  s odkazem se převezme kurz původního dokladu, bez odkazu a bez
  `exchangeRate` zůstane prázdný a potvrzení zastaví validace dokladu
  (informace v náhledu místo `exchange_rate_from_list`).
- **U5 — Export.** `DocumentExporter` vyváží blok u dokladu
  s `corrective` = 1 (číslo, datum plnění a důvod ze sloupců). Odkaz se
  po seedu obnoví dohledáním podle čísla (U3 bod 2) — původní doklad má
  nižší id, v dumpu je dřív.
- **U6 — Sestavení opravného dokladu.** Nová služba v `docs.core`
  (pracovně `CorrectiveDocBuilder` + `CorrectiveDocService`) podle vzoru
  `InvoiceBuilder`: canonical se skládá z hlavičky a řádků původního
  dokladu v databázi.
  - **Přebírá se:** typ dokladu, partner (`_resolve` `useExisting`),
    měna a kurz, režim DPH, místo plnění a registrace DPH, způsob
    úhrady, vlastní bankovní účet, dimenze, zakázka, řada původního
    dokladu (`applyOptions.numberSeriesId`).
  - **Nepřebírá se:** čísla a symboly, data (vystavení = dnes, ostatní
    doplní dokument), text a poznámky dokladu, rekapitulace DPH a součty
    (spočítají se; automatika zaokrouhlení podle #73 D8), ruční zařazení
    do KH, přílohy.
  - **Řádky:** textové beze změny; cenový řádek musí dát `net_total`
    s opačným znaménkem než původní — záporné množství a stejná
    jednotková cena, u řádku z celkové ceny i záporná celková cena,
    sleva částkou s opačným znaménkem, sleva procentem beze změny; řádek
    bez množství nese znaménko v ceně. Položky přes `_resolve`
    `useExisting`. Kódy DPH řádků se přebírají.
  - **Oprava:** `_resolve.correction` = původní doklad, `correction.reason`
    prázdný (doplní uživatel), `source.kind` = `correctiveDoc`,
    `applyOptions.targetDocState` = 10.
- **U7 — Kdy je akce dostupná.** Doklad typu s příznakem `corrective` ve
  stavu V pořádku (i když je sám opravný). Jinak služba odmítne
  (`corrective_source_invalid`, 422).
- **U8 — Endpoint a frontend.** `POST /_docs/corrective` `{docId}` →
  `{docId: <nový koncept>}`; read-only režim 403 (výchozí
  `ReadOnlyPolicy`). Akce detailu `createCorrectiveDoc` („Vystavit
  opravný doklad“, `secondary`); po úspěchu obnoví detail a otevře nový
  koncept ve formuláři (vzor `accountFiling`). Chyby applieru jako 422
  se seznamem hlášek.
- **U9 — Druh zdroje.** `docs.core.sourceKinds`: `correctiveDoc`
  („Opravný doklad“ / „Corrective document“).

## Co je potřeba udělat

### Commit 1 — schéma, applier, export (D10, U1–U5)

1. Schéma: `correction` a `_resolve.correction` ve všech třech kopiích;
   `SchemaDriftTest` a `ProfileSchemaDriftTest` zelené. Prompt profilu
   pošty se nemění (model blok neemituje).
2. `DocumentApplier::transform()` podle U2–U4, issues do náhledu.
3. `DocumentExporter` podle U5.
4. Testy applieru: blok se všemi údaji; blok se samými `null`; import mód
   s blokem (nic se nevyžaduje, doklad se uloží ve stavu z importu);
   dohledání podle čísla (jedna shoda, žádná, víc); `_resolve` s id;
   blok u přijaté faktury → varování a běžný doklad; sazba k datu
   původního plnění; kurz podle U4. `DocumentExporterTest`: blok ve
   výstupu jen u opravného dokladu.
5. Test dump → seed: opravný doklad s odkazem má po seedu odkaz na
   správný doklad, číslo, datum a důvod.
6. `docs/exchange-format.md`: blok, dohledání, issues, import mód.

### Commit 2 — služba a endpoint (D6, U6, U7, U9)

1. `CorrectiveDocBuilder` (čistá funkce nad daty původního dokladu →
   canonical) a `CorrectiveDocService` (načtení, kontrola U7, applier).
2. Druh zdroje `correctiveDoc`.
3. Routa, dispatch, kontroler; oprávnění jako založení dokladu.
4. Unit testy builderu: faktura s řádky z jednotkové ceny, z celkové
   ceny, se slevou procentem a částkou, textovým řádkem, řádkem bez
   množství, v cizí měně, s dimenzemi — pro každý cenový řádek
   `net_total` = −původní.
5. Integrační test (ukázkový zdroj): k potvrzené faktuře vznikne koncept
   s `corrective` = 1, odkazem, číslem a datem plnění původního dokladu,
   stejným partnerem, řadou a kurzem; `total_base` a `total_vat` jsou
   přesně opačné k původní faktuře; koncept bez důvodu opravy nejde
   potvrdit, s důvodem ano. Odmítnutí: koncept, stornovaný doklad,
   přijatá faktura.

### Commit 3 — akce v detailu, frontend, dokumentace (D6, U8)

1. `detailActions()` u dokladu podle U7.
2. Frontend: volání v `frontend/src/api/`, větev v `handleDetailAction`,
   texty v i18n, test obsluhy podle zvyklostí `frontend/tests`.
   `npm run build`.
3. Dokumentace: `docs/frontend.md` (slovník id akcí), `docs/rest-api.md`
   (nový endpoint, podle zvyklostí ostatních akčních rout), `CLAUDE.md` →
   Dokladový systém (služba opravného dokladu, jeden odstavec).
4. Nápověda: `help/faktury-vydane/opravny-doklad.md` — postup přes
   tlačítko jako hlavní cesta; `python3 scripts/help-index.py`.
5. `**Stav:**` tohoto tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (ukázkový zdroj)

- Potvrzená faktura vydaná → Vystavit opravný doklad → otevře se koncept
  se zápornými řádky, vyplněným původním dokladem a prázdným důvodem.
- Úprava řádků na částečnou opravu, doplnění důvodu, potvrzení; součty,
  rekapitulace a deník odpovídají záporné faktuře.
- Faktura v cizí měně: opravný doklad má kurz původní faktury.
- Detail původní faktury ukazuje nový opravný doklad (task A D7).
- Dump → seed ukázkové sady s opravným dokladem.

## Návaznost — starý Shipard

Po tomto tasku napsat task v `old_shipard`: `DocsRunner` posílá u dokladu
s `correctiveDoc` = 1 blok `correction` se samými `null`. Do té doby se
importované dobropisy chovají jako dnes (záporné faktury bez příznaku).

## Mimo rozsah

- Tisk a ISDOC opravného dokladu — task C.
- Opravný doklad z pošty (AI, přijatý ISDOC) — přijaté opravné doklady
  jsou mimo rozsah #92 (D11).
- Hromadné vystavení opravných dokladů a souhrnný doklad z více faktur —
  souhrnný doklad se pořizuje ručně bez odkazu.
- Vratka zboží na sklad.

## Pasti

- **Builder nesmí jít přes `DocumentExporter`.** Export nese
  `applyOptions` pro datové sady (import čísla, stav) — doklad by vznikl
  s číslem a stavem původního. Canonical skládej z databáze jako
  `InvoiceBuilder`.
- **Deklarované součty.** Canonical opravného dokladu nesmí nést
  `totals` ani `vatRecap` — jinak applier vypne automatiku zaokrouhlení
  a převezme rekapitulaci původního dokladu.
- **Znaménka řádků.** Sleva částkou se od ceny odečítá — u záporného
  řádku musí být taky záporná. Hlídá test `net_total` = −původní.
- **Řádek z celkové ceny** se záporným množstvím vyžaduje opravu #98
  (`doc-row-unit-price-negative-qty.md`, hotovo).
- **Datum plnění původního dokladu doplň už v applieru.** Dokument ho
  z odkazu přepisuje až v `beforeSave()` (`applyCorrectiveColumns`), ale
  `validate()` a odvození sazeb DPH běží dřív a datum sazby čtou z dat
  (`DocHeadVatContext::vatRateDate`). Applier proto po dohledání odkazu
  nastaví `corrected_doc_number` a `corrected_doc_date` sám — stejné
  hodnoty, jaké pak potvrdí dokument.
- **Dohledání podle čísla** jen mezi doklady téhož typu ve stavu
  V pořádku — koncept číslo nemá, stornovaný doklad se neopravuje.
- **Tři kopie schématu** — viz poznámky v `doc-total-rounding-auto.md`.
- **Dvojí kliknutí** na akci založí dva koncepty — tlačítko po dobu
  volání zablokovat (vzor ostatních akcí detailu).

## Hotovo když

- [ ] Canonical s blokem `correction` vytvoří opravný doklad; bez bloku
      se nic nemění.
- [ ] Dump → seed zachová opravný doklad včetně odkazu.
- [ ] Tlačítko v detailu potvrzené faktury vydané založí koncept
      opravného dokladu s opačnými částkami a otevře ho ve formuláři.
- [ ] Opravný doklad v cizí měně má kurz původní faktury a sazby k datu
      původního plnění.
- [ ] Dokumentace a nápověda aktuální.
- [ ] Testy zelené (cíleně, pak celá sada), `npm run build`.
- [ ] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`.
