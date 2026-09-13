# Shipard — Saldokonto (modul `economy.accbal`)

**Designový dokument.** Saldokonto = párování úhrad proti předpisům
(pohledávky, závazky, zálohy, …) postavené **čistě nad účetním deníkem**.
Vychází z myšlenek rozpracovaného „Saldo2" ze starého Shipardu
(`e10doc/accBal`), ale párování řeší jinak a opírá se o regenerovatelný
deník a clearing šev nového systému.

> **Stav:** Fáze 0–2b hotové a nasazené; clearing infrastruktura (§4.5, #18)
> hotová. **Revize saldokonta #69 (D1–D8) nahrazuje matcher §5:** T1
> `bank-payment-routing` (D3/D4/D8, rozhodnutí #19) je hotový — účet úhrady
> určuje bankovní engine dohledáním otevřeného předpisu (`OpenItemLookup`),
> clearing přeúčtovává `ClearingRouter` (trigger po zaúčtování předpisu,
> CLI `accbal-match`, `POST /_accbal/match` v2). `BalanceMatcher` /
> `AllocationPlanner` / tabulka 419 jsou mrtvý kód do T2 `accbal-symbol-key`
> (D1, párovací klíč na ledgeru), který přepíše i tento dokument.

---

## 1. Motivace a princip

Saldokonto odpovídá na otázku „kdo komu kolik dluží a je to uhrazené?".
Technicky: vybrané řádky účetního deníku (na saldokontních účtech —
311/321/314/324/…) jsou buď **předpisy** (vznik pohledávky/závazku), nebo
**úhrady**. Saldokontní případ je uzavřený, když se předpisy a úhrady
v rámci jednoho párovacího kontextu vyrovnají na nulu.

### 1.1 Co se přebírá ze starého Saldo2 a co ne

Přebírá se **datový princip**: nastavení saldokont = seznam skupin + seznam
účtů s konfigurací (strana MD/DAL, znaménko, předpis/úhrada), a generování
saldo pohybu z řádku deníku, pokud řádek vyhoví nastavení (`AccBalanceCreator`).
Duální měna (měna dokladu + domácí) zůstává — řeší obchodní vs. účetní
saldokonto (§6).

**Nepřebírá se** párování zašité do journal řádku. Starý `AccBalanceCreator`
vázal úhradu na předpis přes `fetch()` podle klíče `(balance, person, symbol1,
symbol2)` a sečetl. Když mělo víc faktur stejný variabilní symbol (typicky
opakované platby služeb), `fetch()` vzal první předpis a zbytek se rozsypal —
matematicky to sedělo, ale rozpad „která faktura je uhrazená" byl náhodný.
Ruční obcházení (`specifický symbol = rok+měsíc`) většina uživatelů nezvládla.

Nový model párování od symbolového klíče odděluje: pohyby jsou ryzí seznam,
**párování je samostatná vrstva** (allocations) s alokačním algoritmem
v rámci bucketu `(partner, balance, currency)`. Variabilní symbol je pro
matcher silný **signál**, ne tvrdý párovací klíč (§5).

### 1.2 Saldo pracuje výhradně s deníkem

Klíčové architektonické rozhodnutí: saldo čte **jen** `economy_accounting_journal`.
Nesahá na doklady ani transakce při generování pohybů. Důsledek: každý budoucí
zdroj účtování (pokladna, zápočty, otevírací sekvence období, ruční zápis)
nakrmí saldo **bez jediné změny v saldo kódu** — stačí, aby do deníku zapsal
řádky se symboly a splatností.

Aby to platilo doslova, deník musí symboly a splatnost nést (dnes je nemá —
viz prerekvizita §3.5). Jediná zbývající vazba saldo↔účtovací engine je
**přeúčtovací trigger** (matcher řekne bance „přegeneruj clearing → 311"),
což je vrstva **párování**, ne generování.

---

## 2. Architektura

```
┌──────────────────────────────────────────────────────────────────┐
│  Účetní deník (economy_accounting_journal)                        │
│  - jednostranné řádky, obě měny, partner                          │
│  - NOVĚ: payment_reference / specific_symbol / constant_symbol /  │
│          due_date (plní účtovací enginy — prerekvizita §3.5)      │
│  - po (pře)zápisu deníku zdroje vyšle událost journalWritten      │
├──────────────────────────────────────────────────────────────────┤
│  Generátor pohybů (economy.accbal, handler journalWritten)        │
│  - načte nastavení saldokont (balances + balance_accounts)        │
│  - řádek deníku na saldo-účtu → saldo pohyb (předpis | úhrada)    │
│  - idempotentní UPSERT podle stabilního klíče zdroje (§4.3)       │
├──────────────────────────────────────────────────────────────────┤
│  economy_accbal_ledger   (pohyby)                                 │
│  - balance, bal_side, partner, symboly, splatnost, obě měny      │
│  - source_kind + zdroj (doc_head | bank_transaction)             │
├──────────────────────────────────────────────────────────────────┤
│  Matcher (samostatná fáze) → economy_accbal_allocations           │
│  - vazby úhrada↔předpis s rozúčtovanou částkou (obě měny)        │
│  - default FIFO dle splatnosti + ruční úprava                    │
│  - výsledek řekne bance „přegeneruj clearing → 311/321"          │
└──────────────────────────────────────────────────────────────────┘
```

Modul `economy.accbal`, závislosti: `core.system`, `economy.accounting`,
`economy.bank`, `docs.core`, `economy.codebooks`.

Saldo zůstává oddělené od `economy.accounting` (ten je „čistě deník");
accbal na něj jen závisí a čte jeho tabulku.

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
| `id` | int PK | identita pohybu — na něj se vážou allocations (stabilní, §4.3) |
| `balance` | int, FK balances, not null | skupina saldokonta |
| `bal_side` | enumInt, not null | 0 = Předpis, 1 = Úhrada |
| `source_kind` | enumString 20 | `doc` \| `bankTransaction` (denorm z deníku) |
| `doc_head` | int, FK docs_core_heads, nullable | zdroj (dle source_kind) |
| `bank_transaction` | int, FK economy_bank_transactions, nullable | zdroj — FK přidává `economy.bank` jako extension (správný směr závislosti) |
| `journal_row` | int, nullable | **denorm** odkaz na aktuální řádek deníku (pro „otevřít deník"); **není** stabilní identita — viz §4.3 |
| `account_number` | varchar 12, not null | saldo-účet pohybu (311100…) |
| `fiscal_year` | int, FK fiscal_years | saldokonto je period-scoped (§7) |
| `partner` | int, FK base_persons_persons, nullable | |
| `payment_reference` | varchar 35, nullable | denorm z deníku (signál pro matcher) |
| `specific_symbol` | varchar 20, nullable | denorm |
| `constant_symbol` | varchar 10, nullable | denorm (jen informační, ne párovací) |
| `due_date` | date, nullable | splatnost (předpis); u úhrady typicky NULL |
| `currency` | enumString 3 | měna dokladu |
| `home_currency` | enumString 3 | domácí měna |
| `amount` | numeric 15,2 | částka v měně dokladu (po `modify_sign`) |
| `amount_hc` | numeric 15,2 | částka v domácí měně |
| `text` | varchar 200, nullable | |

Indexy: bucket `(balance, partner, currency, fiscal_year)`, `(payment_reference)`,
`(doc_head)`, `(bank_transaction)`, `(account_number, fiscal_year)`.

Poznámky:

- **Žádné `request`/`payment`/`residual` na pohybu** (na rozdíl od starého
  Saldo2). Reziduum a stav případu se počítají z allocations (§5).
- Symboly + splatnost se denormalizují z deníku jen kvůli indexovaným
  bucket-dotazům matcheru a UI; zdroj pravdy je deník.

### 3.4 `economy_accbal_allocations` — párovací vazby

tableId **419**.

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK | |
| `payment_entry` | int, FK ledger, not null | úhradový pohyb (`bal_side = 1`) |
| `request_entry` | int, FK ledger, not null | předpisový pohyb (`bal_side = 0`) |
| `amount` | numeric 15,2 | rozúčtovaná částka v měně dokladu |
| `amount_hc` | numeric 15,2 | rozúčtovaná částka v domácí měně |
| `created_by` | enumInt | 0 = auto (matcher), 1 = ruční |
| `note` | varchar 200, nullable | |

Indexy: `(request_entry)`, `(payment_entry)`.

Many-to-many: jedna úhrada → více předpisů (platba 600 na tři faktury) i jeden
předpis → více úhrad (faktura placená na splátky). Reziduum předpisu =
`request.amount − Σ allocations.amount`; předpis je obchodně uzavřený, když je
to nula (analogicky v domácí měně, §6).

**Případ (case)** se v Fázi 1–2 **nemodeluje jako entita** — je odvozený:
bucket `(partner, balance, currency)`, otevřené předpisy = ty s nenulovým
reziduem. Explicitní tabulka případů (kvůli ručnímu seskupení faktur
s různým VS nebo poznámce k případu) je možné rozšíření, ne nutnost (§8).

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
modul `economy.accbal` zaregistruje handler na `journalWritten`. Handler
**re-derivuje** saldo pohyby daného zdroje z aktuálního deníku.

Proč událost a ne `stateChanged`: drží §1.2 (saldo zná jen deník) a **samo
řeší clearing → 311 přechod** — když matcher spustí přeúčtování transakce,
bankovní engine přepíše deník a vyšle `journalWritten`; saldo re-derivaci
provede automaticky (clearing pohyb zmizí, 311 úhrada vznikne). Žádná zvláštní
cesta pro „po spárování".

> Vyžaduje malé doplnění do core (událost, kterou enginy vyšlou) — analogické
> `documentEventHandlers`. Detail viz prerekvizity v PRD.

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
        partner, symboly, due_date, fiscal_year, account_number, journal_row
4. UPSERT pohybů zdroje podle stabilního klíče (§4.3); chybějící smaž.
```

Jeden řádek deníku může vyhovět **víc** řádkům nastavení (vznikne víc pohybů) —
to je validní (např. týž účet ve dvou skupinách). Pohyb dědí měny z deníku
přímo, žádný přepočet (deník je už vede v obou měnách).

### 4.3 Idempotence a stabilní identita pohybu

**Problém:** deník je DELETE+INSERT, takže `economy_accounting_journal.id`
**není stabilní** přes přeúčtování. Kdyby pohyb FK-oval na `journal_row.id`,
po každém přechodu dokladu 40→80→40 by `id` přeskákalo a allocations by
dangly.

**Řešení:** identita pohybu = stabilní klíč odvozený ze **zdroje**, ne z řádku
deníku:

```
(source_kind, source_id, balance, bal_side, account_number)
```

Tenhle klíč je stabilní přes přeúčtování (zdroj se nemění, saldo-účet se
nemění). Generátor pohyby **UPSERTuje** podle něj (vzor starého
`saveBalanceJournalRequests` + memo `claimAccountForNewId`):

- existuje pohyb s klíčem → UPDATE částek/symbolů, `id` se zachová → allocations
  drží
- nový klíč → INSERT
- pohyb zdroje, který už v novém deníku není → DELETE (cascade allocations)

`journal_row` je jen **denorm** odkaz na aktuální řádek (refreshuje se při
každé re-derivaci), pro akci „otevřít řádek deníku". Není load-bearing.

> Jednoznačnost klíče: grouping deníku slučuje řádky per `(side, account_number,
> partner, operation)`; partner je konstantní per zdroj, takže na daném
> saldo-účtu a straně je per zdroj **právě jeden** řádek → klíč je unikátní.

### 4.4 Clearing šev (varianta B)

Nespárovaná bankovní úhrada má protistranu na clearingu (261200/261300, viz
`bank.md` §6.3). Clearing účty se zařazují do nastavení saldokont jako skupina
**„Nespárované platby"**:

```
261200 DAL Kladné Úhrada   (nespárovaný příjem)
261300 MD  Kladné Úhrada   (nespárovaný výdaj)
```

Důsledky:

- Nespárovaná úhrada je **normální saldo pohyb** na skupině „Nespárované platby"
  (bal_side = Úhrada, bez allocation). Matcher má tím **jediný zdroj kandidátů**
  — ledger.
- Po spárování bankovní engine přeúčtuje transakci clearing → 311/321, vyšle
  `journalWritten`, saldo re-derivuje: clearing pohyb (na „Nespárovaných")
  **zmizí**, vznikne 311/321 úhrada na skupině Pohledávky/Závazky, a matcher na
  ni naváže allocation.
- Invariant zůstává čistý: **nenulový obrat skupiny „Nespárované platby" =
  existují nespárované úhrady** (signál k akci, ne chyba).
- Pravidlo matcheru: skupina „Nespárované platby" se **nepáruje sama proti
  sobě** (nemá předpisy).

### 4.5 Clearing infrastruktura na migrovaném DS

Clearing účty 261200/261300 i skupina `unmatched_payments` normálně vznikají
seedem v `ds-upgrade` (`AccountChartProvisioner` / `BalancesProvisioner`). Na
**migrovaném DS** je ale provisioning vypnutý (`skipProvisioning`) — osnova i
saldo nastavení se přebírají ze staré strany, kde tyhle dva nové konstrukty
**nemají protějšek**. Bez nich `AccountMaskResolver` nedohledá 261200/261300
(bankovní engine → `accounting_state=2` u každé platby, hlučně) a matcher
nenajde skupinu `unmatched_payments` (`balanceId('unmatched_payments')` → null
→ tiše nula kandidátů).

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
spadne dřív, než začne import dokladů/transakcí (tichý no-op matcheru → hlasitá
chyba).

---

> **Nahrazeno (#69, 2026-09-13):** §5 a rozhodnutí #13–#17 nahrazují
> rozhodnutí D1–D8 v issue #69. Platný stav po T1: spárovanost nenese
> `operation` (matched operace zrušeny), routing clearing ↔ účet předpisu dělá
> `BankTransactionAccountingEngine` přes `OpenItemLookup` (`bank.md` §6.1),
> „platba dřív než faktura" řeší `ClearingRerouteHandler` +
> `ClearingRouter` (rozhodnutí #19), allocations se nezapisují. Aktuální je
> z této sekce jen **§5.7** (kontrakt endpointu). Přepis celého dokumentu na
> symbolový klíč provede T2 `accbal-symbol-key`.

## 5. Párování (matcher) — Fáze 3

Matcher je **samostatný explicitně volaný průchod**, ne handler `journalWritten`
(ten drží jen re-derivaci ledgeru, §4.1) — tím je rozpojená smyčka
reaccount → událost → matcher (rozhodnutí #15). Pracuje v odvozeném **bucketu**
`(partner, balance, currency)` nad otevřenými předpisy (`bal_side=0`, nenulové
reziduum) a jednotlivými úhradami; reziduum předpisu = `amount − Σ allocations`.

### 5.1 Spárovanost = hodnota `operation` (kontrakt s enginem)

Spárovanost nenese zvláštní příznak — nese ji **`operation`** transakce. Default
`payment.in` („Příjem (nespárováno)") má `cat` `bank.unmatched.in`, ten přes
účtovací předpis padá na clearing `261200`. Clearing tedy není zvláštní větev
enginu, je to výstup řetězce `operation → cat → maska`
(`accountingRules.cz.jsonc`). Spárování ten řetězec jen dotáhne na 311/321.

Nové v configu (a **nic** jinde — engine, accbal seed ani deník se nemění):

| operation | směr | cat | maska |
|---|---|---|---|
| `payment.in.matched`  | příjem | `bank.matched.in`  | 311 |
| `payment.out.matched` | výdaj  | `bank.matched.out` | 321 |

Kontrakt matcher → engine (vzor `BankController::reaccount`):

```
1. operation = payment.in.matched, partner = P    (na transakci)
2. accountTransaction(txId)              ← stávající engine, beze změny
3. engine: matched op → cat → maska 311 → účet; řádek 311 DAL + partner P;
   DELETE+INSERT deníku; po commitu synchronně journalWritten(bankTx, txId)
4. LedgerGenerator re-derivuje: clearing pohyb (jiný stabilní klíč) zmizí,
   vznikne pohyb 311 DAL = Úhrada v Pohledávkách (nové id, partner P)
5. matcher najde nový úhradový pohyb a zapíše na něj allocations
```

Re-derivace zařadí 311 DAL jako Úhradu v Pohledávkách (a 321 MD jako Úhradu
v Závazcích) sama — seed saldokont ty řádky už má (`balancesDefault.cz.jsonc`).
Rozpárování viz §5.5.

### 5.2 Routing — z clearingu do správného saldokonta

Konzervativní strategie (rozhodnutí #14). Než matcher cokoli alokuje, nasměruje
clearing-platbu:

- **Směr transakce** určuje cíl: příjem → pohledávky/311, výdaj → závazky/321.
  Opačné páry (vrácení od dodavatele jako snížení závazku ap.) jsou edge-case →
  MVP nechá na clearingu / ruční.
- **Partner povinný.** Bez `tx.partner` se routovat nedá → zůstává na clearingu.
  Dohledání partnera při ingestaci zatím není (§5.6, §11) — tvrdá závislost
  automatu; na importovaných datech partnera máme.
- **Jen stejná měna** (bucket nese `currency`). Kříž měn = území kurzových
  rozdílů (§6) → clearing / ruční.

### 5.3 Alokační algoritmus

Pořadí: rozhodni celý plán (předpisy jsou stabilní bez ohledu na to, kde platba
sedí), pak teprve přesuň a zapiš.

- **FIFO dle splatnosti:** předpisy `due_date ASC`, NULL na konec, tie-break
  `ledger.id ASC`; úhrada se rozúčtuje na nejstarší otevřené předpisy.
- **VS jako signál, ne klíč:** `payment_reference` úhrady přebije FIFO **jen**
  když jednoznačně sedí na *právě jeden* otevřený předpis — ten dostane přednost
  do výše rezidua, zbytek pokračuje FIFO. Sedí na víc předpisů (starý chaos
  opakovaných plateb) → VS se ignoruje, jede čisté FIFO; „která faktura" se pak
  rozhoduje stářím, ne náhodným prvním záznamem. `specific_symbol` se v MVP jako
  auto-signál nebere (jen se nese/zobrazuje).
- **Měny:** alokuje se v `amount` (měna dokladu = obchodní saldokonto, §6).
  `amount_hc` allocation je **proporční z platby**
  (`alloc.amount × payment.amount_hc / payment.amount`), ne z kurzu faktury →
  platba je plně spotřebovaná v obou měnách. Domácí reziduum předpisu proti jeho
  fakturačnímu kurzu zůstane jako kurzový rozdíl (vlastní engine, mimo scope).
- **Zaokrouhlení:** haléřové dorovnání na poslední alokaci, aby
  `Σ alloc.amount == payment.amount` i `Σ alloc.amount_hc == payment.amount_hc`
  přesně (vzor `accounting.md` §8).
- **Pořadí plateb v dávce:** `date_transaction ASC`, tie `id ASC`; každá platba
  hned zapíše allocations → další vidí snížená rezidua.

**Konzervativní brána (jen pro přechod clearing → 311):** platba opouští clearing
jen když je **celá alokovatelná** — `payment.amount ≤ Σ rezidua` (+ haléř) a
existuje ≥1 otevřený předpis. „Celá alokovatelná" = plně spotřebovat platbu, ne
uzavřít celé faktury (poslední dotčený předpis smí zůstat částečně otevřený).
Přeplatek / žádný kandidát / dvojznačnost → platba **zůstává na clearingu**
(signál k ruční akci). Tím jsou zavřené i přeplatky: nikam se „neukládají", jen
se nehnou z clearingu.

**Kontrolní příklad — „600 Kč na 3×250":** zákazník má tři vydané faktury po
250 Kč (tři předpisy na 311, různé VS), dluží 750, pošle 600.

```
Předpisy (ledger, bal_side=0, balance=Pohledávky):
  inv1  250   due 1.3.
  inv2  250   due 1.4.
  inv3  250   due 1.5.

Úhrada (ledger, bal_side=1): příjem 600 → nejdřív clearing „Nespárované",
matcher v bucketu (partner, Pohledávky, CZK):

  allocation: 600 → inv1 250  (inv1 uzavřen)
              → inv2 250  (inv2 uzavřen)
              → inv3 100  (inv3 reziduum 150, otevřen)

Matcher: VS platby nesedí jednoznačně → čisté FIFO. Platba je celá alokovatelná
(600 ≤ 750) → projde branou. operation = payment.in.matched → reaccount
clearing → 311 (jeden řádek 600 na partnera) → journalWritten → re-derivace:
clearing pohyb zmizí, vznikne 311 úhrada 600, allocations (250/250/100) se
navážou na ni.
```

Granularita 3-cestného rozúčtování žije **jen v allocations** — deník nese jeden
311 řádek 600 Kč (bankovní engine nepotřebuje znát rozpad). Tím zůstává deník
čistý a starý „chaos stejných VS" mizí: párujeme částkou a stářím, ne přesnou
shodou symbolu.

### 5.4 Auto vs. ruční allocations

`created_by=0` (auto) jsou jednorázové, `created_by=1` (ruční) posvátné. Matcher
na ruční **nikdy** nesáhne a počítá je jako předem spotřebované — reziduum
předpisu i zbytek platby se počítají po odečtení ručních allocations; automat
doplní jen to, co ruční nezabraly. Ruční cesta je neomezená: člověk v UI smí
spárovat cokoli (záměrný přeplatek, kříž s ručním kurzem, později zálohu) týmž
mechanismem (matched operace → reaccount → ruční allocation). Automat je opatrný,
člověk má plnou moc.

### 5.5 Přegenerace případu vs. úplné rozpárování

Dvě různé operace na dvou různých vrstvách (rozhodnutí #17):

- **Přegenerace případu** (levný reset *vrstvy allocations*): smaž `created_by=0`
  allocations bucketu → spusť matcher pro bucket znovu (přeživší ruční respektuje,
  zkusí i dosud clearingové platby partnera). Platby **zůstávají na 311** —
  routing se nehýbe. Konzervativní brána platí jen pro clearing → 311; platba,
  která už na 311 je, se alokuje best-effort a při neúplném pokrytí nechá
  nealokované reziduum na 311 (signál), **nevrací se** na clearing.
- **Úplné rozpárování** (vědomý destruktivní reset *vrstvy routingu*): `operation`
  zpět na `payment.in/out` + reaccount → 311 pohyb zmizí a cascade smaže **všechny**
  jeho allocations (auto i ruční). Jediná cesta, která ničí ruční allocations —
  v UI proto zřetelně oddělená od přegenerace (jiné tlačítko + potvrzení).

### 5.6 Vstupní body a běh

Jádro vystaví metody (`matchTransaction`/`matchBucket`, `rematchBucket`,
`unmatch`); nad nimi tenké vrstvy jako dnes `BankController::reaccount` nad
enginem, v pořadí dle reálné potřeby:

- **CLI dávka `AccbalMatchCommand`** (per-DS, `src/Command/DataSource/`) —
  okamžitá potřeba pro importovaná data. Flagy `--all` / `--partner=` /
  `--fiscal-year=`, `--rematch-partner=`, `--unmatch=` a hlavně **`--dry-run`**
  (vypíše plán bez reaccountu a allocations — bezpečné ladění na importu).
- **Controller akce + UI „Spáruj" / „Rozpárovat"** — později, zrcadlo `reaccount`.
- **Auto po ingestaci / cronu — odloženo** do partner resolution + důvěry
  v algoritmus (tiché přesouvání na 311 jde proti konzervativnímu duchu).

Běh je **monotónní** — matcher jen přidává páry, nikdy sám nerozpojuje → dávka je
bezpečně opakovatelná (spárované platby nejsou na clearingu → nejsou kandidáti).
Selhání po reaccountu před alokací není ztrátové: 311 úhrada zůstane nealokovaná
a další běh ji dožene.

Mimo Fázi 3 (pozdější témata): zálohy (přijaté/poskytnuté, odpočty, zdanění —
účty 314/324/…900 jsou v osnově), zápočty, kurzové rozdíly (§6), multi-cíl (jedna
platba na fakturu + odpočet zálohy), explicitní entita případu.

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
a směr → přeskočen; jinak `accountTransaction` (engine si účet předpisu
dohledá sám, `bank.md` §6.1) → `journalWritten` → re-derivace ledgeru.
Sekvenčně: každé přeúčtování hned sníží reziduum klíče, druhá úhrada už
uzavřeného předpisu zůstane na clearingu.

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
| `skipped` | důvod → počet: `no_open_item` (bez otevřeného předpisu, vč. prázdného VS), `no_partner`, `engine_error` (přeúčtování skončilo chybou účtování) |
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
salda: pohyb i allocation vedou `amount` (měna dokladu) i `amount_hc` (domácí).

Dvojí uzavření předpisu:

- **Obchodní saldokonto:** `Σ allocations.amount == request.amount` (měna
  dokladu) → faktura je **uhrazená** (zákazník zaplatil dohodnutou částku).
- **Účetní saldokonto:** `Σ allocations.amount_hc == request.amount_hc`
  (domácí) → účetně vyrovnáno. U cizoměnové faktury domácí strana dosedne až
  po zaúčtování **kurzového rozdílu**.

Reziduum v domácí měně po obchodním uzavření = kurzový rozdíl. **Generátor
kurzových rozdílů je mimo scope** (vlastní pozdější engine, vzor starého
`ExchDiffsEngine`); saldo ho jen vykáže jako otevřené účetní reziduum.

---

## 7. Účetní období a otevírací sekvence

Saldokonto funguje v rámci **účetního období** (`fiscal_year` na pohybu). Na
začátku období se neuhrazené případy „otevřou" sekvencí otevíracích účetních
dokladů — univerzální účetní princip (umožní změnu metodiky apod.).

**Generátor otevíracích dokladů je mimo scope tohoto návrhu.** Pro saldo z toho
plyne jen: otevírací předpisy přijdou jako **normální saldo pohyby** (zdroj =
otevírací doklad, prochází stejným generátorem z deníku). Tabulky nic
speciálního nepotřebují; `fiscal_year`-scoping ano.

---

## 8. Mimo scope

- **Matcher (alokační algoritmus)** — §5; samostatná Fáze 3 s vlastním designem.
- **Zálohy** — přijaté/poskytnuté, odpočet zálohy na fakturu, zdaněné zálohy
  (314900/324900). Účty jsou v osnově, skupiny v seedu; logika odpočtu je
  součást matcheru/pozdější fáze.
- **Zápočty** (vzájemné pohledávky/závazky), **kurzové rozdíly** (vlastní engine),
  **přeplatky / platby bez předpisu**.
- **Generátor otevíracích dokladů období** — §7.
- **Explicitní entita „případ"** — ruční seskupení faktur s různým VS do jednoho
  případu, poznámka k případu. Fáze 1–2 jede na odvozeném case (bucket); entita
  je možné rozšíření.
- **Příkazy k úhradě / upomínky / penalizace** — navazují na saldo, ale samostatně.

---

## 9. Fáze implementace

**Fáze 0 — prerekvizity** (mimo vlastní accbal) ✓ hotovo:

- symboly + splatnost do `economy_accounting_journal` + razítkování v obou
  enginech + `payment_reference` do `JournalViewer` filtrů/fulltextu (§3.5)
- přejmenování `symbol1/2/3 → payment_reference/specific_symbol/constant_symbol`
  na `economy_bank_transactions` + parsery + exchange + applier (§3.6)

> Událost `journalWritten` (§4.1) přesunuta z Fáze 0 do **Fáze 2a** — staví se
> až s prvním konzumentem (generátorem), ne spekulativně bez odběratele.

**Fáze 1 — nastavení saldokont** ✓ hotovo:

- modul `economy.accbal`, tabulky `economy_accbal_balances` (416),
  `economy_accbal_balance_accounts` (417) + formuláře + viewer
- seed skupin + účtů (vč. clearingu jako „Nespárované platby") + provisioner
- import/export nastavení (vzor starého `AccBalances{Import,Export}Wizard`)

**Fáze 2a — událost `journalWritten` (core)** ✓ hotovo:

- mechanismus `journalWritten` (interface + dispatcher + loader + registrace
  `journalEventHandlers`), zrcadlo `documentEventHandlers` (§4.1)
- emise z obou účtovacích enginů (po (pře)zápisu i vymazání deníku),
  proplumbování dispatcheru do míst konstrukce enginu

**Fáze 2b — generování pohybů z deníku** ✓ hotovo:

- tabulky `economy_accbal_ledger` (418), `economy_accbal_allocations` (419)
- handler na `journalWritten` → generátor pohybů (UPSERT dle stabilního klíče,
  §4.3) vč. clearing skupiny + beforeDelete úklid ledger/allocations
- viewer ledgeru (read-only, filtry: skupina, partner, VS, jen otevřené)

**Fáze 3 — matcher** ✓ hotovo (§5):

- config: matched operace `payment.in.matched`/`payment.out.matched` + řádky
  předpisu `bank.matched.in → 311` / `bank.matched.out → 321` (§5.1)
- `AllocationPlanner` — čisté jádro: routing (§5.2) + FIFO/VS (§5.3), konzervativní
  brána (1 haléř), proporční domácí částka + haléřové dorovnání; `enforceGate`
  přepíná clearing→311 (brána) vs. best-effort rematch
- `BalanceMatcher` — `matchTransaction`/`matchAll`/`rematchBucket`/`unmatch`,
  kontrakt přes `operation` + `accountTransaction` (§5.1), monotónní průchod;
  auto/ruční allocations, přegenerace bucketu vs. úplné rozpárování (§5.4/5.5)
- CLI `AccbalMatchCommand` (`accbal-match`) s `--dry-run` (§5.6)

**Revize #69 — T1 `bank-payment-routing`** ✓ hotovo (2026-09-13, #19):
`OpenItemLookup` v core + `LedgerOpenItemLookup`, routing v bankovním enginu,
`ClearingRerouteHandler` + `ClearingRouter`, `accbal-match` a
`POST /_accbal/match` v2 (§5.7); matched operace zrušeny. Následuje T2
`accbal-symbol-key` (D1), T3 `bank-effective-symbols` (D2, D5), T4
opakované platby (D7), T5 průvodci oprav (D6), T6 dashboard (D8).

Pozdější (mimo Fázi 3): UI párování + bucket pohled (kdo kolik dluží),
auto-trigger po ingestaci/cronu.

Pozdější: zálohy, zápočty, kurzové rozdíly, otevírací doklady období, multi-cíl,
explicitní case entita, partner resolution při ingestaci.

---

## 10. Log rozhodnutí

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
5. **Pohyb vs. párování odděleno**: `ledger` = ryzí pohyby (bez request/payment/
   residual), `allocations` = vazby úhrada↔předpis s rozúčtovanou částkou.
   Ruší starý párovací klíč zašitý do journal řádku → řeší „chaos stejných VS".
6. **Identita pohybu = stabilní klíč zdroje** `(source_kind, source_id, balance,
   bal_side, account_number)`, ne `journal_row.id` (ten je nestabilní přes
   DELETE+INSERT deníku). UPSERT podle něj drží allocations přes přeúčtování.
7. **Clearing varianta B**: clearing účty (261200/261300) jsou saldo-skupina
   „Nespárované platby"; matcher má jediný zdroj kandidátů (ledger), přechod
   clearing → 311 řeší re-derivace po `journalWritten`.
8. **Trigger = událost `journalWritten`** z účtovacích enginů, ne `stateChanged`.
   Drží „saldo zná jen deník" a automaticky pokrývá přeúčtování po spárování.
9. **Case je odvozený** (bucket `partner+balance+currency`), ne entita.
   Explicitní case entita je možné pozdější rozšíření.
10. Duální měna → dvojí uzavření (obchodní / účetní); generátor kurzových
    rozdílů mimo scope.
11. **Bankovní symboly přejmenovat** na `payment_reference/specific_symbol/
    constant_symbol` (varchar 35) — párovací klíč musí být porovnatelný
    napříč doklad↔transakce.
12. Matcher (Fáze 3) nadesignován samostatně — rozhodnutí #13–#17.
13. **Spárovanost = hodnota `operation`** (D1). Matcher nastaví matched operaci
    (`payment.in.matched` → 311 / `payment.out.matched` → 321) a zavolá stávající
    `accountTransaction`; clearing → 311/321 je výstup řetězce `operation → cat →
    maska`. Nula změn v enginu, accbal seedu i deníku; reverzibilní a idempotentní
    přes re-derivaci (#7).
14. **Konzervativní routing** (D2). Směr transakce určuje cíl (příjem → 311,
    výdaj → 321), partner povinný, jen stejná měna. Platba opouští clearing jen
    když je celá alokovatelná; jinak zůstává (signál) — tím i přeplatky zůstávají
    na clearingu.
15. **Matcher = samostatný průchod**, ne handler `journalWritten` (D3) — rozpojuje
    smyčku reaccount → událost → matcher. Vstup: CLI dávka s `--dry-run`, UI a
    auto-trigger později. Běh monotónní (jen přidává páry).
16. **FIFO dle splatnosti, VS jako signál** (D4). VS přebije FIFO jen při
    jednoznačné shodě na jeden předpis (léčí „chaos stejných VS"); alokace v měně
    dokladu, domácí proporčně z platby; haléřové dorovnání na poslední alokaci.
17. **Dvě vrstvy rozpárování** (D5). Auto allocations (`created_by=0`) jednorázové,
    ruční (`1`) posvátné. Přegenerace bucketu = smaž auto + spusť znovu (platby
    zůstanou na 311). Úplné rozpárování = `operation` zpět + reaccount (311 →
    clearing, cascade smaže i ruční) — vědomá destruktivní akce.
18. **Clearing infrastruktura na migrovaném DS** (§4.5). Účty 261200/261300 +
    skupina `unmatched_payments` jsou infrastruktura modulů `bank`/`accbal`, ne
    migrovaná data — `ClearingInfrastructureProvisioner` je zajistí
    bezpodmínečně v `ds-upgrade` (i pod `skipProvisioning`), idempotentně podle
    `number`/`code`. Migrace nese jen business saldo skupiny; pre-flight v
    `AllRunner` ověří infrastrukturu před importem dokladů/transakcí. Zdroj
    pravdy = inline konstanty provisioneru (= enginový kontrakt), hlídané testem
    na drift proti seedům.
19. **Routing je věc účtování transakce, ne salda** (#69 D3/D4/D8, T1;
    nahrazuje #13–#17). O účtu úhrady (311/321 vs. clearing) rozhoduje
    `BankTransactionAccountingEngine` dohledáním otevřeného předpisu přes
    `OpenItemLookup` (rozhraní v core, implementace `LedgerOpenItemLookup`
    v accbal, registrace `openItemLookup` v module.jsonc): klíč (partner, VS,
    SS, měna) + směr → skupiny z nastavení saldokont (řádek předpisu na
    přirozené straně směru: příjem MD, výdaj DAL; cílem **všechny** předpisové
    účty těchto skupin — na seedu 311, resp. 321/325/331/336/341/342/345/379 —
    ne jen 311/321, upřesnění po ověření 2026-09-13; dobropisové řádky
    s `modify_sign` mimo hru), reziduum Σ předpisy − Σ úhrady > 0 (vlastní transakce
    vyloučena → reaccount idempotentní, bez paměti), prázdný VS = miss,
    přeplatek se routuje. `operation` spárovanost nenese, matched operace
    zrušeny. „Platba dřív než faktura": `ClearingRerouteHandler` (za
    `JournalLedgerHandler`, jen `doc`) → `ClearingRouter::rerouteForKeys`;
    dávka `rerouteAll` v CLI/endpointu (§5.7). Allocations se nezapisují.

---

## 11. Otevřené body

- **Partner resolution při ingestaci** — dohledání `partner` u bankovních
  transakcí z protiúčtu (reverse lookup přes bankovní účty `base_persons`) zatím
  není. Tvrdá závislost auto-matcheru (§5.2/5.6); na importovaných datech ze
  starého Shipardu partnera máme, takže neblokuje odladění Fáze 3.
- **Výkon hromadné re-derivace** — generátor i matcher běží per zdroj/platbu;
  pro tisíce zdrojů zvážit dávkový režim (analogie `bank.md` §11 „account all").
  Per-platba zpracování matcheru je ale přirozeně nezávislé.
- **Ruční párovací UI** — guard „ruční allocation ≤ min(reziduum předpisu, zbytek
  platby)", výběr předpisů, záměrný přeplatek / kříž měn. Doladit s UI matcheru.
