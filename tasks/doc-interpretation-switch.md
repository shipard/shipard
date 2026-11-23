# Task: Návrh = interpretace → pokyn; applier ukládá jen pokyn, endpoint `/interpret`

**Stav:** naplánováno — zadání 2026-11-23, doplněno po ověření části 1 (#166 etapa 5, část 2; D53–D55, D59–D62)

**Issue:** #166 (etapa 5)

**Cíl:** Cesta návrhu (pošta, ISDOC, REST s `interpret: true`) se přepne
na „interpretace → založení chybějících záznamů → pokyn → učení“.
`DocumentApplier` ztratí režim a všechny větve odvozování — ukládá jen
pokyn. Přibude endpoint, který vrátí hotový doklad bez uložení.

## Před implementací přečti

- issue #166 — D51–D57
- `tasks/doc-interpretation-extract.md` včetně poznámek (stínový test
  musí být zelený, než začneš)
- `docs/exchange-format.md` — § 3, § 9, § 10 („Apply uvnitř cizí
  transakce“, „Přegenerovat v místě“, `autoCreateMode`), § 11, § 12
- `DocumentApplier`: `preview()`, `apply()`, `checkIdempotent()`,
  `writeLineageTargets()`, `runSideCreates()`,
  `writeSupplierCodeMappings()`
- volající: `MessageProposalApplier`, `AnalysisController`,
  `ExchangeController`, `Dataset/Verify/ProposalApplyStep`,
  `modules/core/mail/src/Mcp/MailDraftDocumentTool.php`
- `src/Api/Router.php` (trasy `/_exchange/docs/document/`), `docs/rest-api.md`

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **D53 — Applier ukládá jen pokyn,** bez režimu a bez větví
  odvozování. Vstup návrhu = interpretace → založení chybějících osob
  a položek → pokyn → učení dodavatelských kódů, v jedné transakci.
- ✓ **D54 — Chování návrhu se nemění:** uložené doklady i `dataset-verify`
  před a po shodné.
  - **Přijatá výjimka** (rozhodnuto 2026-11-23, nález stínového testu
    v `tasks/doc-interpretation-extract.md`): návrh, který zůstane
    v režimu Bez DPH, aniž ho interpretace považuje za doklad neplátce
    nebo doklad mimo české DPH (předloha říká `vat.mode: none`, ale řádek
    nese daň dodavatele), dnes uloží `vat_mode` 0 s kódem DPH na řádku
    a s řádkem rekapitulace. Po přepnutí vznikne **bez kódů a bez
    rekapitulace** — doklad Bez DPH kódy nést nemá (#164). Ve veřejných
    sadách se nevyskytuje, `dataset-verify` tím není dotčený. Unit test,
    který by dnešní výsledek očekával, se při přepnutí upraví a zapíše
    do poznámek.
- ✓ **D55 — Endpoint `/interpret`** vrátí hotový doklad a zprávu bez
  uložení; `/apply` s `interpret: true` zůstává jako obojí najednou.

- ✓ **D59 — Přijatá výjimka z D54:** návrh v režimu Bez DPH s daní
  dodavatele na řádku vznikne po přepnutí bez kódů DPH a bez rekapitulace
  (podrobně v U5).

Meze formátu, které našla část 1 — bez nich by přepnutí změnilo chování
návrhu:

- ✓ **D60 — Jednotka i číslem.** `rows[].unit`: text = systémový kód
  (D37), číslo = id jednotky. Jednotka bez systémového kódu (založená
  uživatelem) se jinak do formátu zapsat nedá a hotový doklad by ji
  ztratil. Export píše dál systémový kód; jednotku bez něj vynechá
  s varováním jako dnes.
- ✓ **D61 — Bankovní účet partnera referencí.** `supplier.bankAccount.ref`
  = id účtu partnera. S `ref` pokyn použije ten účet; bez něj přesná
  shoda s účtem osoby jako dnes. Interpretace ho píše vždy, když účet
  zná (shoda nebo volba uživatele). Export `ref` nepíše — účet nemá
  přirozený klíč a data stačí.
- ✓ **D62 — Applier nezávisí na interpretaci.** `item.display`
  a `effectiveAccount` řádků jsou zpráva návrhu; z náhledu pokynu zmizí.
  Co pokyn potřebuje k uložení, je ve sdílené vrstvě.

## Upřesnění pro implementaci

- **U1 — Vstup návrhu.** Třída ve jmenném prostoru interpretace (návrh
  názvu `DocumentProposal`) s `preview()` a `apply()`:
  - `preview()` — `interpret()` + výpočet částek pokynem nad hotovým
    dokladem (`computed`) + porovnání s částkami z papíru
    (`computed_total_mismatch` a spol. zůstávají nálezy interpretace).
    Validaci cílového stavu náhled návrhu nemá ani teď (D54).
  - `apply()` — v jedné transakci: `interpret()` → založení záznamů
    z `pending` podle `autoCreateMode` a voleb uživatele (doplní reference
    do hotového dokladu) → `DocumentApplier::apply()` (vnořená transakce)
    → učení (`learn`) → vazba na zprávu. Chyba kteréhokoli kroku = rollback
    všeho, jako dnes.
  - Odpověď má **stejný tvar jako dnes**: původní payload + `_resolve`
    (zpráva, `computed`, nálezy) + `savedDocId`. Nálezy pokynu nad hotovým
    dokladem se přes `rowMap` převedou na cesty původního payloadu; info
    `resolve_ignored` ani `_resolve.defaults` se v odpovědi návrhu
    neobjeví.
- **U2 — Volající.** `MessageProposalApplier`, `AnalysisController`,
  `ExchangeController` (`interpret: true`), `ProposalApplyStep` volají
  vstup návrhu; pokyn volají `DocumentSeeder`, `InvoicingRunService`,
  `CorrectiveDocService` a REST bez `interpret`.
- **U3 — Applier.** `ApplyMode` zaniká; `validate()`, `preview()`,
  `apply()` bez parametru režimu. Z applieru a `DocumentValidator` zmizí
  vše, co četl jen návrh: hledání stran a položek podle údajů, volby
  `_resolve`, vynechání řádků, zakládání, `autoCreateMode`, heuristiky
  validátoru, oprava IBAN ↔ číslo účtu, učení kódů, větve `derives()`.
  Idempotence podle zprávy pošty a zápis vazby na zprávu patří vstupu
  návrhu — pokud je nepotřebuje jiný volající pokynu (ověř u
  `replaceConcept`, rozhodni a zapiš do poznámek).
  Kritérium: v `Document/DocumentApplier.php` a `DocumentValidator.php`
  není `ApplyMode` ani `derives()`; do poznámek napiš počet řádků před
  a po.
- **U3b — Meze formátu (D60, D61) a závislosti (D62).**
  - schéma (tři kopie): `rows[].unit` text nebo kladné celé číslo;
    `Party.bankAccount.ref` (definice reference). Pokyn: jednotka číslem
    = id, neexistující = `unit_not_found`; `bankAccount.ref` přes
    `ReferenceResolver`, účet jiné osoby = chyba. Přirozený klíč účet
    partnera nemá — `"@…"` je `reference_invalid`.
  - `FinishedDocument` píše jednotku bez systémového kódu číslem a účet
    partnera referencí; `limitations` `unit_without_system_code`
    a `partner_bank_pin` zanikají. Zůstane-li v `limitations` cokoli
    jiného, přepnutí nedělej a zapiš to.
  - v `Document/DocumentApplier.php` není žádný `use …\Interpret\…`:
    projekci nalezených referencí do plánu (`reconcile()` pokynu) drží
    sdílená vrstva; `annotateRowDisplay()` a `carryOverEnrichment()` volá
    jen vstup návrhu.
- **U4 — REST `/interpret`.**
  `POST /api/v1/_exchange/docs/document/interpret`, tělo = papírová podoba
  (s volitelným `_resolve`), oprávnění jako `/preview`. Odpověď
  `{"document": <hotový doklad>, "report": <zpráva včetně pending>}`,
  nic nezapisuje. `document` jde poslat na `/apply` jako pokyn; má-li
  `pending` záznamy, pokyn ho odmítne (`reference_missing`) — volající je
  založí sám (API osob a položek), nebo použije `/apply` s
  `interpret: true`. Chyba schématu = 422 jako u `/preview`.
- **U5 — Stínový test** z předchozího tasku porovnává dvě cesty; po
  smazání staré zaniká. **Před smazáním** staré cesty proveď a zapiš plné
  porovnání podle „Ověření“. Dlouhodobými strážci zůstávají
  `dataset-verify`, unit testy návrhu (převedené na vstup návrhu, beze
  změny očekávání) a nové testy vstupu návrhu: transakce (chyba pokynu
  vrátí i založené osoby), `pending` a `autoCreateMode`, převod cest přes
  `rowMap`, idempotence podle zprávy.

## Co je potřeba udělat

1. Vstup návrhu (U1) + testy; volající přepnout (U2).
2. Plné porovnání staré a nové cesty (U5), zápis do poznámek.
3. Applier a validátor bez režimu (U3); smazání staré cesty a stínového
   testu.
4. Endpoint `/interpret` (U4) + integrační test endpointu.
5. Dokumentace v nezbytném rozsahu: `docs/exchange-format.md` § 3, § 10,
   § 11 (nový endpoint, `interpret: true`), `docs/rest-api.md`,
   `CLAUDE.md`; plný přepis je `tasks/doc-layers-documentation.md`.
   Stav tasku, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (zdroje s třídou dat `free`)

1. Tři veřejné sady, čerstvý seed, kód před taskem × po tasku
   (worktree): `dataset-verify --json` bajtově shodný; uložené hlavičky,
   řádky, rekapitulace a deník dokladů z návrhů shodné.
2. Matice round-tripu, případy payloadů, invariant formulář × pokyn,
   idempotence — beze změny.
3. Demo sada: seed → dump stabilní; periodická fakturace a opravný
   doklad beze změny.
4. REST: `/interpret` nad návrhem z veřejné sady → `document` poslaný na
   `/apply` dá stejný doklad jako `/apply` s `interpret: true`
   (u návrhu bez `pending`).
5. Náhled a použití návrhu v poště prokliknout na ukázkovém zdroji
   (pouští člověk).

## Mimo rozsah

- Výchozí hodnoty formuláře pro doklady z návrhu (odloženo, po evaluaci).
- Validace cílového stavu v náhledu návrhu.
- Dokumentace po vrstvách, úklid schématu
  (`tasks/doc-layers-documentation.md`).
- Runner starého Shipardu (etapa 6), #112 a #113 (etapa 7).

## Pasti

- Zakládání osob a položek musí proběhnout **uvnitř** transakce uložení —
  dnes to tak je (`runSideCreates()`); nový tok to nesmí rozdělit.
- `MailDraftDocumentTool` a `dataset-verify` jdou přes
  `MessageProposalApplier` — přepnutím jednoho místa se mění obojí.
- Odpověď návrhu čte frontend pošty (review modal): bloky `_resolve`
  řádků podle původních indexů, `matchedBy`, `canCreate`, `computed`.
  Tvar se nesmí změnit.
- Kopie schématu v profilu pošty zůstává (strážní test) — schéma se
  tímhle taskem nemění.
- PHPUnit s úzkým `--filter`, grep s `--include`; integrační testy jen
  proti zdroji s třídou dat `free`.

## Hotovo když

- [ ] návrh běží cestou interpretace → pokyn; odpovědi mají stejný tvar
- [ ] `DocumentApplier` a `DocumentValidator` nemají režim ani větve
      odvozování a nezávisí na jmenném prostoru interpretace
- [ ] hotový doklad nemá `limitations` (D60, D61)
- [ ] `/interpret` vrací hotový doklad a zprávu
- [ ] `dataset-verify` a uložené doklady z návrhů shodné s kódem před
      taskem; testy invariantu beze změny
- [ ] cílené testy, pak celá unit sada; dokumentace, stav tasku
