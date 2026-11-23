# Task: Výchozí hodnoty dokladu na jednom místě — formulář i pokyn doplní totéž

**Stav:** naplánováno — zadání 2026-11-23 (#166 etapa 4, část 1; D1, D40–D45, D48–D50)

**Issue:** #166 (etapa 4)

**Cíl:** Co formulář předvyplní novému dokladu a řádku, to doplní i pokyn,
když to payload neuvádí — jedním kódem v `docs.core`. Minimální pokyn
(typ dokladu, partner, položka) pak dá stejný doklad, jaký vznikne ve
formuláři bez zásahu uživatele. Odpověď řekne, co pokyn doplnil sám.

## Před implementací přečti

- issue #166 — D1, D18–D29, D30–D39 a komentář s rozhodnutími etapy 4
- `tasks/doc-apply-instruction-mode.md`, `tasks/doc-format-references.md`
  včetně poznámek
- `docs/exchange-format.md` — § 3 „Dva režimy volání“ (tabulka D20), § 5,
  § 7, § 10 „Pipeline pokynu vedle návrhu“, „Importní mód“, „Invariant
  ukládání“, § 11 „Doklad skriptem“
- `docs/document-system.md`, `docs/edit-forms.md` — hook nového záznamu
  (`applyNewRecordDefaults`)
- formulář: `modules/docs/core/src/DocsHeadsFormBase.php`
  (`applyNewRecordDefaults()`, `applyClientDefaults()`,
  `vatAgendaDisabled()`, `resolveVatRegistrationOptions()`,
  `resolveDefaultBankAccount()`, `newRecordUsesBankAccount()`,
  `applyExchangeRate()`, `applyTotalRoundingAuto()`),
  `CashDeskFormBase.php`, `modules/docs/accountingDocs/src/AccountingDocsForm.php`,
  `DocRowsForm.php` (`applyNewRecordDefaults()`, `recalculate()` — větev
  `item`, `buildOperationOptions()`, `buildVatCodeOptions()`)
- `modules/docs/core/src/` — `DocDocument.php` (`beforeSave()`,
  `applyDateDefaults()`, `applyAuthorDefault()`, `validate()`),
  `DocHeadVatContext.php`, `DefaultBankAccountResolver.php`,
  `TotalRoundingAuto.php`, `DocAuthorResolver.php`
- applier: `modules/core/exchange/src/Document/DocumentApplier.php` —
  `transform()`, `transformRows()`, `documentVatMode()`,
  `instructionVatRegistration()`, `ownVatRegistration()`,
  `resolveTotalRoundingAuto()`, `defaultRowOperationsForApply()`,
  `exchangeRateDecision()`, validace cílového stavu v náhledu;
  `DocumentValidator.php` (kontroly povinnosti), `DocumentIssuePaths.php`
- `modules/core/exchange/src/Export/DocumentExporter.php`
- testy: `DocumentRoundTripMatrixTest` + `RoundTrip/`,
  `DocumentInstructionPayloadTest`, `DocumentApplierInstructionTest`

## Kontext

Výchozí hodnoty dnes žijí na třech místech: hook nového záznamu ve
formuláři, `transform()` applieru a `DocDocument::beforeSave`. Náhled
minimálního pokynu (vydaná faktura, odběratel a položka referencí, cíl
V pořádku) na ukázkovém zdroji:

| Co v payloadu chybí | Formulář | Pokyn dnes |
|---|---|---|
| datum vystavení | dnes | chyba `required` (dvakrát) + chyba na účetním datu |
| vlastní bankovní účet | výchozí účet zdroje | chyba `required`, i když výchozí účet existuje |
| kód DPH řádku | první kód nabídky pro směr a místo plnění | chyba `vat_code_missing` (v konceptu varování) |
| cena, text a jednotka řádku s položkou | z položky | cena prázdná, celkem 0, bez nálezu |
| množství | 1 | celkem 0, bez nálezu |
| režim DPH u neplátce | Bez DPH při vypnuté agendě DPH | „ze základu“, pak chyba na registraci |
| výchozí pohyb řádku | první pohyb nabídky | `docs.core.applyRowOperations` (podle typu položky) |

Shodně, ale každé vlastním kódem: registrace DPH, způsob úhrady,
automatické zaokrouhlení. Účetní datum, DUZP, DPPD, splatnost a autora
doplňuje `DocDocument` — ty jsou společné už dnes.

## Rozhodnutí k designu

Potvrzená (#166):

- ✓ **D1** — povinný je jen typ dokladu; ostatní má výchozí hodnotu, nebo
  ho hlídá validace dokladu.
- ✓ **D40 — Jedno místo.** Výchozí hodnoty hlavičky a řádku v `docs.core`;
  volá je hook formuláře i pokyn. Hodnota z payloadu vždy vyhrává. Návrh
  (pošta, ISDOC) se nemění.
- ✓ **D41 — Hlavička:** datum vystavení dnes, režim DPH podle agendy DPH
  a typu dokladu, registrace DPH, výchozí vlastní účet, způsob úhrady,
  měna (u pokladních typů měna pokladny).
- ✓ **D42 — Řádek s položkou:** chybějící text, cena a jednotka z položky,
  chybějící množství 1. Formulář při výběru položky přepisuje vždy, pokyn
  jen to, co payload neuvádí.
- ✓ **D43 — Kód DPH řádku:** chybí-li u dokladu s DPH, doplní se první kód
  nabídky jako ve formuláři (nikdy „Mimo DPH“).
- ✓ **D44 — `_resolve.defaults`:** odpověď vyjmenuje pole, která pokyn
  doplnil sám — v náhledu i po uložení.
- ✓ **D45 — Povinná pole:** schéma vyžaduje obálku a `docType`; vše ostatní
  hlídá `DocDocument` pro cílový stav jako u formuláře. Vlastní kontroly
  povinnosti validátoru v pokynu končí, nález je na pole jeden.
- ✓ **D48 — Importní mód:** výchozí hodnoty platí i v něm, kromě data
  vystavení — historie datum nést musí, chybějící je chyba.
- ✓ **D49 — V importním módu se obsah řádku nedoplňuje:** kód DPH, cena,
  text, jednotka ani množství. Historický řádek bez kódu by jinak tiše
  dostal základní sazbu a cenu z dnešní karty položky. Pohyb řádku se
  doplňuje i v importu (jako dnes).
- ✓ **D50 — Výslovné `null` znamená „záměrně prázdné“.** Chybějící klíč =
  doplní se výchozí hodnota; klíč s hodnotou `null` = pole zůstane prázdné.
  Export píše `null` výslovně u polí s výchozí hodnotou, která jsou na
  dokladu prázdná (doklad uložený bez vlastního účtu, řádek konceptu bez
  kódu DPH) — jinak by round-trip doklad změnil.

## Upřesnění pro implementaci

- **U1 — Umístění a tvar.** Třídy v `modules/docs/core/src/` (návrh:
  `Defaults\DocHeadDefaults`, `Defaults\DocRowDefaults`) pracují nad daty
  ve sloupcích tabulek, bez znalosti formuláře i formátu. Vrací seznam
  sloupců, které doplnily. Rozdíly mezi typy dokladu řídí atributy typu
  (`docTypes`), ne podtřída formuláře — dnešní
  `newRecordUsesBankAccount()` / `vat_mode = 0` účetního dokladu se
  převedou na atribut nebo na pravidlo nad existujícími atributy.
- **U2 — Hlavička.**

  | sloupec | pravidlo | dnes ve formuláři | dnes v applieru |
  |---|---|---|---|
  | `vat_mode` | účetní doklad 0; `economy.vatAgenda === false` 0; jinak 1 | hook + `AccountingDocsForm` | `documentVatMode()` — vždy `fromBase` |
  | `issue_date` | dnes | hook | chybí |
  | `vat_registration` | první aktivní registrace platná k datu dokladu (země, id); jen s DPH | první položka roletky, bez data | `ownVatRegistration()` |
  | `bank_account` | `DefaultBankAccountResolver`; jen typy, které účet nesou | hook | chybí |
  | `payment_method` | typ vázaný na pokladnu Hotově, jinak Převodem | hook + schéma | `transform()` |
  | `doc_currency` | měna pokladny u pokladních typů, jinak domácí měna | hook | ověř |
  | `total_rounding_auto`, mód | `TotalRoundingAuto` | hook | `resolveTotalRoundingAuto()` |

  Registrace: sjednoť na pravidlo s platností k datu (formulář ho dnes
  nezkoumá — vědomá změna). Kurz (`applyExchangeRate()` ×
  `exchangeRateDecision()`) ověř, že dává totéž; pokud ano, nech být
  a zapiš do poznámek. Změna u formuláře (registrace k datu) je
  odsouhlasená. Autor, účetní datum, DUZP, DPPD a splatnost se
  nemění (`DocDocument`).
- **U3 — Řádek.** Jen položkový řádek (`row_kind` 1) bez kontační strany.
  - pohyb: jedno pravidlo pro formulář i pokyn. Dnešní pravidla se liší
    (první pohyb nabídky × `applyRowOperations` podle typu položky) —
    sjednoť na `applyRowOperations` s pádem na první pohyb nabídky
    (změna u formuláře je odsouhlasená); případy, kde se formulář změní,
    vypiš do poznámek.
  - položka: text, cena (`sales_price_no_vat`, je-li vyplněná), jednotka;
  - množství 1;
  - kód DPH: první kód nabídky k datu sazby řádku, bez „Mimo DPH“; jen
    u hlavičky s DPH. Nabídka potřebuje kontext hlavičky, která v pokynu
    ještě není uložená — `DocHeadVatContext` dostane variantu z dat
    hlavičky (dnes čte jen z databáze podle id).
- **U4 — Pokyn.** Výchozí hodnoty se doplní po dohledání referencí
  (položka, pokladna, partner jsou známé) a před validací dokladu —
  stejnou cestou v `/preview` i `/apply`. Vlastní kopie pravidel
  z applieru zmizí. Doplněná pole jdou do `_resolve.defaults` jako cesty
  formátu (`dates.issueDate`, `vat.mode`, `vat.registration`,
  `payment.ownBankAccount`, `payment.method`, `currency`,
  `rows.N.description`, `rows.N.unitPrice`, `rows.N.unit`,
  `rows.N.quantity`, `rows.N.vat.code`, `rows.N.operation`); převod sloupec
  → cesta přes `DocumentIssuePaths`. Hodnoty, které dopočítává
  `DocDocument` (splatnost, DUZP, účetní datum), v seznamu nejsou.
- **U5 — Importní mód (D48, D49).** Chybějící datum vystavení = chyba.
  Obsah řádku se nedoplňuje; ostatní výchozí hodnoty ano.
- **U6 — `null` (D50).** Rozlišuj chybějící klíč a `null` u všech polí
  z U2 a U3. Objekt `vat` řádku bez klíče `code` je „chybí“ (doplní se);
  `"vat": {"code": null}` je záměrně bez kódu. `DocumentExporter` píše
  `null` výslovně tam, kde je pole s výchozí hodnotou na dokladu prázdné.
  Sekce `docs/` sad se tím mohou změnit — přegeneruj je.
- **U7 — Validace (D45).** V pokynu `DocumentValidator` povinnost polí
  nehlídá; rozhoduje `DocDocument::validate()` pro cílový stav
  a `/preview` ji ukáže. Jeden nález na pole (dnes `required` na datu
  vystavení dvakrát a odvozená chyba na účetním datu). Návrh beze změny.
- **U8 — Návrh.** Výsledek návrhu se nesmí změnit — smí se přesunout kód,
  ne chování (`dataset-verify` je strážce). Kde návrh a pokyn sdílely
  větev s výchozí hodnotou, návrh si ji drží.
- **U9 — Formulář.** Hooky volají společné třídy; chování formuláře se
  nemění kromě registrace k datu (U2) a případně pohybu (U3).
  `applyClientDefaults()` (jen renderování) bere hodnoty ze stejného
  místa, kde se dnes pravidlo opakuje.
- **U10 — Testy.**
  - nový případ invariantu „minimální pokyn = doklad z formuláře bez
    zásahu uživatele“: doklad cestou formuláře jen s výchozími hodnotami
    (hook hlavičky, partner, řádek s položkou přes hook a výběr položky)
    proti pokynu `{docType, partner, rows: [{item}]}` — shoda ve všech
    porovnávaných sloupcích. Typy: vydaná a přijatá faktura, pokladní
    doklad, účetní doklad.
  - payloady: hodnota z payloadu vyhrává; `null` nechá pole prázdné;
    import bez data vystavení = chyba; import neplní obsah řádku;
    `_resolve.defaults` v náhledu i po uložení; jeden nález na pole;
    řádek bez položky (doplní se jen množství a kód DPH).
  - neplátce (`economy.vatAgenda === false`): unit test společné třídy.
  - stávající případy „řádek bez kódu DPH“ mimo import změní očekávání
    (kód se doplní); v importu zůstávají.
  - matice round-tripu drží bez nové mezery.

## Co je potřeba udělat

### Commit 1 — společné třídy a formulář (U1–U3, U9)

Třídy výchozích hodnot + unit testy; hooky formuláře na ně; testy
formuláře beze změny očekávání kromě vyjmenovaných.

### Commit 2 — pokyn (U4, U5, U7, U8)

Applier volá společné třídy, `_resolve.defaults`, konec vlastních kopií
a kontrol povinnosti v pokynu.

### Commit 3 — `null` a export (U6)

Applier, `DocumentExporter`, přegenerované sekce `docs/` demo sady
a generátor sad.

### Commit 4 — testy invariantu (U10)

### Commit 5 — dokumentace a stav

1. `docs/exchange-format.md`: § 3 (tabulka D20 → výchozí hodnoty na
   jednom místě s odkazem na `docs.core`), § 5 a § 7 (chybějící × `null`),
   § 9 (`_resolve.defaults`), § 10 („Pipeline pokynu“, „Importní mód“),
   § 11 „Doklad skriptem“ — minimální payload jen s odběratelem
   a položkou.
2. `docs/document-system.md` — kapitola o výchozích hodnotách dokladu
   (jedno místo, kdo je volá, seznam); `modules/docs/core/README.md`,
   `CLAUDE.md`.
3. `**Stav:**`, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (zdroje s třídou dat `free`)

1. Matice, payloady, nový případ invariantu.
2. `dataset-verify` tří veřejných sad beze změny.
3. Demo sada: seed → dump → seed → dump stabilní; rozdíl proti sadě
   v repu jen výslovná `null` (U6).
4. Periodická fakturace a opravný doklad nad demo sadou proti kódu před
   taskem — rozdíly vyjmenuj a vysvětli v poznámkách (čeká se nejvýš
   doplněný vlastní účet tam, kde chyběl).
5. Minimální payload z návodu přes REST `/preview` a `/apply`.

## Mimo rozsah

- Idempotence pokynu (`tasks/doc-apply-idempotence.md`).
- Výchozí hodnoty v návrhu (pošta, ISDOC).
- Fyzické oddělení interpretace (etapa 5), runner starého Shipardu
  (etapa 6).

## Pasti

- Hook formuláře běží při otevření formuláře, pokyn nad hotovým payloadem
  — společná třída nesmí předpokládat ani jedno (uložená hlavička, známá
  položka).
- `DocDocument` nepozná „uživatel pole vymazal“ od „nikdo ho neuvedl“ —
  výchozí hodnoty proto nepatří do `beforeSave` (formulář by vymazaný
  účet nebo autora dostal zpět).
- Výchozí kód DPH mění částky: skript bez kódu dostane základní sazbu.
  `_resolve.defaults` a náhled jsou jediné místo, kde to uvidí — nesmí
  chybět ani v odpovědi `/apply`.
- Generátory dokladů (periodická fakturace, opravný doklad) a seed sad
  volají pokyn — výchozí hodnoty se jich týkají; porovnej výstup před
  a po.
- PHPUnit s úzkým `--filter`, grep s `--include`; integrační testy jen
  proti zdroji s třídou dat `free`.

## Hotovo když

- [ ] výchozí hodnoty hlavičky a řádku jsou na jednom místě v `docs.core`;
      formulář i pokyn je volají, applier vlastní kopie nemá
- [ ] minimální pokyn dá stejný doklad jako formulář bez zásahu uživatele
- [ ] `_resolve.defaults` v náhledu i po uložení
- [ ] importní mód podle U5, `null` podle U6, jeden nález na pole
- [ ] návrh beze změny (`dataset-verify`), matice drží
- [ ] cílené testy, pak celá unit sada
- [ ] dokumentace, stav tasku
