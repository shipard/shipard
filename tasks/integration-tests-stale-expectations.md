# Task: Dva integrační testy se zastaralým očekáváním

**Stav:** naplánováno — zadání 2026-11-22 (#167)

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

- [ ] oba testy zelené na dvou zdrojích dat s různou registrací DPH
- [ ] `VatReturnAccountingServiceTest` po sobě vrací `vat_id` registrace
- [ ] seznam dalších očekávání vázaných na data zdroje v poznámkách
