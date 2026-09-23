# Shipard — Banka (modul `economy.bank`)

**Referenční specifikace modulu** pro bankovní transakce a výpisy (původně
designový dokument; modul je implementován — viz Stav níže). Sekce „Otevřené
body" se postupně rozpouští, jak se rozšíření realizují.

Modul nahrazuje koncept „bankovní výpis = doklad s řádky" ze starého Shipardu
(`e10doc/bank`) modelem, kde **prvotřídním záznamem je jednotlivá transakce**.
Výpis se stává nepovinnou evidenční a kontrolní vrstvou.

> **Stav:** Fáze 1–4 hotové (datový model, import výpisu, mikroengine účtování,
> migrační výměnný formát + applier). Nasazení runneru staré strany
> (`old_shipard`) je samostatný navazující úkol. Saldokonto a přegenerace
> clearing → 311/321 je pozdější fáze (viz §8).

---

## 1. Motivace

Starý Shipard modeloval bankovní výpis jako klasický doklad (`docType=bank`
v `e10doc_core_heads`) s transakcemi jako řádky (`e10doc_core_rows`). Doklad
se zaúčtoval a vygeneroval saldokonto. Dvě zásadní slabiny:

- **Granularita oprav.** Špatně spárovaná transakce ve výpisu o stovkách
  řádků znamená otevřít celý doklad, najít řádek, opravit, přeúčtovat a
  přegenerovat saldokonto celého výpisu.
- **Dávkovost.** Výpis chodí max. 1× denně. V době okamžitých plateb je to
  málo — chceme transakce importovat průběžně přes bankovní API.

### Cílový model

- **Transakce** je samostatný záznam (tabulka `economy_bank_transactions`).
  Opravuje, páruje a účtuje se nezávisle na ostatních.
- **Výpis** je nepovinný evidenční záznam (tabulka `economy_bank_statements`)
  s počátečním/koncovým zůstatkem a PDF přílohou. Slouží ke **kontrole
  úplnosti** transakcí (zůstatkový můstek) a archivaci.
- **Vstup** je dvojí a konverguje do týchž transakcí:
  1. transakční API banky (průběžně, blízko reálnému času),
  2. import výpisu (soubor / API) — bez transakčního API se transakce
     z výpisu **vygenerují**, s transakčním API se jen **dohledají a ověří**.
- **Ruční pořizování** transakcí/výpisů se neuvažuje (dnes se nedělá).

### Vztah k saldokontu

Saldokonto je v novém Shipardu vědomě odloženo (`docs/accounting.md` §10).
Bankovní transakce je přitom ze své podstaty **úhrada** — protistrana zápisu
(311/321) a vazba na konkrétní fakturu jsou saldo území. Tento modul proto:

- účtuje **bankovní stranu vždy** (221xxx) a **deterministické typy**
  (poplatek, úrok, daň) na jejich reálný účet,
- **fakturové úhrady** účtuje protistranou na **zúčtovací (clearing) účet
  nespárovaných plateb** — to je definovaný **šev**, do kterého saldokonto
  v budoucí fázi zapadne bez přepisování dat (viz §6).

Princip „uživatel nikde nezadává čísla účtů" z `accounting.md` zůstává.

---

## 2. Architektura

```
┌──────────────────────────────────────────────────────────────────┐
│  Vstup (ingestion)                                                │
│  - import výpisu (soubor/API) → parser → transakce + statement    │
│  - transakční API (pozdější fáze) → transakce                     │
│  - deduplikace přes (bank_account, external_id) + fingerprint     │
├──────────────────────────────────────────────────────────────────┤
│  economy_bank_transactions   (+ economy_bank_statements)          │
│  - částka, měna, směr, symboly, protiúčet, partner, operation     │
│  - vlastní docStates; přechod do stavu „Zaúčtováno"               │
├──────────────────────────────────────────────────────────────────┤
│  BankTransactionAccountingEngine (mikroengine)                    │
│  - bankovní strana z analytiky účtu (221xxx)                      │
│  - protistrana dle operation: clearing (default) / reálný účet    │
│  - čte sekci accounts účtovacího předpisu (masky účtů)            │
├──────────────────────────────────────────────────────────────────┤
│  Účetní deník (economy_accounting_journal) — polymorfní zdroj     │
│  - source_kind: doc | bankTransaction                             │
└──────────────────────────────────────────────────────────────────┘
```

Modul `economy.bank`, závislosti: `core.system`, `economy.accounting`,
`economy.codebooks`, `docs.core` (kvůli partnerům a budoucí vazbě na doklady),
`core.attachments` (PDF výpisu).

---

## 3. Datový model

### 3.1 `economy_bank_transactions` — bankovní transakce

tableId **414**. docStates: vlastní sada `economy.bank.txStates` (§5).

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK autoincrement | |
| `bank_account` | int, FK `economy_codebooks_bank_accounts`, not null | náš účet, na kterém pohyb nastal |
| `statement` | int, FK `economy_bank_statements`, nullable | výpis, do kterého transakce patří (může dorazit přes API dřív než výpis) |
| `external_id` | varchar 80, nullable | stabilní ID transakce od banky (FIO `column22`, CAMT `AcctSvcrRef`/`EndToEndId`) |
| `fingerprint` | varchar 64, nullable | hash pro dedup, když chybí `external_id` (§4.3); plní ho ingestion |
| `direction` | enumInt | 1 = příjem (na účet), 2 = výdaj (z účtu); odvozeno ze znaménka |
| `amount` | numeric 15,2, not null | částka v měně účtu (vždy kladná; směr drží `direction`) |
| `currency` | enumString 3, cfgItem `world.base.currencies` | měna transakce (= měna účtu) |
| `amount_dom` | numeric 15,2, not null | částka v domácí měně |
| `exchange_rate` | numeric 15,6, default 1 | kurz měna→domácí |
| `date_transaction` | date, not null | datum zaúčtování bankou |
| `date_value` | date, nullable | datum valuty |
| `counterparty_account` | varchar 40, nullable | protiúčet (číslo/IBAN) |
| `counterparty_name` | varchar 150, nullable | název protistrany dle banky |
| `payment_reference` | varchar 35, nullable | variabilní symbol (konvence dokladů; 35 pro RF/EndToEndId) |
| `specific_symbol` | varchar 20, nullable | specifický symbol |
| `constant_symbol` | varchar 10, nullable | konstantní symbol |
| `message` | varchar 250, nullable | zpráva pro příjemce / poznámka |
| `partner` | int, FK `base_persons_persons`, nullable | dohledaná protistrana (§4.5) |
| `operation` | enumString 40, cfgItem `economy.bank.txOperations`, nullable | co transakce znamená — řídí účtování (§4.2 / §6.2) |
| `accounting_state` | enumInt, default 0, system, cfgItem `economy.accounting.accountingStates` | sdílíme stavy z účetnictví: 0 neúčtováno / 1 zaúčtováno / 2 chyba |
| `accounting_messages` | json, nullable, system | chyby mikroenginu |
| `docState` / `docStateMain` | tinyint, system | stavový automat (§5) |

Indexy: unikátní `(bank_account, external_id)` *(jen kde `external_id` not null)*,
unikátní `(bank_account, fingerprint)`, dále `(bank_account, date_transaction)`,
`(partner)`, `(statement)`, `(docStateMain, date_transaction)`.

Poznámky:

- `direction` + vždy kladná `amount` je čistší než debit/credit pár starého
  systému — engine i UI mají jednoznačné znaménko.
- Politika „každá částka v měně transakce i v domácí měně" (jako účtování,
  `accounting.md` §8). Kurzové rozdíly se neřeší (saldo/exch-diff, §8).
- `operation` je nepovinné; prázdné = engine použije default dle `direction`
  (clearing). Vyplněním (ručně nebo budoucí auto-klasifikací) se transakce
  zaúčtuje na reálný účet (poplatek, úrok…).

### 3.2 `economy_bank_statements` — bankovní výpis

tableId **415**. docStates: archivní sada
`core.system.docStatesArchive`. PDF výpisu přes `core.attachments`.

| sloupec | typ | popis |
|---|---|---|
| `id` | int PK | |
| `bank_account` | int, FK `economy_codebooks_bank_accounts`, not null | |
| `statement_number` | varchar 40, nullable | číslo výpisu od banky (`docOrderNumber`/`idList` ve starém) |
| `external_id` | varchar 80, nullable | stabilní identita výpisu (migrace `old:{ndx}`, budoucí API); soubory nenesou → NULL |
| `period_start` | date, not null | |
| `period_end` | date, not null | |
| `opening_balance` | numeric 15,2 | počáteční zůstatek |
| `closing_balance` | numeric 15,2 | koncový zůstatek |
| `currency` | enumString 3 | |
| `reconciliation_state` | enumInt, default 0 | 0 nezkontrolováno / 1 souhlasí / 2 nesouhlasí (§4.4) |
| `docState` / `docStateMain` | tinyint, system | |

Kontrola úplnosti (§4.4): `opening_balance + Σ(příjem) − Σ(výdaj) == closing_balance`
nad navázanými transakcemi.

**Identita výpisu** (`findOrCreateStatement`): unikátní index
`unq_external (bank_account, external_id)`, pořadí párování
1. `(bank_account, external_id)` — přesná identita,
2. `(bank_account, statement_number, period_start, period_end)` — NULL číslo
   páruje jen s NULL; nese-li payload external_id, matchují se jen výpisy bez
   něj (jiné external_id = jiný výpis) a nalezený ho dostane backfillem,
3. create.
Klíč jen z periody nestačí — dva výpisy téhož účtu z jednoho dne se slévaly
(hlavička prvního, transakce obou → nevyrovnáno). Známé omezení: dva
bezčíselné výpisy bez external_id se stejnou periodou se slijí dál — reálné
soubory číslo výpisu nesou.

### 3.3 Extension `economy.bank` → `economy_codebooks_bank_accounts`

Číselník bankovních spojení (tableId 318) je v `economy.codebooks`. Bankovní
modul ho rozšíří (vzor: jak `economy.accounting` rozšiřuje `economy_items`):

`modules/economy/bank/extensions/economy_codebooks_bank_accounts.jsonc`:

| sloupec | typ | popis |
|---|---|---|
| `accounting_account` | int, FK `economy_accounting_accounts`, nullable | analytika 221xxx — bankovní strana zápisu (ekvivalent `debsAccountId`) |
| `ebanking_id` | varchar 80, nullable | identifikace účtu v ebankingu / pro párování s hlavičkou výpisu |

Sloupce konektorů (`connector_kind`, `connector_config` se šifrovanými secrety,
`sync_cursor`) jsou **mimo scope** a přibudou až s API fází (`ADD COLUMN` je
bezpečné dodat později) — viz §8.

Picker `accounting_account` omezen na analytiky `221xxx` (`account_level = 4`,
prefix 221), aktivní záznamy.

### 3.4 Generalizace `economy_accounting_journal` — polymorfní zdroj

Deník má dnes `doc_head` jako **NOT NULL** FK na `docs_core_heads`. Aby mohl
nést i zápisy z transakcí, zavádí se diskriminátor zdroje:

| změna | popis |
|---|---|
| `source_kind` | enumString 20, not null, default `"doc"`, cfgItem `economy.accounting.journalSources` — `doc` \| `bankTransaction` |
| `doc_head` | **→ nullable** |
| `bank_transaction` | int, FK `economy_bank_transactions`, nullable — nový |
| invariant | vyplněno právě jedno z (`doc_head`, `bank_transaction`) dle `source_kind` |

Denormalizované `doc_type` / `doc_number` zůstávají (u transakce nesou
pseudo-typ a referenci výpisu/transakce pro filtr a zobrazení).
Index `idx_doc_head` doplnit o `idx_bank_transaction`.

Alternativa: generický pár `(source_table, source_id)` místo dvou FK sloupců.
Volím dva FK sloupce kvůli referenční integritě a malému počtu zdrojů — viz
Log rozhodnutí.

Invariant deníku se zobecňuje: **zdroj má řádky v deníku právě tehdy, když je
ve stavu „zaúčtováno"** (doklad stav 40; transakce `accounting_state = 1`).
Generování zůstává idempotentní (DELETE + INSERT per zdroj).

Dělba mezi moduly (realizováno): `source_kind` + nullable `doc_head` jsou v
základní tabulce (`economy.accounting`), FK `bank_transaction` přidává
`economy.bank` jako **extension** — správný směr závislosti (banka → účetnictví).
Změnu `doc_head` na nullable `ds-upgrade` sám neprovede (umí jen rozšíření
typu); deník je ale čistý derivát, takže se řeší drop+recreate.

---

## 4. Vstup transakcí (ingestion)

### 4.1 Dvě cesty, jedna pravda

| cesta | charakter | ID transakce | fáze |
|---|---|---|---|
| import výpisu (soubor) | dávka | dle formátu (CAMT ano, GPC/MT940 často ne) | **1** |
| transakční API | průběžně | obvykle ano | pozdější |

Bez transakčního API se z výpisu transakce **vygenerují**; s API už transakce
existují a import výpisu je jen **dohledá, naváže na `statement` a ověří**
zůstatkový můstek. Klíčem k tomu, aby se obě cesty nepřekrývaly do duplicit,
je dedup (§4.3).

### 4.2 Parsery formátů

Staré parsery (`e10doc/bank/ebanking/cz/*`) jsou cenný domain asset —
**portovat, ne přepisovat**: GPC, MT940, CAMT (`cba-xml`, ISO 20022), FIO JSON,
ČSOB (XML/SLK), ČS (XML/CSV), KB CSV, RB e-mail. Detekce formátu přes regexp
nad obsahem (`formats.json`) zůstává funkční koncept.

Strategicky je **CAMT.053 (ISO 20022)** hlavní formát — většina CZ bank ho
exportuje a nese stabilní reference (`AcctSvcrRef`/`EndToEndId`). Parser
produkuje neutrální mezistrukturu (hlavička výpisu + pole transakcí), z níž
se plní obě tabulky. Abstrakce parseru nesmí být tvarovaná podle FIO — API
fáze přidá konektory se složitější autentizací (ČS/Erste = OAuth2/AISP).

### 4.3 Deduplikace

Toto je u průběžného importu kritické a starý systém to neřešil (re-import
přepisoval celý doklad). FIO formát dokonce stabilní ID nese (`column22`) a
starý import ho **zahazoval**.

- **Primárně** `(bank_account, external_id)` — když banka stabilní ID dává.
- **Fallback `fingerprint`** = hash z `(bank_account, date_transaction,
  amount, direction, counterparty_account, payment_reference, specific_symbol, message,
  pořadové číslo v rámci dne)` — pro formáty bez ID. Pořadové číslo řeší dvě
  identické transakce v jednom dni.
- Import je **idempotentní**: existující transakce (dle klíče) se přeskočí
  (volitelně doplní `external_id`/`statement`, pokud dříve chyběly), nové se
  vloží.

### 4.4 Výpis jako kontrolní vrstva

`opening_balance + Σ(příjem) − Σ(výdaj) == closing_balance` nad transakcemi
navázanými na výpis → `reconciliation_state`. Nesoulad (stav 2) = chybějící
nebo přebývající transakce; napojí se na alert (vzor `core.alerts`).
Hlavička výpisu se s naším účtem páruje přes `bank_account` /
`account_number` / `iban` / `ebanking_id` (jako `checkBankAccount` ve starém).

### 4.5 Dohledání partnera

Saldo-nezávislé a levné, ponechat už v fázi 1:

- protiúčet (`counterparty_account`) → osoba přes bankovní spojení v registru
  osob (vzor `e10_base_properties` payments/bankaccount ve starém).

Dohledání podle VS proti otevřeným pohledávkám/závazkům je **saldo** —
odloženo (§6 / §8).

---

## 5. Stavový model transakce

Vlastní sada `economy.bank.txStates` (vzor `core.mail.docStatesIncoming`).
Návrh:

| docState | název | mainState | viewGroup | readOnly | přechody do |
|---|---|---|---|---|---|
| 10 | Nová | 1 | active | — | 40, 80, 90 |
| 40 | Zaúčtováno | 3 | active | 1 | 80, 90 |
| 80 | V opravě | 2 | active | — | 40, 90 |
| 90 | Smazáno | 5 | trash | 1 | 80 |

- Přechod **do 40** spustí mikroengine (zápis do deníku, `accounting_state`).
- Přechod **ze 40** (→ 80/90) smaže řádky deníku, `accounting_state = 0`.
- Účtování **neblokuje** přechod do 40 — nedohledaný účet → `accounting_state = 2`
  + alert (filozofie z `accounting.md` §7.4).

Trigger (realizováno): spouštěč je generický — `TableGateway` (instanciovaný
per-tabulku) volá `DocumentEventDispatcher` při každém přechodu stavu, takže
stačí zaregistrovat `documentEventHandlers` na `economy_bank_transactions`
(`BankTransactionEventHandler`). Žádný zásah do `core` (§6.5).

---

## 6. Účtování transakce — mikroengine

`BankTransactionAccountingEngine` (`economy.bank`). Vlastní engine kvůli
odlišnému tvaru zdroje (jedna částka + směr), ale **čte stejný účtovací
předpis** jako doklady (`economy.accounting.rules.{country}`, sekce `accounts`
pro masky účtů) — drží princip „účet se nikde nezadává".

### 6.1 Princip — dvě strany

- **Bankovní strana (vždy):** analytika z `bank_account.accounting_account`
  (221xxx). Příjem → 221 MD; výdaj → 221 DAL.
- **Protistrana (dle `operation`):**
  - prázdné / `payment.in` / `payment.out` (default, kategorie
    `bank.unmatched.*`) → **dohledání otevřeného předpisu** (#69 D3): má-li
    transakce partnera, engine se zeptá `OpenItemLookup` (rozhraní v core,
    implementace `LedgerOpenItemLookup` v `economy.accbal`) na otevřený
    předpis pro klíč případu (partner, VS, SS, měna) **v účetním období
    transakce** (#69 D11 — předpis z jiného období je miss, zůstatky
    přenáší otevírací doklad; `accbal.md` §3.4, §5.5) a směr: nejdřív
    skupiny s předpisem na straně směru (příjem → MD, typicky Pohledávky
    311*; výdaj → DAL, typicky Závazky 321* vč. 325/331/336/…) s kladným
    reziduem = dluh, pak skupiny s předpisem na opačné straně se **záporným**
    reziduem = přeplatek, dobropis nebo platba bez faktury, která se vrací
    (#69 D19; cíle plynou z nastavení saldokont, ne z čísel účtů). Zásah →
    protistrana = **účet předpisu přesně vč. analytiky**; miss / bez
    partnera / bez VS → **clearing účet nespárovaných plateb** dle masky
    (§6.3). Strana zápisu plyne ze směru transakce i u vratky (výdaj →
    311 MD), saldo z ní udělá předpis + (`accbal.md` §4.2, D23) a případ
    uzavře; částečně
    uhrazený dluh se routuje také (stačí reziduum > 0, symbolový model).
    Dobropis přesměrovaný sign-pravidlem výchozího seedu (311 záporně →
    Závazky) lookup nevidí — jeho vratka jde na clearing (`accbal.md` §5.1).
    Vlastní transakce se
    z rezidua vylučuje → reaccount je idempotentní a **bez paměti**: zmizí-li
    předpis, reaccount vrátí úhradu na clearing (automatický zpětný trigger
    není). DS bez modulu saldokonta má `NullOpenItemLookup` → vše na clearing.
    **Zásah ve skupině s kategorií úhrady** (`OpenItem::paymentCategory`,
    #79 D3a — Zálohové faktury vydané, 756 na podrozvaze): protistrana =
    maska kategorie předpisu (`advances.received` → 324) přes
    `AccountMaskResolver`, ne účet předpisu; strana dál ze směru (příjem →
    324 DAL = předpis přijaté zálohy pod klíčem proformy, `accbal.md` §5.1).
    Chybějící maska / účet → `account_not_found` jako u masky kategorie.
  - `fee.out` → 568 (bankovní poplatky), `interest.in` → 662, `interest.out`
    → 562, `tax.*` → … — reálný účet z kategorie předpisu (jako `acc.entry`).

### 6.2 Pohyby transakce — `txOperations`

cfgItem `economy.bank.txOperations` (vzor `docs.core.rowOperations`):

```jsonc
{
    "payment.in":   {"name:cs": "Příjem", "direction": 1, "cat": "bank.unmatched.in"},
    "payment.out":  {"name:cs": "Výdaj",  "direction": 2, "cat": "bank.unmatched.out"},
    "fee.out":      {"name:cs": "Bankovní poplatek",     "direction": 2, "cat": "bank.fee"},
    "interest.in":  {"name:cs": "Připsaný úrok",          "direction": 1, "cat": "bank.interest.in"},
    "interest.out": {"name:cs": "Zaplacený úrok",         "direction": 2, "cat": "bank.interest.out"},
    "transfer.in":  {"name:cs": "Příjem z převodu peněz", "direction": 1, "cat": "cash.transit"},
    "transfer.out": {"name:cs": "Výdej pro převod peněz", "direction": 2, "cat": "cash.transit"}
    // tax.* … dle potřeby
}
```

Default operace = `payment.in` / `payment.out` dle `direction`. Auto-klasifikace
poplatků/úroků dle protistrany/textu je **pozdější refinement** — fáze 1 jede
na defaultu + ruční změně `operation` ve formuláři.

`transfer.in` / `transfer.out` (#59 Task D) = převod peněz s vlastní
pokladnou (nebo vlastním jiným účtem) jako protistranou. Obě strany převodu
jdou přes **261100 Peníze na cestě** (kategorie `cash.transit`, sdílená
s pohyby `transfer.*` pokladního dokladu — `docs/accounting.md` §2, §4):
příjem `221 MD / 261100 DAL`, výdaj `261100 MD / 221 DAL`; po zaúčtování
obou stran je 261100 z převodů nulový. Routing (`ClearingRouter`, `accbal`)
takovou transakci **nevidí** — kandidáty bere z ledgeru clearingu
261200/261300 a 261100 v žádné saldo skupině není; dohledání předpisu
engine dělá jen u `payment.*`. Formulář transakce nabízí všechny pohyby bez filtru;
`BankTransactionDocument::validate` odmítne pohyb s jiným `direction`, než
má transakce (`operation_direction_mismatch`). Automatická detekce převodu
z výpisu (protiúčet = náš účet) zůstává refinement (§11).

### 6.3 Dva clearing účty + nulová kontrola

Do účtové osnovy (seed) přibudou **dvě analytiky** oddělené od generického
261100 „Peníze na cestě" (aby kontrola zůstala čistá):

| účet | název | strana při nespárování |
|---|---|---|
| `261200` | Nespárované platby — příjmy | DAL (proti 221 MD) |
| `261300` | Nespárované platby — výdaje | MD (proti 221 DAL) |

*(261 je standardní účet pro bankovní pohyb, jehož protizápis ještě není
zaúčtován; finální čísla/název potvrdit — viz Otevřené body. Mapování kategorií
`bank.unmatched.in/out` → tyto účty žije v sekci `accounts` předpisu, stejně
jako 311/321 dnes.)*

**Kontrola správnosti účtování:** v plně spárovaném stavu je na obou účtech
**nulový zůstatek i nulový obrat**. Funguje to právě proto, že deník je
derivát: jakmile engine pro transakci dohledá otevřený předpis (§6.1) — při
zaúčtování, při reaccountu, nebo když ji po zaúčtování předpisu přeúčtuje
trigger `ClearingRerouteHandler` / dávka `accbal-match` (`docs/accbal.md`
§5.7) — její řádky **přegeneruje** (DELETE + INSERT) rovnou na 311/321 a
clearing řádek **zmizí** — není to storno protizápisem. Proto na clearing
účtech v každém okamžiku sedí přesně jen **aktuálně nespárované** transakce;
nenulový zůstatek/obrat = existují nespárované platby (= signál k akci, ne
chyba účtování).

### 6.4 Kontrolní příklady

Příjem 1 210 Kč, nespárováno (`payment.in`):

```
221xxx  MD   1 210,00   (banka — analytika účtu)
261200  DAL  1 210,00   (nespárované příjmy)
                         MD = DAL ✓
```

Bankovní poplatek 50 Kč (`fee.out`):

```
568xxx  MD      50,00   (bankovní poplatky — kategorie bank.fee)
221xxx  DAL     50,00   (banka)
                         MD = DAL ✓
```

Po zavedení salda se příjem výše přegeneruje (úhrada pohledávky):

```
221xxx  MD   1 210,00
311xxx  DAL  1 210,00   (místo 261200 — clearing řádek zmizel)
```

### 6.5 Lifecycle

| událost | akce |
|---|---|
| přechod transakce **do 40** | smazat staré řádky deníku transakce → engine → zápis deníku + `accounting_state` |
| přechod **ze 40** | smazat řádky deníku, `accounting_state = 0` |
| změna `operation` u transakce ve stavu 40 | přeúčtovat (idempotentní DELETE + INSERT) |
| `beforeDelete` | smazat řádky deníku |

Chybové kódy (`accounting_messages`): `bank_account_not_found`,
`account_not_found`, `fiscal_period_missing`, `unbalanced`. Akce **Přeúčtovat**
v detailu transakce (vzor `accounting.md` §9).

---

## 7. Migrace ze starého Shipardu

Stará a nová instance běží na oddělených hostech — migrace jede přes
**výměnný formát** posílaný HTTP (vzor docs/persons/items), ne přes sdílený
filesystem.

**Nová strana — hotová.** Formát `shpd.bank.statement.v1` (hlavička výpisu +
pole transakcí, schéma v `modules/core/exchange/schemas/`) + `BankStatementApplier`
(`core.exchange`, endpointy `/_exchange/bank/statement/{validate|preview|apply}`).
Applier nemá vlastní create logiku: payload přemapuje na `ParsedStatement`/
`ParsedTransaction[]` a deleguje na **sdílené apply jádro**
`StatementImportService::applyParsedStatement` — migrace i souborový import
(§4) tak konvergují do téže logiky (dohledání účtu, dedup, vznik transakce,
reconciliace, partner). Náš účet jde jako `bankAccountId` (runner ho zná
z `LocalIdMap`), applier ho použije přímo.

**Stará strana — navazující úkol** (`old_shipard`, runner čtoucí staré
`e10doc_core_heads` `docType=bank` + `e10doc_core_rows`, mapování polí jako
níže). Nasadit **až po** nové straně (ta musí umět formát přijmout dřív).
Z hlavičky: `myBankAccount` → `bankAccountId`, perioda, `initBalance`/`balance`,
`docOrderNumber` → výpis; PDF příloha → `core.attachments` (mimo exchange JSON).
Z řádku: `debit/credit` → znaménková `amount`, `exchangeRate` (kurz za
jednotku) → `exchange_rate`/`amount_dom`, `bankAccount` → `counterparty_account`,
symboly, `text` → `message` → jedna transakce.

**Rozsah migrace (rozhodnuto):** migrujeme **jen výpisy a jejich transakce**.
„Hotové" výpisy vznikají rovnou ve stavu **40** přes dokumentovou vrstvu →
`BankTransactionEventHandler` je zaúčtuje **novým enginem** (bankovní strana +
clearing); konceptové ve stavu 10. Historický deník starého systému se
nereprodukuje. **Párování (saldo) se nemigruje** — udělá se až po saldokontu
(§6.3 přegeneruje clearing na 311/321).

Důsledek: po migraci mají všechny historické transakce protistranu na clearing
účtech → jejich nenulový zůstatek po migraci je **očekávaný** a rozpustí se
s během saldo párování. (Alternativa „zrekonstruovat i historické párování"
byla zamítnuta jako drahá a závislá na neexistujícím saldu.)

---

## 8. Mimo scope

- **Saldokonto** — párování úhrad proti pohledávkám/závazkům, dohledání 311/321,
  zálohy, zápočty, kurzové rozdíly (starý `ExchDiffsEngine`). Samostatná fáze;
  tento modul jen připravuje šev (clearing účty + `partner` + symboly +
  přegenerovatelný mikroengine).
- **API konektory** — stahování transakcí z bankovních API (FIO token,
  ČS/Erste OAuth2/AISP…), plánovač, `sync_cursor`, šifrované credentials,
  retry. Vlastní pozdější fáze; sloupce `connector_*`/`sync_cursor` na číselníku
  účtů přibudou až tehdy (`ADD COLUMN` je bezpečný) — ve Fázi 1 zavedeny nebyly.
- **Auto-klasifikace** `operation` dle protistrany/textu (poplatky, úroky).
- **Příkazy k úhradě / inkasu** (starý `bankorder`) — samostatné téma.
- **Automatická detekce převodů** mezi vlastními účty / s pokladnou z výpisu
  (protiúčet = náš účet) a párování obou stran převodu na 261100 — ruční
  volba `transfer.in/out` už existuje (#59 Task D, §6.2).
- **Kurzové rozdíly** u cizoměnových účtů — saldo/exch-diff.

---

## 9. Fáze implementace

1. ✅ **Datový model + generalizace deníku.** Tabulky `economy_bank_transactions`,
   `economy_bank_statements`, extension číselníku účtů, polymorfní zdroj deníku
   (`source_kind` + `bank_transaction`), `txStates`, `txOperations`, seed clearing
   účtů (261200/261300) + mapování v předpisu, viewer transakcí/výpisů.
2. ✅ **Import výpisu (soubor) + dedup.** Parsery (CAMT + GPC + FIO), neutrální
   mezistruktura (`ParsedStatement`/`ParsedTransaction`), deduplikace, zůstatkový
   můstek, `reconciliation_state` + alert, dohledání partnera dle protiúčtu.
3. ✅ **Mikroengine + UI účtování.** `BankTransactionAccountingEngine`, handler
   `BankTransactionEventHandler` registrovaný přes generický
   `documentEventHandlers`, tab Zaúčtování v detailu transakce + akce Přeúčtovat
   (`POST /_bank/reaccount`), alert `BankAccountingErrorsCheck` na
   `accounting_state = 2`.
4. ✅ **Migrace (nová strana).** Schéma `shpd.bank.statement.v1` +
   `BankStatementApplier` (sdílené apply jádro `applyParsedStatement`,
   zaúčtování novým enginem ve stavu 40, bez párování). Runner staré strany je
   samostatný navazující úkol (`old_shipard`).

Pozdější (mimo tento návrh): API konektory; auto-klasifikace; saldokonto a
přegenerace clearing → 311/321.

---

## 10. Log rozhodnutí

1. Transakce je prvotřídní záznam (dedikovaná tabulka `economy_bank_transactions`),
   ne řádek dokladu. Výpis (`economy_bank_statements`) je nepovinná evidenční
   a kontrolní vrstva. Typ dokladu „bankovní výpis" ze starého systému zaniká.
2. Účtování (varianta 1): bankovní strana vždy (221xxx) + deterministické typy
   na reálný účet; **fakturové úhrady na clearing účet** jako saldo-šev.
3. Dva clearing účty — `Nespárované platby — příjmy` (261200) a `— výdaje`
   (261300), oddělené od 261100. V plně spárovaném stavu nulový zůstatek
   **i obrat** (funguje díky derivátní povaze deníku: párování řádky
   přegeneruje, neúčtuje protizápis).
4. Transakce má vlastní účetní **mikroengine**, který čte sekci `accounts`
   sdíleného účtovacího předpisu (masky účtů). Princip „účet se nikde
   nezadává" zachován.
5. Účetní deník dostává polymorfní zdroj: `source_kind` (`doc` |
   `bankTransaction`) + nullable `doc_head` + nullable `bank_transaction`
   (ne generický `source_table`/`source_id` — kvůli FK integritě a malému
   počtu zdrojů). Invariant „řádky v deníku ⇔ zaúčtováno" zobecněn.
6. `direction` + vždy kladná `amount` místo debit/credit páru.
7. Deduplikace přes `(bank_account, external_id)`, fallback `fingerprint` pro
   formáty bez stabilního ID. FIO `external_id` (které starý systém zahazoval)
   se využije.
8. Dohledání partnera dle protiúčtu zůstává ve fázi 1 (saldo-nezávislé);
   dohledání dle VS proti otevřeným fakturám je saldo → odloženo.
9. API konektory jsou samostatná pozdější fáze (před saldem nepotřebné);
   abstrakce parseru/konektoru se nesmí tvarovat podle FIO (ČS = OAuth2/AISP).
   Datový model `connector_*` je předpřipraven, secrety šifrovaně.
10. Migrace: jen výpisy + jejich transakce, zaúčtované novým enginem; párování
    (saldo) se nemigruje. Historický deník se nereprodukuje.
11. Stavy transakce: vlastní sada `economy.bank.txStates`; přechod do
    „Zaúčtováno" (40) spouští mikroengine; účtování neblokuje přechod
    (`accounting_state = 2` + alert).
12. Převody peněz (#59 Task D): operace `transfer.in/out` na kategorii
    `cash.transit` → 261100, sdílené s pokladním dokladem; bez saldokontní
    skupiny (nula po obou stranách je kontrola), matcher převody nevidí
    (nejsou na clearingu). Karty pokladny tranzit nemají (#72 D1: pohledávka
    311 za protistranou terminálu, 261400 se neúčtuje). Směr pohybu
    vůči směru transakce hlídá validace dokumentu, roletka se nefiltruje.
13. Úhrada zálohové faktury vydané (#79 D3a): lookup, který trefí skupinu
    `proformas_out`, vrátí `paymentCategory = advances.received` a engine
    úhradu položí na 324, ne na podrozvahový účet předpisu 756. Totéž
    platí pro `ClearingRerouteHandler` / `accbal-match` (reaccount dělá
    engine). Uzavření případu proformy je navazující task
    (`tasks/accbal-proforma-closure.md`).

---

## 11. Otevřené body

Vyřešené (ponechané pro kontext):

- ✅ **Čísla clearing účtů** — 261200 (příjmy) / 261300 (výdaje), seedované
  v účtové osnově + mapování `bank.unmatched.in/out` v `accountingRules.cz.jsonc`.
- ✅ **Trigger účtování transakce** — generický `documentEventHandlers` (žádný
  zásah do `core`): `BankTransactionEventHandler` registrovaný na
  `economy_bank_transactions`, `TableGateway` dispatchuje při přechodu stavu.
- ✅ **tableId** — transakce 414, výpisy 415 (ne 420/421 z původního odhadu).
- ✅ **Sekce předpisu pro transakce** — vystačila kategorie `bank.*` v sekci
  `accounts` + logika v enginu; samostatná `bankTransactions` sekce netřeba.
- ✅ **Kurz u migrace** (`exchange_rate`) — nese se ze starého řádku (kurz za
  jednotku) celým řetězcem schéma `shpd.bank.statement.v1` → `ParsedTransaction`
  → `applyParsedStatement` → runner; `amount_dom = amount × rate`, prázdný/CZK
  → rate 1.

Otevřené:

- **Detekce „převod mezi vlastními účty"** (protiúčet je náš jiný účet nebo
  odvod z pokladny) — bez ruční volby `transfer.*` jde transakce na clearing
  jako ostatní; automatická detekce a párování obou stran převodu na 261100
  je rozšíření. Ruční operace `transfer.in/out` existují (#59 Task D).
- **Auto-create partnera při migraci** (`createMissingPartner`) — flag je
  proplumbovaný do apply jádra, ale auto-create osoby z protistrany zatím
  neimplementován (saldo-nezávislé, viz §7).
- **Výkon hromadné migrace** — každá transakce ve stavu 40 spustí engine
  synchronně; pro tisíce transakcí zvážit dávkové účtování (import ve stavu 10
  + jeden „account all" krok).
