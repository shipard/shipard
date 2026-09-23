# Uzavírání zálohových faktur při úhradě — `JournalContributor`

**Stav:** částečně — kód hotový 2026-09-23 (5 commitů, #79 D3b/D3c):
core `JournalContributor` + loader + injekce, contributory v obou enginech
(sdílené `AccountingRules` / `JournalContributions`, varování
`contributor_failed` s `level`), `CaseClosureContributor`,
`CaseClosureRerouteHandler`, docs a help; unit i integrační testy na
dev DS 4l3j zelené (`ProformaClosureTest`: banka, částečné úhrady,
přeplatek, idempotence, EUR kurzem proformy, jiný rok, pokladna, konečná
faktura, platba dřív než proforma oběma cestami). Bez změny schématu ani
cfgItem. Zbývá ruční proklik UI a nasazení na alfu spolu
s `tasks/doc-proforma-out.md` a `tasks/accbal-proformas-out.md`; na DS
s proformami uhrazenými před nasazením `doc-reaccount` proforem
(trigger dohledá bankovní i pokladní zdroje bez uzavření)
**Issue:** #79 (komentář 2026-09-23 „Revize rozhodnutí“, D3 nahrazuje
původní D3 z těla issue); souvisí s #69 (saldokonto — D4, D11, D19, D23).
**Milník:** M2.
**Návaznost:** předpokládá `tasks/doc-proforma-out.md` a
`tasks/accbal-proformas-out.md` (skupina `proformas_out` se sloupci
`payment_category` a `closing_category`, přesměrování úhrady na 324).
Navazuje import v `old_shipard` (#79 D4) a storno / uzavření zbytku (D5).

## Cíl

Úhrada zálohové faktury uzavře její případ v saldokontu **ve stejném
deníku, ve kterém vzniká přijatá záloha**:

```
bankovní příjem 12 100 s VS proformy
  221100 MD 12 100 / 324100 DAL 12 100    ← dnes (accbal-proformas-out)
  799100 MD 12 100 / 756100 DAL 12 100    ← nově: uzavření proformy
```

Totéž u pokladního dokladu s řádkem `advance.received` a VS proformy.
Uzavírací pár přidá modul saldokonta přes nové **core rozhraní
`JournalContributor`**, které volají **oba** účtovací enginy před zápisem
deníku. Bankovní ani dokladový engine tím nezíská žádnou znalost proforem;
pravidlo je jedno pro všechny kanály (banka, pokladna, import) a reaccount
zůstává idempotentní (DELETE + INSERT, jako dnes).

Proč ne v bankovním enginu (původní D3): stejnou logiku by potřebovala
pokladna i budoucí kanály; a proč ne dopsáním z handleru `journalWritten`:
každý reaccount by řádky smazal a handler by je musel psát znovu do cizího
deníku — křehké.

## Před implementací přečti

- `docs/accounting.md` §7.1 (`documentEventHandlers`, `journalEventHandlers`,
  `openItemLookup` — vzor „deklarace v core, implementace v modulu,
  registrace v `module.jsonc`“), §7.3 (algoritmus `AccountingEngine`)
- `docs/bank.md` §6.1, §6.5
- `docs/accbal.md` §3.3–3.4 (ledger, případ), §4.2–4.3 (generátor,
  idempotence), §5.1–5.2 (lookup, `ClearingRerouteHandler`), §5.5 (období)
- `docs/modules.md` (pole `module.jsonc`, `ModuleDefinition::fromArray`)
- `src/Core/Accounting/OpenItemLookup.php`, `AbstractOpenItemLookup.php`,
  `NullOpenItemLookup.php`; `src/Api/OpenItemLookupLoader.php`,
  `JournalEventHandlerLoader.php`; `src/Core/Module/ModuleDefinition.php`
- `src/Core/Document/DocumentEventDispatcher.php`,
  `AbstractDocumentEventHandler.php` (`setOpenItems` — vzor injekce)
- `modules/economy/accounting/src/AccountingEngine.php` (`accountDocument`,
  `makeLine`, `groupLines`, `writeResult`), `AccountMaskResolver.php`
- `modules/economy/bank/src/BankTransactionAccountingEngine.php`
  (`accountTransaction`, `makeLine`, `maskForCategory`, `writeResult` —
  identita řádků z transakce)
- `modules/economy/accbal/src/LedgerOpenItemLookup.php`, `CaseQuery.php`,
  `ClearingRerouteHandler.php`, `ClearingRouter.php`, `LedgerGenerator.php`
- všechna místa, kde vzniká engine:
  `grep -rn --include=*.php -e 'new AccountingEngine(' -e 'new BankTransactionAccountingEngine(' modules src public`
  (dnes 6: `DocsHeadsEventHandler`, `AccountingController`,
  `BankTransactionEventHandler`, `BankController`, `ClearingRouter`,
  `DocReaccountCommand`)

## Rozhodnutí (#79)

- **D3b** Uzavírací pár přidá `JournalContributor` modulu saldokonta
  ke každému spouštěcímu řádku (níže), jehož klíč má otevřený případ ve
  skupině s `closing_category`, ve stejném deníku a transakci.
- **D3c** Částka = min(spouštěcí řádek, reziduum případu) v měně případu;
  v domácí měně **kurzem proformy** (poměr Σ `amount_hc` / Σ `amount`
  předpisů případu), takže podrozvaha vychází na nulu. Kurzový rozdíl
  zůstává na zálohách.
- **D6** Klíč případu včetně fiskálního roku (#69 D11) — úhrada v jiném
  roce než proforma proformu nenajde, dokud přenos neudělá otevírací
  doklad (import D4, později uzávěrka).

## Scope

### 1. Core — rozhraní a loader

`src/Core/Accounting/JournalContributor.php`:

```php
/**
 * Příspěvek modulu do deníku zdroje (#79 D3b): engine po sestavení řádků
 * (a před kontrolou vyrovnanosti a zápisem) předá contributorům kontext
 * a řádky; contributor vrátí požadavky na další řádky. Engine je doplní,
 * dohledá účty a zapíše vše v jedné transakci. Deklarace v core,
 * implementace v modulu, registrace `journalContributors` v module.jsonc
 * (vzor openItemLookup) — engine na modulu nezávisí.
 */
interface JournalContributor
{
    /**
     * @param list<JournalLineView> $lines  řádky zdroje (po seskupení)
     * @return list<JournalLineRequest>     vyrovnané páry (Σ MD = Σ DAL)
     */
    public function contribute(JournalSourceContext $context, array $lines): array;
}
```

Datové třídy (readonly, bez logiky, `src/Core/Accounting/`):

- `JournalSourceContext` — `sourceKind` (`doc` | `bankTransaction`),
  `sourceId`, `accountingDate`, `fiscalYear`, `currency` (měna zdroje),
  `homeCurrency`.
- `JournalLineView` — `side`, `accountNumber`, `isError`, `operation`,
  `partner`, `paymentReference`, `specificSymbol`, `moneyDom`, `moneyCur`
  (částka strany, kladná). Engine ji plní z vlastních řádků; bankovní
  engine doplní identitu z transakce (jeho řádky ji samy nenesou).
- `JournalLineRequest` — `side`, **buď** `category` (kategorie předpisu,
  engine dohledá masku) **nebo** `accountNumber` (přesný účet, engine
  ověří v rozvrhu), `partner`, `paymentReference`, `specificSymbol`,
  `moneyDom`, `moneyCur`, `text`. Operace řádku se nepředává — engine
  zapíše `NULL` (generátor pak řádek zařadí podle nastavení skupin, krok
  d) v `docs/accbal.md` §4.2).

`AbstractJournalContributor` se settery `setDb`, `setConfig`, `setDsConfig`
(vzor `AbstractOpenItemLookup`).

**Registrace**: `module.jsonc` → `"journalContributors": ["FQCN", …]`
(seznam, víc modulů smí přispívat; pořadí = pořadí resolvovaných modulů).
`ModuleDefinition` nové pole + validace ve `fromArray` (neprázdné stringy).
Loader `src/Api/JournalContributorLoader.php` (mirror
`OpenItemLookupLoader`: `load()` + `fromModules()`, neexistující třída
nebo třída bez rozhraní = `LogicException`) vrací
`JournalContributorSet` (core, iterovatelný seznam; prázdný = žádní
contributoři).

**Injekce** všude, kde se dnes injektuje `OpenItemLookup`:
`DocumentEventDispatcher` → `AbstractDocumentEventHandler::setJournalContributors()`,
controllery v `public/index.php`, `ClearingRouter`, `DocReaccountCommand`.
Oba enginy dostanou volitelný parametr konstruktoru
`?JournalContributorSet $contributors = null` (na konci; `null` = žádní —
testy a DS bez modulu saldokonta beze změny). `AccountingEngine` ho
dostane poprvé; stávající volání se jen rozšíří.

### 2. Enginy

Oba enginy po sestavení řádků (`AccountingEngine` po `groupLines`,
bankovní po sestavení dvou řádků), **před** kontrolou vyrovnanosti:

1. Sestaví `JournalSourceContext` a `JournalLineView` pro řádky bez
   `is_error`.
2. Zavolá contributory v pořadí; výjimka contributoru se **zaloguje
   (`ErrorLogger`) a spolkne** — deník se zapíše bez příspěvku a do zpráv
   účtování přibude varování `contributor_failed` (stav účtování to
   nezmění na chybu; saldo pak ukáže proformu otevřenou, což je
   bezpečný stav).
3. Každý `JournalLineRequest` převede na řádek deníku: `category` →
   maska z `accounts` předpisu (`maskForCategory` — v `AccountingEngine`
   přidat ekvivalent, ne přes `resolveCategoryAccount` s krokem) →
   `AccountMaskResolver`; `accountNumber` → `AccountMaskResolver` s
   plným číslem. Nenalezeno → chybový řádek jako dnes
   (`account_not_found`, číslo doplněné `?`).
4. `AccountingEngine`: nové řádky projdou `groupLines` znovu (sčítání
   podle klíče), identita z požadavku. Bankovní engine: identitu píše
   z transakce (`writeResult`) — požadavek s jinou identitou, než má
   transakce, je chyba contributoru → `LogicException` (kontrakt, ne
   datová chyba).
5. Pak dosavadní kontrola vyrovnanosti — požadavky musí být vyrovnané,
   jinak skončí jako `unbalanced` (chyba contributoru se tím projeví).

Prázdný `JournalContributorSet` = chování přesně jako dnes (regresní
testy obou enginů beze změny).

### 3. `economy.accbal` — `CaseClosureContributor`

Generický contributor nad nastavením skupin, ne nad „proformami“:
cílové skupiny = aktivní skupiny s vyplněným `payment_category`
**a** `closing_category` (na seedu jen `proformas_out`). Registrace
`"journalContributors": ["Shipard\\Module\\Economy\\Accbal\\CaseClosureContributor"]`.

**Spouštěcí řádek** pro skupinu G:

- operace řádku je příjem/výdej peněz nebo zálohy — konstanta
  `TRIGGER_OPERATIONS = ['payment.in', 'payment.out', 'advance.received', 'advance.given']`
  (banka a pokladna). Ostatní operace nespouští nic — zejména
  `sale.advanceVat` / `sale.advanceDeduction` na faktuře (pohyb 324 bez
  peněz), ruční `acc.entry` na účetním dokladu (uzavírá se explicitně
  průvodcem D5);
- účet řádku začíná maskou kategorie `payment_category` skupiny
  (maska z předpisu, např. `324`);
- strana řádku je **opačná** k předpisové straně G (G = předpis 756 MD →
  spouští 324 **DAL**; přijaté proformy by zrcadlově spouštěl 314 MD);
- kladná částka, vyplněný partner a VS, `is_error = 0`.

**Pro každý spouštěcí řádek** (v pořadí řádků; víc řádků téhož klíče
postupně spotřebovává reziduum):

1. Klíč případu = (G, fiskální rok zdroje, partner, VS, SS, měna zdroje)
   — normalizace jako `CaseQuery` (D10).
2. Reziduum případu v měně případu z `economy_accbal_ledger` **bez
   pohybů vlastního zdroje** (`source_kind`, `source_id`) — stejná
   exkluze jako lookup u reaccountu, díky ní je výsledek idempotentní.
   Sdílená metoda s `LedgerOpenItemLookup` (např.
   `LedgerOpenItemLookup::caseResidual(int $balance, array $key, ?string $excludeKind, ?int $excludeId): ?CaseResidual`
   vracející reziduum, Σ `amount`/`amount_hc` předpisů a účet prvního
   předpisu), ne druhé SQL.
3. Reziduum ≤ tolerance → nic. Jinak částka `cur = min(řádek.moneyCur,
   reziduum − už spotřebované tímto zdrojem)`; `dom = cur ×
   (Σ amount_hc / Σ amount)` předpisů, zaokrouhleno na 2 místa (v domácí
   měně `dom = cur`). Poslední uzavření, které reziduum vynuluje,
   dorovná `dom` tak, aby Σ `dom` úhrad = Σ `dom` předpisů (žádný
   haléřový zbytek na podrozvaze).
4. Dva požadavky: `closing_category` na **předpisovou** stranu G
   (799 MD), přesný **účet prvního předpisu** případu na stranu úhrady
   (756100 DAL) — obojí s identitou spouštěcího řádku, text
   „Uzavření zálohové faktury {VS}“.

Contributor čte ledger ostatních zdrojů, ne vlastní (ten se právě
přepisuje). Nic nezapisuje.

**Vlastnosti, které dokumentovat** (`docs/accbal.md` nová §5.8):

- Idempotence: reaccount zdroje dá stejný výsledek, dokud se nezmění
  ostatní zdroje případu.
- Závislost na pořadí: dvě úhrady téže proformy nad reziduum — uzavře
  ta, která se zaúčtuje dřív; druhá už jen založí zálohu (přeplatek
  zálohy řeší #69 D19 / ručně). Reaccount starší úhrady po novější může
  pořadí prohodit — součet se nezmění.
- Změna proformy po úhradě (snížení částky) případ přeplatí (reziduum
  < 0). Nápravu řeší D5 (storno uhrazené proformy zakázané, zbytek
  interním dokladem); tento task nic nepřepočítává.
- Platba v jiném fiskálním roce než proforma: klíč s rokem (#69 D11) →
  bez uzavření, dokud otevírací doklad nepřenese proformu do nového roku.

### 4. Platba dřív než proforma

- **Banka**: pokryto — transakce čeká na clearingu, po zaúčtování proformy
  ji `ClearingRerouteHandler` přeúčtuje; engine teď přesměruje na 324
  (předchozí task) a contributor přidá uzavření. Jen ověřit testem.
- **Pokladna** (`advance.received` s VS proformy zaúčtovaný před proformou):
  nový handler `CaseClosureRerouteHandler` (`journalEventHandlers`,
  registrace **za** `ClearingRerouteHandler`): po `journalWritten('doc')`
  vezme z ledgeru dokladu předpisové klíče ve skupinách s
  `closing_category`; pro každý najde v deníku ostatní zdroje se
  spouštěcím řádkem téhož klíče a bez uzavíracího řádku, a přeúčtuje je
  (`doc` → `AccountingEngine`, `bankTransaction` → bankovní engine; oba
  s contributory). Re-entrance: přeúčtování pokladního dokladu vyšle
  `journalWritten(doc)`, jeho ledger předpis v cílové skupině nemá →
  no-op. Žádná smyčka. Výjimka se loguje a spolkne (vzor
  `ClearingRerouteHandler`).
- Období: jen zdroje ve stejném fiskálním roce jako předpis (D11).

### 5. Dokumentace

- `docs/accounting.md` §7.1 — `journalContributors` (rozhraní, loader,
  injekce, chování při výjimce); §7.3 — krok contributorů v algoritmu.
- `docs/bank.md` §6.1 — contributoři v bankovním enginu, identita z transakce.
- `docs/accbal.md` — §5.8 uzavírání případu úhradou mimo skupinu
  (`closing_category`, spouštěcí řádky, kurz, vlastnosti výše),
  `CaseClosureRerouteHandler` v §5.2, log rozhodnutí (#79 D3b/c).
- `docs/modules.md` — pole `journalContributors`.
- `help/faktury-vydane/zalohova-faktura.md` — po zaplacení se zálohová
  faktura v saldokontu uzavře sama; částečná úhrada nechá zbytek otevřený.

## Mimo scope

- Storno / snížení uhrazené proformy, uzavření neuhrazeného zbytku,
  přehled proforem k vyřízení (#79 D5).
- Vratka zálohy (výdaj proti 324) — proformu znovu neotevírá.
- Import (#79 D4) — import jen posílá doklady a transakce; uzavření
  zařídí tento mechanismus sám.
- Přijaté proformy (`invpi`) — mechanismus je obecný, seed je nemá.

## Testy

- `ModuleDefinition` / `JournalContributorLoader`: registrace, neexistující
  třída, třída bez rozhraní, víc modulů.
- Enginy s fake contributorem: požadavky se doplní, kategorie i přesný účet
  se dohledají, nenalezený účet → chybový řádek; nevyrovnané požadavky →
  `unbalanced`; výjimka contributoru → deník bez příspěvku + varování;
  bankovní engine a jiná identita → `LogicException`; prázdná sada →
  výstup shodný s dneškem.
- `CaseClosureContributor` (unit nad poli / integrační nad ledgerem):
  - plná úhrada → pár 799100 MD / 756100 DAL, případ proformy uzavřen;
  - částečná úhrada → pár na částku úhrady, zbytek otevřený; druhá
    úhrada zbytek uzavře a přebytek jde jen na 324;
  - přeplatek → uzavření jen do výše rezidua;
  - reaccount téže transakce → shodný deník (idempotence);
  - cizí měna → `dom` kurzem proformy, poslední uzavření dorovná haléře,
    Σ podrozvahy 0 v domácí měně;
  - jiný fiskální rok → nic;
  - `sale.advanceVat` / `sale.advanceDeduction` na faktuře a `acc.entry`
    na 324 → nic;
  - skupina bez `closing_category` → nic.
- Integrační end-to-end: proforma → bankovní příjem → 221/324 +
  799/756 → konečná faktura s odpočtem zálohy (VS + SS proformy) →
  Přijaté zálohy i Zálohové faktury vydané uzavřené; totéž s pokladním
  dokladem `advance.received`.
- „Platba dřív než proforma“: banka (přes `ClearingRerouteHandler`)
  i pokladna (přes `CaseClosureRerouteHandler`).

Filtrem, např.
`vendor/bin/phpunit --filter 'JournalContributor|CaseClosure|AccountingEngine|BankTransactionAccountingEngine|LedgerOpenItemLookup|ModuleDefinition'`;
celá sada na konci lokálně.

## Commity

1. `core: rozhraní JournalContributor, loader, registrace v module.jsonc (#79 D3b, 1/5)`
2. `accounting+bank: enginy volají contributory před zápisem deníku (#79 D3b, 2/5)`
   — vč. injekce na všech místech vzniku enginu.
3. `accbal: CaseClosureContributor — uzavření případu úhradou mimo skupinu (#79 D3b/c, 3/5)`
4. `accbal: CaseClosureRerouteHandler — pokladní platba dřív než proforma (#79 D3b, 4/5)`
5. `docs: contributoři deníku, uzavírání proforem; task (#79, 5/5)` —
   dokumentace, help, `**Stav:**`, `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] Na dev DS: proforma → bankovní příjem celé částky → v deníku
      transakce 4 řádky (221/324, 799/756), případ v Zálohových fakturách
      vydaných uzavřený, v Přijatých zálohách otevřený předpis
      (`ProformaClosureTest`; ruční proklik UI zbývá).
- [x] Částečná úhrada nechá zbytek proformy otevřený; přeplatek
      proformu nepřeplatí (integrační test).
- [x] Konečná faktura s odpočtem zálohy uzavře Přijaté zálohy (integrační test).
- [x] Pokladní úhrada zálohy s VS proformy uzavře proformu, i když byla
      zaúčtovaná dřív než proforma (integrační test).
- [ ] Reaccount (`doc-reaccount`, přeúčtování transakce, `accbal-match`)
      nezmění výsledek (integrační test přeúčtování transakce);
      `accbal-regenerate --all` také ne (ověřit na alfě).
- [x] Účty 756100 a 799100 mají v součtu nulový zůstatek pro uhrazené
      proformy (i v cizí měně) (integrační test).
- [x] Bez modulu saldokonta a na dokladech bez proforem se deník nemění
      (regresní testy obou enginů + prázdná sada = shodný výstup).
- [x] Dokumentace a help aktualizované, `tasks-index.py --check` projde.
