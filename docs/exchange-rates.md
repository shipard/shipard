# Kurzy cizích měn — kurzovní lístky a dohledání kurzu

Modul `economy.exchangeRates` (#121, task A — `tasks/exchange-rates-core.md`):
kurzovní lístky země zdroje dat v tabulkách, kurz k datu pro doklady,
applier a bankovní výpisy, nahrání lístků ze souboru a z datové sady. Task B
(`tasks/exchange-rates-download.md`, #121 D2, D3, D9, U1–U7) přidal
stahování ze služby v periodických úlohách, ruční doplnění z CLI
a upozornění na chybějící lístek (§9).

## 1. Typy lístků — `world.<země>.exchangeRateLists` (D1)

Typy lístků deklaruje modul země, ne modul kurzů. Česko
(`modules/world/cz/config/exchangeRateLists.jsonc`):

| Typ | Co | Platnost |
|---|---|---|
| `cz.cnb.daily` | ČNB — kurzy devizového trhu, denní lístek | `maxAgeDays: 7` — lístek bez `validTo` se použije nejvýš 7 dní od platnosti (U1) |
| `cz.cnb.other` | ČNB — kurzy ostatních měn, měsíční lístek | platnost nese sám lístek (`validTo`) |

Klíč = id typu (`listType` ve formátu), pořadí v konfiguraci = priorita
dohledání (U2), `homeCurrency` = měna, ve které lístek kurzy vyhlašuje
(malými, jako `world.base.currencies`). Jediné čtení je
`ExchangeRateListTypes::fromConfig($config, $country)`; země =
`DataSourceConfig::getCountry()`. Chybějící cfgItem (jiná země,
nezkompilovaná konfigurace) = žádné typy: importer odmítne každý lístek,
resolver kurz nenajde, nic dalšího se nerozbije. Typ musí mít
`maxAgeDays`, nebo jeho lístky musí nést `validTo` — importer lístek bez
obojího odmítne. Další typ (celní kurz, ECB) = další záznam v konfiguraci
země.

**Stahování (`download`, task B U2).** Typ, který se má stahovat ze služby
(§9), nese klíč `download` → `Download\ListDownloadConfig`:
`serviceListType` (`listType` služby: 1 denní, 2 ostatní měny),
`frequency` `workingDays` (lístek každý pracovní den kalendáře `calendar`
— dnes jen `cz` → `CzechHolidays`; dnešní lístek se čeká až po
`publishAfter` v zóně `timezone`, výchozí Europe/Prague) nebo `monthly`
(lístek s `validFrom` = 1. den měsíce). Typ bez `download` se nestahuje
a upozornění ho nehlídá; neplatná hodnota typ pro resolver nezruší, jen
se nestahuje (`ExchangeRateListType::$downloadIssue`, stahovač to vypíše).

## 2. Formát `shpd.exchangeRates.list.v1` (D2)

Schéma `modules/core/exchange/schemas/shpd.exchangeRates.list.v1.jsonc`
(+ `.json`, drift hlídá `SchemaDriftTest`). Jeden soubor = jeden lístek;
stejný formát čte CLI (§6), datové sady (§7) a vrací ho služba (§9).

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

- Měny velkými písmeny jako v `shpd.docs.document.v1`; v databázi malými.
- `rate` = kurz za `amount` jednotek tak, jak ho vydavatel vyhlásil. Kurz
  za jednotku (`rate / amount`, zaokrouhlený na 6 desetinných míst jako
  `exchange_rate`) se počítá až při dohledání (`ExchangeRate::perUnit`).
- Schéma: `amount` celé číslo ≥ 1, `rate` > 0, `rates` aspoň jedna
  položka, data `YYYY-MM-DD`, žádné cizí klíče. Sémantiku hlídá importer
  (§3).

## 3. Tabulky a importer (D1)

`modules/economy/exchangeRates/` — závislosti `core.system`, `world.base`;
je v závislostech `docs.core` (formuláře a applier volají resolver; banka
ho dostává přes `docs.core`) a v `install.base`.

**`economy_exchange_rates_lists`** (tableId 464): `list_type` varchar 40,
`home_currency` (malými), `valid_from`, `valid_to` (null u denního),
`list_number` (null), `source` enumInt `economy.exchangeRates.listSources`
(1 soubor, 2 datová sada, 3 služba). Unikátní (`list_type`, `valid_from`),
index (`home_currency`, `valid_from`). **`economy_exchange_rates_values`**
(465): `list` → lístek, `currency` (malými), `amount`, `rate` numeric 15,6
(kurz za `amount` jednotek); unikátní (`list`, `currency`). Hodnoty jsou
child tabulka lístku (`childTables`, dataKey `values`) — hlavička
a hodnoty v jedné transakci, mazání lístku maže hodnoty. Bez stavů dokladu;
obě tabulky `systemManaged` (generické CRUD POST/DELETE odmítne).

**`ExchangeRateListImporter`** je jediná cesta zápisu (CLI, seeder,
služba): `import(array $list, int $source, bool $replace = false)`
→ `ExchangeRateListImportResult`. Schéma → sémantika (známý typ pro zemi
zdroje dat, `homeCurrency` = měna typu = domácí měna zdroje dat, žádná
měna dvakrát, `validTo` ≥ `validFrom`, typ bez `maxAgeDays` vyžaduje
`validTo`) → **neznámá měna** (mimo `world.base.currencies`, např. XDR
v denním lístku ČNB) se z lístku vyřadí a vrátí v `skippedCurrencies`
(task B U1; CLI ji vypíše jako varování u souboru, seeder jako varování
sady, stav zůstává ok; lístek bez jediné známé měny je `invalid`) → zápis
přes `TableGateway`. Výsledek per lístek: nový (`list_type`, `valid_from`)
→ `created`; existující se shodným obsahem (otisk bez zdroje a id,
**počítaný až po vyřazení neznámých měn** — jinak by každé další stažení
hlásilo konflikt) → `skipped`; s jiným obsahem → `conflict`, s volbou
`replace` se hodnoty nahradí (`replaced`: hodnoty téže měny přes id
existujícího řádku, nové vložené, chybějící smazané — bez kolize
unikátního klíče); `invalid` / `failed` s chybami.

## 4. Dohledání kurzu — `ExchangeRateResolver` (D4, D5)

```php
$resolver = ExchangeRateResolver::create($dibi, $config, $dsConfig); // domácí měna z economy.homeCurrency
$rate = $resolver->rateFor('EUR', '2026-10-17');   // ?ExchangeRate: rate (za jednotku), listType, validFrom, listNumber, listId
$lookup = $resolver->lookup('EUR', '2026-10-17');  // + důvody per typ (CLI exchange-rate)
```

1. Měna = domácí měna zdroje dat → kurz 1 bez lístku (`listType` null).
2. Typy z `world.<země>.exchangeRateLists` s `homeCurrency` = domácí měna,
   v pořadí konfigurace.
3. V rámci typu se vezme **poslední lístek s `valid_from` ≤ D bez ohledu na
   měnu**. Obsahuje-li měnu, platí jeho kurz; jinak se zkusí další typ —
   nikdy starší lístek téhož typu (U2: měna vyřazená z denního lístku nesmí
   sáhnout do historie).
4. Platnost (U1): lístek s `valid_to` platí, jen když D ≤ `valid_to`; lístek
   bez něj, jen když D − `valid_from` ≤ `maxAgeDays` typu. Starý nebo
   propadlý lístek = v tomto typu nenalezeno, jde se na další typ (měsíční
   lístek je na denním nezávislý).
5. Žádný kalendář: dopoledne před vyhlášením kurzu (ČNB kolem 14:30) je
   posledním lístkem ≤ D ten předchozí — záměr (D4): tuzemská faktura
   v cizí měně uvádí DPH v Kč a po odeslání se nemění. Na dokladu žádné
   varování.
6. Nenalezeno → `null`; `lookup()` nese důvody per typ: `no_list`,
   `list_stale` (starší než `maxAgeDays`), `list_expired` (po `validTo`),
   `currency_missing`; bez typů `no_types`, špatné datum `invalid_date`.

Memoizace per instance (měna, datum) — formulář i applier se ptají
opakovaně. Datum = **účetní datum** dokladu (D5), u banky datum transakce
(D7). DB přístup přes dvě chráněné metody (`latestList`, `listValue`) kvůli
testům v paměti; testy formulářů, applieru a banky používají
`tests/Fixtures/Module/Economy/ExchangeRates/FakeExchangeRateResolver`.

## 5. Kde se kurz doplňuje (D6)

### 5.1 Formulář dokladu

`DocsHeadsFormBase::applyExchangeRate()`: měna dokladu = domácí měna →
`exchange_rate = 1`; jinak `rateFor(doc_currency, accounting_date)` (bez
účetního data datum vystavení) — nalezený kurz **přepíše i ručně zadaný**
(U3), nenalezený hodnotu nechá a pole dostane upozornění „Kurz EUR
k 16. 10. 2026 není v kurzovním lístku — zadej ho ručně.“ Upozornění
cestuje přechodným klíčem `_exchange_rate_hint` v datech (do uložení se
nedostane — `FormController` zapisuje jen sloupce tabulky; každé volání
helperu ho nejdřív smaže). `home_currency` je system sloupec, v datech
nového dokladu ani v payloadu recalculate není — domácí měna jde
z nastavení (`hasForeignCurrency()`).

Volá se na konci `applyNewRecordDefaults` (nový doklad v cizí měně od
začátku — pokladna v cizí měně nastaví měnu před `parent::`),
v `recalculate` pro `doc_currency`, `accounting_date`, `issue_date` (když
posunulo účetní datum) a v `CashDeskFormBase` pro `number_series` (měna
z pokladny). `accounting_date` má `triggers: 'reload'` ve všech hlavičkových
formulářích; element `exchange_rate` nese hint ve všech formulářích včetně
prodejky.

**Doklad v domácí měně má vždy kurz 1** (U4): `DocDocument::beforeSave`
hned za `applyHomeCurrency` (`applyHomeCurrencyRate`). Opravuje skrytou
chybu, kdy přepnutí měny EUR → CZK nechalo skrytý kurz a částky `_dom`
vyšly vynásobené; existující data se neopravují (#121 Nasazení bod 6).
Kontrola „Kurz je povinný pro cizí měnu“ ve `validate` bere domácí měnu
z dat, jinak z nastavení — vystřelí i na cestě apply (U5), kde
`home_currency` v datech není.

### 5.2 Applier (náhled i apply)

`DocumentApplier::transform()` (jediné místo pro náhled i apply): měna ≠
domácí, `exchangeRate` null a není import mód (`applyOptions.importNumber`)
→ `rateFor(měna, accountingDate ?? issueDate)`. **Kurz z canonicalu
(ISDOC, AI analýza) vždy vyhrává.** Import ze starého Shipardu nedohledává:
kurz ze starého Shipardu je fakt, ne návrh; chybějící kurz zůstane
chybějící a v cílovém stavu V pořádku ho zastaví validace dokladu.
`appendExchangeRateIssue` v `preview()` i `apply()` (U5): doplněno → `info`
`exchange_rate_from_list` („Kurz 24,325 z lístku ČNB — kurzy devizového
trhu platného od 16. 10. 2026.“), nenalezeno → `warning`
`exchange_rate_missing` („Kurz EUR k 16. 10. 2026 není v kurzovním lístku
— doklad nepůjde potvrdit bez ručního kurzu.“). Doklady generované
Shipardem (periodická fakturace přes `InvoiceBuilder`) jdou přes apply,
takže je to pokrývá. Resolver dostává applier v `create()` jen na zdroji
dat s tabulkou lístků. Ve výměnném formátu viz `docs/exchange-format.md`
(`exchangeRate`).

### 5.3 Banka (D7)

`docs/bank.md` §4.6: `StatementImportService::resolveExchangeRates` před
transakcí pro celý výpis — účet v domácí měně 1 (kurz z výpisu se
ignoruje), cizí měna z výpisu (migrace) nebo z lístku k datu transakce;
chybí-li lístek, `ImportException` se seznamem dat a nic se nezapíše.
`BankTransactionDocument` odmítne cizí měnu bez kurzu, u domácí vynutí 1
a `amount_dom` přepočítá, když chybí nebo se kurz změnil (ruční oprava ve
formuláři `BankTransactionForm` — pole Kurz jen u cizí měny, stavy Nová
a V opravě).

## 6. CLI (`docs/cli.md`)

- `shpd-ds exchange-rates-import <soubor|složka> [--replace]` — soubor
  `.jsonc` / `.json` nebo složka (setříděno podle jména), každý přes
  importer se zdrojem *soubor*; souhrn založeno / beze změny / nahrazeno /
  selhalo, nenulový exit při jakémkoli selhání.
- `shpd-ds exchange-rate <měna> [<datum>]` — výsledek resolveru (kurz za
  jednotku, typ, platnost od, číslo lístku) nebo „nenalezen“ s důvody per
  typ. Ověření a podpora.
- `shpd-ds exchange-rates-download [--from] [--to] [--type] [--dry-run]`
  — stažení chybějících lístků ze služby (§9): bez voleb běh jako z cronu,
  `--from` / `--to` ruční okno bez limitu a pauzy, `--dry-run` jen plán.

Všechny vyžadují tabulku lístků (`ds-upgrade`); síť volá jen
`exchange-rates-download`.

## 7. Datové sady (D10)

`docs/datasets.md` §1–§4: sekce `exchangeRates/NNNN-<listType>-<validFrom>.jsonc`
ve formátu §2, klíč `exchangeRates` v `counts`, schéma v preflightu.
`ExchangeRatesSeeder` (za fiskálními roky, před doklady a zprávami) přes
importer se zdrojem *datová sada*: `created` i `skipped` = ok, `conflict`
= chyba (seed nepřepisuje). Dump (`ExchangeRatesExporter`) vypíše právě ty
lístky, které resolver použije pro účetní data dokladů a návrhů z analýz
zpráv v cizí měně (U7); sada bez cizí měny sekci nemá; dvojice bez lístku
= varování. Kurzy v sadách `tests/datasets/` a zrušení přeskočení dokladů
v cizí měně v `dataset-verify` (#103 D30) jsou samostatný task (U6).

## 8. Nastavení — Kurzovní lístky (§9)

`settingsItems` sekce `accounting`: viewer `economy.exchangeRates.lists`
(`ExchangeRateListsViewer`) jen ke čtení — bez přidání, úprav a mazání.
Řádek: typ, platnost od (do), číslo, počet měn, zdroj; filtr podle typu;
řazení podle `valid_from` sestupně; hledání podle čísla lístku. Detail:
Přehled + tab Kurzy (měna, množství, kurz, kurz za jednotku). Ikona
`exchange-rates`.

## 9. Stahování ze služby (task B — D2, D3, D9, U2–U7)

**Služba** (D2): vlastní feed, ne přímo ČNB —
`GET <serviceUrl>?dateFrom=YYYY-MM-DD&dateTo=YYYY-MM-DD&listType=1|2`,
vždy HTTP 200 a JSON `{"valid": 1, "data": [lístky §2]}`, bez lístku
`{"valid": 0}`, chybné parametry `{"valid": 0, "error": "…"}`. Adresa =
`exchangeRates.serviceUrl` v `server.json`
(`ServerConfig::getExchangeRatesServiceUrl`, U3): klíč chybí →
`https://data.shipard.app/feed/money-exchange-rates`, prázdný řetězec →
`null` = stahování vypnuté, jiná hodnota musí být http(s). Vývojářské
servery stahují taky. Každý zdroj dat stahuje sám (žádná sdílená cache).

**Třídy** (`modules/economy/exchangeRates/src/Download/`):

- `ExchangeRateServiceClient` nad rozhraním `ExchangeRateServiceTransport`
  (produkce `CurlExchangeRateServiceTransport`: timeout 15 s, připojení
  5 s, bez přesměrování, jen http(s); testy
  `tests/Fixtures/Module/Economy/ExchangeRates/Service/` s uloženými
  odpověďmi, U7) → `ExchangeRateServiceResponse` `lists` / `none` /
  `error` (síť, HTTP ≠ 200, nevalidní JSON, `valid: 0` s `error`, bez
  `data`; do logu jde zpráva, nikdy tělo).
- `DownloadWindow::start(dnes, fiskální roky)` — začátek okna = začátek
  předchozího fiskálního roku: rok končící den před začátkem roku
  s dneškem → jeho `date_begin`; jinak `date_begin` roku s dneškem minus
  rok; bez fiskálních let (tabulka `economy_codebooks_fiscal_years` nemusí
  existovat — `FiscalYears::load`) nebo bez roku s dneškem 1. 1.
  předchozího kalendářního roku. Konec = dnešek v Europe/Prague.
- `DownloadSchedule::expectedDates` — co typ v okně očekává: `workingDays`
  = pracovní dny kalendáře po dnešek typu (datum v jeho `timezone`),
  dnešek jen po `publishAfter`; `monthly` = 1. den každého měsíce od
  měsíce začátku okna (i když okno začíná uprostřed měsíce). Sdílí ho
  stahovač i upozornění, aby se nerozešly v tom, co je pracovní den.
- `ExchangeRateListPresence` — přítomná data typu jedním dotazem přes
  rozsah očekávaných dat; `hasAny` pro upozornění.
- `DownloadState` — stav pokusů v nastavení zdroje dat
  `economy.exchangeRates.downloadState`:
  `{"lastError": <unix>|null, "attempts": {"<typ>|<YYYY-MM>": <unix>}}`.
  Jen cron mód; zápis jen po odeslaném dotazu; měsíce mimo okno se při
  zápisu zahodí (jen podle měsíce, nikdy podle typu).
- `ExchangeRateDownloader::plan()` / `run()` nad `DownloadOptions` →
  `DownloadPlan` (okno, per typ `TypeDownloadPlan`, `DownloadQuery`,
  varování konfigurace) / `DownloadReport`.

**Běh** (U4, `ExchangeRateDownloader`): typy = `forHomeCurrency(domácí
měna)` s platným `download` (typ bez `download` tiše, neplatný nebo
neznámý kalendář = varování). Chybějící = očekávané − přítomné; **nic
nechybí → žádná síť, žádný zápis, žádný log** (úloha běží každých 5 minut
na každém zdroji dat). Chybějící data se seskupí po kalendářních měsících
per typ, dotaz = první..poslední chybějící datum měsíce, pořadí měsíc
sestupně → pořadí typů (běžný měsíc je nejdůležitější). Cron mód (bez
`--from` / `--to`): nejvýš **6 dotazů** za běh; měsíc s pokusem mladším
než hodinu se přeskočí (`attempts`); jen **dnešní** chybějící lístek
denního typu po `publishAfter` se zkouší každý běh samostatným dotazem
`dnes..dnes` bez zápisu pokusu (odpověď `valid: 0` je pár bajtů; měsíční
typ výjimku nemá); **chyba služby nebo sítě** (`lastError`) ukončí běh
a pozastaví celý následující běh na hodinu — jinak by výjimka pro dnešek
bušila do nedostupné služby každých 5 minut. Ruční mód (`--from` / `--to`)
bez limitu, bez pauzy, stav nečte ani nepíše; `--type` sám o sobě zůstává
v cron módu a dotýká se jen pokusů svého typu.

**Zápis** (§4.4): lístek k datu, které typ **už má, se ignoruje bez
porovnání** (`ignored`; dotaz na měsíc vrací i přítomné dny — ruční
lístky s jinými hodnotami nesmí každou hodinu hlásit konflikt a shazovat
úlohu; změnu obsahu lístku ve službě tedy úloha nezjistí); lístek
s budoucím datem se přeskočí s jedním varováním; ostatní vrácené lístky
jdou do importeru se zdrojem *služba* (3), i mimo očekávaná data (svátek,
který služba má). `created` / `skipped` ok, `conflict` (jen souběh dvou
běhů) → varování, nepřepisuje se; `failed` se jednou opakuje (souběh na
unikátním klíči), pak varování; přeskočené měny (U1) do logu jen u nově
založeného lístku. Stahovač sám neloguje — varování, poznámky a chybu
nese `DownloadReport`, do `ErrorLogger` je píše CLI, a jen v běhu, který
poslal dotaz.

**CLI** `shpd-ds exchange-rates-download [--from] [--to] [--type]
[--dry-run]` (U5, `docs/cli.md`): modul neaktivní nebo stahování vypnuté
= zpráva a exit 0 (cron); `--dry-run` vypíše okno, per typ očekáváno /
přítomno / chybí, varování a dotazy s pauzou / limitem — bez sítě
a zápisu; exit 1 při chybě služby, konfliktu nebo selhání zápisu; INVALID
při špatném datu (`--to` nejvýš dnešek, `--from` ≤ `--to`) nebo neznámém
`--type`. **Cron**: `CronCommand::SLOT_JOBS['five-minutes']` =
`exchange-rates-download` (před `alerts-run`, aby čerstvý zdroj dat
nedostal nález dřív, než poprvé stáhne), stav `active`, modul
`economy.exchangeRates`; přepínač cronu a stav zdroje dat řeší dispatcher.

**Upozornění `economy.exchangerates.list_missing`** (D9, U6; warning,
1 h, sekce Účetnictví, `Checks\ListMissingCheck`): denní typ bez lístku za
pracovní den v [dnes − 30 dní, včera] (Europe/Prague), ne před začátkem
okna → jeden nález na typ s počtem a nejvýš 5 nejnovějšími daty („Lístek
chybí za 3 pracovní dny (19. 10., 16. 10., 15. 10.). K těmto datům se kurz
nedoplní.“); měsíční typ od 2. dne měsíce bez lístku s `valid_from` = 1.
den. Dnešek se nehlídá (stahovač ho ještě zkouší). Běží i se stahováním
vypnutým, ale **jen u typu, který už nějaký lístek má** — zdroj dat bez
kurzů by jinak nesl trvalé varování. Akce otevře viewer Kurzovní lístky.
Země zdroje dat pro typy: `AlertCheck` dostává volitelný čtvrtý parametr
`?DataSourceConfig` (`docs/alerts.md` §4).

Kontrola kurzů na dokladech (D8) je mimo #121 → #130; celní kurz a ECB
a měny, které služba vynechává, jsou mimo task B.

## 10. Pasti

- `exchange_rate` má 6 desetinných míst — kurz za jednotku se zaokrouhluje
  stejně (`ExchangeRate::perUnit`), jinak by dump → seed → dump nebyl
  shodný.
- Měny: formát velkými, databáze malými.
- Import mód nikdy nedohledává; mimo import mód kurz z canonicalu vždy
  vyhrává.
- `triggers: 'reload'` na účetním datu přidává roundtrip jako u data
  vystavení — po reloadu drží aktivní tab, fokus se obnovuje stejně jako
  u ostatních reload polí.
- Resolver bez typů (jiná země, konfigurace před `ds-upgrade`) tiše
  nenachází — CLI `exchange-rate` to vypíše jako důvod `no_types`.
- Otisk obsahu lístku až po vyřazení neznámých měn (§3) — jinak konflikt
  při každém stažení.
- Čas vyhlášení i „dnešek“ se počítají v Europe/Prague (zóna typu), ne
  v zóně serveru (UTC): nikde `date('Y-m-d')`.
- Kalendář se může se službou rozejít (pracovní den bez lístku): měsíc se
  zkouší každou hodinu a upozornění to drží viditelné — bez pauzy by úloha
  zkoušela každých 5 minut donekonečna.
- Lístek k přítomnému datu stahovač neimportuje — konflikt vzniká jen při
  souběhu; `--replace` má jen `exchange-rates-import`.
- Stahovač nikdy nepřepisuje (lístek mohly použít doklady) a neloguje sám
  — bez odeslaného dotazu nesmí vzniknout žádný záznam v logu.
