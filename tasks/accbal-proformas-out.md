# Zálohové faktury vydané — podrozvaha, účtovací předpis, saldokonto, přesměrování úhrady

**Stav:** částečně — kód hotový 2026-09-23 (4 commity, #79 D2/D3a);
`ds-upgrade` na dev DS 4l3j založil účty 756100/799100, opravil povahu
75–79 a založil skupinu Zálohové faktury vydané; unit i integrační testy
(deník proformy, rozvaha/výsledovka beze změny, routing úhrady na 324,
platba dřív než proforma) zelené. Zbývá ruční proklik UI (Nastavení,
navigace, konečná faktura s odpočtem) a nasazení na alfu spolu
s `tasks/doc-proforma-out.md`
**Issue:** #79 (rozhodnutí v komentáři 2026-09-23 „Revize rozhodnutí“);
souvisí s #69 (saldokonto — D11, D17–D23) a #72.
**Milník:** M2.
**Návaznost:** předpokládá `tasks/doc-proforma-out.md` (typ `invpo`);
nasazují se spolu. Uzavírací pár `799100 MD / 756100 DAL` při úhradě
(#79 D3b/c — `JournalContributor`) je **samostatný navazující task**
`tasks/accbal-proforma-closure.md`: po
tomto tasku úhrada proformy správně vznikne jako přijatá záloha, ale případ
proformy zůstane otevřený, dokud ho navazující task neuzavře.

## Cíl

Potvrzená zálohová faktura vydaná (`invpo`) se zaúčtuje na podrozvahu
`756100 MD / 799100 DAL` celkovou částkou. Saldokonto ji vede jako
samostatnou skupinu **Zálohové faktury vydané** (`proformas_out`) z deníku
— žádný neúčetní zdroj pohybu. Bankovní úhrada s VS proformy, kterou
lookup najde v této skupině, se **nezaúčtuje na účet předpisu** (756 —
peníze na podrozvaze by byla chyba), ale na **přijatou zálohu** (kategorie
`advances.received`, 324) pod klíčem proformy.

## Před implementací přečti

- `docs/accounting.md` §4 (účtovací předpis, kroky, `src: head`), §5
  (dohledávání účtů), §7.1 (`openItemLookup`), §7.3 (engine)
- `docs/accbal.md` §3.1–3.2 (skupiny a účty, seed, legacy varianta), §4.2
  (generátor — krok d) pro řádky bez saldokontní operace), §5.1 (lookup,
  pořadí cílů, přirozená / opačná skupina), §5.2 (trigger
  `ClearingRerouteHandler`), §5.7 (`accbal-match`)
- `docs/bank.md` §6.1 (protistrana úhrady), §6.3 (clearing)
- `modules/economy/accounting/config/accountingRules.cz.jsonc`,
  `accountChartDefault.jsonc` (ř. ~665–678, třída 7), `accountChartNpo.jsonc`
  (třídu 7 nemá), `accountKinds.jsonc` (6 = Podrozvaha)
- `modules/economy/accounting/src/TransitAccountsProvisioner.php` +
  `provisionTransitAccounts` v `src/Command/DataSource/DsUpgradeCommand.php`
  (vzor bezpodmínečného provisioneru účtů, i pod `skipProvisioning`)
- `modules/economy/accbal/config/balancesDefault.cz.jsonc`,
  `src/BalancesProvisioner.php`, `tables/economy_accbal_balances.jsonc`
- `src/Core/Accounting/OpenItem.php`, `OpenItemLookup.php`,
  `NullOpenItemLookup.php`; `modules/economy/accbal/src/LedgerOpenItemLookup.php`,
  `ClearingRouter.php`, `ClearingRerouteHandler.php`
- `modules/economy/bank/src/BankTransactionAccountingEngine.php`
  (`resolveCounterpartyAccount`, `resolveOpenItemAccount`, `maskForCategory`)
- testy: `CashAccountingRulesTest`, `LedgerOpenItemLookupTest` (unit
  i integrační), `BankTransactionAccountingEngineTest`,
  `BalancesProvisionerTest`

## Rozhodnutí (#79)

- **D2** `invpo`: `756100 MD / 799100 DAL` na celkovou částku.
  `756100` Vydané zálohové faktury, `799100` Evidenční protiúčet
  podrozvahy (společný pro budoucí podrozvahové evidence). Oba
  `account_kind` 6; skupiny 75–79 v seedu opravit z 0 na 6. Skupina
  saldokonta `proformas_out`: `756 MD` = předpis, `756 DAL` = úhrada, klíč
  jako u ostatních skupin (#69 D17–D23 beze změny).
- **D3a** Lookup, který trefí `proformas_out`, vrací jako účet úhrady
  kategorii `advances.received` (324), ne účet předpisu. Totéž pro
  `ClearingRerouteHandler` / `accbal-match`.
- **D6** Přenos přes rok podle #69 D11 (rok v klíči), bez výjimky —
  pro tento task nic neznamená, jen žádná výjimka v lookupu.

## Scope

### 1. Osnova — podrozvahové účty

**Seed** `accountChartDefault.jsonc`: skupiny `75`–`79` → `account_kind: 6`;
přidat `756` + `756100` „Vydané zálohové faktury“ a `799` + `799100`
„Evidenční protiúčet podrozvahy“, vše kind 6. `accountChartNpo.jsonc`:
třída 7 tam není — přidat `75`, `756`, `756100`, `79`, `799`, `799100`
(kind 6; syntetiku `7` jen pokud ji NPO osnova u jiných tříd má).

**Provisioner** `OffBalanceAccountsProvisioner` (`economy.accounting`),
vzor `TransitAccountsProvisioner`: inline konstanta účtů výše (enginový
kontrakt — maska v předpisu), idempotence dle `number`, volání
z `DsUpgradeCommand` **bezpodmínečně** (i pod `skipProvisioning` —
migrovaný rozvrh je nemá; starý DS má jen syntetiky 75 a 79 bez analytik).
Navíc jednorázová oprava povahy: `UPDATE … SET account_kind = 6 WHERE
account_level < 4 AND LEFT(number, 2) BETWEEN '75' AND '79' AND
account_kind = 0` a totéž pro analytiky 75x–79x s kind 0 — kind 0 je na
podrozvahovém účtu vždy chyba (i v migrovaném rozvrhu), jiné hodnoty
se nemění. Idempotentní. Výstup ve stylu ostatních provisionerů (`-v`).

Rozvaha (třídy 0–4) a výsledovka (5–6) třídu 7 nečtou — ověř testem, že
zaúčtovaná proforma rozvahu ani výsledovku nezmění a invariant
vyrovnanosti deníku drží.

### 2. Účtovací předpis

`accountingRules.cz.jsonc`:

- `categories`: `"proformas.out": {"name:cs": "Zálohové faktury vydané (podrozvaha)"}`,
  `"offbalance.contra": {"name:cs": "Evidenční protiúčet podrozvahy"}`.
- `accounts`: `{"cat": "proformas.out", "accountMask": "756"}`,
  `{"cat": "offbalance.contra", "accountMask": "799"}`.
- `documents`:

```jsonc
// Zálohová faktura vydaná (#79 D2): jen podrozvaha, celkovou částkou
// hlavičky. Žádné DPH, výnosy ani rozvaha — řádky a rekapitulace se
// neúčtují. Partner, VS, SS a splatnost z hlavičky → klíč případu
// v saldokontní skupině proformas_out. Úhradu uzavírá navazující
// JournalContributor (#79 D3b), ne předpis.
{"docType": "invpo",
    "accounting": [
        {"cat": "proformas.out", "src": "head", "col": "total", "side": 0,
            "text": "Zálohová faktura vydaná"},
        {"cat": "offbalance.contra", "src": "head", "col": "total", "side": 1,
            "text": "Zálohová faktura vydaná"}
    ]
}
```

Bez `partnerSrc` — pohledávka ze zálohové faktury jde vždy za partnerem
z hlavičky. Zaokrouhlení je v `total` na obou stranách, deník je vyrovnaný.
Ověř, že operace řádku deníku pro `src: head` (prázdná / NULL) vede
v generátoru do kroku d) (pravidla nastavení), a že `OperationSidesTest`
beze změny projde (žádná nová operace).

### 3. Skupina saldokonta `proformas_out`

**Tabulka** `economy_accbal_balances`: dva nové sloupce (varchar 40,
nullable, `system: true`, skupina `settings`):

- `payment_category` — kategorie účtovacího předpisu, na kterou se účtuje
  úhrada nalezená v této skupině místo účtu předpisu. `NULL` = účet
  předpisu (dosavadní chování všech skupin).
- `closing_category` — kategorie protiúčtu, proti kterému se případ
  skupiny uzavírá, když úhrada odejde na `payment_category`. Tento task
  sloupec jen zakládá a plní seedem; čte ho až navazující
  `tasks/accbal-proforma-closure.md`. Zakládá se už teď, protože
  `BalancesProvisioner` existující skupinu nepřepisuje — doplnit sloupec
  do už založené skupiny by znamenalo ruční zásah.

Sloupce plní jen seed; ve formuláři skupiny se nezobrazují (enginový
kontrakt, ne uživatelské nastavení). Popis v `.md` tabulky.

**Seed** `balancesDefault.cz.jsonc` — nová skupina:

```jsonc
// Zálohové faktury vydané (#79 D2, D3a): podrozvahová evidence proforem.
// Předpis = proforma (756 MD), úhrada = uzavření (756 DAL, navazující
// JournalContributor). Bankovní / pokladní platba nalezená v této skupině
// se účtuje na přijatou zálohu (payment_category), ne na 756.
{
    "code": "proformas_out",
    "name": "Zálohové faktury vydané",
    "short_name": "Zál. faktury vyd.",
    "sort_order": 15,
    "show_in_navigation": 1,
    "payment_category": "advances.received",
    "closing_category": "offbalance.contra",
    "accounts": [
        {"account_number": "756", "acc_side": 0, "amounts_sign": 1, "bal_side": 0, "modify_sign": false, "note": "Zálohová faktura vystavena"},
        {"account_number": "756", "acc_side": 1, "amounts_sign": 1, "bal_side": 1, "modify_sign": false, "note": "Zálohová faktura uhrazena / uzavřena"}
    ]
}
```

`BalancesProvisioner` zapisuje `payment_category` a `closing_category`
při založení skupiny.
Legacy varianta (`skipProvisioning`): skupina se zakládá také — řádky
nemají `creditNoteRule`, takže legacy transformace je nezmění kromě
`amounts_sign` → 0 (ověř, že to pro 756 nevadí: záporná proforma v datech
není). Skupina vznikne i na existujících DS při dalším `ds-upgrade`
(provisioner doplňuje chybějící skupiny dle `code`).

### 4. Přesměrování úhrady (D3a)

- **Core** `OpenItem`: nový volitelný parametr `?string $paymentCategory = null`
  (na konci konstruktoru — stávající volání beze změny). Docblock: je-li
  vyplněn, konzument účtuje úhradu na účet kategorie předpisu, ne na
  `accountNumber`; `accountNumber` dál nese účet předpisu (pro diagnostiku).
  Docblock `OpenItemLookup::findOpenRequest` doplnit o jednu větu.
- **`LedgerOpenItemLookup`**: `groups()` načte i `b.payment_category`
  a `residualOf` ho předá do `OpenItem`. Pořadí cílů, reziduum ani
  přirozená/opačná skupina se nemění.
- **`BankTransactionAccountingEngine::resolveOpenItemAccount`**: při
  `paymentCategory !== null` → `maskForCategory($item->paymentCategory)`
  + `maskResolver` (jako u neúspěšného dohledání, stejné chybové hlášky
  `account_not_found` s kategorií). Jinak beze změny. Strana zápisu plyne
  ze směru jako dosud (příjem → protistrana DAL = 324 DAL).
- **`ClearingRouter`**: `RouteResult` (plán i výsledek `accbal-match`)
  ukazuje u přesměrovaného případu kategorii / masku místo účtu předpisu,
  ať výpis `--dry-run` neslibuje 756. Logika routování beze změny —
  reaccount dělá engine.
- **`ClearingRerouteHandler`** beze změny: po zaúčtování proformy vezme
  klíče jejích předpisů (756 MD) a přeúčtuje čekající clearingové úhrady
  se shodným klíčem → engine je díky přesměrování položí na 324. Ověř
  integračním testem („platba dřív než proforma“).

Výsledný tok (příjem s VS proformy, partner dohledán): lookup prohledá
přirozené skupiny v pořadí nastavení (Pohledávky 10, Zálohové faktury
vydané 15, …), zásah v `proformas_out` → `221 MD / 324xxx DAL` pod
klíčem proformy → generátor z řádku 324 DAL udělá předpis v Přijatých
zálohách (#69 D23) → konečná faktura s odpočtem zálohy (VS + SS proformy)
ho uzavře. Případ proformy zůstává do navazujícího tasku otevřený —
druhá platba se stejným VS proto proformu najde znovu a jde opět na 324
(žádoucí; přeplatek řeší až uzavírání s limitem rezidua).

Pokladní doklad (`advance.received` s VS proformy) účtuje 324 přímo
z předpisu pokladny — lookup se ho netýká, nic se nemění.

### 5. Dokumentace

- `docs/accounting.md` §4 — předpis `invpo`, kategorie, podrozvaha;
  §7.1 — `OpenItem::paymentCategory`.
- `docs/accbal.md` §3.1 (sloupec `payment_category`), seed skupin,
  §5.1 (přesměrování úhrady), log rozhodnutí (odkaz na #79 D2/D3a).
- `docs/bank.md` §6.1 — zásah ve skupině s `payment_category`.
- `docs/cli.md` / `docs/ds-setup.md`, kde je výčet bezpodmínečných
  provisionerů `ds-upgrade` — doplnit `OffBalanceAccountsProvisioner`.
- `help/uctarna/` nebo stránka o saldokontu, pokud existuje — skupina
  Zálohové faktury vydané jednou větou; jinak zmínka ve stránce
  `help/faktury-vydane/zalohova-faktura.md` z předchozího tasku
  („úhrada se v saldokontu objeví jako přijatá záloha“).

## Mimo scope

- Uzavírací pár `799100 MD / 756100 DAL` při úhradě (`JournalContributor`,
  #79 D3b/c) — navazující task.
- Import proforem a otevíracích řádků podrozvahy (`old_shipard`, #79 D4).
- Storno uhrazené proformy, uzavření zbytku (#79 D5), převod podrozvahy
  při uzávěrce období (#79 D6 — budoucí task uzávěrky).
- Přijaté zálohové faktury (`invpi`).

## Testy

- Předpis (vzor `CashAccountingRulesTest`): `invpo` s celkem 12 100,00
  (základ 10 000 + DPH 2 100) → dva řádky deníku `756100 MD 12 100 /
  799100 DAL 12 100`, partner a VS z hlavičky, žádný řádek 343/6xx/311.
- Drift: maska `756` / `799` v předpisu ↔ konstanta
  `OffBalanceAccountsProvisioner` ↔ seedy osnovy.
- `OffBalanceAccountsProvisioner`: založí chybějící, existující nepřepíše,
  kind 0 → 6 u 75–79, jiný kind nechá; opakované volání no-op.
- Integrační: zaúčtovaná proforma nezmění rozvahu ani výsledovku
  (`BalanceSheetBuilder`, `ProfitLossBuilder`), invariant deníku drží.
- `BalancesProvisioner`: skupina `proformas_out` s `payment_category`
  a `closing_category`, v normální i legacy variantě.
- `LedgerGenerator`: 756 MD z proformy → předpis `proformas_out`.
- `LedgerOpenItemLookup`: zásah v `proformas_out` vrátí `OpenItem`
  s `paymentCategory = 'advances.received'`; ostatní skupiny `null`.
- `BankTransactionAccountingEngine`: příjem s VS otevřené proformy →
  `221 MD / 324100 DAL`; bez proformy → dosavadní chování (clearing /
  311); kategorie bez masky → `account_not_found`.
- Integrační „platba dřív než proforma“: transakce na 261200, pak se
  potvrdí proforma → `ClearingRerouteHandler` přeúčtuje na 324,
  v Přijatých zálohách vznikne předpis pod klíčem proformy.

Filtrem, např.
`vendor/bin/phpunit --filter 'OffBalance|CashAccountingRules|BalancesProvisioner|LedgerOpenItemLookup|BankTransactionAccountingEngine|LedgerGenerator|Proforma'`;
celá sada na konci lokálně.

## Commity

1. `accounting: podrozvahové účty 756100/799100, povaha 75–79, provisioner (#79 D2, 1/4)`
2. `accounting: předpis invpo na podrozvahu (#79 D2, 2/4)`
3. `accbal+bank: skupina proformas_out, přesměrování úhrady na přijatou zálohu (#79 D3a, 3/4)`
4. `docs: proformy v předpisu, saldokontu a bance; task (#79, 4/4)` —
   dokumentace, `**Stav:**`, `python3 scripts/tasks-index.py`.

## Hotovo když

- [x] `ds-upgrade` na dev DS: účty 756100/799100 (kind 6), skupiny 75–79
      kind 6, skupina saldokonta „Zálohové faktury vydané“ (DB ověřeno
      2026-09-23; zobrazení v Nastavení a navigaci zbývá prokliknout).
- [x] Potvrzená proforma → deník `756100 / 799100`, případ otevřený
      v Zálohových fakturách vydaných; rozvaha a výsledovka beze změny
      (`ProformaAccountingTest`, integrační `LedgerGeneratorTest`).
- [ ] Bankovní příjem s VS proformy → `221 / 324`, předpis v Přijatých
      zálohách pod klíčem proformy (`BankPaymentRoutingTest` ✓); konečná
      faktura s odpočtem zálohy (VS + SS proformy) případ na 324 uzavře
      (zbývá proklik).
- [x] Platba zaúčtovaná před proformou se po potvrzení proformy sama
      přeúčtuje z 261200 na 324 (`BankPaymentRoutingTest`).
- [x] Platby bez proformy se chovají beze změny (regresní testy banky
      a lookupu zelené).
- [x] Dokumentace aktualizovaná, `tasks-index.py --check` projde.
- [ ] Nasazení na alfu spolu s `tasks/doc-proforma-out.md`
      (`ds-upgrade`; na importovaných DS se nic nezmění, dokud import
      proformy nepošle — #79 D4).
