# Shipard — Saldokonto (modul `economy.accbal`)

**Designový dokument.** Saldokonto = kdo komu kolik dluží a co je uhrazené,
postavené **čistě nad účetním deníkem**: předpisy a úhrady jsou vybrané řádky
deníku, **případ** je jejich agregát podle párovacího klíče (saldokonto,
období, partner, variabilní symbol, specifický symbol, měna). Přebírá
datový princip i párovací klíč rozpracovaného „Saldo2" ze starého Shipardu
(`e10doc/accBal`); od něj se liší regenerovatelným deníkem, clearing švem
a tím, že o účtu úhrady rozhoduje účtovací engine dohledáním otevřeného
předpisu.

> **Stav:** Fáze 0–2b hotové a nasazené; clearing infrastruktura (§4.5,
> #18) hotová. **Revize saldokonta #69** (rozhodnutí D1–D11 v komentářích
> issue z 2026-09-12 a 2026-09-13): T1 `bank-payment-routing` (routing
> v enginu, trigger přeúčtování, §5) a T2 `accbal-symbol-key` (případ =
> agregát klíče, alokační vrstva zrušena, viewer po případech, §3.4) hotové
> 2026-09-14; téhož dne oprava klíče pohybu **D13** (pohyb per platební
> identita řádku, `movement_key`, `accbal-regenerate` — §4.3, §4.6;
> `tasks/accbal-ledger-identity-key.md`). Následují T3 efektivní symboly,
> T4 opakované platby, T5 průvodci oprav, T6 dashboard (§9).

---

## 1. Motivace a princip

Saldokonto odpovídá na otázku „kdo komu kolik dluží a je to uhrazené?".
Technicky: vybrané řádky účetního deníku (na saldokontních účtech —
311/321/314/324/…) jsou buď **předpisy** (vznik pohledávky/závazku), nebo
**úhrady**. Saldokontní **případ** je uzavřený, když se předpisy a úhrady
se stejným párovacím klíčem vyrovnají na nulu.

**Párovací symbol, ne alokace.** Případ se **nemodeluje** jako entita ani
jako vazby úhrada↔předpis. Je to agregát: klíč `(saldokonto, období,
partner, VS, SS, měna)` a stav `Σ předpisy − Σ úhrady` z ledgeru. Důvody
(#69, D1):

- **Deník je jediný zdroj pravdy.** Alokační vrstva (tabulka vazeb plněná
  matcherem) byla druhý zdroj pravdy mimo deník — porušovala axiom „saldo je
  derivát deníku" a při každém přeúčtování ji bylo nutné držet v synchronu.
- **Vysvětlitelnost.** Rozhodnutí FIFO matcheru („platba 600 šla na faktury
  1, 2 a kus 3") nebylo vidět v žádném dokladu. Se symbolovým klíčem je stav
  případu spočitatelný z ledgeru rukou a každá oprava je doklad (§8).
- **Srovnatelnost se starým systémem.** Starý Shipard páruje symbolem;
  kontrolní součty saldokonta proti němu (M2) sedí 1:1 jen se stejným
  modelem.

Problém „víc faktur se stejným VS" (opakované platby služeb) se neřeší při
párování, ale **tam, kde vzniká** — při vzniku identity dokladu: efektivní
symboly na transakci (D5, T3) a doplnění specifického symbolu na přijaté
faktury z detekce opakovaných plateb (D7, T4).

### 1.1 Co se přebírá ze starého Saldo2 a co ne

Přebírá se **datový princip**: nastavení saldokont = seznam skupin + seznam
účtů s konfigurací (strana MD/DAL, znaménko, předpis/úhrada), generování
saldo pohybu z řádku deníku, pokud řádek vyhoví nastavení
(`AccBalanceCreator`), a **párovací klíč** `(balance, person, symbol1,
symbol2)` — dnes doplněný o období a měnu. Duální měna (měna dokladu +
domácí) zůstává — řeší obchodní vs. účetní saldokonto (§6).

**Nepřebírá se** vazba úhrady na *první* nalezený předpis zašitá do journal
řádku (`fetch()` podle klíče a součet — při stejném VS náhodný rozpad „která
faktura je uhrazená"). V novém modelu jsou pohyby ryzí seznam, případ je
agregát celého klíče (všechny předpisy i úhrady klíče dohromady) a
rozpad na jednotlivé faktury se zajišťuje identitou dokladu (SS), ne
párovacím algoritmem.

### 1.2 Saldo pracuje výhradně s deníkem

Klíčové architektonické rozhodnutí: saldo čte **jen** `economy_accounting_journal`.
Nesahá na doklady ani transakce při generování pohybů. Důsledek: každý budoucí
zdroj účtování (pokladna, zápočty, otevírací sekvence období, ruční zápis)
nakrmí saldo **bez jediné změny v saldo kódu** — stačí, aby do deníku zapsal
řádky se symboly a splatností (prerekvizita §3.5, hotová).

Jediná vazba saldo ↔ účtovací engine je **dohledání otevřeného předpisu**
(`OpenItemLookup`, §5.1): bankovní engine se salda zeptá, zda pro klíč
úhrady existuje otevřený předpis, a podle toho účtuje na účet předpisu
nebo na clearing. Saldo o „párování" nic neví — je to jen jiný pohled na
deník.

---

## 2. Architektura

```
┌──────────────────────────────────────────────────────────────────┐
│  Účetní deník (economy_accounting_journal)                        │
│  - jednostranné řádky, obě měny, partner                          │
│  - payment_reference / specific_symbol / constant_symbol /        │
│    due_date (plní účtovací enginy — §3.5)                         │
│  - po (pře)zápisu deníku zdroje vyšle událost journalWritten      │
├──────────────────────────────────────────────────────────────────┤
│  Generátor pohybů (economy.accbal, JournalLedgerHandler)          │
│  - načte nastavení saldokont (balances + balance_accounts)        │
│  - řádek deníku na saldo-účtu → saldo pohyb (předpis | úhrada)    │
│  - normalizuje klíč (D10) a idempotentně UPSERTuje (§4.3)         │
├──────────────────────────────────────────────────────────────────┤
│  economy_accbal_ledger   (pohyby)                                 │
│  - balance, bal_side, fiscal_year, partner, symboly, splatnost,   │
│    obě měny; source_kind + zdroj (doc_head | bank_transaction)    │
│  - idx_case = klíč případu                                        │
├──────────────────────────────────────────────────────────────────┤
│  Případ = agregát klíče (CaseQuery, §3.4) — žádná tabulka         │
│  - Σ předpisy − Σ úhrady per (balance, fiscal_year, partner,      │
│    VS, SS, currency); viewer případů, viewer pohybů, lookup       │
└──────────────────────────────────────────────────────────────────┘
        ▲                                             │
        │ journalWritten                              │ OpenItemLookup
        │                                             ▼
┌──────────────────────────────────────────────────────────────────┐
│  BankTransactionAccountingEngine (economy.bank, bank.md §6.1)     │
│  - úhrada s partnerem: otevřený předpis pro klíč → účet předpisu  │
│    (vč. analytiky), jinak clearing 261200/261300                  │
│  ClearingRerouteHandler + ClearingRouter (economy.accbal, §5.2)   │
│  - po zaúčtování předpisu přeúčtuje čekající clearingové úhrady   │
└──────────────────────────────────────────────────────────────────┘
```

Modul `economy.accbal`, závislosti: `core.system`, `economy.accounting`,
`economy.bank`, `docs.core`, `economy.codebooks`.

Saldo zůstává oddělené od `economy.accounting` (ten je „čistě deník");
accbal na něj jen závisí a čte jeho tabulku. Rozhraní `OpenItemLookup` je
deklarované v core (`Shipard\Core\Accounting`), implementace
`LedgerOpenItemLookup` v accbal, registrace `openItemLookup` v
`module.jsonc` — bankovní engine na modulu saldokonta nezávisí (DS bez
accbal má `NullOpenItemLookup`, vše na clearing).

**Pohledy (UI):** viewer **Saldokonto** (`economy.accbal.cases`,
`CasesViewer`) po případech je výchozí vstup — sidebar položky saldokont
(Pohledávky, Závazky; `BalancesNavigationProvider`) ho otevírají s fixním
chipem; viewer **Saldo pohyby** (`economy.accbal.ledger`, `LedgerViewer`) je
detail — akce „Pohyby případu" ho otevře s chipem saldokonta a filtry
období případu / partner / VS / SS. Oba pohledy startují s filtrem
**Období = aktuální fiskální rok** (`default` filtru, `frontend.md`
§ Filtry vieweru). Společný základ `AccbalViewerBase`. Detaily §3.4.

---

## 3. Datový model

### 3.1 `economy_accbal_balances` — saldokonta (skupiny)

tableId **416**. docStates: archivní sada (`core.system.docStatesArchive`).

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK | |
| `code` | varchar 25 | stabilní identifikátor pro seed/exchange (nahrazuje starý `globalId`) |
| `name` / `short_name` | varchar 140 / 80 | |
| `order` | int | pořadí v UI |
| `show_in_navigation` | bool | vlastní položka v sidebaru (otevře viewer případů s fixním chipem) |
| `valid_from` / `valid_to` | date, nullable | platnost skupiny |
| `docState` / `docStateMain` | tinyint, system | |

Seed (dle screenshotu starého systému): Pohledávky, Poskytnuté půjčky,
Závazky, Úvěry, Přijaté půjčky, Poskytnuté zálohy, Přijaté zálohy,
**Nespárované platby** (clearing — §4.4), Náklady příštích období.

### 3.2 `economy_accbal_balance_accounts` — účty saldokont

tableId **417**. Řádek per účet ve skupině.

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK | |
| `balance` | int, FK balances, not null | |
| `account_number` | varchar 12, not null | **prefix** účtu (`311` chytí `311100`), `str_starts_with` jako ve starém |
| `acc_side` | enumInt | strana řádku deníku: 0 = MD, 1 = DAL |
| `amounts_sign` | enumInt | 0 = Všechny, 1 = Kladné, 2 = Záporné |
| `bal_side` | enumInt | 0 = Předpis, 1 = Úhrada |
| `modify_sign` | bool, default 0 | obrátit znaménko částky (dobropisy) |
| `note` | varchar 80, nullable | |
| `system_order` | int | |
| `valid_from` / `valid_to` | date, nullable | |
| `docState` / `docStateMain` | tinyint, system | |

**Proč MD/DAL + znaménko + filtr částky:** dělá to sémantické přesměrování,
které účtovací engine sám nedělá. Dobropis vydané faktury se zaúčtuje na **311
záporně** (engine ho nepřesměruje na 321). Záporná pohledávka je ale ekonomicky
**závazek**, takže nastavení „Závazky" obsahuje řádky pro 311 se zápornou
částkou a `modify_sign` (přesně řádky 13–16 na screenshotu): `311 MD Záporné →
Předpis *−1`, `311 DAL Záporné → Úhrada *−1`. Bez této vrstvy by dobropisy
v saldu seděly na špatné straně.

**315 v Pohledávkách (#72 D6).** Seed skupiny `receivables` má vedle 311
i `315` (MD kladné → předpis, DAL kladné → úhrada): vyúčtování úhrad od
platební brány / terminálu / dopravce účtuje 311 DAL per doklad (uzavře
pohledávku za prostředníkem — ten je v saldokontu **běžný dlužník**, klíč
partner + VS = číslo dokladu) proti 315 MD per dávka, kterou pak zaplatí
banka; obojí se páruje ve stejné skupině. `BalancesProvisioner` do
existující skupiny **doplní chybějící účty seedu** (klíč `account_number`,
`acc_side`, `bal_side`, `modify_sign`; existující řádky nemění) — i pod
`skipProvisioning` (jen doplnění, nové skupiny nezakládá), takže 315
dostanou i DS se skupinou z doby před #72 bez ručního kroku. Tvorba
vyúčtování v novém Shipardu je mimo scope (#72 D6).

Příklad seedu pro „Závazky" (zkráceně):

```
321 DAL Kladné  Předpis        (běžný závazek vzniká)
321 MD  Kladné  Úhrada         (závazek se platí)
311 MD  Záporné Předpis  *−1   (dobropis pohledávky = závazek)
311 DAL Záporné Úhrada   *−1
325/331/336/341/342/345/379 …  (ostatní závazkové účty)
```

### 3.3 `economy_accbal_ledger` — saldo pohyby

tableId **418**. **Bez docStates, bez formu** — čistý derivát deníku, generuje
a maže ho jen handler (jako účetní deník sám).

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK | identita pohybu, stabilní přes reaccount (§4.3); `row_id` případu ve vieweru |
| `balance` | int, FK balances, not null | skupina saldokonta — **klíč** |
| `bal_side` | enumInt, not null | 0 = Předpis, 1 = Úhrada |
| `source_kind` | enumString 20 | `doc` \| `bankTransaction` (denorm z deníku) |
| `source_id` | int, not null | id zdroje (kopie doc_head / bank_transaction) — součást klíče pohybu, `idx_source` |
| `movement_key` | varchar 40, nullable | SHA-1 kanonické podoby klíče pohybu (§4.3, D13) — jen identita pohybu pro UPSERT a unikátnost; `NULL` = pohyb z doby před D13, ještě neregenerovaný (§4.6) |
| `doc_head` | int, FK docs_core_heads, nullable | zdroj (dle source_kind) |
| `bank_transaction` | int, FK economy_bank_transactions, nullable | zdroj |
| `journal_row` | int, nullable | **denorm** odkaz na aktuální řádek deníku (pro „otevřít deník"); **není** stabilní identita — viz §4.3 |
| `account_number` | varchar 12, not null | saldo-účet pohybu (311100…) |
| `fiscal_year` | int, FK fiscal_years | účetní období — **klíč** (§7, D11) |
| `partner` | int, FK base_persons_persons, nullable | **klíč** |
| `payment_reference` | varchar 35, nullable | VS — **klíč**; normalizovaný (D10) |
| `specific_symbol` | varchar 20, nullable | SS — **klíč**; normalizovaný |
| `constant_symbol` | varchar 10, nullable | denorm (jen informační, ne párovací) |
| `due_date` | date, nullable | splatnost (předpis); u úhrady typicky NULL |
| `currency` | enumString 3 | měna dokladu — **klíč**; malými písmeny |
| `home_currency` | enumString 3 | domácí měna |
| `amount` | numeric 15,2 | částka v měně dokladu (po `modify_sign`) |
| `amount_hc` | numeric 15,2 | částka v domácí měně |
| `text` | varchar 200, nullable | |

Indexy: `unq_movement_key (movement_key)` unique (klíč pohybu, D13),
`idx_source (source_kind, source_id)` (pohyby zdroje — UPSERT, úklid),
**`idx_case (balance, fiscal_year, partner, payment_reference,
specific_symbol, currency)`** (klíč případu, D11), bucket `(balance,
partner, currency, fiscal_year)`, `(payment_reference)`, `(doc_head)`,
`(bank_transaction)`, `(account_number, fiscal_year)`. Historický
`unq_stable_key (source_kind, source_id, balance, bal_side,
account_number)` v definici není; na DS z doby před D13 zůstává a ruší se
ručně (§4.6).

Poznámky:

- **Žádné `request`/`payment`/`residual` na pohybu** (na rozdíl od starého
  Saldo2) a **žádný uložený hash klíče případu** — případ je agregát
  (§3.4), klíč je n-tice sloupců a složený index. `movement_key` je hash
  jen **identity pohybu** (unikátnost v DB, §4.3), do dotazů nad případem
  nevstupuje.
- **Normalizace klíče při zápisu (D10):** generátor ukládá symboly
  `TRIM`nuté, prázdné jako `NULL`, měnu malými písmeny. Rovnost klíče pak
  jde přes `idx_case` bez `TRIM`/`COALESCE`/`LOWER` ve `WHERE`; prázdný
  symbol se porovnává `IS NULL`. Vstupní klíč každého dotazu prochází
  stejnou normalizací (`CaseQuery::normalizeKey`).
- Symboly + splatnost se denormalizují z deníku kvůli indexovaným dotazům
  klíče a UI; zdroj pravdy je deník.

### 3.4 Případ — agregát klíče (tabulka `economy_accbal_allocations` zrušena, #69 D1)

Tabulka 419 párovacích vazeb, `AllocationPlanner`, `BalanceMatcher` a config
`allocationOrigins` **neexistují** (T2, 2026-09-14). tableId 419 se
nerecykluje (`table-definitions.md`, vyřazená ID); na DS z doby před T2
tabulka osiřele zůstává (`ds-upgrade` nemaže), nový DS ji nedostane.
Migrace se neřešila — DS na alfě se resetují a importují znovu (#69).

**Definice případu** — jediné místo je třída `CaseQuery`
(`modules/economy/accbal/src/CaseQuery.php`), sdílená lookupem, routerem
i viewery:

```
klíč     = (balance, fiscal_year, partner, payment_reference, specific_symbol, currency)
předpisy = Σ amount   WHERE bal_side = 0      (+ amount_hc)
úhrady   = Σ amount   WHERE bal_side = 1      (+ amount_hc)
zůstatek = předpisy − úhrady                  (obchodní: měna dokladu;
                                               účetní: domácí, §6)
otevřený = zůstatek ≠ 0
typ      = dluh (zůstatek > 0) | přeplatek (předpisy ≠ 0, zůstatek < 0)
         | úhrada bez předpisu (předpisy = 0, zůstatek ≠ 0) | uzavřeno
splatnost = MIN(due_date) předpisů klíče; dny po splatnosti k dnešku
            (jen dluh; počítá se při renderu, nic se neukládá)
row_id   = MIN(id) pohybů klíče — stabilní id řádku pro viewer, detail
            z něj klíč odvodí (CaseQuery::keyOfRow)
```

Stavební kameny `CaseQuery`: `normalizeKey()` / `keyConditions()` (rovnost
i částečného klíče, `IS NULL`, pořadí `idx_case`), `keyColumnsSql()` +
`aggregateColumnsSql()` (SELECT nad `GROUP BY` klíče), `kindConditionSql()`
/ `openConditionSql()` (filtry nad agregátem), `residualSubquerySql()`
(zůstatek případu daného řádku ledgeru jako korelovaný subdotaz — viz
poznámka níže), instanční `caseOf(key)` a `keyOfRow(id)`.

> **Proč korelovaný subdotaz, ne JOIN na derived table:** MariaDB 10.11
> (`split_materialized`) při bodovém dotazu (`WHERE l.id = ?`) s NULL-safe
> rovností `<=>` v ON derived tabulky s GROUP BY vrací NULL. Volba splitu
> je cost-based, takže by se chyba projevila nepředvídatelně i v seznamu
> s úzkým filtrem. Per řádek jde o bodové dohledání přes `idx_case`.

**Lookup je užší než případ.** `LedgerOpenItemLookup` (§5.1) počítá
reziduum jen z řádků na **předpisových účtech** skupiny (dobropisové řádky
s `modify_sign` mimo hru — T1), případ agreguje celou skupinu. Sdílí se
klíč a normalizace, ne filtr účtů; na seedu je rozdíl vidět jen u
dobropisů.

**Pohledy nad případem:**

- **Viewer případů** `economy.accbal.cases` (`CasesViewer`) — jeden řádek =
  případ. ViewGroups = saldokonta (chip, identita `code`); grid se
  skupinami per partner, skupinový řádek nese součet zůstatku partnera
  v domácí měně přes filtrovaný set (okno `SUM() OVER (PARTITION BY
  partner)`, sedí i přes hranici stránek); sloupce období, VS, SS, měna,
  předpisy, úhrady, zůstatek, zůstatek HC, splatnost, dní po splatnosti,
  počet pohybů, saldokonto; footer Σ předpisy / úhrady / zůstatek v HC.
  Filtry: **období** (první; select roků nejnovější první, výchozí
  aktuální fiskální rok = rok obsahující dnešek, jinak nejnovější —
  `Core\Viewer\FiscalYearFilter` přes `AccbalViewerBase::periodFilter()`;
  případ je na období vázaný (D11), bez filtru by tentýž klíč stál v deseti
  letech pod sebou; „— vše —" ukáže všechna období, sloupec Období pak
  rozlišuje), partner, VS, **typ** (dluh / přeplatek / úhrada bez
  předpisu / uzavřeno — úhrady bez předpisu jsou na reimportovaném DS
  tisíce, musí být samostatně filtrovatelné), po splatnosti, **včetně
  uzavřených** (výchozí jen otevřené přes obrácený checkbox z doby před
  `default`; zůstává, nové filtry ho nekopírují). Klíčové filtry včetně
  období jdou před `GROUP BY` (`l.fiscal_year`, `idx_case`), případové
  nad agregát; footer sdílí obě úrovně. Zvýraznění doc-state konvencí
  (`design-system.md` §4): dluh bez proužku, dluh po splatnosti
  `cancelled`, přeplatek / úhrada bez předpisu `concept`, uzavřený
  `archive`. Detail (z `row_id`) + akce **Pohyby případu** (`open_viewer`
  s `viewGroup` a `filters` = období případu + partner / VS / SS,
  `frontend.md`) — období případu přebíjí výchozí rok pohybů, pohyby
  klíče přes roky jsou dostupné uvolněním filtru.
- **Viewer pohybů** `economy.accbal.ledger` (`LedgerViewer`) — sloupec
  „Zbývá" per pohyb zanikl; místo něj **Zůstatek případu** (stejná hodnota
  na všech pohybech klíče, `residualSubquerySql`), filtr **období**
  (stejný default jako u případů), „Jen otevřené případy" = otevřenost
  případu, filtry partner / VS / SS (prefixové), řazení uvnitř partnera
  po klíči případu, detail se skupinou Případ; akce „Otevřít řádek
  deníku" posílá období pohybu (deník má týž výchozí rok, cílový řádek ze
  staršího roku by jinak ze seznamu zmizel).

### 3.5 Prerekvizita: symboly + splatnost do účetního deníku

Deník dnes symboly ani splatnost nenese (`accounting.md` rozhodnutí #10).
Pro §1.2 je potřeba je doplnit. **Additivní, bezpečné** (`ds-upgrade` ADD
COLUMN; deník je derivát):

Sloupce do `economy_accounting_journal` (vše nullable, system):

| sloupec | typ | zdroj |
|---|---|---|
| `payment_reference` | varchar 35 | hlavička `payment_reference` / transakce |
| `specific_symbol` | varchar 20 | hlavička / transakce |
| `constant_symbol` | varchar 10 | hlavička / transakce |
| `due_date` | date | hlavička `due_date`; u transakce NULL |

- Hodnoty jsou konstantní přes celý doklad (z hlavičky), takže se jen
  orazítkují na každý vkládaný řádek v `AccountingEngine::writeResult`
  (vedle stávajících `doc_type`/`doc_number`/`currency`). **Grouping se
  nemění** (klíč `(side, account_number, partner, operation)` je nedotčený) —
  riziko nula.
- `BankTransactionAccountingEngine` razítkuje symboly transakce, `due_date`
  NULL.
- `JournalViewer`: přidat `payment_reference` do filtrů a fulltextu — to je
  rovnou ta lidská hodnota („najdi v deníku všechno na VS 12345"), kvůli které
  to do deníku patří i nezávisle na saldu.
- Index `(payment_reference)` na deníku.

### 3.6 Prerekvizita: sjednocení symbolů na bankovních transakcích

`economy_bank_transactions` má dnes `symbol1/2/3` (varchar **10**), starým
jménem. Hlavička dokladu má `payment_reference` (varchar **35**, RF/EndToEndId)
+ `specific_symbol` + `constant_symbol`. Párovací klíč musí být porovnatelný
napříč doklad↔transakce — proto lockstep přejmenování na transakcích:

```
symbol1 → payment_reference (varchar 35)
symbol2 → specific_symbol   (varchar 20)
symbol3 → constant_symbol   (varchar 10)
```

Doplnit do parserů (CAMT `EndToEndId` ⇒ `payment_reference`), exchange schématu
`shpd.bank.statement.v1` a applieru. Bez toho RF reference z faktury nikdy
nesedne na osekaný 10znakový VS z banky.

---

## 4. Generování pohybů z deníku

### 4.1 Trigger — událost `journalWritten`

Saldo se nezahákuje na změnu stavu dokladu (to dělá účtování), ale na **změnu
deníku**. Účtovací enginy (`AccountingEngine`, `BankTransactionAccountingEngine`)
po každém (pře)zápisu deníku zdroje — i po jeho vymazání — vyšlou událost:

```
journalWritten(sourceKind, sourceId)   // deník zdroje se změnil / vymazal
```

Generický mechanismus (obdoba `documentEventHandlers` z `accounting.md` §7.1):
modul `economy.accbal` zaregistruje handlery na `journalWritten` —
`JournalLedgerHandler` **re-derivuje** saldo pohyby daného zdroje z aktuálního
deníku, za ním `ClearingRerouteHandler` (§5.2; pořadí registrace je
významové).

Proč událost a ne `stateChanged`: drží §1.2 (saldo zná jen deník) a **samo
řeší clearing → 311 přechod** — když engine přeúčtuje transakci, přepíše
deník a vyšle `journalWritten`; saldo re-derivaci provede automaticky
(clearing pohyb zmizí, 311 úhrada vznikne). Žádná zvláštní cesta pro „po
přeúčtování".

### 4.2 Algoritmus generátoru (per zdroj)

```
1. Načti nastavení saldokont platné k účetnímu datu (balances +
   balance_accounts), seřazené.
2. Načti aktuální řádky deníku zdroje (source_kind + source_id).
3. Pro každý řádek deníku:
   pro každý řádek nastavení (balance_account):
     - account_number řádku začíná na prefix nastavení?  (str_starts_with)
     - acc_side nastavení == strana řádku (MD ⇔ money_dr, DAL ⇔ money_cr)?
     - amounts_sign vyhovuje znaménku částky?
     → ano: vznikne kandidát na saldo pohyb:
        balance   = nastavení.balance
        bal_side  = nastavení.bal_side
        amount    = částka řádku (×−1 dle modify_sign), obě měny
        partner, symboly (normalizované, D10), due_date, fiscal_year,
        account_number, journal_row
     Kandidáti téhož zdroje se stejným klíčem pohybu (§4.3: zdroj + účet +
     platební identita řádku) se sčítají do jednoho pohybu; journal_row,
     due_date a text z prvního řádku skupiny.
4. UPSERT pohybů zdroje podle klíče pohybu (§4.3); chybějící smaž.
```

Chybový řádek deníku (`is_error`, nedohledaný účet) pohyb nevyrobí —
fantomový pohyb by maskoval účetní chybu. Jeden řádek deníku může vyhovět
**víc** řádkům nastavení (vznikne víc pohybů) — to je validní (např. týž účet
ve dvou skupinách). Pohyb dědí měny z deníku přímo, žádný přepočet.

### 4.3 Idempotence a stabilní identita pohybu

**Problém:** deník je DELETE+INSERT, takže `economy_accounting_journal.id`
**není stabilní** přes přeúčtování. Kdyby pohyb FK-oval na `journal_row.id`,
po každém přechodu dokladu 40→80→40 by `id` přeskákalo a odkazy z UI by
dangly.

**Řešení:** identita pohybu = stabilní klíč odvozený ze **zdroje** a
z **platební identity řádku** (D13), ne z řádku deníku:

```
(source_kind, source_id, balance, bal_side, account_number,
 partner, payment_reference, specific_symbol, currency)
```

Tenhle klíč je stabilní přes přeúčtování (zdroj se nemění, saldo-účet se
nemění, identita řádku plyne z dokladu, ne z `journal.id`). Symboly a měna
v klíči jsou normalizované stejně jako klíč případu (D10,
`CaseQuery::normalizeSymbol/normalizeCurrency`), takže klíč pohybu a klíč
případu si odpovídají. Generátor pohyby **UPSERTuje** podle něj (vzor
starého `saveBalanceJournalRequests` + memo `claimAccountForNewId`):

- existuje pohyb s klíčem → UPDATE částek/symbolů, `id` se zachová
- nový klíč → INSERT
- pohyb zdroje, který už v novém deníku není → DELETE (jde první, aby se
  nový pohyb nepotkal se starým řádkem téhož zdroje)

Případ je agregát (§3.4), takže smazaný pohyb z něj zmizí sám — žádná
cascade. `journal_row` je jen **denorm** odkaz na aktuální řádek (refreshuje
se při každé re-derivaci), pro akci „otevřít řádek deníku". Není load-bearing.

**Proč identita řádku v klíči (D13):** původní klíč `(source_kind,
source_id, balance, bal_side, account_number)` stál na předpokladu, že
partner je konstantní per zdroj, takže na daném saldo-účtu je per zdroj
právě jeden řádek deníku. Platí pro fakturu a bankovní transakci, ne pro
**otevírací doklad období** (desítky pohledávek různých partnerů v jednom
dokladu), **interní doklady** (zápočty, hromadné vyúčtování, opravy salda)
a **pokladní doklady s více řádky**. Ty se slévaly do jednoho pohybu
s partnerem a VS prvního řádku a součtem všech — na reimportovaném DS
tisíce dokladů a většina „úhrad bez předpisu". Řádky téhož zdroje se
**stejnou** identitou se dál sčítají (faktura se dvěma řádky na 311 =
jeden předpis); různé identity = různé pohyby, každý s částkou své
pohledávky, součet pohybů dokladu = obrat dokladu na saldo-účtu.

**V DB klíč nese `movement_key`** = SHA-1 kanonické podoby (hodnoty klíče
oddělené `|`, `NULL` jako prázdný řetězec, po normalizaci D10;
`LedgerGenerator::movementKey()` je jediná definice), s unikátním indexem
`unq_movement_key`. Unikátní index nad n-ticí sloupců by nestačil — MariaDB
v něm nevynucuje shodu přes `NULL` a VS/SS po D10 `NULL` být mohou. Hash je
jen identita **pohybu**; případ zůstává n-ticí bez hash sloupce (D11).
Sloupec je nullable kvůli `ds-upgrade` na existujících DS (§4.6).

### 4.4 Clearing šev (varianta B)

Úhrada bez otevřeného předpisu má protistranu na clearingu (261200/261300,
viz `bank.md` §6.3). Clearing účty se zařazují do nastavení saldokont jako
skupina **„Nespárované platby"**:

```
261200 DAL Kladné Úhrada   (nespárovaný příjem)
261300 MD  Kladné Úhrada   (nespárovaný výdaj)
```

Důsledky:

- Nespárovaná úhrada je **normální saldo pohyb** na skupině „Nespárované
  platby" (bal_side = Úhrada); ve vieweru případů je to případ typu „úhrada
  bez předpisu". `ClearingRouter` má tím **jediný zdroj kandidátů** — ledger.
- Po přeúčtování bankovní engine položí transakci na účet předpisu, vyšle
  `journalWritten`, saldo re-derivuje: clearing pohyb (na „Nespárovaných")
  **zmizí**, vznikne úhrada na skupině Pohledávky/Závazky a případ klíče se
  uzavře (nebo sníží).
- Invariant zůstává čistý: **nenulový obrat skupiny „Nespárované platby" =
  existují nespárované úhrady** (signál k akci, ne chyba).
- Skupina „Nespárované platby" nemá řádek předpisu → lookup ji nikdy
  neprohledá (§5.1).

### 4.5 Clearing infrastruktura na migrovaném DS

Clearing účty 261200/261300 i skupina `unmatched_payments` normálně vznikají
seedem v `ds-upgrade` (`AccountChartProvisioner` / `BalancesProvisioner`). Na
**migrovaném DS** je ale provisioning vypnutý (`skipProvisioning`) — osnova i
saldo nastavení se přebírají ze staré strany, kde tyhle dva nové konstrukty
**nemají protějšek**. Bez nich `AccountMaskResolver` nedohledá 261200/261300
(bankovní engine → `accounting_state=2` u každé platby, hlučně) a router
nenajde skupinu `unmatched_payments` (tiše nula kandidátů).

Řešení (rozhodnutí #18): clearing účty + skupina nejsou *migrovaná data*, ale
**infrastruktura modulů** `bank`/`accbal`. Zajišťuje je
`ClearingInfrastructureProvisioner` **bezpodmínečně** (i pod `skipProvisioning`)
v `ds-upgrade`, idempotentně podle `number` / `code` — mimo gate provisioningu,
hned po sync schématu. Tím je infrastruktura zaručeně přítomna před jakýmkoli
importem (ds-upgrade vždy předchází `all`). Migrace pak nese jen **business**
saldo skupiny; clearing skupinu (`unmatched_payments`) v migračním JSONu mít
nesmí (kolize `unq_code`), a stará skupina „Peníze na cestě" na holém prefixu
`261` se zúží na `261100` (jinak prefix-overlap → dvojité pohyby na clearingu).
Pojistka: pre-flight v `AllRunner` ověří přítomnost infrastruktury a tvrdě
spadne dřív, než začne import dokladů/transakcí (tichý no-op → hlasitá
chyba).

### 4.6 Hromadná re-derivace a nasazení změny klíče na existující DS

Ledger drží událost `journalWritten` per zdroj; pro dávku je
`shpd-ds accbal-regenerate --all | --doc=<id> | --fiscal-year=<id>
[--dry-run]` (`docs/cli.md`): projde zdroje deníku ∪ ledgeru (osiřelý
pohyb bez deníku se smaže), per zdroj `LedgerGenerator::generate` —
idempotentní UPSERT podle `movement_key`, žádné `journalWritten` (deník se
nemění; přeúčtování clearingu zůstává věcí `accbal-match`). Vypíše počty
vložených / aktualizovaných / smazaných pohybů. Použití: po každé změně
generátoru, po `ds-upgrade` s novým klíčem, při podezření na rozjetý
ledger. Na importovaném DS běží nízké desítky sekund.

**Nasazení D13 na DS z doby před změnou klíče** (jednorázově, v tomto
pořadí; na alfě mutace jen po schválení v chatu):

```bash
cd /opt/shipard/data-sources/<id>
shpd-ds ds-upgrade                     # přidá movement_key (NULL), unq_movement_key, idx_source
# ručně (ds-upgrade index neumí zrušit ani změnit):
#   ALTER TABLE economy_accbal_ledger DROP INDEX unq_stable_key;
shpd-ds accbal-regenerate --all --dry-run
shpd-ds accbal-regenerate --all        # staré pohyby (movement_key NULL) nahradí novými
```

Bez ručního `DROP INDEX` selže INSERT druhého pohybu téhož dokladu na
starém unikátním indexu. `ds-upgrade` sloupec přidává jako `NULL` právě
proto, aby unikátní index vznikl bez kolize na existujících řádcích;
regenerace klíč dopíše. Při této jednorázové regeneraci se `id` pohybů
změní (starý řádek bez klíče se smaže, nový vloží) — nikde nejsou
persistované odkazy (`row_id` případu se počítá živě), takže to nevadí.
DS, které se resetují a importují znovu, kroky nepotřebují.

Kontrola po regeneraci — doklady, kde je na saldo-účtu víc platebních
identit než pohybů (musí vrátit 0 řádků):

```sql
SELECT j.source_kind, j.doc_head AS source_id, j.account_number,
       COUNT(DISTINCT j.partner, NULLIF(TRIM(j.payment_reference), ''),
             NULLIF(TRIM(j.specific_symbol), ''), LOWER(j.currency)) AS identities,
       (SELECT COUNT(*) FROM economy_accbal_ledger l
         WHERE l.source_kind = j.source_kind AND l.source_id = j.doc_head
           AND l.account_number = j.account_number) AS movements
FROM economy_accounting_journal j
WHERE j.source_kind = 'doc' AND j.is_error = 0
GROUP BY 1, 2, 3
HAVING movements > 0 AND movements < identities;
```

---

## 5. Routing úhrad a přeúčtování clearingu (#69 D3, D4, D9, T1)

Účet úhrady (účet předpisu vs. clearing) je věc **účtování transakce**, ne
salda. Saldo nic nerozhoduje dvakrát a nic si nepamatuje: reaccount
transakce je idempotentní a bez paměti — přeúčtuj a úhrada spadne, kam má.
`operation` transakce spárovanost nenese (matched operace `payment.*.matched`
zrušeny).

### 5.1 Kontrakt s enginem — `OpenItemLookup`

```php
namespace Shipard\Core\Accounting;

interface OpenItemLookup
{
    public function findOpenRequest(
        int $partner, string $paymentReference, string $specificSymbol,
        string $currency, int $direction, ?int $fiscalYear,
        ?string $excludeSourceKind = null, ?int $excludeSourceId = null,
    ): ?OpenItem;   // OpenItem {balance, accountNumber, residual}
}
```

- Volá `BankTransactionAccountingEngine::resolveCounterpartyAccount()` pro
  operace kategorie `bank.unmatched.*` (`payment.in` / `payment.out`) **před**
  řetězcem `cat → maska`: má-li transakce partnera, dohledá otevřený předpis
  pro klíč `(partner, VS, SS, měna)` v **období účetního data transakce**
  (D11) a směr. Zásah → protistrana = účet předpisu přesně vč. analytiky;
  miss / bez partnera / bez VS / bez období → clearing dle masky
  (`bank.md` §6.1).
- **Cílové skupiny pro směr z nastavení saldokont**, ne z kódu skupiny ani
  z čísel účtů: směr určuje přirozenou stranu předpisu (příjem → předpis na
  MD, výdaj → na DAL) a cílem je každá skupina s řádkem `bal_side = předpis`
  na té straně, kladné částky, bez `modify_sign`; prefixy těchto řádků jsou
  účty, na kterých se hledají řádky klíče. Na seedu příjem prohledá
  Pohledávky (311, 315 — dávka vyúčtování brány, #72 D6), výdaj Závazky
  (321, 325, 331, 336, 341, 342, 345, 379);
  zálohy a úvěry následují v pořadí nastavení, první zásah vyhrává.
  Dobropisový řádek 311 v Závazcích mezi prefixy není, „Nespárované platby"
  nemají řádek předpisu → nikdy se neprohledají.
- **Reziduum** = Σ předpisy − Σ úhrady klíče v měně dokladu (jen řádky na
  předpisových účtech skupiny, §3.4 „lookup je užší"); otevřený = > 0.
  Vlastní transakce (`excludeSource*`) se z Σ úhrad vylučuje — reaccount už
  routované úhrady neuvidí své reziduum jako nulu.
- **Pravidla dohledání (D5)** v pořadí: (1) přesná shoda klíče — **platí
  dnes**; (2) stejný `(partner, VS)` a právě jeden otevřený předpis,
  (3) opakovaná platba → nejstarší neuhrazené období — **přijdou s T3**
  (efektivní symboly) a T4; (4) jinak clearing.

### 5.2 Trigger „platba dřív než faktura" (D4)

`ClearingRerouteHandler` (handler `journalWritten`, registrovaný **za**
`JournalLedgerHandler`, reaguje jen na `doc`): z čerstvě re-derivovaného
ledgeru vezme klíče předpisových pohybů dokladu (období, partner, VS, SS,
měna) a přes `ClearingRouter::rerouteForKeys()` přeúčtuje čekající
clearingové úhrady se shodným klíčem. Engine si účet předpisu dohledá sám
(§5.1), vyšle `journalWritten(bankTransaction)` — to tu skončí hned, žádná
smyčka. Ve starém Shipardu tohle uživatel dělal ručně; tady je to záměr.
Zpětný přesun na clearing (předpis zmizel) se automaticky nedělá — reaccount
transakce ji tam vrátí sám.

`ClearingRouter`: kandidát = úhradový pohyb bankovní transakce (stav 40) ve
skupině `unmatched_payments`, sekvenčně v pořadí data transakce (každé
přeúčtování hned sníží reziduum klíče — druhá úhrada už uzavřeného předpisu
zůstane na clearingu); bez partnera nebo bez zásahu lookupu → přeskočen.
Idempotentní: přeúčtovaná úhrada už není kandidát. Dry-run vypíše plán bez
zápisu (pořadí nesimuluje).

### 5.3 Reziduální routing (D9)

Platba se přeúčtuje z clearingu jen když má její klíč **kladný zůstatek**
(otevřený předpis). Přeplatek a úhrada bez předpisu zůstávají na clearingu
jako signál (D5/4) — ne existenční model (vše s klíčem na 311, přeplatek
jako záporný zůstatek). Důsledek: u vícenásobných úhrad téhož klíče závisí
výsledek reaccountu na pořadí — přijatelné, invariant „nenulový clearing =
podívej se" drží. Přeplatek *vzniklý* routovanou úhradou (reziduum > 0
stačí, částka může být vyšší) je záporný zůstatek případu na 311/321.

### 5.4 Vstupní body

- **Automaticky:** engine při každém účtování transakce (§5.1), trigger po
  zaúčtování předpisu (§5.2).
- **CLI `accbal-match`** (`src/Command/DataSource/AccbalMatchCommand.php`):
  `--all` / `--partner=` / `--fiscal-year=` + `--dry-run` — dávka
  `ClearingRouter::rerouteAll()` nad clearingem (import, ladění).
- **CLI `accbal-regenerate`** (§4.6) — hromadná re-derivace ledgeru
  z deníku; routing nespouští, po ní případně `accbal-match`.
- **`POST /_accbal/match`** — §5.7, kontrakt s importem ze starého Shipardu.

### 5.5 Idempotence a období (D11)

Klíč nese `fiscal_year`: předpis z jiného období je pro lookup miss.
Platba v novém období za předpis ze starého spadne na clearing a přeúčtuje
se, jakmile se zaúčtuje **otevírací doklad** nového období — je to `doc`
s klíčem, projde §5.2 bez zvláštní cesty. Router bere období z
`fiscal_year` clearingového pohybu (denorm z deníku transakce), engine z
účetního data transakce.

### 5.6 Rozdíl lookup × případ

Viz §3.4: lookup filtruje řádky na předpisové účty skupiny (T1 testy),
případ agreguje celou skupinu. Klíč a normalizace jsou společné
(`CaseQuery`).

### 5.7 API — dávkové přeúčtování clearingu (verze kontraktu 2)

`POST /api/v1/_accbal/match` — HTTP obal nad `ClearingRouter::rerouteAll()`
(#69 D4, T1), zrcadlo CLI dávky (`accbal-match --all` / filtry). Auth
standardně API klíčem. Primární konzument: import ze starého Shipardu
(závěrečný krok `all` pipeline volá match „na dálku"). Cesta i request jsou
stejné jako ve verzi 1; změnilo se tělo odpovědi.

Request body (JSON, všechna pole volitelná; vyžaduje `scope: "all"` **nebo**
aspoň jeden filtr — jinak 400 `VALIDATION`):

```json
{"scope": "all", "partner": 42, "fiscalYear": 7, "dryRun": true}
```

Běh: kandidát = úhradový pohyb bankovní transakce (stav 40) ve skupině
`unmatched_payments`, v pořadí data transakce. Bez partnera → přeskočen;
`OpenItemLookup` nenajde otevřený předpis pro klíč (partner, VS, SS, měna)
v období pohybu a směr → přeskočen; jinak `accountTransaction` (engine si
účet předpisu dohledá sám, `bank.md` §6.1) → `journalWritten` → re-derivace
ledgeru. Sekvenčně: každé přeúčtování hned sníží reziduum klíče, druhá
úhrada už uzavřeného předpisu zůstane na clearingu.

Response nese **jen agregát** z `RouteSummary` — per-result řádky (mohou být
tisíce) se neserializují:

```json
{
  "success": true,
  "data": {
    "dryRun": false,
    "candidates": 1234,
    "routed": 1100,
    "planned": 0,
    "skipped": {"no_open_item": 120, "no_partner": 14},
    "routedAmount": 1234567.89
  }
}
```

| pole | význam |
|---|---|
| `candidates` | počet clearingových úhrad ve výběru |
| `routed` | přeúčtováno na účet předpisu (v dry-runu vždy 0) |
| `planned` | dry-run: kolik by se přeúčtovalo (plán nesimuluje pořadí — obě úhrady téhož předpisu jsou v plánu, ostrý běh druhou nechá na clearingu) |
| `skipped` | důvod → počet: `no_open_item` (bez otevřeného předpisu, vč. prázdného VS a jiného období), `no_partner`, `engine_error` (přeúčtování skončilo chybou účtování) |
| `routedAmount` | Σ `amount_hc` (domácí měna) přeúčtovaných, v dry-runu naplánovaných úhrad |

Změny proti verzi 1 (matcher): `allocated → routed`, `matchedAmount →
routedAmount` (dřív Σ v měně dokladu napříč měnami), `routedUnallocated`
zaniklo, důvody `skipped` jsou nové (`no_open_items`/`not_on_clearing`
zanikly). Runner v `old_shipard` (`printMatchSummary`) čte `planned`/
`allocated` — upravit v samostatném tasku pod #69; pořadí nasazení: nový
Shipard první (staré pole runner jen vypíše jako chybějící).

**Timeout:** běh nad migrovanými daty trvá nízké desítky sekund → endpoint je
synchronní. Controller volá `set_time_limit(0)` (PHP `max_execution_time`) a
`docs/nginx/shipard-common.conf` nastavuje `fastcgi_read_timeout 600s` (jinak
nginx utne odpověď po defaultních 60 s → 504). Klient má mít timeout ~600 s.

Kód: `Router::resolveAccbalRoute()`, `src/Api/Controller/AccbalController.php`,
`dispatchAccbal()` v `public/index.php`.

---

## 6. Měny a uzavření případu

Politika „každá částka v obou měnách" (z `accounting.md` §8) se propisuje do
salda: každý pohyb vede `amount` (měna dokladu) i `amount_hc` (domácí), a
případ má proto dva zůstatky.

Dvojí uzavření případu:

- **Obchodní saldokonto:** `Σ předpisy.amount − Σ úhrady.amount == 0` (měna
  dokladu, `residual`) → faktura je **uhrazená** (zákazník zaplatil
  dohodnutou částku). Tenhle zůstatek řídí otevřenost případu, lookup
  (§5.1) i filtry viewerů.
- **Účetní saldokonto:** `Σ předpisy.amount_hc − Σ úhrady.amount_hc == 0`
  (domácí, `residual_hc`) → účetně vyrovnáno. U cizoměnové faktury domácí
  strana dosedne až po zaúčtování **kurzového rozdílu**.

Reziduum v domácí měně po obchodním uzavření = kurzový rozdíl. **Generátor
kurzových rozdílů je mimo scope** (vlastní pozdější engine, vzor starého
`ExchDiffsEngine`); saldo ho jen vykáže jako otevřený účetní zůstatek
(sloupec Zůstatek HC, footer v HC).

---

## 7. Účetní období a otevírací sekvence

Saldokonto funguje v rámci **účetního období** (`fiscal_year` na pohybu je
součást klíče případu, D11). Na začátku období se neuhrazené případy
„otevřou" sekvencí otevíracích účetních dokladů — univerzální účetní princip
(umožní změnu metodiky apod.). Otevírací předpis nese partnera a VS/SS na
řádcích, jinak nemá klíč a nemá co párovat.

**Generátor otevíracích dokladů je mimo scope tohoto návrhu** (samostatný
task, M3). Pro saldo z toho plyne jen: otevírací předpisy přijdou jako
**normální saldo pohyby** (zdroj = otevírací doklad, prochází stejným
generátorem z deníku) a spustí trigger §5.2 — platba nového období za starý
předpis, která čekala na clearingu, se přeúčtuje sama. Na importovaném DS
je zdrojem otevíracích předpisů import ze starého Shipardu.

---

## 8. Opravy salda — jen interními doklady (D6)

Automaty **píší jen do dokladů** (D2): každý automat (dohledání symbolů,
detekce opakovaných plateb) zapisuje výsledek do zdrojového dokladu nebo
transakce (efektivní symboly), nikdy do vedlejší tabulky; deník se pak jen
přegeneruje a případ se přepočítá sám.

Opravy salda dělá uživatel **výhradně doklady**, v tomto pořadí:

1. **symboly na transakci** (efektivní VS/SS, T3) — platba s překlepem
   v symbolu;
2. **symboly na předpisu** (faktuře) — doklad měl symbol špatně;
3. **interní doklad** — přesun mezi klíči, haléřové vyrovnání, odpis,
   zápočet. Řádky na bankovní transakci se nezavádějí; automat se
   o vícecílové platby nepokouší.

Průvodci v UI (T5): sem patří jen princip. Každá oprava je vidět v deníku
a je dohledatelná zpětně — proto žádné ruční „přepárování" mimo doklady.

---

## 9. Mimo scope

- **Zálohy** — přijaté/poskytnuté, odpočet zálohy na fakturu, zdaněné zálohy
  (314900/324900). Účty jsou v osnově, skupiny v seedu; logika odpočtu je
  pozdější fáze.
- **Zápočty**, **kurzové rozdíly** (vlastní engine, §6).
- **Generátor otevíracích dokladů období** — §7.
- **Opakované platby** (D7, T4): detekce, doplnění SS, pravidlo (3) z D5;
  generování splátek a období od–do později.
- **Efektivní symboly na transakci** (D5, T3): originál z výpisu neměnný,
  efektivní editovatelný, deník razítkuje efektivní; pravidla dohledání 2–3.
- **Průvodci oprav** (D6, T5), **dashboard signál** (D8, T6: existence
  úhrady na clearingu s klíčem faktury = silný signál, že faktura je
  v pořádku).
- **Příkazy k úhradě / upomínky / penalizace** — navazují na saldo, ale samostatně.

---

## 10. Fáze implementace

**Fáze 0 — prerekvizity** (mimo vlastní accbal) ✓ hotovo:

- symboly + splatnost do `economy_accounting_journal` + razítkování v obou
  enginech + `payment_reference` do `JournalViewer` filtrů/fulltextu (§3.5)
- přejmenování `symbol1/2/3 → payment_reference/specific_symbol/constant_symbol`
  na `economy_bank_transactions` + parsery + exchange + applier (§3.6)

**Fáze 1 — nastavení saldokont** ✓ hotovo: modul `economy.accbal`, tabulky
416/417 + formuláře + viewer, seed skupin + účtů (vč. clearingu jako
„Nespárované platby") + provisioner, import/export nastavení.

**Fáze 2a — událost `journalWritten` (core)** ✓ hotovo: interface +
dispatcher + loader + registrace `journalEventHandlers`, emise z obou
účtovacích enginů.

**Fáze 2b — generování pohybů z deníku** ✓ hotovo: tabulka 418, handler na
`journalWritten` → generátor (UPSERT dle stabilního klíče, §4.3), beforeDelete
úklid, viewer ledgeru; UI vylepšení (chip lišta, sidebar položky, grid per
partner — `accbal-ledger-viewgroup-chips.md`, `accbal-nav-items.md`,
`accbal-ledger-grid.md`).

**Fáze 3 — matcher** ✓ historicky hotovo, **zrušeno revizí #69**: tabulka
419, `AllocationPlanner`, `BalanceMatcher`, matched operace (rozhodnutí
#13–#17). Odstraněno v T2.

**Revize #69** (D1–D11):

| # | Task | Rozhodnutí | Stav |
|---|---|---|---|
| T1 | `bank-payment-routing.md` | D3, D4, D8 (+ D9) — `OpenItemLookup`, routing v enginu, trigger, `accbal-match` + `/_accbal/match` v2 | ✓ 2026-09-13 |
| T2 | `accbal-symbol-key.md` | D1, D10, D11 — `CaseQuery`, normalizace klíče, `idx_case`, období v lookupu, odstranění alokační vrstvy, viewer případů, přepis tohoto dokumentu | ✓ 2026-09-14 |
| T3 | `bank-effective-symbols` | D2, D5 — originál/efektivní symboly, pravidla dohledání 2–3 | — |
| T4 | `accbal-recurring-payments` | D7 — detekce opakovaných plateb, doplnění SS | — |
| T5 | `accbal-correction-wizards` | D6 — průvodci oprav interními doklady | — |
| T6 | dashboard signál (#49) | D8 — karta přijaté faktury s úhradou na clearingu | — |

Pozdější: zálohy, zápočty, kurzové rozdíly, otevírací doklady období,
partner resolution při ingestaci.

---

## 11. Log rozhodnutí

1. Saldokonto = **vlastní modul `economy.accbal`** (závisí na accounting + bank),
   ne rozšíření accounting — ten zůstává „čistě deník".
2. Saldo čte **výhradně účetní deník** (§1.2). Žádný přímý read dokladů/transakcí
   při generování pohybů. Každý budoucí zdroj účtování krmí saldo zadarmo.
3. **Symboly + splatnost se doplní do deníku** (prerekvizita §3.5) — obrací část
   rozhodnutí #10 v `accounting.md` („saldo bez předpřípravy ve schématu").
   Důvod: drží §1.2, zlevňuje generátor (žádný join na zdroj) a dává cennou
   lidskou hodnotu (filtr deníku za VS). Levné a bezrizikové (hodnoty
   z hlavičky, grouping nedotčen).
4. Nastavení saldokont (skupiny + účty s MD/DAL + znaménko + filtr částky)
   přebráno ze starého Saldo2 — nutné kvůli sémantickému přesměrování
   (dobropis 311 záporně → Závazky, §3.2).
5. ~~**Pohyb vs. párování odděleno**: `ledger` = ryzí pohyby, `allocations` =
   vazby úhrada↔předpis s rozúčtovanou částkou.~~ **Nahrazeno #69 D1 (#20)**
   — párování je agregát klíče, žádná vrstva vazeb.
6. ~~**Identita pohybu = stabilní klíč zdroje** `(source_kind, source_id, balance,
   bal_side, account_number)`~~, ne `journal_row.id` (ten je nestabilní přes
   DELETE+INSERT deníku). UPSERT podle něj drží `id` pohybu přes přeúčtování
   (odkazy z UI, `row_id` případu). **Klíč rozšířen #69 D13 (#32)** o
   platební identitu řádku — princip „klíč ze zdroje, ne z řádku deníku"
   trvá.
7. **Clearing varianta B**: clearing účty (261200/261300) jsou saldo-skupina
   „Nespárované platby"; router má jediný zdroj kandidátů (ledger), přechod
   clearing → účet předpisu řeší re-derivace po `journalWritten`.
8. **Trigger = událost `journalWritten`** z účtovacích enginů, ne `stateChanged`.
   Drží „saldo zná jen deník" a automaticky pokrývá přeúčtování.
9. ~~**Case je odvozený** (bucket `partner+balance+currency`), ne entita.~~
   **Nahrazeno #69 D1/D11 (#20, #27)** — případ je agregát klíče
   `(balance, fiscal_year, partner, VS, SS, currency)`; entita ani sloupec
   se nezavádí.
10. Duální měna → dvojí uzavření (obchodní / účetní); generátor kurzových
    rozdílů mimo scope.
11. **Bankovní symboly přejmenovat** na `payment_reference/specific_symbol/
    constant_symbol` (varchar 35) — párovací klíč musí být porovnatelný
    napříč doklad↔transakce.
12. Matcher (Fáze 3) nadesignován samostatně — rozhodnutí #13–#17
    (**všechna nahrazena #69**).
13. ~~**Spárovanost = hodnota `operation`.**~~ **Nahrazeno #69 D3 (#19)** —
    matched operace zrušeny, o účtu úhrady rozhoduje engine dohledáním.
14. ~~**Konzervativní routing** s branou „celá alokovatelná".~~ **Nahrazeno
    #69 D3/D9 (#19, #25)** — reziduální routing: stačí otevřené reziduum > 0.
15. ~~**Matcher = samostatný průchod.**~~ **Nahrazeno #69 D4 (#19)** —
    trigger po zaúčtování předpisu + dávka routeru.
16. ~~**FIFO dle splatnosti, VS jako signál.**~~ **Nahrazeno #69 D1/D5
    (#20, #21)** — VS je součást klíče, žádná alokace.
17. ~~**Dvě vrstvy rozpárování** (auto/ruční allocations).~~ **Nahrazeno #69
    D1/D6 (#20, #22)** — opravy jen interními doklady.
18. **Clearing infrastruktura na migrovaném DS** (§4.5). Účty 261200/261300 +
    skupina `unmatched_payments` jsou infrastruktura modulů `bank`/`accbal`, ne
    migrovaná data — `ClearingInfrastructureProvisioner` je zajistí
    bezpodmínečně v `ds-upgrade` (i pod `skipProvisioning`), idempotentně podle
    `number`/`code`. Migrace nese jen business saldo skupiny; pre-flight v
    `AllRunner` ověří infrastrukturu před importem dokladů/transakcí. Zdroj
    pravdy = inline konstanty provisioneru (= enginový kontrakt), hlídané testem
    na drift proti seedům.
19. **Routing je věc účtování transakce, ne salda** (#69 D3/D4/D8, T1;
    nahrazuje #13–#15). O účtu úhrady (311/321 vs. clearing) rozhoduje
    `BankTransactionAccountingEngine` dohledáním otevřeného předpisu přes
    `OpenItemLookup` (§5.1): klíč + směr → skupiny z nastavení saldokont
    (řádek předpisu na přirozené straně směru; cílem **všechny** předpisové
    účty těchto skupin — na seedu 311, resp. 321/325/331/336/341/342/345/379,
    upřesnění 2026-09-13; dobropisové řádky s `modify_sign` mimo hru),
    reziduum > 0 (vlastní transakce vyloučena → reaccount idempotentní, bez
    paměti), prázdný VS = miss. `operation` spárovanost nenese. „Platba dřív
    než faktura": `ClearingRerouteHandler` (za `JournalLedgerHandler`, jen
    `doc`) → `ClearingRouter::rerouteForKeys`; dávka `rerouteAll`
    v CLI/endpointu (§5.7).
20. **Symbolový klíč — případ je agregát** (#69 D1, komentář issue
    2026-09-12; T2, nahrazuje #5, #9, #16, #17). Případ = klíč
    `(saldokonto, období, partner, VS, SS, měna)`, stav Σ předpisy − Σ úhrady
    z ledgeru; vrstva allocations (tabulka 419, matcher) zrušena. Důvody:
    deník jediný zdroj pravdy, vysvětlitelnost, srovnatelnost se starým
    systémem (§1). Jediná definice v `CaseQuery` (§3.4).
21. **Automaty píší jen do dokladů; originální a efektivní symboly** (#69
    D2, D5; T3). Pravidla dohledání (1) přesná shoda klíče — dnes, (2)–(3)
    s T3/T4, (4) clearing.
22. **Opravy salda výhradně interními doklady** (#69 D6; T5) — pořadí
    symboly na transakci → na předpisu → doklad (§8).
23. **Opakované platby v M2 jen minimálně** (#69 D7; T4): detekce, doplnění
    SS na přijaté faktury, pravidlo (3).
24. **Sdílený lookup** (#69 D8; T6): totéž rozhraní použije dashboard
    přijatých faktur (#49).
25. **Reziduální routing** (#69 D9, komentář 2026-09-13; potvrzeno
    2026-09-13): platba se přeúčtuje jen když má klíč kladný zůstatek;
    přeplatek a úhrada bez předpisu zůstávají na clearingu jako signál
    (§5.3). Ne existenční model.
26. **Normalizace klíče při zápisu ledgeru** (#69 D10; T2): symboly `TRIM`,
    prázdné = `NULL`, měna malými písmeny — rovnost klíče přes složený index
    `idx_case` bez funkcí ve `WHERE`, žádný uložený hash (§3.3).
27. **Účetní období v klíči** (#69 D11; T2): případ i lookup jsou scoped na
    `fiscal_year`; `OpenItemLookup::findOpenRequest` dostává období
    (`null` = bez klíče → miss); zůstatky přenáší otevírací doklad, po jehož
    zaúčtování trigger přeúčtuje čekající platbu (§5.5, §7).
28. **Viewer po případech je výchozí vstup** (T2): sidebar položky saldokont
    otevírají případy, pohyby jsou detail (akce „Pohyby případu" s chipem
    a viditelnými filtry partner / VS / SS — prefixové, uživatel je může
    uvolnit; žádný skrytý exaktní filtr). „Jen otevřené" výchozí přes
    obrácený checkbox „Včetně uzavřených" (frontend tehdy neměl výchozí
    hodnoty filtrů — od bodu 31 má, checkbox zůstává); typ otevřenosti
    samostatný filtr; doc-state konvence bez nové barvy (§3.4).
29. **Zůstatek případu na pohybu korelovaným subdotazem** (T2): LEFT JOIN na
    derived GROUP BY s `<=>` vrací v MariaDB 10.11 při `split_materialized`
    NULL (bodový dotaz) — `CaseQuery::residualSubquerySql` (§3.4).
30. **tableId 419 se nerecykluje** (T2) — vyřazená ID v
    `table-definitions.md`.
31. **Období jako první filtr s výchozím aktuálním rokem** (doplněk po
    T2, 2026-09-14, `tasks/viewer-filter-defaults-fiscal-year.md`):
    výchozí hodnoty řeší framework (`default` v definici filtru,
    `frontend.md`), ne další obrácené checkboxy; aktuální rok = rok
    obsahující dnešek, jinak nejnovější (`FiscalYearFilter`, jediný
    helper sdílený s deníkem). „Pohyby případu" posílají období případu
    (`pendingFilters` > `default`) — mění bod 28: pohyby klíče přes roky
    nejsou výchozí pohled, jsou dostupné uvolněním filtru (§3.4).
32. **Identita pohybu = zdroj + platební identita řádku** (#69 D13,
    2026-09-14, `tasks/accbal-ledger-identity-key.md`; upřesňuje #6):
    klíč `(source_kind, source_id, balance, bal_side, account_number,
    partner, VS, SS, měna)`, řádky stejné identity v jednom zdroji se
    sčítají. Původní předpoklad „partner konstantní per zdroj" neplatí pro
    otevírací, interní a pokladní doklady — slévaly se do jednoho pohybu.
    V DB `movement_key` (SHA-1, unikátní index; nullable kvůli ds-upgrade)
    — hash jen pro unikátnost pohybu, klíč případu zůstává n-ticí (D11).
    `unq_stable_key` z definice pryč, na starých DS ručně; hromadná
    re-derivace CLI `accbal-regenerate`, ne reimport (§4.3, §4.6).
33. **Osoba pro saldokonto** (#72 D1–D6, 2026-09-15,
    `tasks/doc-partner-balance.md`): pohledávka z prodejního dokladu
    kartou / bránou / dobírkou vzniká na 311 za protistranou terminálu /
    brány / dopravce (`docs_core_heads.partner_balance`, `partnerSrc:
    "balance"` v předpisu), ne na tranzitu 261400 — prostředník je běžný
    dlužník, klíč případu (D1/D11) se nemění. 315 přidáno do Pohledávek,
    provisioner doplňuje chybějící účty do existující skupiny (§3.2). Po
    nasazení na DS s doklady kartou `accbal-regenerate --all` (pohyby
    261400 zaniknou s deníkem, 311 za plátcem vzniknou po přeúčtování).

---

## 12. Otevřené body

- **Vyúčtování úhrad od brány / terminálu** (#72 D6) — 311 DAL per doklad
  / 315 MD per dávka + poplatky se v novém Shipardu zatím netvoří (jen
  import ze starého); směrování bankovního připsání od brány na 315 podle
  dávky také ne. Do té doby uživatel účetním dokladem ručně.

- **Partner resolution při ingestaci** — dohledání `partner` u bankovních
  transakcí z protiúčtu (reverse lookup přes bankovní účty `base_persons`)
  zatím není. Bez partnera úhrada zůstává na clearingu; na importovaných
  datech ze starého Shipardu partnera máme.
- **Výkon `GROUP BY` nad ledgerem** (viewer případů) pro tisíce partnerů —
  měřit na importovaném DS po nasazení; případně materializovat zůstatky až
  podle čísel, ne předem. Per-řádkový subdotaz zůstatku (viewer pohybů) je
  bodové dohledání přes `idx_case`.
- **Generátor otevíracích dokladů období** (§7) — do té doby import ze
  starého Shipardu; ověřit, že importovaný otevírací doklad nese partnera
  a VS/SS na řádcích.
