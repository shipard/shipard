# 15 — Export hlavní knihy pro kontrolní diff (M3)

> PRD pro jednu Claude Code session (**old_shipard**). Read-only exportér
> agregovaného účetního deníku do JSON kompatibilního s `report-diff` na
> nové straně. Kanonický kontrakt formátu: `shpd:docs/reports.md` §7.4;
> nová strana hotová (Reporty Fáze 4 — `ReportDiff`, CLI `report-run` /
> `report-diff`, issue shpd#42).

## Kontext

Kontrola importu M3: „stejný report za stejné období musí sedět". Starý
reportovací engine neoživujeme — shoda reportů se redukuje na shodu
**agregovaného deníku per účet a období**, protože novou stranu počítá
vždy tentýž builder nad novým deníkem. Tenhle task dodává starou stranu:
subcommand `export-general-ledger`, který z `e10doc_debs_journal`
vyprodukuje minimální `ReportResult`-kompatibilní JSON (detail řádky,
žádné subtotaly). Diff pak:

```
starý DS:  import.php export-general-ledger ... > old.json
nový DS:   bin/shpd-ds report-run economy.accounting.generalLedger ... > new.json
           bin/shpd-ds report-diff old.json new.json
```

Vzor implementace: `BookingHistoryExporter` (fáze 27) — standalone,
výhradně SELECTy, bez ImportConfig/HttpClient (odbočka v `ImportApp`
před `ImportConfig::load()`), Logger do `log/`.

## Návaznost

- **Prerekvizita (nová strana):** Reporty Fáze 4 nasazená tam, kde se
  bude diffovat (`report-run`/`report-diff` CLI) — na alfě zatím Fáze 1–3,
  diff samotný ale běží kdekoli (čte jen dva JSON soubory).
- **Využití:** M3 validace importu; první reálný cíl = DS zrcadlené na
  alfě (qrce/2xvt/l6ot/dtje).
- Znaménková konvence: obě strany balance = **md − d, syrové** (žádné
  otáčení dle povahy účtu) — exportér NIC nepřevrací.

## Před implementací přečti

- `shpd:docs/reports.md` §7.4 — kontrakt výstupu (autoritativní)
- `libs/BookingHistoryExporter.php` + jeho odbočka v `libs/ImportApp.php`
  (`export-booking-history`) — vzor: konstrukce bez configu, Logger,
  argumenty, výstupní soubor
- Deník: `modules/e10doc/debs/tables/journal.json` — sloupce `accountId`,
  `moneyDr`, `moneyCr`, `fiscalYear`/`fiscalMonth` (ndx reference!),
  `fiscalType` (0 běžné / 1 otevření / 2 uzavření), `accRing`
  (20 Výchozí / 40 Zásoby), `document` (ref e10doc_core_heads)
- `modules/e10doc/base/tables/fiscalmonths.json` (`calendarMonth`,
  `fiscalType`, `start`) + `libs/runners/FiscalYearsRunner.php`
  (mapování starý fiscalType → nový period_type; jak se hledá rok)
- `libs/ResolvesAccountingAccount.php` — `e10doc_debs_accounts`
  (číslo `id`, název — zdroj labelů)
- Stavy dokladů: `libs/BookingHistoryExporter.php` doc komentář
  k DOC_STATE_DONE (4000) a mapování stavů v `libs/runners/DocsRunner.php`
  (4000→40, 4100→70 storno)

## Scope

**Uvnitř:** `libs/GeneralLedgerExporter.php`, subcommand
`export-general-ledger` v `ImportApp`, dokumentace použití v README
modulu (odstavec), ruční ověření.

**Mimo:** jakékoli změny nové strany; automatizace diffu (skript
orchestrace — až M3); export jiných reportů (výsledovka/rozvaha derivují
z týchž detail řádků — diff hlavní knihy pokrývá vše).

## Co implementovat

### A. `GeneralLedgerExporter` (`libs/GeneralLedgerExporter.php`)

Argumenty: `--fiscal-year=<rok>` (kalendářní rok začátku fiskálního roku;
lookup ndx v `e10doc_base_fiscalyears` — přesný způsob dle
FiscalYearsRunner), `--month-from=<1-12>`, `--month-to=<1-12>`
(kalendářní ordinály — musí odpovídat parametrům `report-run` na nové
straně), `--acc-ring=<20>` (default 20, viz Otevřené body),
`--output=<file>` (default `log/general-ledger-<ds>-<rok>-<od>-<do>.json`).

Výpočet (zrcadlí `GeneralLedgerBuilder` nové strany):

1. Mapa fiskálních měsíců roku: ndx → (calendarMonth, fiscalType)
   z `e10doc_base_fiscalmonths`.
2. **opening** = SUM(moneyDr), SUM(moneyCr) per `accountId` z řádků:
   `fiscalType = 1` (otevření) NEBO (`fiscalType = 0` a calendarMonth
   < month-from). Nová strana počítá opening jako „vše před intervalem
   včetně otevíracího období" — ekvivalence ověřit v testu níže.
3. **turnover** = dtto pro `fiscalType = 0` a calendarMonth v intervalu.
4. **closing** = opening + turnover (per strany; balance = md − d).
5. `fiscalType = 2` (uzavření) **nikdy nevstupuje** (nová strana uzavírací
   období v intervalu nenabízí).
6. **Filtr stavu dokladu**: JOIN na `e10doc_core_heads` a vyloučit
   doklady, jejichž řádky na nové straně v deníku nejsou — minimálně
   storno (4100) a smazané (9800), pokud jejich řádky v deníku vůbec
   figurují. PROVĚŘIT na reálné DB (SELECT count per docState přes join)
   a rozhodnutí zapsat do doc komentáře třídy — tohle je nejpravděpodobnější
   zdroj falešných rozdílů.
7. Labely účtů z `e10doc_debs_accounts` (accountId → name); chybějící →
   label = accountId.
8. Účty s nulovým opening i turnover neemitovat (shodně s novou stranou).

Výstup dle kontraktu §7.4: `reportId: "external.oldShipard.generalLedger"`,
`params: {fiscalYear, monthFrom, monthTo, accRing, ds: <id starého DS>}`,
`status: "ok"`, `messages: []`, sloupce `opening`/`turnover`/`closing`,
řádky `{kind: "detail", level: 4, account, label, values}`. Řádky řadit
dle account (deterministický výstup — diffy verzí exportu).

Read-only: třída obsahuje výhradně SELECTy (vzor BookingHistoryExporter,
stejná věta do doc komentáře).

### B. Zapojení v `ImportApp`

Subcommand `export-general-ledger` — odbočka před `ImportConfig::load()`
(vzor `export-booking-history`), vlastní log soubor
`log/export-general-ledger-<timestamp>.log`.

## Ověření (stará strana nemá PHPUnit — ruční, ale tvrdé)

1. Na vybraném zdrojovém DS (doporučeně ten, který zrcadlí dtje / MSI
   Zlín) spusť export za uzavřené období (např. 2026 / 7–7).
2. **Vnitřní konzistence**: skriptem (python/jq) ověř closing = opening +
   turnover na každém řádku a Σmd == Σd v turnover (vyrovnaný deník).
3. **Proti starému Shipardu samotnému**: součty vybraných syntetik
   (např. 311, 321, 5xx celkem) musí sedět na hlavní knihu ve starém UI
   za stejné období — ruční kontrola 3–5 čísel, PŘED jakýmkoli diffem
   proti nové straně (odděluje chyby exportéru od chyb importu).
4. Volitelně (pokud je kde): `report-diff old.json new.json` proti
   odpovídajícímu novému DS — výstup je informace o kvalitě importu,
   ne kritérium hotovosti tasku (rozdíly tady = nález pro M3, ne bug
   exportéru, POKUD prošly body 2 a 3).

## Commit strategie

1. `imports/newShipard: GeneralLedgerExporter — export hlavní knihy pro report-diff (M3)`
2. `imports/newShipard: README — použití export-general-ledger`

## Hotovo když

- [ ] `php import.php export-general-ledger --fiscal-year=… --month-from=…
      --month-to=…` vytvoří JSON validní dle kontraktu (parsovatelný,
      povinné klíče)
- [ ] vnitřní konzistence (bod 2) drží
- [ ] kontrola proti starému UI (bod 3) sedí na halíř
- [ ] rozhodnutí o filtru docState zapsané v doc komentáři třídy
      (s čísly z prověření)
- [ ] README modulu zmiňuje subcommand + odkaz na shpd:docs/reports.md §7.4

## Otevřené body

- **`accRing` 40 (Zásoby)**: default exportu je jen okruh 20 — nová strana
  skladové účtování zatím nevede, takže ring 40 by generoval falešné
  rozdíly na účtech 1xx/5xx. Až nová strana zásoby dostane, přidá se
  `--acc-ring=20,40`. Ověřit na reálné DB, jestli ring 40 na zdrojových
  DS vůbec obsahuje řádky.
- Orchestrace celého M3 porovnání (smyčka přes DS × období, souhrnná
  tabulka) — samostatný krok, až budou exporty i Fáze 4 na svých místech.
