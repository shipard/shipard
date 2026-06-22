# 11 — Migrace bankovních výpisů (BankStatementsRunner)

> **Oprava (2026-06-22):** symbolová pole transakce v kanonickém schématu byla
> přejmenována — runner posílal `symbol1/symbol2/symbol3`, ale `shpd.bank.statement.v1`
> má `additionalProperties: false` a zná jen `paymentReference` (VS) /
> `specificSymbol` (SS) / `constantSymbol` (KS) → každý výpis s transakcí padal
> na `schema_invalid`. Opraveno v `loadTransactions()` (VS→paymentReference jako
> Fáze 09). Re-run bez `ds-reset` (failnuté výpisy se do LocalIdMap neuložily).

## Kontext

Runner na **staré straně**, který přečte bankovní výpisy ze starého Shipardu
(`e10doc_core_heads` `docType='bank'` + `e10doc_core_rows`) a pošle je přes
výměnný formát `shpd.bank.statement.v1` na novou stranu (`ExchangeClient` →
`POST /_exchange/bank/statement/apply`). Závěrečná fáze modulu `economy.bank`
(návrh `nov_shipard:docs/bank.md` §7).

**Závislost na nasazení:** nová strana
(`nov_shipard:tasks/bank-phase4.md` — schéma + `BankStatementApplier` +
endpoint) **musí být nasazena dříve**, než se tento runner spustí. Bez ní
endpoint neexistuje.

**Rozsah migrace (rozhodnuto, `docs/bank.md` §7):** migrujeme **jen výpisy
a jejich transakce**. Nová strana je zaúčtuje novým enginem na clearing
(261200/261300). **Párování (saldo) se nemigruje** — historický deník starého
systému se nereprodukuje; nenulové clearing zůstatky po migraci jsou
očekávané a rozpustí se s budoucím saldem.

Pracovní balíčky:

- **W1** — `BankStatementsRunner` (čtení starých výpisů → výměnný formát)
- **W2** — dohledání našeho účtu + idempotence (LocalIdMap)
- **W3** — mapování stavu / částek / symbolů / dat
- **W4** — migrace PDF příloh výpisu
- **W5** — registrace (AllRunner + samostatný příkaz) + LocalIdMap entita
- **W6** — dry-run + testy

## Návaznost

- `nov_shipard:tasks/bank-phase4.md` — protistrana (deploy dříve).
- `nov_shipard:docs/bank.md` §7 (migrace), §3 (cílové sloupce transakce/výpisu).
- Vzor: `DocsRunner` (čte `e10doc_core_heads`+rows, staví exchange, POST,
  chunkování, LocalIdMap, diagnostika) — **hlavní šablona**, ale jednodušší.
- Existuje `BankAccountsRunner` (číselník účtů → LocalIdMap
  `ENTITY_BANK_ACCOUNT`) — `myBankAccount` výpisu přes něj dohledáme.

## Před implementací přečti

- `modules/imports/newShipard/libs/runners/DocsRunner.php` — **hlavní vzor**:
  `run()` (pre-flight, chunkování, stats), `sourceQuery()`, `buildCanonical()`,
  `loadRows()`, `resolveOwnBankAccount()` (LocalIdMap `ENTITY_BANK_ACCOUNT`
  z `myBankAccount`), `parseBankAccountString()`, helpery
  (`dateToString`/`moneyOrNull`/`emptyToNull`)
- `modules/imports/newShipard/libs/BaseExchangeRunner.php` — báze
  (`processOneRow`, LocalIdMap skip already-imported, `entityType`/
  `exchangeFlow`/`exchangeType`/`savedIdKey`/`entityLabel`)
- `modules/imports/newShipard/libs/ExchangeClient.php`,
  `libs/LocalIdMap.php` (přidat `ENTITY_BANK_STATEMENT`),
  `libs/runners/AllRunner.php` (registrace), `libs/runners/AllCodebooksRunner.php`
- `modules/imports/newShipard/libs/AttachmentImporter.php`,
  `AttachmentReader.php`, `AttachmentUploadClient.php` — migrace PDF (W4),
  vzor task `07a-attachments-client.md`
- starý model bankovního výpisu: `modules/e10doc/bank/bank.php`
  (`ebankingImportDoc` — pole hlavičky/řádků), `modules/e10doc/bank/libs/`
  (sloupce: hlavička `myBankAccount`/`currency`/`datePeriodBegin`/
  `datePeriodEnd`/`docOrderNumber`/`initBalance`/`balance`; řádek `debit`/
  `credit`/`bankAccount`/`symbol1`/`symbol2`/`symbol3`/`dateDue`/
  `exchangeRate`/`person`/`text`)

## Scope

### V scope

- runner čtoucí `e10doc_core_heads` `docType='bank'` (mimo smazané) + rows
- stavba `shpd.bank.statement.v1` (hlavička výpisu + transakce)
- dohledání našeho účtu (`myBankAccount` → LocalIdMap), idempotence výpisu
- mapování: `debit`/`credit` → znaménková `amount`, symboly, data, protiúčet,
  memo; `operation = null` (nová strana doplní default dle směru)
- migrace PDF příloh výpisu
- registrace v `AllRunner` + samostatný příkaz; dry-run

### Mimo scope

- **párování / person → partner** — nepřenáší se (saldo-nezávislé; nová strana
  případně dohledá partnera dle protiúčtu)
- **účtování** — dělá nová strana (applier vytvoří transakce ve stavu 40)
- ostatní typy dokladů, příkazy k úhradě (`bankorder`)

---

## Co implementovat

### W1 — `BankStatementsRunner`

`modules/imports/newShipard/libs/runners/BankStatementsRunner.php extends
BaseExchangeRunner` (vzor `DocsRunner`, podstatně jednodušší — bez řad, čísel,
selfParty):

- `entityType()` → `LocalIdMap::ENTITY_BANK_STATEMENT`; `exchangeFlow()` →
  `'bank'`; `exchangeType()` → `'statement'`; `savedIdKey()` →
  `'savedStatementId'`; `entityLabel()` → `'bank statement'`
- `sourceQuery()`: `SELECT h.* FROM e10doc_core_heads h WHERE h.docState != 9800
  AND h.docType = 'bank'` (+ volitelně rozsah `dateAccounting`/`datePeriodEnd`,
  vzor DocsRunner; chunkování per měsíc je u výpisů spíš zbytečné — výpisů je
  málo, lze vynechat)
- `buildCanonical($head)`: dohledá náš účet (W2), načte řádky (W3), sestaví
  payload dle schématu `shpd.bank.statement.v1` (W1 v `bank-phase4`):
  `bankAccountId`, `statement{…}`, `transactions[]`, `applyOptions{targetState,
  createMissingPartner:false}`. Vrátí `null` (skip) jen když chybí dohledatelný
  náš účet (warning).

### W2 — Náš účet + idempotence

- **Náš účet:** `resolveOwnBankAccount($head)` (vzor DocsRunner): `myBankAccount`
  (ndx) → `idMap()->lookup(LocalIdMap::ENTITY_BANK_ACCOUNT, $myBankNdx)`.
  Nenalezeno → warning + skip výpisu (účet musí být napřed naimportován
  `BankAccountsRunner` — `AllCodebooksRunner` běží před doklady).
- **Idempotence:** výpis se mapuje přes `LocalIdMap` (`ENTITY_BANK_STATEMENT`,
  klíč = old `ndx`) — `BaseExchangeRunner` re-run přeskočí už importované
  (vzor DocsRunner). Transakce uvnitř deduplikuje nová strana
  (`external_id`/`fingerprint`).

### W3 — Mapování polí

**Hlavička → `statement`:** `docOrderNumber`/`idList` → `statementNumber`;
`datePeriodBegin`/`datePeriodEnd` → `periodStart`/`periodEnd`; `initBalance` →
`openingBalance`; `balance` → `closingBalance`; `currency` → `currency`.

**`applyOptions.targetState`:** dle starého `docState` výpisu — „hotový"
(4000/8000) → **40** (zaúčtovat); rozpracovaný (1000/1200) → **10**. Mapu
držet explicitně (vzor `DocsRunner::DOC_STATE_MAP_TARGET`); neznámý stav =
tvrdá chyba (fail výpisu, ne tichý default).

**Řádek → `transaction`** (`loadRows($docNdx)` z `e10doc_core_rows WHERE
document = ndx ORDER BY rowOrder, ndx`):

- `amount` (znaménková) = `credit > 0 ? +credit : -debit` (příjem kladně,
  výdaj záporně — nová strana z toho odvodí `direction`+kladnou částku)
- `externalId` = stabilní odvozenina z old řádku (např. `old:{rowNdx}`) —
  zaručí idempotenci i kdyby se tentýž výpis později naimportoval souborem
  (cross-source dedup nová strana řeší i přes `fingerprint`)
- `dateTransaction` = `dateDue` (řádek má jen jedno datum) **nebo**
  `dateAccounting` hlavičky jako fallback; `dateValue` = `null`
- `counterpartyAccount` = `bankAccount` (řetězec), `counterpartyName` = `null`
  (starý řádek název protistrany nedrží)
- `symbol1`/`symbol2`/`symbol3` (VS/SS/KS) → `paymentReference`/`specificSymbol`/
  `constantSymbol` (názvy kanonického schématu; VS→paymentReference jako Fáze 09)
- `message` = `text`/memo (sloučené)
- `operation` = `null` (nová strana doplní default `payment.in`/`payment.out`
  dle směru → účtování na clearing)

Cizí měna: `exchangeRate` řádku se do payloadu posílat nemusí — `amount_dom`
dopočítá nová strana (Fáze 2/3). Pokud bude potřeba věrný kurz, doplnit
`exchangeRate` do schématu transakce (viz Otevřené body).

### W4 — Migrace PDF příloh

Pokud má starý bankovní doklad přílohu (PDF výpisu), přenést ji k novému výpisu
přes `AttachmentImporter`/`AttachmentUploadClient` (vzor task
`07a-attachments-client.md` + `MailRunner`). Binární upload jde mimo exchange
JSON (samostatný upload klient s `savedStatementId` z apply odpovědi).
Sekundární — výpis bez PDF je validní.

### W5 — Registrace

- `LocalIdMap`: přidat `public const ENTITY_BANK_STATEMENT = 'bankStatement';`
- `AllRunner`: přidat `['Bank statements', fn() => (new
  BankStatementsRunner($this->context))->run()]` **za** `Documents` (účty
  z `AllCodebooksRunner` jsou už naimportované).
- Samostatný příkaz (jako ostatní runnery — `bank-statements`) pro cílené
  spuštění/dry-run.

### W6 — Dry-run + testy

- **W6.1** Dry-run nad reálnými daty: vypíše počty výpisů/transakcí, žádný
  zápis.
- **W6.2** Ostrý běh malého vzorku → na nové straně vznikne výpis + transakce,
  „hotový" výpis je zaúčtovaný (ověřit přes nový systém).
- **W6.3** Idempotence: druhý běh → 0 nových (LocalIdMap skip).
- **W6.4** Zůstatkový můstek na nové straně sedne (`reconciliation_state = 1`)
  u výpisů s konzistentními zůstatky.

## Hotovo když

1. `bank-statements` (i v rámci `AllRunner`) naimportuje staré bankovní výpisy:
   na nové straně vzniknou výpisy + transakce, „hotové" zaúčtované na clearing.
2. Náš účet se dohledá přes `LocalIdMap` z `myBankAccount`; chybějící účet →
   warning + skip (ne pád).
3. Idempotence: opakovaný běh nezdvojuje (LocalIdMap výpis + dedup transakcí
   na nové straně).
4. Dry-run nic nezapisuje a vypíše souhrn.
5. PDF výpisu (kde existuje) je u nového výpisu jako příloha.

## Doporučené pořadí

1. W5 (LocalIdMap entita + kostra registrace) → W1 (runner skeleton) → dry-run
2. W2 (účet + idempotence) + W3 (mapování) → W6.1/W6.2/W6.3
3. W4 (PDF přílohy) → W6.4
4. Commit: runner+registrace / přílohy

## Rozhodnutí ✓

- Runner přes `BaseExchangeRunner` + `shpd.bank.statement.v1`, vzor `DocsRunner`
  (jednodušší — bez řad/čísel/selfParty).
- Náš účet přes `LocalIdMap` (`ENTITY_BANK_ACCOUNT`) z `myBankAccount`;
  idempotence výpisu přes nový `ENTITY_BANK_STATEMENT`.
- `amount` znaménková (credit + / debit −); `operation = null` → nová strana
  default dle směru → clearing.
- `targetState` dle starého stavu výpisu (hotový → 40 zaúčtovat, rozpracovaný
  → 10); párování/`person` se nemigruje.
- Účtování i partner dělá nová strana; runner posílá jen fakta transakce.

## Otevřené body

- **Datum transakce** — řádek má `dateDue`; pokud chybí, fallback
  `dateAccounting` hlavičky. Ověřit, co je v datech reálně vyplněné.
- **Cizoměnové výpisy** — posílat `exchangeRate` řádku do schématu pro věrný
  `amount_dom`, nebo nechat dopočet na nové straně (kurz dle data)? FX rozdíly
  jsou stejně mimo scope — zatím dopočet na nové straně.
- **`targetState` granularita** — účtovat migrované „hotové" výpisy en bloc
  ve 40 může být pomalé (engine per transakce). Sladit s výkonovým otevřeným
  bodem v `bank-phase4` (případně import ve 10 + dávkové účtování).
- **PDF u starých výpisů** — ověřit, kolik výpisů přílohu reálně má (jestli
  W4 stojí za to v první iteraci, nebo až follow-up).
