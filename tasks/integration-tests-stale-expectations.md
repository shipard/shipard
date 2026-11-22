# Task: Dva integrační testy se zastaralým očekáváním

**Stav:** hotovo — 2026-11-22 (#167; soupis dalších očekávání vázaných na data zdroje viz „Poznámky k implementaci“)

**Issue:** #167

**Cíl:** Dva integrační testy selhávají nezávisle na rozpracované práci
(nález z ověření #166 části A, ověřeno 2026-11-22 nad zdrojem dat se
sadou plátce DPH). V obou je chyba v testu, ne v aplikaci. Opravit testy,
aplikační kód neměnit.

## Před implementací přečti

- `tests/Integration/README.md`, `tests/Integration/IntegrationTestCase.php`
- `tests/Integration/Prints/DocJournalPrintTest.php`,
  `PrintFixtureDocuments.php`, `tests/Fixtures/Prints/docJournalInvoice.json`,
  `tests/Fixtures/Prints/invoice.json`
- `tests/Integration/Vat/VatReturnAccountingServiceTest.php`,
  `modules/economy/vat/src/Accounting/VatReturnAccountingService.php`
  (~ř. 265, ~ř. 536), `VatReturnAccountingBuilder.php` (~ř. 194)
- commit `cfd3ab05` (#162, D130–D133 — kontakty vlastní firmy v zápatí)

## Co je špatně

1. **`DocJournalPrintTest::testInvoiceJournalMatchesContractSnapshot`** —
   snímek kontraktu `tests/Fixtures/Prints/docJournalInvoice.json` nemá
   v kontaktu účetní jednotky `web` a `gov_e_box_id`. Tisková data je od
   #162 (commit `cfd3ab05`) nesou; `invoice.json` tehdy aktualizovaný byl,
   snímek tisku Kontace ne.
2. **`VatReturnAccountingServiceTest::testAccountsFiledReturnAsDraftDocument`**
   — očekává variabilní symbol natvrdo podle DIČ registrace jednoho
   konkrétního zdroje dat. Služba bere VS z `vat_id` registrace
   (`VatReturnAccountingService`), test si ale `vat_id` nenastavuje —
   v `setUp()` přepisuje jen `filing_profile` a `tax_office_person`. Na
   zdroji s jinou registrací test selže.

## Co je potřeba udělat

1. `docJournalInvoice.json`: doplnit `web` a `gov_e_box_id` do kontaktu
   účetní jednotky podle skutečného výstupu (hodnoty z fixtury vlastní
   firmy, jako v `invoice.json`). Nic dalšího ve snímku neměnit; liší-li
   se výstup i jinde, je to nález k nahlášení.
2. `VatReturnAccountingServiceTest`: test nesmí záviset na DIČ zdroje dat.
   V `setUp()` nastav registraci syntetické `vat_id` (a původní hodnotu
   vrať v `onTearDown()` spolu s `filing_profile` a `tax_office_person`),
   očekávaný VS odvoď z něj. Stejně projdi ostatní testy třídy — žádné
   očekávání nesmí stát na datech zdroje.
3. Projdi `tests/Integration/` cíleným grepem na další očekávání vázaná
   na data konkrétního zdroje (natvrdo zapsané IČO / DIČ, čísla dokladů,
   názvy) — jen sepiš do poznámek, neopravuj nad rámec bodů 1 a 2.
4. `**Stav:**`, `python3 scripts/tasks-index.py`.

## Ověření

`vendor/bin/phpunit --testsuite Integration --filter 'DocJournalPrintTest|VatReturnAccountingServiceTest'`
proti dvěma zdrojům dat s třídou `free` s různou registrací DPH — oba
zelené.

## Mimo rozsah

- Změny v `modules/` a `src/`.
- Zařazení integrační sady do pravidelného běhu.

## Hotovo když

- [x] oba testy zelené na dvou zdrojích dat s různou registrací DPH
- [x] `VatReturnAccountingServiceTest` po sobě vrací `vat_id` registrace
- [x] seznam dalších očekávání vázaných na data zdroje v poznámkách

## Poznámky k implementaci (2026-11-22)

**Ověření.** Filtr z oddílu Ověření nad třemi zdroji dat s třídou `free`
a třemi různými registracemi DPH (`blkm-i`, `8yft-3`, `060z-b`): všude
13 testů, 12 zelených, 1 přeskočený (`testJournalDimensionsBecomeColumns`,
zdroj bez dimenze deníku — přeskakoval se i před změnou). Před změnou
padal snímek Kontace na všech třech a variabilní symbol na dvou; na
`060z-b` procházel, protože natvrdo zapsaná hodnota byla DIČ právě jeho
registrace. Řádek registrace (`vat_id`, `filing_profile`,
`tax_office_person`) je po běhu na všech třech shodný se stavem před ním.

**Snímek Kontace.** Výstup se od snímku lišil jen o `web`
a `gov_e_box_id` v kontaktu účetní jednotky — žádný další nález.

**`VatReturnAccountingServiceTest`.** Registrace dostává v `setUp()`
syntetické DIČ (`VAT_ID`), očekávaný VS jsou jeho číslice. Tím zmizela
i druhá, skrytá závislost: `testMissingTaxOfficeIsWarningAndBalanceRowHasNoPartner`
čeká právě jedno varování a na zdroji s registrací bez DIČ by přibylo
`vatIdMissing`. Zbylá očekávání třídy stojí na tom, co si test zakládá
(období, doklady, podání, správce daně, profil podání), a na konfiguraci
modulu (specifický a konstantní symbol, splatnost). Na datech zdroje
zůstávají **čísla účtů** (`343120`, `343110`, `343801`, `548100`) — viz
první bod soupisu; ponecháno po dohodě v chatu.

### Další očekávání vázaná na data zdroje (bod 3)

Soupis z cíleného grepu nad `tests/Integration/` (literály IČO / DIČ,
čísla účtů, pevná id, názvy firem v asercích), bez spouštění — neopraveno.

1. **Čísla účtů standardního rozvrhu.** Testy, které nechají účet
   dohledat aplikaci (účtovací předpis → rozvrh zdroje) a pak čekají
   konkrétní číslo: `Accounting/AccountingEngineTest`,
   `Accounting/CashDocsAccountingTest`, `Accounting/CashAdvancesAccountingTest`,
   `Exchange/AccountingDocumentImportTest`, `Assets/AssetDocLinkServiceTest`,
   `Dataset/DatasetRoundTripTest`, `Vat/VatReturnAccountingServiceTest`.
   Předpoklad je rozvrh z provisioningu; na zdroji s převzatým rozvrhem
   (import ze starého systému) tato očekávání platit nemusí.
   `DatasetRoundTripTest` se bez účtu `211100` přeskočí; u ostatních
   jsem pojistku pro chybějící účet neprověřoval.
   Ostatní soubory s šestimístnými čísly účtů (`Accbal/*`, `Reports/*`,
   `Bank/*`, `Vat/ClosedPeriodBalanceServiceTest`, …) si řádky deníku
   nebo účty zakládají samy — číslo je jejich fixtura, ne data zdroje.
2. **Pevné id osoby.** `Accbal/LedgerGeneratorTest` (4×)
   a `Accbal/AccbalRegenerateCommandTest` (1×) zapisují saldo pohyb
   s `partner => 1`. Referenční integrita je aplikační, takže zápis
   projde i bez osoby s id 1; výsledek ale závisí na tom, která osoba to
   na zdroji je (nebo že není žádná).
3. **Reálné IČO v sondě registru.** `Api/PersonsRegistryControllerTest`
   sonduje veřejný registr reálným IČO výrobce (záměrně — registr na
   vymyšlené IČO vrací HTML). Na zdroji, kde tato osoba už je, import
   skončí shodou místo založením; chování testu se tedy liší podle
   adresáře zdroje.
4. **Bez nálezu.** Ostatní literály IČO / DIČ jsou syntetické (`0000…`,
   `8888…`, `9999…`, `CZ12345678` apod.) a patří fixturám, které si test
   vkládá; názvy firem v asercích pocházejí z fixtur (`IT-…`, snímky
   tisků); VS `1426000001` v `Prints/CashRegisterPrintTest` nastavuje test
   sám. Kódy DPH (`cz-110`, `cz-120`, …) a kódy sald (`receivables`) jsou
   konfigurace, ne data zdroje.

Mimo tento soupis zůstávají selhání ze **stavu** zdroje (chybějící
registrace DPH, zbytky po spadlých bězích) — ta nejsou očekáváním
zapsaným v testu.
