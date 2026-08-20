# 27 — Export účetní historie (booking history) pro nový Shipard

**Stav:** design potvrzen, k implementaci

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

- Doklady: `e10doc_core_heads`, `docType = 'invni'` (přijaté faktury);
  stavový filtr = stejná množina jako bere `DocsRunner` pro import
  (vyřazené/zrušené doklady ne — převzít existující podmínku, nevymýšlet
  novou). Volitelné omezení účetním obdobím (`--from`, `--to` dle
  účetního data dokladu).
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

- [ ] Subcommand existuje, usage aktualizované.
- [ ] Export na pilotním DS doběhne, soubor projde validátorem nové
      strany bez chyb.
- [ ] Kontrolní SQL sedí (počty, sumy, distinct doklady).
- [ ] Degenerované texty v souboru zůstávají; companyId bez IČO → null.
- [ ] Export nic nezapisuje do DB starého DS.
