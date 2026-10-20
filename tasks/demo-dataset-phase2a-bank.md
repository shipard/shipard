# Demo sada „IT servis“ — fáze 2a: banka

**Stav:** naplánováno — zadání podle #131 D16–D18, D21, D22, čeká na implementaci

## Kontext / Cíl

Fáze 1 (`tasks/demo-dataset-phase1.md`) dala sadu `demo/datasets/it-servis/`
s vydanými a přijatými fakturami, pokladnou, mzdami a spisovnou. Úhrady
faktur v ní nejsou (kromě hotovosti), takže saldokonto ukazuje skoro vše
jako otevřené nebo po splatnosti a výpis z banky chybí úplně.

Fáze 2a přidá **bankovní výpisy jako novou sekci sady** (exportér pro dump,
seeder) a **platby do příběhu firmy**. Úhrady se v sadě nepodvrhují:
transakce nese jen to, co dodá banka, a partnera i párování s fakturou
dopočítá aplikace stejnou cestou jako při importu souboru (D17). Po seedu
tak saldokonto, přehled nespárovaných plateb a bankovní viewery ukazují
výsledek skutečného párování.

Fáze 2b (zakázky) a 2c (majetek) jsou samostatné tasky (D16).

## Návaznost

- **#131** — rozhodnutí D1–D22 (shrnutí na konci; D16–D22 v komentáři
  „Fáze 2 — rozhodnutí D16–D22“)
- `tasks/demo-dataset-phase1.md` — generátor, příběh, D10 (seed → dump
  bajtově stejně), Odchylky (krok 0 v PHP, tvar dumpu)
- `docs/bank.md` — datový model (§3), dohledání partnera (§4.5), kurz
  (§4.6), stavy (§5), mikroengine a dohledání předpisu (§6.1), pohyby
  `txOperations` (§6.2), clearing (§6.3), migrace a applier (§7)
- `docs/accbal.md` — klíč případu (partner, VS, SS, měna), clearing šev
  (§4.4), zálohové faktury (`proformas_out`), kurzové rozdíly mimo scope
- `tasks/bank-phase4.md` — výměnný formát `shpd.bank.statement.v1`
  a `BankStatementApplier`

## Před implementací přečti

- `docs/datasets.md` celý (sekce, pořadí seedu, § 4 dump, konvence JSON)
- `docs/bank.md` § 3–7, `docs/accbal.md` § 4.4 a oddíl o zálohových
  fakturách
- `modules/core/exchange/schemas/shpd.bank.statement.v1.jsonc`
- `modules/core/exchange/src/Bank/BankStatementApplier.php`,
  `modules/economy/bank/src/Import/StatementImportService.php`
  (`applyParsedStatement`, `resolvePartner`, `resolveExchangeRates`, dedup)
- `modules/economy/bank/src/BankTransactionAccountingEngine.php`
  (bankovní strana z `accounting_account`, protistrana, clearing)
- vzory sekcí: `modules/base/registry/src/Dataset/RegistrySeeder.php`,
  `RegistryExporter.php`, `modules/core/mail/src/Dataset/MailSeeder.php`,
  `MailExporter.php`; registrace v `src/Command/DataSource/DatasetSeedCommand.php`
  (`createSeeders`) a `DatasetDumpCommand.php` (`createExporters`)
- generátor: `tools/invoice-generator/invoicegen/story.py`, `expand.py`,
  `dumpshape.py`, `timeline.py`; příběh `demo/datasets/stories/it-servis/`

## Rozsah

**V rozsahu:**

- sekce sady `bank/` — obálka výpisu s přirozenými klíči, exportér
  (dump) a seeder; dokumentace formátu
- příběh: platby odběratelů podle chování (D18), úhrady přijatých faktur,
  výplata mezd a odvody s vyrovnávacími interními doklady (D22), poplatky
  banky, počáteční zůstatek, záměrně nespárované platby
- eurový účet pro platby slovenských a německého odběratele (U1)
- přegenerovaná sada, unit testy, ověření seed → dump

**Mimo rozsah:**

- DPH: platby finančnímu úřadu se do výpisu nedávají (D22, otevřený bod
  v #131)
- řádkový partner pokladního dokladu (D21)
- zakázky a dimenze zakázky (2b), majetek (2c)
- kurzové rozdíly (accbal je nemá; viz Pasti → Cizí měna)
- automatická klasifikace poplatků a převodů v aplikaci

## Návrh

### Sekce `bank/` (D17)

Jeden soubor = jeden výpis jednoho našeho účtu za kalendářní měsíc.
Obálka nad kanonickým formátem — vzor `shpd.dataset.registryDocument.v1`:

```jsonc
{
    "format": "shpd.dataset.bankStatement.v1",
    "bankAccount": "BU1",                 // code z economy_codebooks_bank_accounts
    "statement": {                        // jako shpd.bank.statement.v1
        "statementNumber": "1/2026",
        "externalId": "…",                // stabilní, deterministické
        "periodStart": "2026-01-01", "periodEnd": "2026-01-31",
        "openingBalance": 0, "closingBalance": 0,
        "currency": "CZK"
    },
    "transactions": [                     // jako shpd.bank.statement.v1, BEZ partnerId
        {
            "externalId": "…", "amount": 8712.0,
            "dateTransaction": "2026-02-09", "dateValue": null,
            "counterpartyAccount": "2440913574/0300", "counterpartyName": "…",
            "paymentReference": "1260005", "specificSymbol": null,
            "constantSymbol": null, "message": null,
            "operation": null,           // jen výslovně zadaná (fee.out), jinak null
            "exchangeRate": null          // jen u účtu v cizí měně
        }
    ],
    "targetState": 40
}
```

Název formátu, umístění tříd a přesný tvar polí jsou návrh — rozhoduje
konzistence se sekcemi `registry` a `mail` a s tím, co dump umí vrátit.
Pravidla, která platí bez ohledu na tvar:

- **Žádná interní id.** Účet kódem, partner se nepřenáší vůbec — po seedu
  ho určí protiúčet (`resolvePartner`), stejně jako u souborového importu.
- **Dopočtené hodnoty se nepřenáší:** `partner`, `amount_dom`,
  `accounting_state`, účtování. Exportér vypíše jen to, co by dodala banka,
  plus `operation`, pokud je výslovně nastavená (poplatek). Výchozí
  `payment.in` / `payment.out` se nevypisuje.
- **Seeder** mapuje kód účtu na id a deleguje na `BankStatementApplier`
  (žádná vlastní create logika). Cílový stav 40 → `BankTransactionEventHandler`
  zaúčtuje a mikroengine dohledá otevřený předpis.
- **Pořadí seedu:** sekce `bank` až za `docs` (předpisy musí existovat),
  registrovaná jen když DS má tabulku `economy_bank_transactions`.
- **Dump:** pořadí výpisů podle účtu a období, transakce podle data
  a pořadí v rámci výpisu; slug souboru z kódu účtu a období. Pořadí
  transakcí musí přežít seed beze změny (ověř, podle čeho ho drží DB).

### Platby v příběhu (D18)

Model plateb z fáze 1 (chování odběratele, výjimky u faktur) se teď
promítne do transakcí. Příběh nese:

- **Odběratelé:** včas (N dní před / po splatnosti), pozdě (N dní po
  splatnosti), částečně (podíl, doplatek později nebo vůbec), vůbec.
  Výsledek k datu kotvy: část faktur uhrazená, pár částečně, několik po
  splatnosti — přehled saldokonta má co ukázat. VS = číslo faktury (D8),
  protiúčet = bankovní spojení odběratele v osobách.
- **Zálohová faktura:** uhrazená včas (přijatá záloha, skupina Zálohové
  faktury vydané → 324). Ověř párování podle `docs/accbal.md`; kdyby
  skončila v nespárovaných, nech ji neuhrazenou a zapiš do Odchylek.
- **Přijaté faktury:** úhrada dodavateli z BU1 se splatností dodavatele
  (většina včas, jedna dvě pozdě), VS = VS přijaté faktury, protiúčet =
  účet dodavatele. Přijaté faktury, jejichž zpráva je k řešení (návrh,
  ne doklad), se neplatí.
- **Mzdy a odvody (D22):** výplata čistých mezd, odvod pojištění a záloha
  na daň za měsíc M v měsíci M+1 — bez partnera (protiúčet, který není
  u žádné osoby) a **bez VS**. Ke každé platbě interní doklad ve stejný
  den: MD 331100 / 336100 / 342100, DAL účet nespárovaného výdaje (§6.3
  `bank.md`, 261300). Řádky bez partnera a bez symbolů — případ se
  prázdným klíčem se vynuluje.
- **Poplatky banky:** měsíčně, `operation: fee.out`, bez partnera.
- **Záměrně nespárované platby:** 2–3 příjmy od známého odběratele
  (protiúčet z osob) s chybějícím nebo překlepnutým VS. **Musí mít
  partnera** — bez něj by spadly do prázdného případu mezd (D22).
- **Počáteční zůstatek** k prvnímu dni dat z příběhu; v deníku ho
  zastupuje doklad počátečního stavu účtu 221 (ověř v `docs/accounting.md`,
  jak se v aplikaci zakládá otevírací stav — otevírací doklad, vzor
  `cmnbkp` 211/701), aby zůstatek účtu 221 k poslednímu dni sedl
  s `closingBalance` posledního výpisu.

Zůstatek účtu nesmí během roku klesnout pod nulu — příběh ho drží
počátečním zůstatkem a pořadím plateb; validace loaderu to hlídá.

### Eurový účet (U1)

Faktury slovenským a německému odběrateli jsou v EUR (D13) a klíč případu
obsahuje měnu — platba v CZK by se s nimi nespárovala. Příběh proto dostane
druhý účet **BU2 v EUR** (setup) s vlastními výpisy. Transakce na BU2 nesou
`exchangeRate` = kurz faktury, kterou hradí (z příběhu, D13), aby v domácí
měně nevzniklo reziduum (kurzové rozdíly accbal neřeší).

### Bankovní účty v setup

`setup/bank_accounts` nese `accounting_account` (číslo analytiky 221xxx,
exportér účet z rozvrhu přenáší číslem). Bez něj mikroengine zapíše chybový
řádek (`bank_account_not_found`). Ověř, které analytiky 221 existují na
čerstvém zdroji dat po seedu, a buď použij existující, nebo zjisti, jak
sada analytiku založí — pokud sada analytiky účtů zakládat neumí, zastav
se a zapiš do Odchylek (rozhodnutí v chatu).

## Kroky (commity)

1. **Sekce `bank/` v PHP.** Obálka (schéma `.jsonc` + `.json` vedle
   ostatních dataset formátů), exportér, seeder, registrace v seed i dump
   příkazu, preflight sady bez DS. Testy: unit test exportéru (tvar, pořadí,
   nepřenášené sloupce), unit test seederu (kód účtu, neznámý kód = chyba),
   rozšíření `DatasetRoundTripTest` o výpis s příjmem, výdajem a poplatkem.
   Dokumentace sekce v `docs/datasets.md` a odkaz z `docs/bank.md` § 7.
2. **Účty a počáteční stav v příběhu.** BU2 v EUR, `accounting_account`
   u obou účtů, počáteční zůstatek. Ověř na vzorku (seed jednoho výpisu do
   čerstvého zdroje dat), že transakce zaúčtuje bez chyby.
3. **Platby v generátoru.** Rozvinutí plateb na transakce a měsíční výpisy,
   vyrovnávací interní doklady (D22), validace (zůstatek ≥ 0, nespárované
   platby s partnerem, každá platba mezd má svůj interní doklad). Unit testy:
   determinismus, posun kotvy, součty výpisů (počáteční + pohyby = konečný),
   částečné úhrady, žádná platba po kotvě.
4. **Sada, ověření, dokumentace.** Přegenerovat `demo/datasets/it-servis/`,
   ověřit podle oddílu Ověření, doplnit `demo/datasets/README.md` (platby
   v příběhu, účty), `tools/invoice-generator/README.md`, řádek
   v `tasks/README.md`, `**Stav:**` + `python3 scripts/tasks-index.py`.

## Ověření

Na vývojovém stroji, čerstvý volný zdroj dat (`ds-create --no-cron`),
`dataset-seed`, `dataset-dump`, `diff -r` jako ve fázi 1:

- `diff` jen `manifest.created` a `title`;
- deník MD = D; **žádná transakce s `accounting_state` = 2** (chyba);
- saldokonto Pohledávky: uhrazené faktury uzavřené, částečně uhrazené
  s reziduem, neuhrazené po splatnosti — počty zapsat do Odchylek
  a porovnat s příběhem;
- skupina Nespárované platby: **jen** záměrně nespárované příjmy (počet
  podle příběhu), případ mezd s prázdným klíčem nulový;
- závazky 331 / 336 / 342 nulové kromě mezd posledního měsíce, které se
  platí až po kotvě;
- zůstatek 221 (BU1, BU2) = `closingBalance` posledního výpisu;
- EUR faktury uhrazené bez rezidua v domácí měně;
- ruční kontrola v aplikaci (zapsat): viewer transakcí a výpisů, detail
  transakce s dohledaným partnerem, saldokonto odběratele s úhradami,
  přehled nespárovaných plateb.

Ověřovací zdroj dat po kontrole smaž (`shpd-server ds-delete`); pokud ho
necháš Anně k ručnímu průchodu, zapiš jeho prefix do Odchylek.

## Pasti

- **Prázdný klíč případu (D22).** Všechny platby bez partnera a bez VS
  sdílí jeden případ na Nespárovaných platbách. Interní doklady ho vynulují
  jen tehdy, když v něm nic jiného není — záměrně nespárované platby proto
  vždy s partnerem; poplatky jdou přes `fee.out` mimo clearing.
- **Protiúčet mezd** nesmí patřit žádné osobě v sadě, jinak se dohledá
  partner a platba se rozpadne do jiného případu.
- **Účetní období.** Dohledání předpisu běží v účetním období transakce
  (#69 D11); všechna data leží v jednom fiskálním roce — hlídej to i při
  posunu kotvy (platba v lednu za prosincovou fakturu by předpis nenašla).
  Pokud posun kotvy přes hranici roku nejde udržet, zapiš omezení do
  `demo/datasets/README.md`.
- **Cizí měna.** Transakce na BU2 vždy s kurzem; bez něj by applier hledal
  kurzovní lístky (sada je nemá) a výpis by se nezapsal celý. Kurz platby
  = kurz hrazené faktury, jinak reziduum v domácí měně (kurzové rozdíly
  nejsou).
- **Dedup.** `externalId` transakcí unikátní per účet a deterministické;
  bez něj by fingerprint závisel na pořadí v rámci dne.
- **Zdroj partnera.** `resolvePartner` hledá osobu podle protiúčtu
  v bankovním spojení — ověř, že osoby ze sady nesou účet ve tvaru, který
  resolver najde (číslo/kód banky vs. IBAN).
- **Determinismus a fiktivní data** platí jako ve fázi 1 (žádné `random`,
  `now()`; čísla účtů z příběhu, protiúčet mezd fiktivní, žádná reálná
  data).

## Hotovo když

- [ ] sekce `bank/` v PHP s testy, round-trip test projde
- [ ] příběh nese účty BU1 + BU2, počáteční zůstatek a platby podle D18, D22
- [ ] `python3 demo/datasets/build.py` vygeneruje sadu s výpisy, druhý běh
      nic nezmění; `--anchor` posune i platby
- [ ] unit testy generátoru a dotčené PHPUnit testy projdou
- [ ] ověření podle oddílu Ověření, výsledky v Odchylkách
- [ ] dokumentace: `docs/datasets.md`, `docs/bank.md`,
      `demo/datasets/README.md`, `tools/invoice-generator/README.md`,
      řádek v `tasks/README.md`
- [ ] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

## Rozhodnutí k designu (potvrzená, #131)

- ✓ **D16** — fáze 2 po oblastech: 2a banka → 2b zakázky → 2c majetek
- ✓ **D17** — sekce `bank/`, měsíční výpis, účet kódem; transakce jen
  s tím, co dodá banka; partnera a párování dopočítá aplikace; cílový stav
  Zaúčtováno
- ✓ **D18** — výpis jako skutečný: chování plateb odběratelů, úhrady
  přijatých faktur, mzdy a odvody, poplatky, počáteční zůstatek, pár
  záměrně nespárovaných plateb se známým protiúčtem
- ✓ **D21** — pokladna beze změny, řádkový partner se do formátu nepřidává
- ✓ **D22** — mzdy a odvody bez partnera a VS + vyrovnávací interní doklad;
  DPH se do výpisu nedává

- ✓ **U1** — eurový účet BU2 pro platby EUR faktur, kurz transakce = kurz
  hrazené faktury (upřesnění při psaní zadání, potvrzeno v chatu)
