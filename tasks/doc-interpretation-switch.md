# Task: Návrh = interpretace → pokyn; applier ukládá jen pokyn, endpoint `/interpret`

**Stav:** hotovo — 2026-11-23 (#166 etapa 5, část 2; D53–D55, D59–D62); zbývá proklik pošty člověkem a `ds-upgrade` zdrojů kvůli kopii schématu v profilu pošty

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

- [x] návrh běží cestou interpretace → pokyn; odpovědi mají stejný tvar
- [x] `DocumentApplier` a `DocumentValidator` nemají režim ani větve
      odvozování a nezávisí na jmenném prostoru interpretace
- [x] hotový doklad nemá `limitations` (D60, D61)
- [x] `/interpret` vrací hotový doklad a zprávu
- [x] `dataset-verify` a uložené doklady z návrhů shodné s kódem před
      taskem; testy invariantu beze změny
- [x] cílené testy, pak celá unit sada; dokumentace, stav tasku

## Poznámky k implementaci

Osm commitů na `stable`, každý se zelenou unit sadou: `e0064908` (meze
formátu), `8a8db657` (plán pokynu ve sdílené vrstvě), `45941cd3` (vstup
návrhu), `523f319d` (volající), `c076cedc` (testy návrhu, validátor),
`228f15c4` (applier bez režimu), `01dad074` (`/interpret`) a commit
s dokumentací.

### Rozpor v zadání — schéma se mění

Past „schéma se tímhle taskem nemění“ je starší než doplněné U3b. Platí
U3b: `rows[].unit` text nebo kladné celé číslo a `Party.bankAccount.ref`
ve všech třech kopiích (`.jsonc`, `.json`, `output_schema` profilu pošty —
`ProfileSchemaDriftTest` vyžaduje shodu). Profil pošty je uložený ve
zdroji dat; nové schéma model uvidí až po `ds-upgrade`.

### Meze formátu (D60, D61)

- **Jednotka číslem** = id aktivní jednotky (`UnitResolver::resolveId()`),
  v pokynu neexistující = chyba `unit_not_found`, v návrhu varování.
  `FinishedDocument` píše systémový kód, bez něj id. Výchozí jednotka pokynu
  bez systémového kódu jde dál stranou (`InstructionDefaults::unitIds`) —
  beze změny odpovědi pokynu.
- **`supplier.bankAccount.ref`** — moje upřesnění D61, ať pokyn vyjádří
  i rozhodnutí „bez vazby“: **id** = ten účet, **výslovné `null`** = účet
  se k záznamu neváže, **chybějící klíč** = přesná shoda čísla jako dřív
  (konvence formátu „chybí = výchozí chování, `null` = záměrně prázdné“).
  Interpretace píše klíč vždy: id (shoda, volba uživatele; po založení
  z `pending` ho doplní `withCreated()`), jinak `null`. Text `"@…"` je
  `reference_invalid`.
- **Účet jiné osoby** = chyba `bank_account_person_mismatch`, ale jen když
  doklad osobu dodavatele má. Doklad bez osoby dodavatele (strana „bez
  osoby“ v návrhu, účet se přitom našel) referenci přijme — jinak by se
  změnilo dnešní chování návrhu (stínový případ „dodavatel bez osoby“).
- **Známý okraj:** uživatel v review zvolí jiného dodavatele, než jakého
  našlo hledání, a účet se našel pod tím původním. Dřív doklad dostal
  partnera Y a účet partnera X; pokyn to odmítne
  (`bank_account_person_mismatch`). Je to přímý důsledek D61, ve veřejných
  sadách ani fixturách se nevyskytuje.
- **Návrh z pošty referenci účtu zahazuje** stejně jako `source.ref`
  (`MessageProposalApplier::withoutPartnerBankReference()`, i náhled) —
  pole je ve schématu, které vidí model. REST s `interpret: true` ji čte
  (má přednost před hledáním podle čísla).
- `limitations` zanikly celé (`InterpretedDocument`, `DocumentInterpreter`).

### Sdílená vrstva (D62)

- `PayloadResolve::basePlan()` — co plyne z bloků `_resolve` bez
  rozhodování; `plan()` — plán pokynu (projekce nalezených referencí).
  `RecordMatching::reconcile()` staví na `basePlan()` a přidává rozhodnutí.
- Zachované chování pokynu: strana vlastní firmy na zdroji bez vlastní
  osoby je dál `unresolved_required` (blok bez shody, který není
  referencí).
- `item.display`, `effectiveAccount` a přenos auditu obohacení nese jen
  zpráva návrhu; z náhledu pokynu zmizely.
- `SourceRefLookup` — dohledání dokladu podle `source.ref` čte pokyn
  i interpretace (nález `already_applied` v náhledu návrhu).
- `ApplyMode` žil i v kontextu DPH (`vatCtx['mode']`); nahradil ho příznak
  `interpreted`, který nastavuje `VatInterpretation::context()`.

### Vstup návrhu `DocumentProposal` (U1)

- `preview()` = `interpret()` + náhled pokynu nad hotovým dokladem +
  `ProposalReport::withComputed()`; `apply()` = jedna `NestedTransaction`:
  `PendingRecords::createFrom()` → `withCreated()` →
  `DocumentApplier::apply()` → `SupplierCodeLearning::learnFrom()` → vazba
  zprávy. Neúspěch pokynu vyhodí `ProposalStoppedException`, takže se vrátí
  i založené záznamy. `validate()` = schéma + kontroly předlohy.
- `interpret($canonical, saving: true)` — zpráva pro uložení nenese
  `item.display` / `effectiveAccount` ani nález o existujícím `source.ref`
  (odpověď uložení je neměla ani dřív).
- **Nálezy pokynu v odpovědi návrhu:** přebírají se ty, které zpráva
  interpretace ještě nemá (shoda kódu a cesty po převodu přes `rowMap`) —
  sdílené kontroly payloadu běží v interpretaci i v pokynu. **Varování
  úspěšně uloženého dokladu se nepřebírají** (dnešní chování, hlídá
  `testWarningsFromSuccessfulSaveAreReportedOnlyInInstruction`); applier je
  kvůli tomu vystavuje (`savedDocumentWarnings()`). `resolve_ignored`
  a `_resolve.defaults` se v odpovědi neobjeví.
- **Idempotence a zámek `source.ref`** (REST s `interpret: true`): rychlá
  cesta před interpretací (`DocumentApplier::existingBySource()`), zámek
  drží vstup návrhu přes commit své transakce (`lockSource()` /
  `unlockSource()`; `GET_LOCK` je pro jedno spojení opakovatelný, pokyn
  uvnitř projde) a doklad dohledává znovu jako první dotaz transakce. Bez
  toho by se zámek pokynu uvolnil před commitem vnější transakce.
- `InterpretedDocument::$operationIssues` — nálezy doplnění pohybu řádků
  odděleně od rozhodnutí, aby je odpověď při chybě rozhodnutí nenesla
  (jako dřív).

### Applier a validátor bez režimu (U3)

- **Idempotence podle zprávy a zápis vazby na zprávu patří vstupu návrhu.**
  `replaceConcept` volá jen periodická fakturace a `source.message`
  neposílá; exportér ani generátory ho také nepíšou. Pokyn se
  `source.message` uloží jen sloupec `source_message`.
- Z applieru zmizelo: parametr režimu, hledání podle údajů, volby
  `_resolve`, vynechání řádků, zakládání, učení, větve `derives()`, ceny
  s daní dodavatele v `transformRows()`, gatewaye osob a položek,
  `ItemResolver`, `AccountResolver`, `VatPlaceDerivation`, štítky obsahu.
  `ApplyMode` je smazaný. **`DocumentApplier.php` 3 166 → 2 558 řádků,
  `DocumentValidator.php` 376 → 365**; ani jeden nemá `ApplyMode`,
  `derives()` ani `use …\Interpret\…`.
- `PaperChecks::validate()` prokládá kontroly předlohy kontrolami payloadu
  z `DocumentValidator` (`checkDocTypeFields()`, `checkPartnerDocNumber()`,
  `checkOptionsAndValues()`) — pořadí nálezů návrhu se nezměnilo.

### Endpoint `/interpret` (U4)

- Odpověď `{document, report}`; `report` = bloky a nálezy zprávy návrhu
  (včetně `unresolved_required` / `conflict` — jsou to nálezy, ne chyba
  endpointu), `summary`, `pending` a `rowMap`.
- **Chyba schématu je 400 `schema_invalid`** — zadání píše 422 „jako
  u `/preview`“, ale `/preview` vrací 400; držím se `/preview`.
- V read-only zdroji je povolený (`ReadOnlyPolicy`), jen čte.
- Dodavatelské kódy se učí jen při uložení návrhem (`/apply` s
  `interpret: true`), ne při `/interpret` + vlastní `/apply`.

### Plné porovnání staré a nové cesty (U5)

- **Stínový test rozšířený na celé odpovědi** (do té doby jen `_resolve`
  náhledu a data k uložení): náhled i uložení — výsledek, HTTP stav,
  zpráva, celý payload s `_resolve` — a data předaná k uložení. Nad 33 unit
  fixturami a nad třemi veřejnými sadami (čerstvý seed, 64 návrhů, každý
  ve dvou variantách voleb) **bez rozdílu**; negativní kontrola (klíč navíc
  v odpovědi) shodila všech 25 případů.
- **Kód před taskem × po tasku** (worktree nad `7df087f5`, čerstvý seed
  tří veřejných sad): `dataset-verify --json` **bajtově shodný**; výpis
  `docs_core_heads`, `docs_core_rows`, `docs_core_vat_recap`,
  `economy_accounting_journal`, osob, účtů osob, položek, dodavatelských
  kódů, zpráv a analýz (bez časových sloupců) **bez jediného rozdílu**.
  Dva běhy původního kódu proti sobě dávají nulový šum. Zopakováno po
  přepnutí volajících, po převodu testů a po smazání staré cesty.
- Stínové testy zanikly: nad sadou po přepnutí volajících (použití návrhu
  už applierem neprochází), nad unit fixturami se starou cestou.

### Dva rozdíly, které odhalil až převod testů návrhu

Stínové fixtury je neměly; oba jsou opravené v `FinishedDocument`:

1. **Účet navržený historií u ručně zvolené položky** (#111 D7b) se
   nezapisuje — účet zapsaný referencí (`"@518100"`) zůstával v hotovém
   dokladu a pokyn ho uložil.
2. **Pokladní doklad s polem `partner` a stranou s jinou osobou**: v návrhu
   má pole přednost; pokyn by hlásil `partner_conflict`. Strana proto
   v hotovém dokladu ztrácí referenci i údaje osoby (jako strana „bez
   osoby“).

### Testy

Testy návrhu volají `self::proposalOf($applier)` (vstup návrhu nad mocky
testu); interpretaci staví `TestableDocumentApplier` (vlastní soubor)
z resolverů, které test předal. Očekávání beze změny, s těmito výjimkami:

- **D59:** žádný unit test dřívější výsledek (Bez DPH s kódy řádků)
  neočekával — nesl ho jen známý rozdíl stínového testu, který zanikl.
- `total_rounding_mode` bez odhadu: návrh klíč neposílal, hotový doklad
  nese výslovně 0 („na haléře“) — šest asercí `assertArrayNotHasKey` →
  `?? 0` (známá normalizace z části 1, uložený doklad stejný).
- Počty volání: původní doklad opravy dohledá interpretace i pokyn (dvě
  stejná čtení), zámek `source.ref` bere návrh i pokyn (čtyři události).
- Testy mechaniky pokynu, které běžely v režimu návrh jen historicky
  (vlastní transakce × savepoint, cíle uložení „před transakcí“, zamítnutý
  cíl `replaceConcept`), volají pokyn — v návrhu běží uvnitř jeho
  transakce a kryje je `DocumentProposalTest`.
- Fixtury: resolver referencí, ve kterém existuje každé id
  (`anyIdReferenceResolver()`), mock resolveru účtů s vlastníkem účtu,
  jednotka se systémovým kódem `hr` místo vymyšleného `hour`.
- Výchozí pohyb řádku a přednost založeného plátce před plánem se ověřují
  na hotovém dokladu (`FinishedDocument`, `withCreated()`), ne v
  `transform()`.

Nové: `DocumentProposalTest` (transakce a rollback založené osoby,
`pending` a `autoCreateMode`, převod cest přes `rowMap`, idempotence podle
zprávy, zámek, `/interpret`), `PayloadResolvePlanTest`, integrační
`DocumentProposalTransactionTest` (skutečná databáze: chyba pokynu vrátí
založeného dodavatele) a `DocumentInterpretEndpointTest`.

### Závěrečné ověření

1. Tři veřejné sady, čerstvý seed, kód před taskem × po tasku —
   `dataset-verify --json` bajtově shodný, uložené tabulky beze změny.
2. Integrační testy nad volným zdrojem `blkm-i8` (matice round-tripu,
   případy payloadů, invariant formulář × pokyn, idempotence, pošta, sady,
   účtování, DPH, tisky, majetek, zakázky): 578 testů, 16 přeskočených,
   bez selhání.
3. Demo sada nad `360b-n`, kód před × po: `dataset-dump` shodný až na čas
   vzniku v manifestu; po běhu periodické fakturace (20 období) hlavičky,
   řádky, rekapitulace, deník a období zakázek bez rozdílu. Opravný doklad
   kryje `CorrectiveDocServiceTest`.
4. `/interpret` nad předlohami sady: `document` poslaný na `/apply` dá
   stejný doklad jako `/apply` s `interpret: true`
   (`DocumentInterpretEndpointTest`).
5. Celá unit sada 12 219 testů zelená (`DatabaseManagerTest` hesla je
   nahodile nestabilní, s taskem nesouvisí).

**Zbývá člověku:** proklik náhledu a použití návrhu v poště na ukázkovém
zdroji; `ds-upgrade` zdrojů dat (kopie schématu v profilu pošty).
