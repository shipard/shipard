# Účetní předpisy podle varianty osnovy (NNO)

**Stav:** naplánováno — design schválen 2026-10-15 (#122 D1–D7), čeká na implementaci

## Kontext / Cíl

Účtovací předpis (`modules/economy/accounting/config/accountingRules.cz.jsonc`)
se vybírá podle země vlastní firmy (`AccountingRules::resolve()`,
cfgItem `economy.accounting.rules.{country}`) a jeho masky účtů jsou
napsané pro podnikatelskou osnovu (`economy.accountChart` = `default`).
V osnově pro neziskové organizace (`npo`) mají některé syntetiky jiný
význam nebo neexistují. Zdroj dat s osnovou `npo` tak účtuje zaokrouhlení
na 548 Manka a škody a 648 Zúčtování fondů. Bankovní poplatky, úroky
a kurzové rozdíly končí na maskách, které v NNO nejsou (`568???`).
Našla to revize snímků deníku testovací sady spolku (#103 F3).

**Cíl:**

- předpis dostane přepisy pro variantu osnovy `npo`;
- všechny cesty k předpisu dostanou efektivní předpis;
- strážce zajistí, že každá maska v každé osnově míří na existující účet
  se správným významem;
- bankovní poplatky mají v obou osnovách vlastní analytiku.

## Návaznost

- **#122** — rozhodnutí D1–D7 (oddíl na konci)
- **#103 F3** — `tasks/testing-phase3.md`; po opravě se schválí snímek deníku
  spolku (kontrola 5)
- `tasks/ds-setup-12-accounting-items-tag-coverage.md` — nabídky účetních
  položek a strážce pokrytí štítků
- `docs/accounting.md` §4 (předpis), §5 (dohledávání účtů)

## Před implementací přečti

- `modules/economy/accounting/src/AccountingRules.php`, `AccountMaskResolver.php`
  (maska → nejnižší analytika úrovně 4 s číslem začínajícím maskou)
- `modules/economy/accounting/src/AccountingEngine.php` → `resolveCategoryAccount()`
- `modules/economy/vat/src/Accounting/VatReturnAccountingService.php`
  (čte předpis přímo z cfgItem) a `VatReturnAccountingBuilder.php`
  (hláška s natvrdo zapsanou maskou 548 / 648)
- `modules/economy/bank/src/BankTransactionAccountingEngine.php`,
  `modules/economy/accbal/src/CaseClosure*.php`, `JournalContributions.php`
- `src/Core/Settings/LayerCParameters.php` — `economy.accountChart`
- `modules/economy/accounting/src/Dataset/CostsCheck.php`

## Změny

### 1. Sekce přepisů v předpisu (D1)

`accountingRules.cz.jsonc` dostane sekci:

```jsonc
"chartOverrides": {
	"npo": {
		"accounts": [
			{"cat": "rounding.cost",     "accountMask": "549"},
			…
		]
	}
}
```

- **Sémantika:** pro variantu osnovy zdroje dat (`economy.accountChart`)
  nahradí každá kategorie uvedená v přepisu **všechny** obecné záznamy
  téže kategorie v `accounts`. Ostatní kategorie zůstanou beze změny.
  Varianta bez přepisů (`default`, `none`) dostane předpis beze změny.
- **Kde:** `AccountingRules::resolve()` vrátí rovnou efektivní předpis
  (přepisy aplikované, sekce `chartOverrides` odebraná). Varianta osnovy
  se čte z parametrů vrstvy C stejně jako jinde.
- Kategorie v přepisu, která v obecném `accounts` není = chyba při
  načtení (strážce), ne tichá nová kategorie.

### 2. Masky pro NNO (D2)

| Kategorie | Obecně (`default`) | `npo` | Dohledá se v NNO |
|-----------|-------------------|-------|------------------|
| `rounding.cost` | 548 | 549 | 549100 Jiné ostatní náklady |
| `rounding.revenue` | 648 | 649 | 649100 Jiné ostatní výnosy |
| `vat.nondeductible` | 548 | 549 | 549100 |
| `bank.fee` | `["568201", "568"]` (D7) | `["549301", "549"]` (D7) | 549301 Bankovní poplatky |
| `bank.interest.out` | 562 | 544 | 544100 Úroky |
| `bank.interest.in` | 662 | 644 | 644100 Úroky |
| `fx.loss` | 563 | 545 | 545100 Kurzové ztráty |
| `fx.gain` | 663 | 645 | 645100 Kurzové zisky |

Ostatní masky (311, 321, 314, 324, 343…, 2613xx, 75x, 799, 602, 604,
504, 518) mají v obou osnovách stejný význam a zůstávají společné.

### 3. Jediná cesta k předpisu (D3)

- `VatReturnAccountingService` čte předpis přes `AccountingRules::resolve()`,
  ne přímo z cfgItem.
- `VatReturnAccountingBuilder`: hláška o chybějícím účtu zaokrouhlení
  vezme masku z předpisu, ne natvrdo zapsanou 548 / 648.
- Najdi a srovnej další přímá čtení `economy.accounting.rules` mimo
  `AccountingRules` (grep `--include=*.php` přes `modules/economy` a `src`).
  Konfigurační provisionery (`TransitAccountsProvisioner`,
  `OffBalanceAccountsProvisioner`, `ClearingInfrastructureProvisioner`)
  zkontroluj, jestli používají kategorie z tabulky D2. Pokud ano, musí
  jít přes efektivní předpis.

### 4. Bankovní poplatky jako samostatná analytika (D7)

- **`default`:** maska `bank.fee` → `["568201", "568"]`. Poplatek z výpisu
  pak jde na 568201 Bankovní poplatky, stejně jako poplatek z faktury
  (štítek `services.banking`). Fallback `568` zachová rozvrhy bez 568201.
- **`npo`:** do osnovy přidat analytiku **549301** Bankovní poplatky
  (`accountChartNpo.jsonc`, vlastnosti jako 549100). Maska
  `["549301", "549"]`. V nabídce `accountingItemsNpo.jsonc` nahradí
  položku `549100B` nová položka `549301` Bankovní poplatky
  (`services.banking`). Strážce pokrytí štítků (`ds-setup-12`) musí dál
  platit — jeden štítek, jedna položka. Existující zdroje dat si
  vygenerovanou `549100B` ponechají.

### 5. Strážce (D4)

Rozšířit `tests/Unit/Module/Economy/Accounting/AccountingRulesTest.php`:

- pro každou variantu osnovy (`default`, `npo`) se každá maska efektivního
  předpisu (každý prvek řetězu) dohledá v seedu osnovy té varianty jako
  analytika úrovně 4. Ze řetězu musí projít aspoň jedna maska, ideálně
  první.
- pro `npo` test drží tabulku D2 (kategorie → maska). Změna masky
  v přepisu je pak vědomá.
- přepis nezná neexistující kategorii;
- `AccountingRules::resolve()` pro `npo` vrátí přepsané masky, pro
  `default` původní, bez sekce `chartOverrides`.

### 6. `dataset-verify` — zaokrouhlení podle kategorie (D5)

`CostsCheck` vyřazuje zaokrouhlení z nákladů dokladu podle prefixu účtu
548 / 549. Na těchto účtech jsou ale i pojištění (548201/202, v NNO
549201/202), bankovní poplatky a jiné náklady. Nově se vyřadí řádky
deníku vzniklé z kategorie zaokrouhlení (`rounding.cost` /
`rounding.revenue`). Podle toho, co deník nese, to bude kategorie,
zdroj kroku nebo text „Zaokrouhlení dokladu“, ale vždy podle původu
řádku, ne podle účtu.

## Existující zdroje dat (D6)

Po nasazení účtují nové doklady správně. Doklady zaúčtované dřív
na 548 / 648 nebo na nedohledané masky (`568???`, `562???`, `563???`)
zůstanou, dokud se nepřeúčtují (`doc-reaccount <docId>`).

- **Vývojové a testovací sady** (`CLAUDE.local.md`, režim volný):
  přeseedovat a `dataset-verify`.
- **Testovací server a ostrá data:** jen seznam dotčených dokladů
  (čtení). Přeúčtování až po schválení v chatu, **per zdroj dat**.
  Žádný hromadný příkaz v rámci tohoto tasku.

## Kroky

1. Sekce `chartOverrides` + efektivní předpis v `AccountingRules::resolve()` + unit testy.
2. Masky D2, analytika 549301, položka nabídky, maska `bank.fee` v `default`.
3. Jediná cesta k předpisu (zaúčtování přiznání DPH, hláška, provisionery).
4. Strážce.
5. `CostsCheck` podle původu řádku.
6. Ověření: spolek (`wp66-o35z-tl9n-rt1t`, volný) přeseedovat
   `tests/datasets/cz/association` a `dataset-verify`. Kontroly 1–4 `ok`,
   deník bez 548 / 648. Snímek přes `--write-ledger-snapshot` připravit
   ke schválení a **zastavit se**: schvaluje člověk (#103 F3, #123).
   Plátce a neplátce: `dataset-verify --no-apply` beze změny výsledku
   (snímky se nemění, žádný doklad v korpusu nemá bankovní poplatek).
7. Dokumentace a hlavička tasku.

## Hotovo když

- [ ] unit testy předpisu, strážce a `CostsCheck` zelené (úzké `--filter`);
      `AccountingItemsOfferCoverageTest` dál zelený
- [ ] spolek: `dataset-verify` kontroly 1–4 `ok`, deník bez 548 / 648,
      snímek připravený ke schválení
- [ ] plátce a neplátce: kontroly 1–5 `ok` beze změny snímků
- [ ] `docs/accounting.md` §4: sekce `chartOverrides`, sémantika přepisu,
      tabulka D2; §5: řetěz masek u `bank.fee`
- [ ] `docs/test-corpus.md`: `CostsCheck` vyřazuje zaokrouhlení podle původu řádku
- [ ] hlavička tasku, `python3 scripts/tasks-index.py`

## Pasti

- **Přepis nahrazuje celou kategorii.** Kategorie s více záznamy
  (`query`) se v přepisu musí uvést celá, jinak zmizí varianty.
  V D2 má každá kategorie jeden záznam.
- **548 a 648 nejsou v NNO „ostatní“.** Žádná maska v efektivním předpisu
  `npo` na ně nesmí mířit — strážce.
- **Ostrá data se nepřeúčtovávají automaticky** (D6).

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — Přepisy podle varianty osnovy** jako sekce `chartOverrides`
  ve stejném souboru předpisu. Přepis kategorie nahradí její obecné
  záznamy, efektivní předpis vrací `AccountingRules::resolve()`.
- ✓ **D2 — Masky NNO:** zaokrouhlení 549 / 649, DPH bez nároku 549,
  úroky 544 / 644, kurzové rozdíly 545 / 645, bankovní poplatky podle D7.
- ✓ **D3 — Jediná cesta k předpisu** (i zaúčtování přiznání DPH), hlášky
  bez natvrdo zapsaných masek.
- ✓ **D4 — Strážce:** každá maska efektivního předpisu se dohledá v osnově
  své varianty, pro NNO test drží tabulku D2.
- ✓ **D5 — `dataset-verify` vyřazuje zaokrouhlení podle původu řádku**,
  ne podle prefixu účtu.
- ✓ **D6 — Existující zdroje dat:** přeúčtování ručně, per zdroj dat se
  schválením, seznam dotčených dokladů čtením.
- ✓ **D7 — Bankovní poplatky jako samostatná analytika** v obou osnovách:
  `default` 568201 (maska `["568201", "568"]`), `npo` nová 549301 (maska
  `["549301", "549"]`), položka nabídky NNO `549301` místo `549100B`.
