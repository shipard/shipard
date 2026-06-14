# Fáze 10 — Revize importu dokladů: řady, stavy, parser čísel

## Kontext

Revize importu dokladů odhalila na této straně tři kořenové problémy
(párový task v nov_shipard: `tasks/docs-import-series-states.md`, který
**musí být nasazen první** — applier musí nové volby přijímat):

1. **DocsRunner neposílá identitu číselné řady.** Starý doklad ji nese ve
   sloupci `dbCounter` → cfg `e10.docs.dbCounters.{docType}.{dbCounter}.docKeyId`
   dává kód řady (%C), který odpovídá `doc_number_code` nové řady.
   Reálná data: invni dbCounter=2 (docKeyId "1", Faktury přijaté, 8680 ks)
   a dbCounter=3 (docKeyId "5", Ostatní závazky, 170 ks); invno dbCounter=1
   (docKeyId "1", 18610 ks).

2. **DocsRunner ignoruje starý docState** (filtruje jen 9800) a všem
   dokladům dává `finalDocState()` = 20. Důsledky: staré koncepty (1000,
   placeholder čísla `!000088028`) se importovaly jako potvrzené **včetně
   importNumber s nesmyslnou sekvencí** (fallback parseru vzal číslice
   z placeholderu → counter řady 1 zamořen na 88028), storna (4100) se
   tváří jako platné doklady, a doklady ve 20 nejdou v novém UI uložit
   (20→10 = release čísla, pojistka odmítne ne-poslední doklad v řadě).

3. **`parseSequenceNumber()` fallback „poslední skupina číslic"** při
   neshodě formule vrátí celé číslo dokladu jako sequence (doklad
   `22511204` → counter řady 2 zamořen na 22511204). Příčina neshody u
   tohoto dokladu: `%r` token (mark fiskálního roku) se vyhodnocuje z
   `oldRow['fiscalYear']`, který je u dokladu 0/nevyplněný → prefix
   nesedí → fallback.

Staré stavy (`e10doc.core.heads.docStates.default.json`): 1000 Nově
rozpracováno, 1200 Potvrzeno, 4000 Hotovo, 4100 Stornováno, 8000 V opravě,
9800 smazáno.
Nové stavy (`docs/core/config/docStates.jsonc`): 10 Koncept, 20 Potvrzeno,
80 V opravě, 40 V pořádku, **30 Storno**, 90 Smazáno. (Pozor: 70 „V archívu"
je pro doklady zrušeno — dřívější verze tohoto tasku mylně uváděla storno=70.
Schema enum `targetDocState` = `[10, 20, 40, 30]`.)

## Cíl

Doklady se importují do správné řady, ve správném stavu (mapovaném ze
starého), s korektní sekvencí — počítadla řad po importu odpovídají
nejvyšší reálné sekvenci dané řady. Žádné tiché špatné číslo: co nejde
spolehlivě určit, selže hlasitě.

## Návaznost

- Vyžaduje: nov_shipard task `docs-import-series-states.md` **nasazený**
  (schema `numberSeriesCode`, targetDocState 40/30, **`rows[].operation`** —
  Nález C). Ověřeno: task má všechny body hotové.
- Navazuje na: Fázi 05/05b (DocsRunner, import-mód čísla), Fázi 10 v
  ItemsRunneru není — číslování fází pokračuje z 09.

## Před implementací přečti

- `libs/runners/DocsRunner.php` — celý; zejména `mapRow` okolí ř. 345–400
  (targetState, importNumber, partnerDocNumber), `parseSequenceNumber()`
  (~ř. 455), `evaluateNumberTokens()` (~ř. 525), `finalDocState()` (~ř. 776),
  `sourceQuery()` (~ř. 245)
- `tasks/05b-doc-numbers.md` — původní rozhodnutí import-módu čísla
- nov_shipard `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc`
  — nový tvar applyOptions

## Scope

Jen `DocsRunner`. Nesahat na: BaseExchangeRunner (hook `afterSkippedExisting`
z Fáze items nechat být — pro docs se backfill neřeší, oprava jde přes
re-import), jiné runnery, applier (nov_shipard).

## Co implementovat

### 1. Mapování stavů `DOC_STATE_MAP_TARGET`

```
1000 → 10   (Koncept; bez čísla, bez importNumber)
1200 → 20   (Potvrzeno; s importNumber)
4000 → 40   (V pořádku; s importNumber → applier zaúčtuje)
4100 → 30   (Storno; s importNumber, bez deníku)
8000 → 40   (V opravě → finalizovat jako V pořádku; má číslo, zaúčtuje se)
```

- Neznámý starý stav → warn + fail dokladu (ne tichý default).
- `finalDocState()` nahrazen `targetDocState()` (mapa, vrací `?int`) +
  `stateHasNumber()` (predikát „stav nese číslo" = `[20, 30, 40]`, nahradil
  dosavadní `>= 20`).
- `--target-state=10` zachován jako globální strop „vše jako koncept" pro
  testovací běhy (přebíjí mapu i fail neznámého stavu); jinak se cílový stav
  řídí mapou.
- Stávající logika invno bez dohledatelného bank účtu (degradace na 10
  + warn) zůstává a má přednost před mapou.

### 2. `numberSeriesCode` z dbCounter

- `docKeyId = cfgItem('e10.docs.dbCounters.{docType}.{dbCounter}.docKeyId')`
  (`resolveNumberSeriesCode()`, **bez defaultu `'1'`** — chybějící cfg vrací
  `null`).
- Poslat v `applyOptions.numberSeriesCode` u všech dokladů se stavem nesoucím
  číslo (`stateHasNumber`). Stejný kód jde i do `%C` v `evaluateNumberTokens`
  (konzistence parseru sekvence s vybranou řadou).
- Nedohledatelný docKeyId (chybějící cfg) → warn + fail dokladu.
- Pozn.: applier řeší neexistující řadu na své straně (fail) — runner jen
  poctivě předává kód.

### 2b. Pohyb řádku `rows[].operation` (Nález C z párového tasku)

V tasku původně chybělo, ale **bez něj se cíl `4000 → 40` nesplní**: applier při
přechodu na stav 40 spouští `DocDocument::validateRowOperations` a každý
item-řádek musí mít platný pohyb (`operation`) pro daný docType. Schema
`rows[].operation` (string|null) i mapování verbatim už applier umí (nasazeno).

- `loadRows` mapuje starý číselný klíč `e10doc_core_rows.operation`
  (`e10.docs.operations`) → nový string (`docs.core.rowOperations`) přes
  `ROW_OPERATION_MAP` per docType:
  - invni: `purchase.goods` (Nákup zásob/bez evidence), `purchase.other`
    (majetek 1090050/51/52), `acc.entry` (zálohy 1020101/04, Účetní položka
    1099998).
  - invno: `sale.services` (Prodej služeb), `sale.goods` (Prodej zásob/majetku),
    `acc.entry` (zálohy 1010101/04, Účetní položka 1099998).
- Pohyb jen u **item-řádků**; text-řádek `operation = null` (jinak applier
  odmítne „Textový řádek nesmí mít pohyb").
- `acc.entry` vyžaduje vyplněný item — bez něj fallback na docType default.
- Neznámý/0 → docType default (invni `purchase.goods`, invno `sale.services`),
  logováno debugem.

### 3. Placeholder čísla `!…`

- `docNumber` začínající `!` = doklad bez přiděleného čísla:
  - žádný `importNumber`,
  - žádný `partner_doc_number` (ani u invni),
  - prakticky se týká jen starých konceptů (1000 → cíl 10), ale ochrana
    platí nezávisle na stavu — kdyby placeholder měl doklad v 4000,
    spadne na fail (reálný stav ≥ 20 vyžaduje reálné číslo, viz bod 4).

### 4. `parseSequenceNumber()` — odstranit fallback, opravit `%r`

- **Odstranit fallback „poslední souvislá skupina číslic".** Neshoda
  formule = `null`. Signatura: `parseSequenceNumber($oldRow, $seriesCode, &$diag)`
  — `$diag` nese formuli + vyhodnocený prefix/suffix + důvod neshody do hlášky.
- Nové pravidlo v `buildCanonical`: stav nesoucí číslo a doklad má reálné
  číslo, ale sequence je `null` → **fail dokladu** s důvodem (`$diag`). Radši
  hlasitě než tiše špatně.
- `evaluateNumberTokens()` `%r` (vyčleněno do `resolveFiscalYearMark()`): když
  `fiscalYear` na hlavičce chybí/0, dohledat fiskální rok podle `dateAccounting`
  v `e10doc_base_fiscalyears`. **Sloupce ověřeny: `start` / `end`** (ne
  `dateStart/dateEnd`), `mark` = 2-místný rok. Řeší doklad ndx=1 (`22511204`).

### 5. Diagnostika

- Na konci běhu vypsat souhrn per (docType, docKeyId): počet importovaných,
  failed, max sequence — usnadní kontrolu počítadel po importu
  (`recordSeries()` / `printSeriesSummary()`).

### 6. Mechanismus „hlasitý fail, import pokračuje" (bez sahání na base)

Tvrdé chyby (neznámý stav, nedohledatelná řada, neparsovatelná sekvence,
placeholder u stavu s číslem) nesmí být tichý skip. Protože
`buildCanonical()→null` base mapuje na `skipped/incomplete`, řeší se to v
`DocsRunner` (scope OK — jen override, ne úprava base):

- `buildCanonical` u tvrdé chyby nastaví `$this->rejectReason` a vrátí `null`;
  měkké skipy (nepodporovaný docType, bez partnera, bez řádků) `rejectReason`
  nesahnou.
- override `processOneRow` po `parent::` povýší `incomplete` + nastavený
  `rejectReason` → status `failed` **bez vyhození výjimky** → `processRows`
  pokračuje (abort je jen v `catch(HttpException)`), `run()` vrátí `false`.
  Funguje i v dry-runu (`buildCanonical` se volá před dry-run větví).

## Hotovo když

- [ ] Dry-run nad reálnými daty: žádný fallback warning, žádná sequence
      > reálného maxima řady; staré koncepty cílí 10 bez čísla; storna 30.
- [ ] Ostrý import vzorku (--from/--to měsíc): invni docKeyId 5 → řada
      „Ostatní závazky"; 4000 → stav 40 + záznamy v deníku +
      `accounting_state=1`; 4100 → 30 bez deníku; počítadla
      `docs_core_number_counters` = max reálné sekvence per (řada, FY).
- [ ] Item-řádky mají `operation` → doklady 4000 projdou na stav 40
      (validateRowOperations nehlásí chybějící pohyb).
- [ ] Doklad s nevyhodnotitelnou formulí → failed s čitelným důvodem,
      import pokračuje dalšími doklady.
- [ ] Idempotence: druhý běh stejného rozsahu nic nemění (skip).

## Doporučené pořadí

1. Stavová mapa + placeholder ochrana + `processOneRow`/`rejectReason`
   (bez nich nelze testovat zbytek)
2. numberSeriesCode
3. parseSequenceNumber (odstranění fallbacku, %r fix)
4. `rows[].operation` (Nález C)
5. Souhrnná diagnostika, testy, commit po celcích

## Postup nasazení / oprava existujících dat

Cílová DB je zamořená (sekvence 88xxx z placeholderů, 22511204 z fallbacku,
všech 1008 dokladů ve 20, invni v jedné řadě). Oprava = **čistý re-import
dokladů**, ne backfill:

1. nasadit nov_shipard task, pak tento,
2. na cílovém DS smazat doklady a navázaná data: `docs_core_heads`,
   `docs_core_rows`, `docs_core_vat_recap`, `docs_core_number_counters`,
   `economy_accounting_journal` (+ ověřit attachments vazby dokladů),
3. `forget --entity=doc` (hotovo — nový CLI subcommand, smaže jen doc mapování,
   codebook/person/item mapy zachová),
4. plný import docs přes orchestrátor.

Alternativa: úplný `ds-reset` cílového DS + kompletní re-migrace AllRunnerem
— čistší, ale delší; rozhodnout při nasazení podle stavu ostatních dat.

## Rozhodnutí ✓

- Řada = docKeyId z dbCounter ↔ doc_number_code; nedohledatelné = fail.
  (revize importu, 2026-06)
- Stavy: 1000→10, 1200→20, 4000→40 (+zaúčtování), **4100→30** (Storno, ne 70 —
  70 „V archívu" pro doklady neexistuje, schema enum `[10,20,40,30]`),
  **8000→40** (V opravě → finalizovat). (implementace 2026-06-14)
- Placeholder `!…` = bez čísla; fallback parseru se ruší; neparsovatelné
  číslo u stavu nesoucího číslo = fail dokladu.
- **Pohyb řádku** (`rows[].operation`, Nález C párového tasku) — runner ho
  musí posílat u item-řádků, jinak doklady neprojdou na stav 40. Plná mapa
  starých operací → nové (rozhodnutí: věrnost, ne jen docType default).
  (implementace 2026-06-14)
- Tvrdé chyby = `failed` (počítá se, `run()` vrátí false), ale import
  pokračuje — přes override `processOneRow`, BaseExchangeRunner se nemění.
- Oprava dat re-importem, ne backfillem.

## Otevřené body

- *(vyřešeno)* Sloupce rozsahu v `e10doc_base_fiscalyears` = `start` / `end`.
- *(vyřešeno)* CLI `forget --entity=doc` implementováno.
- formatVersion zůstává `1.0` — nové volby jsou aditivní rozšíření v1 schématu
  (párový task to potvrdil integračními testy). Ověřit v dry-runu, že applier
  payload nepinuje na konkrétní minor.
