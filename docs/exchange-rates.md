# Kurzy cizích měn — kurzovní lístky a dohledání kurzu

Modul `economy.exchangeRates` (#121, task A — `tasks/exchange-rates-core.md`):
kurzovní lístky země zdroje dat v tabulkách, kurz k datu pro doklady,
applier a bankovní výpisy, nahrání lístků ze souboru a z datové sady. Task B
(#121 D2, D3, D9) doplní stahování ze služby přes periodické úlohy,
doplnění historie a upozornění na chybějící lístek — tento dokument u toho
říká, co z něj už platí.

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

## 2. Formát `shpd.exchangeRates.list.v1` (D2)

Schéma `modules/core/exchange/schemas/shpd.exchangeRates.list.v1.jsonc`
(+ `.json`, drift hlídá `SchemaDriftTest`). Jeden soubor = jeden lístek;
stejný formát čte CLI (§6), datové sady (§7) a v tasku B ho bude vracet
služba.

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

**`ExchangeRateListImporter`** je jediná cesta zápisu (CLI, seeder, v tasku
B služba): `import(array $list, int $source, bool $replace = false)`
→ `ExchangeRateListImportResult`. Schéma → sémantika (známý typ pro zemi
zdroje dat, `homeCurrency` = měna typu = domácí měna zdroje dat, měny
z `world.base.currencies`, žádná měna dvakrát, `validTo` ≥ `validFrom`,
typ bez `maxAgeDays` vyžaduje `validTo`) → zápis přes `TableGateway`.
Výsledek per lístek: nový (`list_type`, `valid_from`) → `created`;
existující se shodným obsahem (otisk bez zdroje a id) → `skipped`; s jiným
obsahem → `conflict`, s volbou `replace` se hodnoty nahradí (`replaced`:
hodnoty téže měny přes id existujícího řádku, nové vložené, chybějící
smazané — bez kolize unikátního klíče); `invalid` / `failed` s chybami.

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

Oba vyžadují tabulku lístků (`ds-upgrade`). Žádné volání sítě.

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

## 9. Co přinese task B (D2, D3, D9)

Stahování lístků ze služby (adresa v `server.json`) v periodických úlohách
(slot `five-minutes`, jen pracovní den po 14:35 Europe/Prague, dokud lístek
pro ten den chybí), doplnění chybějících lístků od začátku předchozího
fiskálního roku s omezeným počtem dotazů, upozornění, když lístek za
pracovní den nepřibude ani další den. Zapisuje přes týž importer se zdrojem
*služba* (3). Kontrola kurzů na dokladech (D8) je mimo #121 → #130.

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
