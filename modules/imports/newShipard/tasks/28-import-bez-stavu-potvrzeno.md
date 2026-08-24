# 28 — Import bez stavu Potvrzeno (20)

**Stav:** kód hotový, ověření na datech (dry-run DS A/B + jeden ostrý apply) čeká
**Issue (nová strana):** shipard/shpd#38 — rozhodnutí D5 varianta (a)
**Návaznost:** nová strana `tasks/docs-remove-confirmed-state.md` — **hotová**
(commity `c04ec60`, `b83ee52`, `b2b1b11`, `b030869`, `03f2775`): exchange
schema `targetDocState: 20` odmítá a 80 povoluje jen s `importNumber`.
Zbývá jen ověřit, že cílový DS má změnu skutečně nasazenou (compiled cfg
`docStates` + `ds-upgrade`) — jinak apply na 80 padne na neznámém přechodu.

## Cíl

Nový Shipard ruší stav Potvrzeno (20) na dokladech. `DocsRunner` se
přizpůsobuje ve dvou rolích, které stav 20 dosud plnil:

1. **Mapování starého stavu 1200 (Potvrzeno)** — ruší se. Ostrý import
   poběží v okamžiku, kdy žádný doklad ve stavu 1200 ve zdroji nebude;
   výskyt je chyba dat, ne stav k mapování.
2. **Parkovací strop pro nezaúčtovatelné cmnbkp** (majetkové operace
   1090070–73 bez mapování) — doklad se dál importuje kompletní
   (číslo, řádky), ale **parkuje se na stavu 80 (V opravě)** místo 20.
   Sémantika sedí: má číslo, je editovatelný, vyžaduje pozornost;
   alert `docs.core.stale_in_repair` na nové straně takové doklady
   navíc připomíná.

## Scope — `modules/imports/newShipard/libs/runners/DocsRunner.php`

### 1. `DOC_STATE_MAP_TARGET`

- Odstranit řádek `1200 => 20`. Doklad ve stavu 1200 tak spadne do
  existující větve „unknown old docState … (not in
  DOC_STATE_MAP_TARGET)" → tvrdá chyba dokladu (s `--continue-on-error`
  pokračuje dalším).
- Aktualizovat komentář u konstanty (ř. ~101: výčet cílových stavů
  „10 Koncept, 40 V pořádku, 30 Storno, 80 V opravě (parking);
  schema enum je [10, 40, 30]" — pozn.: 80 jde mimo
  `applyOptions.targetDocState`? viz bod 3).

### 2. `STATES_WITH_NUMBER`

- `[20, 30, 40]` → `[30, 40, 80]` — parkovaný doklad na 80 nese číslo
  (řada + sekvence + importNumber) stejně jako dřív na 20.
- Komentář u konstanty aktualizovat (zdůvodnění predikátu místo
  `>= 20` zůstává platné — 30 < 40 < 80 tu nehraje roli).

### 3. Cílový stav 80 vs. exchange schema — vyřešeno variantou (a)

Zvolena **(a)** a je hotová na nové straně, tady už není co rozhodovat:

- `shpd.docs.document.v1.jsonc` (i kompilovaný `.json`) má
  `targetDocState enum [10, 40, 30, 80]`,
- `DocumentValidator::checkParkingStateRequiresImport()` odmítá 80 bez
  `applyOptions.importNumber` (`error`, kód
  `target_state_80_requires_import`) — mimo migraci je 80 přes exchange
  nedosažitelné,
- `DocumentApplier` změnu nepotřeboval (`docState` průchozí, číslo řeší
  `_importNumber`),
- AI profil `czech_general.jsonc` nese enum včetně 80 (vynucuje to
  `ProfileSchemaDriftTest` = doslovná kopie kanonického schematu);
  bariérou pro mail/AI flow je tentýž validator guard.

Varianta (b) (import na 40 a spoléhat na validaci) byla v #38 zamítnuta.

**Invariant pro runner:** 80 se nikdy nesmí poslat bez `importNumber`.
Vychází to samo z `80 ∈ STATES_WITH_NUMBER` (číslo + řada se přidělí
jako u 30/40); placeholder `!…` failuje dřív a D14-B duplicita posílá
`sequenceNumber: null` uvnitř validního `importNumber` — guard projde.

### 4. Parkovací větev (`hasNonAccountable`)

- Ř. ~799: `$targetState = 20` → `$targetState = 80`; warn text
  „importing as Confirmed (20), not posted" → „parking as Being
  edited (80), not posted".
- Komentář bloku aktualizovat (strop na 80; „20 ∈ STATES_WITH_NUMBER"
  → 80).
- Komentář v `buildCmnbkpCanonical` (ř. ~1111 „zastropuje na stav 20").
- Podmínka `$targetState > 30` zůstává beze změny a je dál správná: po
  odstranění 1200 do ní vstupuje jen stav 40, storno (30) se necapuje
  (D15.4) a `--target-state=10` se vyhodnotí dřív v `targetDocState()`
  (parkování se tedy v testovacím režimu neaktivuje).

### 5. Ostatní komentáře

- Ř. ~2208: „Vydané faktury ho potřebují při stavu 20+
  (IssuedInvoiceDocument::validate)" → stavy 40/80.
- `targetDocState()` docblock: `--target-state=10` strop beze změny.
- `BaseExchangeRunner.php` ř. ~296/386 — zkontrolováno, beze změny:
  komentáře mluví obecně o post-apply PATCH pro stavy, které schema
  neumí (persons 70/80 mají vlastní enum [10, 40]).
- `BankStatementsRunner::DOC_STATE_MAP_TARGET` — mapování `1200 => 10`
  **zůstává**: výpisy jsou samostatná entita s enumem `[10, 40]`, zrušení
  stavu 20 na dokladech se jich netýká. Aktualizován jen komentář, ať po
  změně nesvádí ke zmatku.
- `README.md` modulu (stavová tabulka, parkování cmnbkp, „docState 20+"
  u vydaných faktur, pre-flight, historie fází) — jediná živá
  dokumentace; starší tasky se historicky nepřepisují.

## Pre-flight ostrého importu

Před během na každém zdrojovém DS:

```sql
SELECT docType, COUNT(*) FROM e10doc_core_heads
 WHERE docState = 1200 AND docType IN ('invni','invno','cmnbkp')
 GROUP BY docType;
```

Očekáván prázdný výsledek. Nález → doklady ve zdroji dořešit (potvrdit do
4000 nebo vrátit do 1000) před importem; runner je jinak odmítne tvrdou
chybou per doklad.

**Filtr na `docType` je podstatný** (korekce původního zadání): v téže
tabulce `e10doc_core_heads` žijí i bankovní výpisy (`docType='bank'`)
a typy mimo scope importu (pokladní doklady, objednávky, dodací listy).
Těch se zrušení stavu 20 netýká — výpisy mají vlastní enum `[10, 40]`
a jejich 1200 se dál mapuje na koncept. Holý `SELECT COUNT(*)` by tedy
hlásil falešné nálezy.

## Ověření

- Dry-run na pilotních DS (DS A, DS B) — pozor, dry-run **neposílá na
  server nic** (`BaseExchangeRunner` vrací `skipped: dry-run` ještě před
  HTTP), takže přijetí kombinace `targetDocState: 80` + `importNumber`
  schematem a guardem prokáže až ostrý apply (bod níže):
  - počty per docType×stav = zdroj modulo mapování (1000→10, 4000→40,
    4100→30, 8000→40; 1200 neexistuje)
  - parkované cmnbkp (majetkové op.) končí ve stavu **80** s číslem
    i řádky, bez deníku
  - žádný doklad ve stavu 20 v cílovém DS
- Deník: 0 nevyrovnaných mimo akceptované haléře (kritérium vlny D
  beze změny).
- **Jeden ostrý apply** na testovacím DS (úzký `--from/--to`, ideálně
  jediný parkovaný cmnbkp) — jediné skutečné ověření cesty 80 přes
  exchange (schema enum + guard `target_state_80_requires_import`).
- Cílený re-run dříve parkovaných dokladů (jsou v LocalIdMap):
  `DocsRunner` `afterSkippedExisting` **neimplementuje** (má ho jen
  `ItemsRunner`), takže druhý běh doklad jen přeskočí a stav 20 v cíli
  sám neopraví → pro dotčené ndx je potřeba `forget` (nebo `--reset`).
  Stejný závěr jako v akceptaci vlny D.

## Hotovo když

- [x] `1200` není v `DOC_STATE_MAP_TARGET`; doklad v 1200 = tvrdá chyba
- [x] parkování nezaúčtovatelných cmnbkp cílí stav 80 (s číslem)
- [x] `STATES_WITH_NUMBER = [30, 40, 80]`
- [x] cesta 80 přes exchange dohodnuta s novou stranou (enum + guard
      na importNumber) — varianta (a), hotová na nové straně
- [ ] dry-run DS A/B: počty sedí, parkované na 80, žádné 20
- [ ] ostrý apply jednoho parkovaného dokladu prošel (80 + importNumber)
- [x] pre-flight SELECT zdokumentován v README (sekce Doklady)
