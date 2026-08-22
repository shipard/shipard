# 27 — Export účetní historie (booking history) pro nový Shipard

**Stav:** implementováno (ověřeno na pilotních DS)

**Cíl:** Nový subcommand `export-booking-history` v `imports.newShipard`,
který ze starého DS vyexportuje agregovanou účetní historii přijatých
faktur do souboru formátu **`shpd.economy.booking-history.v1`** (JSONL).
Soubor pak zpracuje nový Shipard (`shpd-ds booking-history` — report
kvality/taxonomie, seed pravidel IČO→štítek, reverzní otagování položek).

**Kanonická specifikace formátu:** nový Shipard,
`tasks/booking-history-import.md` → materializuje se do
`docs/booking-history-format.md`. Tento task obsahuje jen pracovní kopii
příkladů; při rozporu platí nová strana.

---

## Principy

- **Export = jen fakta.** Texty, účty, IČO, četnosti, částky, data.
  Žádné štítky, žádná znalost taxonomie nového Shipardu — přepočet novou
  verzí taxonomie nesmí vyžadovat nový export.
- **Degenerované texty se nefiltrují** (prázdné, shodné s názvem
  položky…) — jejich podíl je na nové straně metrika kvality zdroje.
- Export je **read-only** vůči starému DS.

## Výběr dat

- Doklady: `e10doc_core_heads`, `docType = 'invni'` (přijaté faktury),
  `docState = 4000` — **jen doklady ve stavu „Hotovo"** (= „V pořádku",
  zaúčtované). Záměrně jiný filtr než `DocsRunner`, který bere
  `docState != 9800`: koncepty (1000), potvrzené (1200), rozpracované
  v opravě (8000) ani storna (4100) účetní historii nereprezentují —
  zaúčtovaná fakta jsou jen ve 4000. Volitelné omezení účetním obdobím
  (`--from`, `--to` dle účetního data dokladu).
- Řádky: `e10doc_core_rows`, `operation = 1099998` (Účetní položka),
  `item IS NOT NULL`. Ostatní operace (zálohy, zaokrouhlení, DPH…) jsou
  balast a do exportu nejdou.
- Položka: `e10_witems_items` — `id` (itemCode), `fullName` (itemName),
  **`debsAccountId`** (extension e10doc/core) = číslo účtu jako string →
  pole `account`. Číslo se exportuje tak, jak je (žádný LocalIdMap,
  žádné nové ndx — exportujeme fakta, ne mapování).
- Dodavatel: osoba dokladu (`e10doc_core_heads.person` →
  `e10_persons_persons`), IČO stejným polem/logikou, jakou používá
  `PersonsRunner` pro `company_id`; normalizace: jen číslice, bez mezer;
  prázdné → `companyId: null` (záznam zůstává, jen bez seed využití).

## Agregace

Klíč: `{companyId, account, itemCode, rowTextNorm}` kde `rowTextNorm` =
text řádku dokladu po lehké normalizaci (trim, collapse whitespace,
lowercase). Agregát nese: `docCount` (distinct dokladů), `rowCount`,
`totalAmount` (suma základů řádků v domácí měně — použít tentýž sloupec,
z něhož `DocsRunner` čte domácí částky řádků; pokud pro řádek není
k dispozici, do sumy nejde a záznam smí mít `totalAmount: null` jen
když nejde spočíst nic), `firstDate`/`lastDate` (účetní datum dokladu),
`rowText` = **nejčetnější originální varianta** textu v klíči.

Agregovat v PHP průchodem (streamovaně, mapa v paměti — počty distinct
klíčů budou řádově tisíce, ne miliony; distinct doklady per klíč počítat
množinou ndx).

## Výstup

JSONL soubor, cesta `--out=<file>` (default
`booking-history-<dsid>.jsonl` v cwd). Řádek 1 hlavička:

```jsonc
{ "format": "shpd.economy.booking-history", "version": 1,
  "sourceSystem": { "name": "shipard-e10", "version": "<verze e10>" },
  "sourceRef": "<dsid / název firmy z appOptions>",
  "chartVariant": "unknown",       // e10 variantu osnovy nezná → unknown
  "currency": "CZK",
  "period": { "from": ..., "to": ... },   // dle --from/--to, jinak min/max z dat
  "docTypes": ["invni"],
  "exportedAt": "...", "recordCount": N }
```

Řádky 2+ — záznamy dle specifikace (companyId, account, itemCode,
itemName, rowText, docCount, rowCount, totalAmount, firstDate, lastDate).

## CLI

```
shpd-app cli-action --action=imports.newShipard/import export-booking-history \
    [--out=cesta.jsonl] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]
```

- Registrace v `ImportApp::dispatch` + nápověda v usage.
- Nová třída `libs/BookingHistoryExporter.php` (vzor runnerů — db()
  přístup, ale bez HTTP klienta a bez LocalIdMap; nejde o import runner,
  jen o lokální export; nepoužívat `ImportRunner` mašinerii, pokud tahá
  HTTP config — stačí DB + Logger).
- Průběžný výpis: počet dokladů/řádků/klíčů, na konci souhrn + cesta.
- Chování bez configu `import-newShipard.json`: export HTTP config
  nepotřebuje — nesmí na jeho absenci spadnout (ověřit, jak ImportApp
  config čte; případně export inicializovat mimo tuto větev).

## Budoucí rozšíření (mimo scope, nechat připravené)

- `--doc-types` pro výdajové pokladní lístky (a jim odpovídající filtr
  operací) — v1 napevno `invni`.
- Zavěšení exportu do `all` sekvence importu (aby migrace DS rovnou
  produkovala i historii) — až se ověří workflow na pilotních DS.

## Testy / ověření

Jednotkové testy dle zvyklostí modulu (pokud modul testy nemá, postačí
ověřovací běh): na reálném DS zkontrolovat (1) počet záznamů odpovídá
`SELECT COUNT(DISTINCT ...)` kontrolnímu dotazu, (2) namátkou 3 klíče
proti ručnímu SQL (docCount, rowCount, suma), (3) hlavička validní podle
specifikace, (4) soubor projde validátorem nové strany
(`shpd-ds booking-history --input=...` bez režimu).

## Hotovo když

- [x] Subcommand existuje, usage aktualizované.
- [x] Export na pilotním DS doběhne, soubor projde validátorem nové
      strany bez chyb.
- [x] Kontrolní SQL sedí (počty, sumy, distinct doklady).
- [x] Degenerované texty v souboru zůstávají; companyId bez IČO → null.
- [x] Export nic nezapisuje do DB starého DS.

## Implementační poznámky (co se při realizaci rozhodlo)

- **Zdroj částky:** `e10doc_core_rows.taxBaseHc` („Základ daně [MD]") —
  tentýž sloupec, z něhož domácí částky řádků čte `DocsRunner`
  (`resolveAssetAccount`). `NULL` do sumy nejde; `totalAmount: null` vznikne
  jen když v klíči není ani jedna nenullová částka.
- **`item IS NOT NULL` → `r.[item] > 0`:** v e10 je nevyplněný int FK `0`,
  ne `NULL`, takže samotné `IS NOT NULL` by balast nevyfiltrovalo.
- **Datum a dodavatel z HLAVIČKY**, ne z řádku — řádek má vlastní
  `dateAccounting`/`person`, ale ty nesou analytiku řádku, ne identitu dokladu.
- **IČO:** `e10_base_properties` (`tableid='e10.persons.persons'`,
  `property='oid'`, `valueString`, první po `ndx`) — shodně s
  `PersonsRunner::loadProperties()`. Jeden bulk dotaz do mapy, ne dotaz per
  osoba. Normalizace na číslice, prázdné → `companyId: null`.
- **Měna hlavičky se odvozuje z dat** (histogram `heads.homeCurrency` nad
  vybranými doklady, nejčetnější + warn při víc variantách), ne z nastavení;
  fallback fiskální rok (jako `SettingsRunner`) → `CZK`.
- **Souhrn hlásí i doklady mimo výběr** (`invni` v období, které nejsou ve
  stavu 4000 ani smazané) — ať je vidět, kolik historie chybí, ještě než
  někdo pustí seed pravidel naostro.
- **Výkon — keyset přes DOKLADY, ne přes řádky.** První verze měla jeden
  JOIN s keysetem `ORDER BY r.[ndx] LIMIT`; optimizer ale řídí join od
  hlavičky a řazení řeší `Using temporary; Using filesort`, takže každá
  dávka přepočítala celý join znovu (kvadraticky). Na DS s 78 tis. doklady
  běh za 10 minut nedojel ani k první tisícovce řádků. Řešení: dávka
  hlaviček keysetem po PK + druhý dotaz `r.[document] IN (…)` (index
  `s1 (document, text)`). Stejný výstup, tentýž DS 5 s.
- **Zápis přes `<out>.tmp` + `rename()`** — přerušený běh nezůstane jako
  zdánlivě platný vstup pro `shpd-ds booking-history`.
- **Bez configu:** subkomanda se v `ImportApp::run()` odbočuje ještě před
  `ImportConfig::load()` (vlastní `runBookingHistoryExport()` s Loggerem
  do `log/export-booking-history-<ts>.log`), takže nezakládá ani
  `import-newShipard.sqlite`. Ověřeno během s přejmenovaným configem.

### Ověření (2026-08-20)

- DS `2059760940246` (475 faktur): 531 řádků → 134 záznamů. Nezávislá
  agregace (Python nad raw dumpem) se shoduje ve všech polích všech 134
  klíčů; `SUM(taxBaseHc)` = 1 909 901,12 a 446 distinct dokladů sedí
  s kontrolním SQL. `--from=2024-01-01 --to=2024-12-31` → 58 řádků / 48
  dokladů, taktéž shodné s SQL.
- DS `68908901448295` (MSI, 78 tis. dokladů): 9 958 řádků z 8 351 dokladů
  → 3 559 záznamů za 5,2 s; 14 dokladů mimo stav 4000 vykázáno.
- Oba soubory projdou čtečkou nové strany (`BookingHistoryFile::open()` +
  `BookingHistoryRecord::fromArray()` nad každým řádkem) bez chyby;
  `recordCount` v hlavičce odpovídá počtu záznamů. Degenerace textů
  zůstávají v souboru (dev DS 5, MSI 29 záznamů `itemName`).
  `shpd-ds booking-history` samotné se v tomto prostředí spustit nedá
  (nová strana tu nemá nainstalované `vendor/`), proto ověření přes její
  parsovací třídy.
