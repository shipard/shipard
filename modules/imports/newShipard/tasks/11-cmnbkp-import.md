# Účetní doklady (cmnbkp) — Fáze 4b (old_shipard): DocsRunner

## Kontext

Párový úkol k `nov_shipard` `tasks/accounting-docs-phase4-import.md`
(**nasazuje se první** — applier musí kontační pole znát dřív, než je posíláme).
Tento rozšiřuje `DocsRunner` o import účetních dokladů (`cmnbkp`) ze starého
Shipardu přes exchange formát `shpd.docs.document.v1`.

Dnešní `DocsRunner` umí jen faktury (`invni`/`invno`). cmnbkp je odlišný:
nemá obchodní směr (žádná selfParty), hlavičkový partner je nepovinný, a řádky
jsou **kontace** — účet + strana MD/DAL + částka + per-řádková saldo identita,
ne položky.

Zdrojová data (ověřeno): `e10doc_core_rows` (+ debs rozšíření) nese
`debsAccountId` (účet, string), `operation`, `item`, `itemBalance`,
`debit` (= Má dáti), `credit` (= Dal), `person`, `symbol1/2/3`, `dateDue`,
`text`. Activity-generované cmnbkp se importují jako obecné účetní doklady
(D-imp-1); `taxrows` se ignorují (D-imp-2).

## Cíl

`DocsRunner` importuje i `cmnbkp`: sestaví kanonický `accountingDocument`
s kontačními řádky (účet z `debsAccountId` nebo položka, strana z `debit`/
`credit`, per-řádkový partner + symboly + splatnost), bez selfParty, číselná
řada z `dbCounter`. Doklad ve stavu 40 se na nové straně zaúčtuje + vygeneruje
saldo. Import faktur beze změny.

## Návaznost

- **Předchází:** Fáze 05/05b/10 (import faktur), `nov_shipard` Fáze 4a
  (**nasadit první**).
- Stejný orchestrátor (`AllRunner`), chunkování, číslo-mód, LocalIdMap.

## Před implementací přečti

- `modules/imports/newShipard/libs/runners/DocsRunner.php` — `DOC_TYPE_MAP`,
  `SELF_PARTY_MAP`, `buildCanonical()` (skip na chybějícím partnerovi,
  selfParty/supplier/customer, sestavení canonical), `loadRows()`,
  `mapRowOperation()` / `ROW_OPERATION_MAP`, `resolveNumberSeriesCode()`,
  `DOC_STATE_MAP_TARGET`, `parseSequenceNumber()`, `sourceQuery()` /
  `effectiveDateRange()` (řízené `array_keys(DOC_TYPE_MAP)`).
- Starý config `e10.docs.operations` (cmnbkp operace — `forceAccount`),
  `e10.docs.dbCounters.cmnbkp` (docKeyId), `e10.docs.types.cmnbkp.docNumber`
  (`%D%y%C%4`).
- `nov_shipard` `tasks/accounting-docs-phase4-import.md` — protistrana
  (kanonická pole řádku: `account`, `accSide`, `paymentReference`,
  `specificSymbol`, `constantSymbol`, `dueDate`; per-řádkový partner přes
  `_resolve.rows[i].partner`).

## Scope

`DocsRunner` (mapy + cmnbkp větev v `buildCanonical` + kontační `loadRows`).
**Nesahat na:** import faktur (stejné cesty zůstanou), applier (nov_shipard),
orchestrátor.

## Co implementovat

### 1. Mapy + zařazení do dotazu

- `DOC_TYPE_MAP` — přidat `'cmnbkp' => 'accountingDocument'`. Tím se cmnbkp
  automaticky dostane do `sourceQuery()` i `effectiveDateRange()`
  (oboje řízené `array_keys(DOC_TYPE_MAP)`).
- `SELF_PARTY_MAP` — cmnbkp **nepřidávat** (selfParty zůstane null).
- `DOC_STATE_MAP_TARGET` — ověřit, že cmnbkp používá stejné staré stavy
  (1000/1200/4000/4100/8000); mapa se sdílí.

### 2. `buildCanonical()` — větev cmnbkp

Pro `oldDocType === 'cmnbkp'`:

- **Nepřeskakovat** na `person <= 0` (hlavičkový partner nepovinný). Pokud
  `person > 0`, nastav volitelného hlavičkového partnera přes pin
  (`_resolve.partner` = `useExisting:<newId>` z LocalIdMap), jinak null.
- `supplier` = `customer` = null, `selfParty` nevyplňovat.
- `docType` = `'accountingDocument'`.
- Řádky přes nový `loadCmnbkpRows()` (níže).
- Číselná řada + sekvence + stav: beze změny použít stávající cestu
  (`resolveNumberSeriesCode` přes `dbCounter`, `parseSequenceNumber`,
  `targetDocState`, `importNumber`). Vlastní bank účet (invno) se cmnbkp
  netýká.
- `partnerDocNumber` = null (cmnbkp číslo je naše, jde do `importNumber`).
- `payment` blok minimální (hlavičkové symboly cmnbkp nemá — jsou na řádcích).

### 3. `loadCmnbkpRows()` — kontační řádky

Načíst `e10doc_core_rows` (+ `debsAccountId` z debs rozšíření; LEFT JOIN
items kvůli kódu/pin) pro doklad. Pro každý řádek:

- **operace + zdroj účtu:**
  - `debsAccountId` neprázdné → operace `acc.record`, kanonické `account` =
    `debsAccountId` (string číslo), `item` = null.
  - jinak `item > 0` → operace `acc.item`, `item` fragment (`ourCode`/`name`)
    + pin `_resolve.rows[i].item` (LocalIdMap, jako u faktur), `account` = null.
  - (Cross-check: operace s `forceAccount` v cfg odpovídá acc.record.)
- **strana + částka:** `debit != 0` → `accSide='debit'`, `totalPrice=debit`;
  `credit != 0` → `accSide='credit'`, `totalPrice=credit`. (Vyplněna právě
  jedna; obě 0 → řádek přeskočit / warning.)
- **per-řádkový partner:** `person > 0` → pin `_resolve.rows[i].partner` =
  `useExisting:<newId>` (LocalIdMap person ndx → nové id).
- **identita:** `paymentReference=symbol1`, `specificSymbol=symbol2`,
  `constantSymbol=symbol3`, `dueDate=dateDue`.
- `description = text`, `orderPos` dle `rowOrder`/`ndx`.
- `rowKind = 'item'` (kontační řádek je v novém modelu `row_kind=1`),
  `priceCalcMode='fromTotal'` (ať se `total_price` nepřepisuje),
  qty/unitPrice/vat prázdné.

Vrací `rows` + pozičně zarovnaný `resolve` (jako stávající `loadRows`):
`_resolve.rows[i]` nese `item` i `partner` piny dle výše.

## Hotovo když

- `DocsRunner` (i v rámci `AllRunner`) importuje cmnbkp doklady: vznikne
  `cmnbkp` ve správné řadě a stavu, s kontačními řádky (účet, strana, částka,
  partner, symboly, splatnost).
- „Účetní zápis" řádky → `acc.record` + účet z čísla; „Účetní položka" → 
  `acc.item` + položka přes pin.
- Doklad bez hlavičkového partnera projde; doklad s `person` na hlavičce má
  hlavičkového partnera.
- Doklady ve 40 se na nové straně zaúčtují a vygenerují saldo (per-řádková
  identita); nevyrovnané hlásí chybu validace (z `AccountingDocument`).
- Series summary vypíše cmnbkp řady; import faktur beze změny.

## Doporučené pořadí

1. `DOC_TYPE_MAP` + ověření stavové mapy.
2. `buildCanonical` větev cmnbkp (partner nepovinný, bez selfParty).
3. `loadCmnbkpRows`.
4. Běh proti vzorku (limit) → kontrola řádků, stavů, deníku + salda na nové
   straně; pak plný běh.

## Rozhodnutí ✓

- **D-imp-1 / D-imp-2 / D-imp-3** — viz `nov_shipard` task.
- Nasazení: **nov_shipard 4a první**, pak tento.

## Otevřené body

- Řádky s nulovými `debit` i `credit` (textové/oddělovací) — přeskočit s
  debug logem (nelze určit stranu).
- `bankAccount` (Č. účtu) na řádku, středisko/zakázka/majetek — mimo MVP.
- README (`tasks/`) — spravuje David.
