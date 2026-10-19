# Kurzy cizích měn — kurzovní lístky, dohledání kurzu, doklady, banka a datové sady (#121, task A)

**Stav:** naplánováno — PRD 2026-10-19 (#121 D1, D4–D7, D10), upřesnění U1–U7 a doplnění D7 (kurz ve formuláři transakce) potvrzené 2026-10-19

> Task pro Claude Code (7 commitů). Design: issue #121, rozhodnutí D1–D10
> (komentář 2026-10-18). Task B — stahování ze služby, slot `five-minutes`,
> doplnění historie a upozornění na chybějící lístek (D2, D3, D9) — přijde
> po úpravě služby. Tento task na službě nezávisí: lístky se do zdroje dat
> dostanou příkazem ze souboru nebo z datové sady.

## Kontext

Dnes se kurz cizí měny zadává ručně:

- `docs_core_heads.exchange_rate` (numeric 15,6). `DocDocument::validate`
  při potvrzení dokladu v cizí měně kurz vyžaduje („Kurz je povinný pro
  cizí měnu“).
- AI analýza a ISDOC dodají kurz jen tehdy, když je na dokladu. Doklady
  korpusu kurz nemají, takže návrhy v cizí měně nejdou potvrdit bez
  ručního kurzu a `dataset-verify` je přeskakuje (#103 D30).
- Banka: `StatementImportService::applyParsedStatement` dosadí
  `$tx->exchangeRate ?? 1.0` a `BankTransactionDocument::beforeSave`
  totéž. Výpis z eurového účtu nahraný ze souboru (CAMT, GPC, FIO kurz
  nenesou) tak uloží částku v domácí měně rovnou částce v cizí měně. Data
  z migrace kurz nesou, chyba zatím spí.
- **Vedlejší nález:** `DocDocument` počítá částky `_dom` z `exchange_rate`
  i u dokladu v domácí měně. Přepnutí měny EUR → CZK ve formuláři nechá
  skrytý kurz z eura a částky v domácí měně vyjdou vynásobené kurzem.
  S automatickým doplněním kurzu by se to stávalo častěji — task to
  opraví (§6.3).

Pravidla z #121:

- **D4** — kurz k datu D = poslední lístek platný od D nebo dřív.
  Dopoledne před vyhlášením kurzu (ČNB kolem 14:30) tak platí včerejší
  kurz. Je to záměr: tuzemská faktura v cizí měně uvádí DPH v Kč a po
  odeslání se nemění. Na dokladu žádné varování; kalendář svátků se pro
  dohledání nepotřebuje.
- **D5** — datum = účetní datum dokladu (u banky datum transakce, D7).
- **D6** — formulář doplní kurz při změně měny i účetního data; applier,
  když kurz chybí — kurz uvedený na dokladu (ISDOC, AI) má přednost;
  import ze starého Shipardu nedohledává. Ruční přepsání vždy možné.
- **D7** — transakce v cizí měně dostane kurz k datu transakce; nikdy
  tiché 1. Chybí-li lístek, import výpisu skončí chybou se seznamem dat
  a nezapíše nic. Doplnění 2026-10-19: kurz jde u transakce v cizí měně
  upravit ve formuláři — ruční oprava je vzácná, ale možná.
- **D10** — datová sada nese kurzy v souborech formátu z D2, seed je
  nahraje bez sítě.

## Před implementací přečti

- issue #121 — komentář s rozhodnutími D1–D10
- `modules/docs/core/src/DocsHeadsFormBase.php` — `applyNewRecordDefaults`,
  `recalculate`, `buildHeaderTab`, `homeCurrency()`
- `modules/docs/core/src/CashDeskFormBase.php` (měna z pokladny přes řadu)
  a per-typ formuláře s `accounting_date`: `IssuedInvoiceFormBase`,
  `ReceivedInvoiceFormBase`, `AccountingDocsForm`, `CashDocForm`,
  `CashRegisterForm`
- `modules/docs/core/src/DocDocument.php` — `validate` (kurz povinný),
  `applyHomeCurrency`, výpočty `_dom` (`$exchRate`)
- `modules/core/exchange/src/Document/DocumentApplier.php` — `preview()`,
  `apply()`, `transform()`, metody `append*Issue`, import mód
  (`applyOptions.importNumber`)
- `modules/economy/bank/src/Import/StatementImportService.php`,
  `modules/economy/bank/src/BankTransactionDocument.php`,
  `modules/core/exchange/src/Bank/BankStatementApplier.php`, `docs/bank.md`
- `modules/world/cz/module.jsonc`, `modules/world/cz/src/Calendar/`,
  `modules/world/assets/` (vzor konfigurace per země)
- `modules/core/exchange/src/Dataset/` (`DatasetPreflight`, `DatasetReader`,
  `SectionSeeder`), `modules/economy/codebooks/src/Dataset/FiscalYearsSeeder.php`,
  `src/Command/DataSource/DatasetSeedCommand.php`, `DatasetDumpCommand.php`,
  `docs/datasets.md`
- `modules/core/exchange/src/Schema/SchemaLoader.php` a schémata
  v `modules/core/exchange/schemas/` (`.jsonc` zdroj + `.json`)
- `docs/table-definitions.md`, `docs/modules.md`, `docs/exchange-format.md`,
  `docs/cli.md`
- jen pro srovnání se starým Shipardem:
  `old_shipard:modules/e10doc/core/libs/E10Utils.php` (`exchangeRate`),
  `old_shipard:modules/e10doc/base/tables/exchangeRatesLists.json`,
  `old_shipard:modules/e10doc/base/tables/exchangeRatesValues.json`

## Rozsah

**V rozsahu:**

1. Typy lístků v `world.cz` (D1) — §1
2. Formát `shpd.exchangeRates.list.v1` (D2 — formát; služba přijde
   v tasku B) — §2
3. Modul `economy.exchangeRates`: tabulky, importer, resolver — §3, §4
4. CLI `exchange-rates-import` a `exchange-rate` — §5
5. Doklady: formulář, applier, kurz 1 u domácí měny (D5, D6) — §6
6. Banka (D7) — §7
7. Datové sady: sekce, seeder, dump (D10) — §8
8. Viewer Kurzovní lístky v Nastavení — §9
9. Dokumentace a testy — §10, §11

**Mimo rozsah:**

- stahování ze služby, slot `five-minutes`, doplnění historie od začátku
  předchozího fiskálního roku, upozornění na chybějící lístek — task B
  (D2, D3, D9); v tomto tasku **žádné volání sítě**
- kontrola kurzů (D8 → #130)
- kurzy v sadách `tests/datasets/` a zrušení přeskočení dokladů v cizí měně
  v `dataset-verify` (#103 D30) — samostatný task v oblasti testování (U6)
- dva kurzy na jednom dokladu (DPH × účetnictví), kurzové rozdíly, pevný
  kurz účetní jednotky
- celní kurz, kurz ECB — další typ lístku jde později přidat konfigurací

## 1. Typy lístků — `world.cz` (D1)

Nový cfgItem `world.cz.exchangeRateLists` (`modules/world/cz/config/exchangeRateLists.jsonc`,
registrace v `modules/world/cz/module.jsonc`):

```jsonc
{
    // Klíč = id typu lístku (`listType` ve formátu). Pořadí = priorita dohledání.
    "cz.cnb.daily": {
        "name": "CNB — foreign exchange market rates",
        "name:cs": "ČNB — kurzy devizového trhu",
        "name:en": "CNB — foreign exchange market rates",
        "homeCurrency": "czk",
        // U1: starší lístek se pro dohledání nepoužije
        "maxAgeDays": 7
    },
    "cz.cnb.other": {
        "name": "CNB — rates of other currencies",
        "name:cs": "ČNB — kurzy ostatních měn",
        "name:en": "CNB — rates of other currencies",
        "homeCurrency": "czk"
        // měsíční lístek — platnost nese sám lístek (`validTo`)
    }
}
```

Resolver i importer berou typy z `world.{země}.exchangeRateLists`, země =
`DataSourceConfig::getCountry()`. Chybějící cfgItem = žádné typy: import
odmítne každý lístek, resolver kurz nenajde, nic dalšího se nerozbije.
Typ musí mít `maxAgeDays`, nebo jeho lístky musí nést `validTo` —
importer lístek bez obojího odmítne.

## 2. Formát `shpd.exchangeRates.list.v1` (D2)

Schéma `modules/core/exchange/schemas/shpd.exchangeRates.list.v1.jsonc`
+ `.json` (stejně jako ostatní schémata, načítá `SchemaLoader`). Jeden
soubor = jeden lístek. Stejný formát čte CLI (§5), datové sady (§8)
a v tasku B ho bude vracet služba.

```jsonc
{
    "format": "shpd.exchangeRates.list.v1",
    "listType": "cz.cnb.daily",
    "homeCurrency": "CZK",
    "validFrom": "2026-10-16",
    "validTo": null,             // měsíční lístek: poslední den platnosti; denní null
    "listNumber": "201",         // nepovinné
    "rates": [
        { "currency": "EUR", "amount": 1,   "rate": 24.325 },
        { "currency": "JPY", "amount": 100, "rate": 15.871 }
    ]
}
```

(Hodnoty v příkladu jsou ilustrační.)

- Měny velkými písmeny jako v `shpd.docs.document.v1`; v databázi malými
  jako `world.base.currencies`.
- `rate` = kurz za `amount` jednotek tak, jak ho vydavatel vyhlásil.
  Kurz za jednotku (`rate / amount`, zaokrouhlený na 6 desetinných míst
  podle `exchange_rate`) se počítá až při dohledání.
- Schéma: `amount` celé číslo ≥ 1, `rate` > 0, `rates` aspoň jedna
  položka, `validFrom` / `validTo` datum. Sémantika v importeru (§3):
  známý typ pro zemi zdroje dat, `homeCurrency` = měna typu = domácí měna
  zdroje dat, měny z `world.base.currencies`, žádná měna dvakrát,
  `validTo` ≥ `validFrom`.

## 3. Modul `economy.exchangeRates` (D1)

`modules/economy/exchangeRates/module.jsonc` — název „Kurzy cizích měn“,
závislosti `core.system`, `world.base`. Přidej ho do závislostí
`docs.core` (formuláře a applier ho volají; banka ho dostane přes
`docs.core`) a do `install.base`. `ds-upgrade` pak tabulky založí všem
zdrojům dat s doklady.

Tabulky (`tableId` přes `shpd-server next-table-id`), bez stavů dokladu —
jsou to převzatá data, v UI jen ke čtení:

**`economy_exchange_rates_lists`**

| Sloupec | Typ | Poznámka |
|---|---|---|
| `list_type` | varchar 40 | id typu z `world.{země}.exchangeRateLists` |
| `home_currency` | varchar 3 | malými |
| `valid_from` | date | |
| `valid_to` | date, null | jen lístek s omezenou platností |
| `list_number` | varchar 20, null | |
| `source` | enum | cfgItem `economy.exchangeRates.listSources`: 1 = soubor (CLI), 2 = datová sada, 3 = služba (task B) |

Unikátní (`list_type`, `valid_from`); index (`home_currency`, `valid_from`).

**`economy_exchange_rates_values`**

| Sloupec | Typ | Poznámka |
|---|---|---|
| `list` | FK → lists | mazání lístku maže hodnoty |
| `currency` | varchar 3 | malými |
| `amount` | int | |
| `rate` | numeric 15,6 | kurz za `amount` jednotek |

Unikátní (`list`, `currency`); index (`currency`).

**`ExchangeRateListImporter`** — jediná cesta zápisu (CLI, seeder, v tasku B
služba). Vstup = dekódovaný lístek + zdroj. Validace schématu a sémantiky
(§2); lístek a hodnoty v jedné transakci přes `TableGateway` podle
konvencí `CLAUDE.md`. Výsledek per lístek:

- nový (`list_type`, `valid_from`) → `created`,
- existující se shodným obsahem → `skipped`,
- existující s jiným obsahem → `conflict` (chyba); s volbou `replace` se
  hodnoty v transakci nahradí → `replaced`.

## 4. `ExchangeRateResolver` (D4, D5)

```php
public function rateFor(string $currency, string $date): ?ExchangeRate
```

`ExchangeRate` (readonly): `rate` (za jednotku), `listType`, `validFrom`,
`listNumber`. Měna malými nebo velkými, datum `Y-m-d`.

1. Měna = domácí měna zdroje dat (`economy.homeCurrency`) → kurz 1
   (`listType` null).
2. Typy z `world.{země}.exchangeRateLists` s `homeCurrency` = domácí měna,
   v pořadí konfigurace.
3. V rámci typu se vezme **poslední lístek s `valid_from` ≤ D** bez ohledu
   na měnu. Obsahuje-li měnu, platí jeho kurz; jinak se zkusí další typ —
   nikdy starší lístek téhož typu (U2).
4. Platnost (U1): lístek s `valid_to` platí, jen když D ≤ `valid_to`;
   lístek bez něj, jen když D − `valid_from` ≤ `maxAgeDays` typu. Jinak
   kurz nenalezen.
5. Žádný kalendář: před vyhlášením kurzu na den D je posledním lístkem
   ≤ D ten předchozí — záměr (D4).
6. Nenalezeno → `null`. Pro diagnostiku (CLI `exchange-rate`) vrátí resolver
   i důvod: žádný lístek, lístek starý, měna v lístku není.

Memoizace per instance (měna, datum) — formulář i applier se ptají
opakovaně.

## 5. CLI

Registrace v `src/Cli/DsApplicationFactory.php`, reference `docs/cli.md`.

**`shpd-ds exchange-rates-import <soubor|složka> [--replace]`**

- soubor `.jsonc` / `.json`, nebo složka (všechny takové soubory, setříděné
  podle jména),
- každý soubor přes `ExchangeRateListImporter` se zdrojem 1,
- souhrn `created / skipped / replaced / failed` a chyby per soubor;
  nenulový exit při jakémkoli selhání.

**`shpd-ds exchange-rate <měna> [<datum>]`** — výsledek resolveru (kurz za
jednotku, typ, platnost od, číslo lístku), nebo „kurz nenalezen“ s důvodem
(§4 bod 6). Datum výchozí dnešní. Slouží k ověření a podpoře.

## 6. Doklady (D5, D6)

### 6.1 Formulář

V `DocsHeadsFormBase` chráněný helper, např. `applyExchangeRate(array &$data)`:

- měna dokladu = domácí měna → `exchange_rate = 1` (§6.3),
- jinak `rateFor(doc_currency, accounting_date)`:
  - nalezeno → přepíše `exchange_rate`, i ručně zadaný (U3),
  - nenalezeno → hodnota zůstane a pole dostane upozornění (hint):
    „Kurz {MĚNA} k {datum} není v kurzovním lístku — zadej ho ručně.“ (U3).
    Hint jen pro tuto odpověď — např. přes přechodný klíč v `$data`, který
    `buildHeaderTab` přečte a do uložení se nedostane.

Volání:

- konec `applyNewRecordDefaults` — nový doklad, jehož měna je od začátku
  cizí (pokladna v cizí měně nastaví měnu dřív, než zavolá rodiče),
- `recalculate` pro `doc_currency`, `accounting_date` a `issue_date`, když
  posunulo účetní datum (nový doklad),
- `CashDeskFormBase::recalculate` pro `number_series` (měna z pokladny).

`accounting_date` dostane `triggers: 'reload'` ve všech hlavičkových
formulářích: `DocsHeadsFormBase`, `IssuedInvoiceFormBase`,
`ReceivedInvoiceFormBase`, `AccountingDocsForm`, `CashDocForm`,
`CashRegisterForm`.

### 6.2 Applier

V `DocumentApplier::transform()` (jediné místo pro náhled i apply):
měna ≠ domácí, `$canonical['exchangeRate']` je null a není import mód
(`applyOptions.importNumber`) → `rateFor(měna, accountingDate)`; nalezený
kurz jde do `exchange_rate`. Kurz z canonicalu vždy vyhrává. Import ze
starého Shipardu nedohledává: chybějící kurz zůstane chybějící a v cílovém
stavu V pořádku zastaví stávající validace.

Nová metoda `appendExchangeRateIssue` (volaná v `preview()` i `apply()`
vedle ostatních `append*Issue`) — U5:

- doplněno → `info`, kód `exchange_rate_from_list`: „Kurz {kurz} z lístku
  {název typu} platného od {datum}.“
- nenalezeno → `warning`, kód `exchange_rate_missing`: „Kurz {MĚNA}
  k {datum} není v kurzovním lístku — doklad nepůjde potvrdit bez ručního
  kurzu.“

Doklady generované Shipardem (periodická fakturace přes `InvoiceBuilder`)
jdou přes apply, takže je to pokryje bez další práce.

### 6.3 Doklad v domácí měně má kurz 1

`DocDocument::beforeSave` (v `applyHomeCurrency` nebo hned za ním): měna
dokladu = domácí měna → `exchange_rate = 1`. Opravuje skrytou chybu
z Kontextu (U4). Data se neopravují — viz Nasazení bod 6.

## 7. Banka (D7)

`StatementImportService::applyParsedStatement`:

- transakce v domácí měně → kurz 1 (případný kurz z banky se ignoruje),
- transakce v cizí měně bez `exchangeRate` → `rateFor(měna, date_transaction)`,
- kurzy se zjistí **před** `begin()` pro celý výpis; chybí-li kterýkoli,
  `ImportException` se seznamem dat a nic se nezapíše: „Kurz {MĚNA} chybí
  pro data: … — nahraj kurzovní lístky (`shpd-ds exchange-rates-import`).“

Migrace (`BankStatementApplier`) jde stejnou cestou: řádek s kurzem beze
změny, bez kurzu dohledání.

`BankTransactionDocument`:

- `validate` odmítne transakci v cizí měně bez kurzu,
- `beforeSave`: domácí měna → kurz 1; `amount_dom` = `amount × exchange_rate`
  (zaokrouhleno na 2 místa), když chybí **nebo když se kurz proti
  `originalData` změnil**. Jinak `amount_dom` zůstává — migrované řádky
  nesou částku ze starého Shipardu a přepočet by ji mohl posunout o haléř.

**Formulář transakce (doplnění D7):** pole Kurz (`exchange_rate`) za
Částkou v domácí měně — editovatelné u transakce v cizí měně, skryté
u domácí měny. Úprava jde jen ve stavech Nová a V opravě (Zaúčtováno je
jen ke čtení), přepočet `amount_dom` při uložení a nové zaúčtování při
Zaúčtovat zařídí stávající tok. Statický JSONC skrývat podle dat neumí —
formulář převeď na třídu (registrace `class` v `module.jsonc`,
`docs/edit-forms.md` §13), ostatní obsah beze změny; komentář v hlavičce
formuláře („měnitelné jsou jen operation / partner / message“) uprav.
Transakce dál vznikají jen importem — viewer Přidat nedostane.

## 8. Datové sady (D10)

- Nová sekce `exchangeRates/NNNN-<listType>-<validFrom>.jsonc` ve formátu
  z §2, klíč `exchangeRates` v `counts`.
- `DatasetPreflight` ověří soubory sekce proti schématu.
- `ExchangeRatesSeeder` (`modules/economy/exchangeRates/src/Dataset/`,
  sekce `exchangeRates`) přes importer se zdrojem 2: `created` = ok,
  `skipped` = ok, `conflict` = failed (seed nic nepřepisuje). Pořadí
  v `DatasetSeedCommand::createSeeders`: za `FiscalYearsSeeder`, před
  `DocumentSeeder` a `MailSeeder`; jen když zdroj dat tabulku má.
- Dump: exporter vypíše právě ty lístky, které resolver použije pro účetní
  data dokladů a návrhů (canonical v analýzách zpráv) v cizí měně v sadě
  (U7). Žádné doklady v cizí měně → sekce prázdná.
- `docs/datasets.md` §1 (struktura), §3 (pořadí seedu), §4 (co formát nese).

## 9. Nastavení — Kurzovní lístky

`settingsItems` sekce `accounting`: viewer `economy.exchangeRates.lists`
jen ke čtení (bez přidání, úprav a mazání). Řádek: typ, platnost od (do),
číslo, počet měn, zdroj; filtr podle typu; řazení podle `valid_from`
sestupně. Detail: tabulka hodnot — měna, množství, kurz, kurz za jednotku.

## 10. Dokumentace

- nový `docs/exchange-rates.md` — typy lístků, formát, tabulky, pravidlo
  dohledání (D4, D5, U1, U2), kde se kurz doplňuje (D6), banka (D7),
  datové sady (D10), CLI; co přinese task B
- `docs/exchange-format.md` — význam `exchangeRate` (null → dohledání
  mimo import mód) a odkaz na nový formát
- `docs/datasets.md` (§8), `docs/bank.md` (kurz místo výchozí 1, pole
  Kurz ve formuláři transakce),
  `docs/cli.md` (dva příkazy)
- řádek v `docs/README.md`, `CLAUDE.md` (tabulka dokumentů) a
  `tasks/README.md` (index, oblast Dokladový systém)

## 11. Testy

Úzké, podle existujících vzorů; PHPUnit jen s `--filter`.

- **Resolver:** poslední lístek ≤ D, víkend (pátek), den před vyhlášením
  (předchozí lístek), lístek starší než `maxAgeDays` (nenalezen),
  měsíční lístek po `valid_to` (nenalezen), měna chybí v posledním denním
  → měsíční typ, domácí měna → 1, kurz za jednotku u `amount` 100 a 1000.
- **Importer:** chyba schématu, neznámý typ, nesoulad domácí měny, shodný
  obsah → `skipped`, jiný obsah → `conflict` / `replaced`.
- **Formulář:** změna měny i účetního data doplní kurz, domácí měna → 1,
  nenalezeno → hodnota zůstane + hint, nový doklad v pokladně v cizí měně.
- **Applier:** null → doplněno + `info`, kurz z canonicalu vyhrává, import
  mód nedohledává, nenalezeno → `warning`.
- **`DocDocument`:** domácí měna → kurz 1 a `_dom` = částky dokladu.
- **Banka:** cizí měna bez kurzu → z lístku, chybějící lístek →
  `ImportException` a nic nezapsáno, domácí měna → 1, validace
  `BankTransactionDocument`; změna kurzu přepočítá `amount_dom`, uložení
  beze změny kurzu migrovanou `amount_dom` nechá; formulář — pole Kurz
  jen u cizí měny.
- **Datové sady:** seeder (ok / skipped / failed), výběr lístků v dumpu,
  round trip dump → seed → dump.

## Commity

1. `world.cz` typy lístků + schéma `shpd.exchangeRates.list.v1`
2. Modul `economy.exchangeRates`: tabulky, importer, resolver, CLI, viewer
   v Nastavení
3. Doklady: formulář a kurz 1 u domácí měny (§6.1, §6.3)
4. Applier (§6.2)
5. Banka (§7)
6. Datové sady (§8)
7. Dokumentace a hlavička tasku

## Nasazení a ověření

Na vývojářském stroji, **volný** zdroj dat:

1. `ds-upgrade` → tabulky a viewer v Nastavení.
2. Ruční soubory s několika denními lístky (pátek, pondělí) a jedním
   měsíčním → `exchange-rates-import`; opakovaný běh = `skipped`.
3. `exchange-rate eur` na sobotu → páteční lístek; na pondělí bez
   pondělního lístku → páteční (D4); 8 dní po posledním lístku →
   nenalezeno s důvodem „lístek starý“ (U1).
4. Formulář: nová faktura v EUR → kurz doplněn; změna účetního data →
   jiný kurz; přepnutí na CZK → kurz 1 a částky v domácí měně = částky
   dokladu.
5. Datová sada s návrhem v EUR: náhled ukáže `info` s kurzem, Použít →
   doklad jde potvrdit.
6. Agregovaný počet dokladů v domácí měně s kurzem ≠ 1 na reálných kopiích
   (jen čtení, výsledek do chatu; data neměnit).
7. Banka: výpis z eurového účtu ze souboru → kurz z lístku; bez lístku →
   chyba se seznamem dat a nic nezapsáno. Transakce v EUR: V opravě →
   změna kurzu → uložit (nová částka v Kč) → Zaúčtovat (deník podle nové
   částky); transakce v CZK pole Kurz nemá.

`ds-upgrade` zdrojů dat s reálnou kopií jen po schválení člověkem.

## Pasti

- `exchange_rate` má 6 desetinných míst — kurz za jednotku zaokrouhli
  stejně, jinak nebude dump → seed → dump shodný.
- Měny: formát velkými, databáze malými.
- `triggers: 'reload'` na účetním datu přidává roundtrip — ověř, že
  formulář drží fokus a aktivní tab jako u ostatních reload polí.
- Import mód nikdy nedohledává — kurz ze starého Shipardu je fakt, ne
  návrh.
- Žádné volání sítě; služba je task B.

## Rozhodnutí k designu (potvrzená 2026-10-19)

- ✓ **D7 doplnění — Kurz ve formuláři transakce.** Editovatelný u cizí
  měny ve stavech Nová a V opravě, skrytý u domácí; změna přepočítá
  částku v domácí měně (§7).
- ✓ **U1 — Stáří lístku.** Denní lístek se použije nejvýš `maxAgeDays` (7)
  dní od platnosti, měsíční do `validTo`; jinak kurz nenalezen. Pojistka
  proti tichému starému kurzu, když lístky nepřibývají (před taskem B,
  výpadek služby). Sedm dní pokryje Velikonoce i Vánoce.
- ✓ **U2 — Dohledání v rámci typu.** Bere se poslední lístek ≤ D bez ohledu
  na měnu; chybí-li v něm měna, zkusí se další typ, ne starší lístek
  téhož typu (měna vyřazená z denního lístku nesmí sáhnout do historie).
- ✓ **U3 — Formulář.** Nalezený kurz přepíše i ručně zadaný; nenalezený
  hodnotu nechá a ukáže upozornění u pole. Jinak by změna data mazala
  ruční kurz — a dokud zdroj dat nemá lístky, dělo by se to všude.
- ✓ **U4 — Domácí měna = kurz 1** v `DocDocument::beforeSave` — oprava skryté
  chyby s přepnutím měny.
- ✓ **U5 — Applier:** doplněný kurz = `info`, chybějící = `warning`; doklad
  se uloží, potvrzení zastaví stávající validace.
- ✓ **U6 — Kurzy v `tests/datasets/` a zrušení #103 D30** jsou samostatný
  task — potřebují očekávání DPH a nákladů v Kč z buildu korpusu.
- ✓ **U7 — Dump sady** exportuje jen lístky, které resolver použije pro
  doklady a návrhy v cizí měně v sadě.

## Hotovo když

- [ ] `world.cz.exchangeRateLists` s typy `cz.cnb.daily` a `cz.cnb.other`
- [ ] schéma `shpd.exchangeRates.list.v1` (`.jsonc` + `.json`)
- [ ] modul `economy.exchangeRates` v závislostech `docs.core`
      a `install.base`, dvě tabulky, `ExchangeRateListImporter`,
      `ExchangeRateResolver`
- [ ] `shpd-ds exchange-rates-import` a `shpd-ds exchange-rate`
- [ ] formulář doplní kurz při novém dokladu, změně měny, účetního data
      a řady pokladny; `accounting_date` s `reload` ve všech hlavičkových
      formulářích
- [ ] applier doplní chybějící kurz mimo import mód, `info` / `warning`
- [ ] doklad v domácí měně má vždy kurz 1
- [ ] banka bez tichého kurzu 1; chybějící lístek zastaví celý výpis;
      pole Kurz ve formuláři transakce v cizí měně, změna přepočítá
      `amount_dom`
- [ ] datové sady: sekce `exchangeRates/`, seeder, výběr lístků v dumpu
- [ ] viewer Kurzovní lístky v Nastavení
- [ ] testy z §11 zelené (úzké `--filter`)
- [ ] dokumentace z §10; hlavička tasku aktualizovaná
- [ ] ověření 1–7 na volném zdroji dat, výsledek bodu 6 v chatu
