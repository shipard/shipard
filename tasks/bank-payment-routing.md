# Banka — účtování úhrady podle otevřeného předpisu (routing clearing ↔ 311/321)

**Stav:** naplánováno — T1 revize saldokonta (#69, D3/D4/D8)

## Kontext

Issue #69 mění saldokonto z alokačního modelu na **párovací symbol**
(rozhodnutí D1–D8 v komentáři issue z 2026-09-12). Tento task je první ze
šesti (T1) a implementuje **D3, D4 a základ D8**: o tom, zda bankovní úhrada
spadne na 311/321 nebo na clearing 261200/261300, rozhoduje **účtovací engine
transakce dohledáním otevřeného předpisu** — ne matcher a ne hodnota
`operation`. Saldo o párování „neví"; je to jen jiný pohled na deník.

Ve starém Shipardu musel uživatel banku v případě „platba přišla dřív než
faktura" přeúčtovat ručně. Tady to udělá trigger po zaúčtování předpisu (D4)
— to je záměr, ne vedlejší efekt.

Dnešní matcher (`BalanceMatcher`, `AllocationPlanner`, tabulka 419) v tomto
tasku **zůstává v kódu, ale nic ho nevolá** — odstraní ho T2
(`accbal-symbol-key`). Tím zůstane import na alfě funkční mezi tasky.

## Návaznost

- **Staví na:** `journalWritten` (Fáze 2a), `LedgerGenerator` (Fáze 2b),
  clearing infrastruktura (#18). Nic z toho se nemění.
- **Nahrazuje:** rozhodnutí #13–#15 v `docs/accbal.md` §10 (spárovanost =
  `operation`, konzervativní routing s branou, matcher jako samostatný průchod).
- **Kontrakt s importem:** `POST /_accbal/match` volá `old_shipard`
  (`modules/imports/newShipard/libs/runners/AllRunner.php::runMatchPhase`).
  Cesta zůstává, tělo odpovědi se mění (§4) → nová verze kontraktu v
  `docs/accbal.md §5.7`, úprava runneru v `old_shipard` pod stejným číslem
  issue (samostatný malý task tam; pořadí nasazení: nový Shipard první).
- **Následuje:** T2 `accbal-symbol-key` (drop alokací, párovací klíč na
  ledgeru), T3 `bank-effective-symbols` (pravidla dohledání 2–3 z D5).
  V tomto tasku platí **jen pravidlo 1 — přesná shoda klíče**.

## Před implementací přečti

- Issue #69 — komentář s D1–D8 a fázováním (`gh --repo shipard/shipard issue view 69 --comments`).
- `docs/accbal.md` §1–§4 (ledger, stabilní klíč, clearing skupina
  `unmatched_payments`) a §5.1–§5.2 — **co se ruší**.
- `docs/bank.md` §6.3 (clearing účty, nulová kontrola).
- `docs/accounting.md` §7 (`documentEventHandlers`, `journalWritten`) —
  vzor pro poskytovatelské rozhraní bez závislosti core na modulu.
- `modules/economy/bank/src/BankTransactionAccountingEngine.php` —
  `resolveCounterpartyAccount()`, `operationOf()`, `writeResult()`.
- `modules/economy/bank/config/txOperations.jsonc`,
  `modules/economy/accounting/config/accountingRules.cz.jsonc` (řádky
  `bank.unmatched.*` / `bank.matched.*`).
- `modules/economy/accbal/src/LedgerGenerator.php`,
  `JournalLedgerHandler.php`, `BalancesLookup.php`.
- `src/Core/Document/JournalEventHandler.php`, `JournalEventDispatcher.php`.
- `src/Api/Controller/AccbalController.php`,
  `src/Command/DataSource/AccbalMatchCommand.php`.

## 1. Lookup rozhraní (D3, D8)

Nové rozhraní v core (vzor `JournalEventHandler` — deklarace v core,
implementace v modulu, registrace v `module.jsonc`):

```php
namespace Shipard\Core\Accounting;

interface OpenItemLookup
{
    /**
     * Otevřený předpis pro klíč úhrady, nebo null.
     * Klíč = (partner, payment_reference, specific_symbol, currency);
     * $direction: 1 = příjem → hledá se předpis v pohledávkách (bal_side=0
     * ve skupině s účty 311*), 2 = výdaj → závazky (321*).
     * Otevřený = Σ předpisy − Σ úhrady pro klíč > 0 (v měně dokladu).
     * Prázdný SS na úhradě sedí jen na prázdný SS předpisu (pravidlo 1 z D5).
     */
    public function findOpenRequest(
        int $partner, string $paymentReference, string $specificSymbol,
        string $currency, int $direction,
    ): ?OpenItem;
}

final readonly class OpenItem
{
    public function __construct(
        public int $balance,            // skupina saldokonta
        public string $accountNumber,   // účet předpisu (např. 311100)
        public float $residual,         // otevřené reziduum v měně dokladu
    ) {}
}
```

- Implementace `modules/economy/accbal/src/LedgerOpenItemLookup.php` —
  agregace nad `economy_accbal_ledger` podle sloupců `balance, partner,
  payment_reference, specific_symbol, currency, bal_side`. Skupinu pro směr
  urči přes nastavení saldokont (`BalancesLookup`): skupina, která má
  `balance_accounts` s prefixem `311` (příjem) / `321` (výdaj) a
  `bal_side = předpis`. Skupinu `unmatched_payments` nikdy neprohledávej.
- Registrace: `module.jsonc` → `openItemLookup: "Shipard\\...\\LedgerOpenItemLookup"`
  (jeden poskytovatel per DS; loader analogický `journalEventHandlers`).
- **Null objekt** `NullOpenItemLookup` v core (vždy `null`) — DS bez modulu
  accbal účtuje všechno na clearing jako dnes.
- Rozhraní je v core proto, aby ho později použil i dashboard přijatých
  faktur (#49, T6). Další metody (nespárované úhrady pro klíč faktury)
  **nepřidávej** — přidá je T6.

## 2. Engine: účet úhrady dohledáním

`BankTransactionAccountingEngine::resolveCounterpartyAccount()`:

- Pro operace `payment.in` / `payment.out` (cat `bank.unmatched.*`) **před**
  řetězcem `cat → maska`: má-li transakce `partner`, zavolej
  `findOpenRequest(partner, payment_reference, specific_symbol, currency,
  direction)`. Hit → protistrana = `OpenItem::accountNumber` (přesně účet
  předpisu vč. analytiky, ne maska). Miss / bez partnera → clearing dle
  masky jako dnes.
- Ostatní operace se **nemění**.
- Engine dostane lookup konstruktorem (nullable, default
  `NullOpenItemLookup`) — proplumbovat všude, kde se engine konstruuje
  (grep `new BankTransactionAccountingEngine(` s `--include=*.php`).
- Reaccount je tím idempotentní a bez paměti: přeúčtuj → spadne, kam má.
  Žádný nový stav na transakci.

Config — **odstranit**:

- `txOperations.jsonc`: `payment.in.matched`, `payment.out.matched`.
- `accountingRules.cz.jsonc`: kategorie `bank.matched.in/out` a jejich
  řádky s maskou 311/321.
- Přejmenovat `name:cs` u `payment.in`/`payment.out` na „Příjem" / „Výdaj"
  (bez „nespárováno" — spárovanost už operace nenese).
- Transakce s dnes uloženou hodnotou `payment.*.matched` na alfě neřeš —
  DS se resetují (#69). Engine na neznámou operaci reaguje jako dnes
  (`account_not_found`).

## 3. Trigger: přeúčtování clearingu po vzniku předpisu (D4)

Nový handler `modules/economy/accbal/src/ClearingRerouteHandler.php`
(`JournalEventHandler`), registrovaný **za** `JournalLedgerHandler`
(pořadí v `journalEventHandlers` musí být deterministické — ověř, jak
dispatcher řadí; když neřadí, řeš to jedním handlerem, který po re-derivaci
ledgeru zavolá router):

1. Reaguje **jen** na `sourceKind = doc` (transakce ignoruje → žádná smyčka
   reaccount → událost → reaccount).
2. Z čerstvě re-derivovaného ledgeru zdroje vezme předpisové pohyby
   (`bal_side = 0`) a jejich klíče.
3. Pro každý klíč najde ve skupině `unmatched_payments` clearingové pohyby
   se shodným klíčem (partner, VS, SS, měna) a odpovídajícím směrem a jejich
   transakce **přeúčtuje** (`accountTransaction`). Engine je díky §2 sám
   položí na účet předpisu.
4. Idempotentní: po přeúčtování transakce na clearingu není → další běh ji
   nenajde.

Sdílené jádro `ClearingRouter` (accbal) s metodami
`rerouteForKeys(array $keys, bool $dryRun): RouteSummary`
a `rerouteAll(array $filters, bool $dryRun): RouteSummary` (filtry `partner`,
`fiscalYear`) — používá ho handler, CLI i endpoint. Dry-run vypíše plán
(tx id, klíč, cílový účet) bez zápisu.

## 4. CLI a endpoint (kontrakt s importem)

- `AccbalMatchCommand` (`accbal-match`) přepnout na `ClearingRouter`:
  ponechat `--all`, `--partner`, `--fiscal-year`, `--dry-run`; **odstranit**
  `--rematch-partner` a `--unmatch` (alokace nejsou).
- `AccbalController::match` (`POST /_accbal/match`): stejná cesta, stejná
  validace (`scope: "all"` nebo filtr), volá `rerouteAll`. Nová odpověď:

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

  Sémantika `planned` jako dosud (dry-run → `routed = 0`, plán v `planned`).
  Verzi kontraktu v `docs/accbal.md §5.7` zvednout a popsat změnu polí
  (`allocated → routed`, `matchedAmount → routedAmount`, nové klíče
  `skipped`). Runner v `old_shipard` čte `planned`/`allocated` — po nasazení
  nového Shipardu upravit `printMatchSummary` (jiný task, stejné číslo issue).

## 5. Testy

PHPUnit jen s úzkým `--filter`.

- Unit `LedgerOpenItemLookupTest`: přesná shoda klíče; prázdný SS ≠ vyplněný
  SS; uzavřený předpis (reziduum 0) → null; směr rozhoduje skupinu; skupina
  `unmatched_payments` se neprohledává.
- Integrace `BankPaymentRoutingTest`:
  1. předpis existuje → transakce spadne na účet předpisu (vč. analytiky);
  2. předpis neexistuje / chybí partner → clearing;
  3. platba dřív než faktura: tx na clearingu, zaúčtuj fakturu → handler
     přeúčtuje tx na 311, clearing pohyb v ledgeru zmizí;
  4. reaccount po spárování je idempotentní (stejný deník, žádná smyčka —
     počítej emise `journalWritten`);
  5. dry-run routeru nic nezapíše.
- `AccbalControllerTest`: nová pole odpovědi.
- Stávající `BalanceMatcherTest` / `AllocationPlannerTest` ponechat, pokud
  projdou; jinak označit `markTestSkipped` s odkazem na #69/T2 (maže je T2).

## 6. Dokumentace

- `docs/accbal.md`: hlavička **Stav** → odkaz na #69; nad §5 poznámka
  „§5 a rozhodnutí #13–#17 nahrazena D1–D8 (#69); přepis celého dokumentu
  provede T2". §5.7 aktualizovat (verze kontraktu, nová pole).
- `docs/bank.md` §6: protistrana úhrady = dohledání otevřeného předpisu přes
  `OpenItemLookup`, clearing jen při miss; kontrola nulového obratu platí
  beze změny.
- `docs/accounting.md`: sekce o `rowSide: 0` / `payment.*` a tabulka configu
  — odstranit zmínky o matched operacích; přidat `OpenItemLookup` vedle
  `journalEventHandlers` v §7.
- `tasks/README.md`: řádek v sekci Saldokonto; `python3 scripts/tasks-index.py`.

## Commit strategie

1. `OpenItemLookup` + `OpenItem` + `NullOpenItemLookup` v core, registrace
   v loaderu, `LedgerOpenItemLookup` v accbal + unit testy.
2. Engine: dohledání v `resolveCounterpartyAccount`, proplumbování, odstranění
   matched operací/kategorií z configu + integrační testy 1–2, 4.
3. `ClearingRouter` + `ClearingRerouteHandler` + CLI + endpoint + testy 3, 5,
   controller test.
4. Dokumentace + hlavička tasku `**Stav:** hotovo` + index.

## Hotovo když

- [ ] transakce s partnerem a klíčem otevřeného předpisu se zaúčtuje na účet
      předpisu bez jakéhokoli matcheru; bez shody na clearing
- [ ] zaúčtování předpisu přeúčtuje čekající clearingové úhrady se stejným
      klíčem (platba dřív než faktura)
- [ ] `payment.*.matched` a `bank.matched.*` v configu neexistují; grep
      `matched` s `--include=*.jsonc,*.php` přes `modules/economy` a `src`
      najde jen `unmatched_payments`
- [ ] `accbal-match --all --dry-run` a `POST /_accbal/match` fungují s novou
      odpovědí; `docs/accbal.md §5.7` má novou verzi kontraktu
- [ ] žádná smyčka `journalWritten` ↔ reaccount (test 4)
- [ ] `BalanceMatcher`/`AllocationPlanner` nikdo nevolá (ověřit grep), ale
      kód i tabulka 419 zůstávají — maže T2
- [ ] docs a index tasků aktualizované ve stejném commitu jako kód

## Rozhodnutí k designu (potvrzená)

- ✓ Routing je věc účtování transakce, ne salda (D3, #69).
- ✓ `operation` spárovanost nenese; matched operace se ruší (nahrazuje #13).
- ✓ Trigger přeúčtování po zaúčtování předpisu (D4); handler reaguje jen na
  `doc`, aby nevznikla smyčka.
- ✓ V T1 jen pravidlo 1 z D5 (přesná shoda klíče); pravidla 2–3 až
  s efektivními symboly (T3).
- ✓ Matcher zůstává jako mrtvý kód do T2 — import mezi tasky funguje.
