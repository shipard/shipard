# Shipard — Formát účetní historie `shpd.economy.booking-history.v1`

> Kanonická specifikace souboru s **agregovanou účetní historií** ze
> zdrojového systému (starý Shipard, cizí ERP, tabulkový export).
> Zpracování na straně nového Shipardu: `shpd-ds booking-history`
> (viz [cli.md](cli.md)), zadání `tasks/booking-history-import.md`.
>
> Protistrana (exportér ze starého Shipardu):
> `old_shipard: modules/imports/newShipard/tasks/27-booking-history-export.md`.
> **Vlastníkem specifikace je tento dokument** — exportéry ho citují, ne
> naopak.

## 1. Účel

Soubor nese **fakta o tom, co a na jaké účty firma historicky účtovala**:
text řádku dokladu, název a kód položky, číslo účtu, IČO dodavatele a
četnosti. Nad ním nový Shipard umí:

1. **report o kvalitě zdroje a o taxonomii** obsahových štítků (pokrytí,
   konzistence, mrtvé štítky),
2. **seed pravidel `IČO → obsahový štítek`** (`core_exchange_tag_rules`,
   origin `seed`) — kolektivní znalost, která novému DS ušetří LLM volání,
3. **reverzní otagování** existujících položek DS podle účtů.

Zdrojová strana proto exportuje **jen fakta — žádné štítky a žádnou
znalost taxonomie**. Přepočet novou verzí taxonomie nebo novým modelem
nevyžaduje nový export; stačí znovu spustit zpracování nad týmž souborem.

## 2. Fyzický tvar

- **JSONL**, UTF-8 bez BOM, LF konce řádků, jeden JSON objekt na řádek.
- **Řádek 1 = hlavička**, řádky 2+ = agregované záznamy.
- Prázdné řádky se ignorují. Komentáře nejsou dovolené (není to JSONC).
- Přípona `.jsonl`. Soubor smí být libovolně velký — čtečka streamuje.

Vedle vstupu vznikají při zpracování dva **odvozené** soubory (nejsou
součástí formátu, exportér je neposílá):

| Soubor | Obsah |
|--------|-------|
| `<input>.tags.jsonl` | cache LLM klasifikace textů — `{rowTextNorm, tag, promptVersion}` |
| `<input>.report.md` | výstup `--report` (default cesta, přebíjí `--report-out`) |

## 3. Hlavička (řádek 1)

```jsonc
{
  "format": "shpd.economy.booking-history",
  "version": 1,
  "sourceSystem": { "name": "shipard-e10", "version": "..." },
  "sourceRef": "<identifikace zdrojového DS/firmy — volný string>",
  "chartVariant": "default",       // "default" | "npo" | "unknown"
  "currency": "CZK",               // měna částek (domácí měna zdroje)
  "period": { "from": "2019-01-01", "to": "2026-06-30" },
  "docTypes": ["invni"],
  "exportedAt": "2026-08-20T10:00:00+02:00",
  "recordCount": 12345
}
```

| Pole | Povinné | Význam |
|------|---------|--------|
| `format` | ano | vždy `shpd.economy.booking-history` |
| `version` | ano | celé číslo; zpracování v1 přijímá jen `1` |
| `sourceSystem` | ne | `{name, version}` — volný identifikátor zdroje, jde do reportu |
| `sourceRef` | ne | identifikace zdrojového DS / firmy, jde do reportu |
| `chartVariant` | ne | varianta účtové osnovy zdroje; default `unknown` |
| `currency` | ne | měna `totalAmount`; default `CZK` |
| `period` | ne | `{from, to}` účetních dat; celé i jednotlivá pole smí být `null` |
| `docTypes` | ne | typy dokladů zahrnuté v exportu (informativní) |
| `exportedAt` | ne | čas exportu (ISO 8601) |
| `recordCount` | ne | počet záznamů; zpracování ho **porovná se skutečností** a rozdíl vykáže v reportu (není to chyba souboru) |

`chartVariant` řídí reverzní mapování účet → štítek:

- `default` — podnikatelská osnova (`accountingItemsDefault.jsonc`),
- `npo` — nezisková osnova (`accountingItemsNpo.jsonc`),
- `unknown` — zpracování použije **podnikatelskou** nabídku a poznamená to
  v reportu; čísla obou osnov se překrývají, takže výsledek je nutné brát
  jako odhad.

## 4. Záznam (řádky 2+)

```jsonc
{
  "companyId": "26378191",
  "account": "518202",
  "itemCode": "518202",
  "itemName": "Internetové služby",
  "rowText": "Měsíční paušál za internet 100/10",
  "docCount": 74,
  "rowCount": 96,
  "totalAmount": 366300.00,
  "firstDate": "2019-02-11",
  "lastDate": "2026-06-05"
}
```

| Pole | Typ | Význam |
|------|-----|--------|
| `companyId` | `string\|null` | IČO dodavatele, normalizované (bez mezer a oddělovačů). `null` = neznámé |
| `account` | `string\|null` | číslo účtu z položky, **jako string** (vedoucí nuly, analytiky). `null` = položka bez účtu |
| `itemCode` | `string\|null` | kód položky ve zdroji |
| `itemName` | `string\|null` | název položky — účetní kategorizace, součást reverzního signálu |
| `rowText` | `string\|null` | text řádku dokladu — **obsah, vstup LLM klasifikace** |
| `docCount` | `int` | počet dokladů v agregaci (≥ 0) |
| `rowCount` | `int` | počet řádků v agregaci (≥ 0) |
| `totalAmount` | `number\|null` | suma základů v domácí měně; `null` když to zdroj neumí |
| `firstDate` | `string\|null` | účetní datum prvního výskytu (`YYYY-MM-DD`) |
| `lastDate` | `string\|null` | účetní datum posledního výskytu |

### Agregační klíč

Zdroj agreguje po čtveřici **`{companyId, account, itemCode, rowTextNorm}`**
(D30). Do `rowText` jde **nejčetnější originální varianta** textu z dané
skupiny — ne normalizovaná podoba, aby report i LLM viděly, jak text
skutečně vypadá.

`rowTextNorm` je normalizace pro klíčování, kterou si obě strany počítají
stejně a **do souboru se neposílá**:

1. trim,
2. collapse whitespace (každá sekvence bílých znaků → jedna mezera),
3. lowercase (`mb_strtolower`, UTF-8).

Žádné odstraňování diakritiky, interpunkce ani čísel — normalizace je
záměrně lehká, aby nesléval texty, které se účtují jinak.

### Nullová pravidla

- `companyId: null` je **legální** — záznam se nepoužije pro seed pravidel,
  vstupuje jen do reportu a metrik kvality.
- `account: null` je **legální** — bez účtu není reverzní štítek; záznam
  vstupuje do metrik kvality.
- `rowText: null` nebo prázdný text je legální — je to *degenerovaný* text
  (viz níže), do LLM klasifikace nejde.

### Degenerované texty

Detekuje **zpracování, ne export** (D33). Text je degenerovaný, když:

| Druh | Podmínka (nad `rowTextNorm`) |
|------|------------------------------|
| `empty` | prázdný / jen bílé znaky / `null` |
| `itemName` | shodný s `itemName` |
| `account` | shodný s `account` |

Degenerované texty nenesou informaci o obsahu, takže se neklasifikují LLM
a jejich podíl je **metrika kvality zdroje** (celkem i per účet). Zdroj
tedy nemá texty filtrovat ani „vylepšovat" — pošle je, jak jsou.

## 5. Kompatibilita

- **Neznámá pole se ignorují** — hlavička i záznamy smí nést pole, která
  zpracování nezná (dopředná kompatibilita).
- Chybějící volitelná pole = `null` / default.
- `version` se zvýší **jen při nekompatibilní změně** (jiný význam
  existujícího pole, nová povinnost). Přidání volitelného pole verzi
  nemění.
- Zpracování v1 odmítne soubor s jiným `format` nebo `version != 1`
  s chybou uvádějící číslo řádku.

## 6. Minimální validní soubor

```jsonl
{"format":"shpd.economy.booking-history","version":1}
{"companyId":"26378191","account":"518202","rowText":"Paušál internet","docCount":3,"rowCount":3}
```

Soubor jen s hlavičkou (nula záznamů) je také validní — zpracování vypíše
souhrn s nulovými počty.

## 7. Zpracování — `shpd-ds booking-history`

Spouští se **z adresáře datového zdroje** (jako všechny `shpd-ds` příkazy).
Bez režimu jen zvaliduje soubor a vypíše souhrn hlavičky; režimy jsou
kombinovatelné.

```bash
cd /opt/shipard/data-sources/<id>

# validace + souhrn hlavičky
sudo shpd-ds booking-history --input=/tmp/history.jsonl

# report (LLM klasifikace + reverz); druhý běh už LLM nevolá — cache
sudo shpd-ds booking-history --input=/tmp/history.jsonl --report
sudo shpd-ds booking-history --input=/tmp/history.jsonl --report --no-llm

# seed pravidel IČO → štítek: nejdřív plán, pak ostrý běh
sudo shpd-ds booking-history --input=/tmp/history.jsonl --apply-seed --dry-run
sudo shpd-ds booking-history --input=/tmp/history.jsonl --apply-seed

# reverzní otagování živých položek DS podle účtů
sudo shpd-ds booking-history --input=/tmp/history.jsonl --tag-items --dry-run
```

| Opce | Význam |
|------|--------|
| `--input <soubor>` | **povinné** — cesta k JSONL souboru |
| `--report` | markdown report (viz níže) + souhrn na stdout |
| `--report-out <soubor>` | cesta reportu (default `<input>.report.md`) |
| `--apply-seed` | zápis pravidel `IČO → štítek` do `core_exchange_tag_rules` |
| `--tag-items[=režim]` | otagování živých položek DS — `offer` / `usage` / `auto` (default `auto`) |
| `--dry-run` | jen plán, žádný zápis (platí pro oba zápisové režimy) |
| `--backend <id\|název>` | AI backend pro klasifikaci |
| `--no-llm` | bez LLM — report jen z reverzu účet→štítek |
| `--seed-min-share <0–1>` | seed: min. podíl řádků dominantního štítku (default 0.8) |
| `--seed-min-docs <N>` | seed: min. počet dokladů dominantního štítku (default 3) |
| `--seed-min-coverage <0–1>` | seed: min. pokrytí řádků IČO reverzem (default 0.5) |
| `--usage-min-share <0–1>` | usage: min. podíl dominantního štítku položky (default 0.7) |
| `--usage-min-rows <N>` | usage: min. řádků dominantního štítku (default 5) |

### Report

Sekce: kvalita zdroje (degenerace textů celkem i per účet, chybějící
IČO/účet, objemné účty), pokrytí taxonomie (zásahy reverzu, objemné účty
bez štítku, texty bez štítku od LLM), konzistence LLM × reverz (matice,
štítky s neshodou nad 30 %, rozptyl účtů per štítek), mrtvé štítky a náhled
seedu včetně plánu zápisu per IČO.

Bez dostupné LLM klasifikace (`--no-llm`, chybějící backend nebo klíč)
report **degraduje** na reverzní pohled — nespadne.

### Klasifikace a její cena

LLM se volá jen nad **distinct obsahonosnými** texty, v dávkách po ~50, a
výsledky se cachují do `<input>.tags.jsonl`. Opakovaný report nad týmž
souborem je proto zadarmo. Padlá dávka běh neshodí a do cache nic nezapíše
— příště se zkusí znovu.

Model, připojení a ladění určuje AI funkce `exchange.bookingHistory`
(Nastavení → AI → Funkce; `docs/ai.md` §5.3). Volby příkazu ji
přepisují: `--backend <id|název>` jiné připojení, `--model <ID>` jiný
model (z katalogu, nebo vlastní ID s `--model-like <ID z katalogu>`).
Doporučení stejné jako u analýzy dokladů — levný model, klasifikace
krátkého textu je triviální úloha.

### Reverz na neznámé osnově — kontrola názvů

Při `chartVariant: unknown` je přesná shoda čísla účtu slabší signál, než
vypadá: analytiky si každý systém vede po svém. Přesná shoda se proto
přijme jen tehdy, když se **název položky** ze záznamu podobá názvu
položky nabídky (bez diakritiky, tolerantně ke skloňování a pořadí slov).
Neshoda nebo chybějící název → záznam se počítá dál, jako by přesná shoda
nebyla (spadne na hrubší syntetickou úroveň). Report vykáže, kolik shod
kontrola zamítla a na kterých účtech.

Motivace z pilotu: zdroj vedl pod `518201`–`518203` finanční leasing, kde
nabídka má telefon / internet / poštovné — bez kontroly z toho vznikly
falešné štítky. U deklarované osnovy (`default`/`npo`) se kontrola
nespouští.

### Seed pravidel (prahy a přednost)

Kandidátem je IČO, u kterého dominantní reverzní štítek drží **≥ 80 %
řádků** (mezi řádky s rozřešeným štítkem), má **≥ 3 doklady** a **pokrytí
≥ 50 %** — tedy reverz dal štítek aspoň polovině řádků toho dodavatele.
Bez prahu pokrytí vznikala pravidla z malého výseku historie („100%
dominance" mezi třemi řádky z dvaceti). Remíza dominance kandidáta nedává
— dodavatel s pestrým sortimentem pravidlo nedostane.

Zapisuje se `origin = seed`, `confirmed = 1`. Přednost:

| Existující pravidlo | Chování |
|---|---|
| žádné | INSERT `seed` |
| stejný štítek | nic |
| `seed`, jiný štítek | UPDATE |
| `user` / `learned`, jiný štítek | **přeskočí** + vypíše |

Import tedy nikdy nepřepíše ruční ani naučené pravidlo.

Kandidát pod prahem pokrytí se v reportu **ukazuje dál** se stavem „pod
prahem pokrytí" — ať je vidět, co těsně nevyšlo. `--apply-seed` ho
nezaloží; pokud ho chceš přesto, sniž `--seed-min-coverage`.

### Otagování položek (`--tag-items`)

Dva režimy nad týmž cílem (živé položky DS s **prázdnými**
`content_tags`; existující štítky se merguje, ne přepisuje):

| Režim | Signál | Kdy má smysl |
|-------|--------|--------------|
| `offer` | účet položky → štítek z nabídky účetních položek | dataset s naší osnovou; cizí soubor slouží jen jako spouštěč |
| `usage` | dominantní štítek klasifikovaných textů **té konkrétní položky** | dataset migrovaný z téhož systému, který dodal historii |

`auto` (default) vybere `usage`, pokud **míra shody kódů** — kolik kódů
položek ze souboru je v katalogu DS — dosáhne 0,8; jinak `offer`. Zvolený
režim i míra shody se vypíšou.

**Režim `offer`** bere jen účty, které nesou v nabídce **právě jeden**
štítek; kolizní a neznámé zůstávají bez zásahu (vypíše se, kolik jich
bylo) — hromadný zápis nemá koho se zeptat. Na rozdíl od reverzu nad
zdrojovým souborem tu **není** syntetická tolerance: účty položek pocházejí
z osnovy tohoto DS. Varianta osnovy se bere z **nastavení DS**
(`economy.accountChart`), ne z hlavičky souboru; bez nastavené varianty
režim jen ohlásí, že není podle čeho tagovat.

**Režim `usage`** agreguje výsledky klasifikace per kód položky a navrhne
štítek při dominanci ≥ 70 % na aspoň 5 řádcích. Do agregace jdou jen
**obsahonosné** texty — text shodný s názvem položky by kruhově potvrzoval
sám sebe. Výsledek `null` („model štítek nenašel") soutěží jako
plnohodnotná možnost, takže catch-all položky („Ostatní služby",
„Materiál") a leasingové splátky návrh správně nedostanou. Multi-tag se
nenavrhuje — jeden dominantní štítek, nebo nic.

Režim `usage` potřebuje LLM klasifikaci, takže si ji vyžádá i bez
`--report`. S `--no-llm` spadne na `offer` (a řekne to).

## 8. Související dokumenty

- [cli.md](cli.md) — příkaz `shpd-ds booking-history`
- `modules/core/mail/docs/ai-analysis.md`, sekce „Obsahová eskalace
  (content tags)" — taxonomie a pravidla `IČO → štítek`, do kterých seed
  z tohoto formátu píše
- `tasks/booking-history-import.md` — zadání a rozhodnutí D29–D35
