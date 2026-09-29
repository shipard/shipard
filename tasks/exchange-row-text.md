# Task: Text řádku dokladu z kanonického formátu — jeden zdroj pravdy

**Stav:** částečně — helper a backend hotové (commit 1/3), náhled a prompt v4.4.0 následují (#84)

**Cíl:** Řádek dokladu vzniklého z AI návrhu nese po vystavení stejný text,
jaký uživatel viděl v review modalu. Text řádku skládá jeden helper, který
používá applier, náhled i všichni, kdo text řádku porovnávají. Prompt
přestane plnit `item.description` šumem.

Issue: #84.

## Před implementací přečti

- `docs/exchange-format.md` §7 (Row object) a pasáž o `_resolve.rows[i]` (~ř. 760)
- `modules/core/mail/docs/ai-prompts.md` — workflow ladění promptu, changelog
- `tasks/enrichment-row-text-candidates.md` — předchozí oprava stejného
  fallbacku, tehdy záměrně jen v enricheru

## Kontext — diagnostika

AI extrakce účtenky PHM vrátila řádek
`item = {name: "Natural 95", description: "DPHM Množství"}` — do popisu
model opsal záhlaví sloupců účtenky. Review modal ukázal „Natural 95“,
vystavený doklad má v řádku „DPHM Množství“. Položka i účet správně.

| Místo | Co čte |
|-------|--------|
| `frontend/src/components/exchange/DocumentExchangePreview.svelte` (~ř. 702) | `row.item?.name` |
| `DocumentApplier::transformRows()` (~ř. 1884) | `row.description ?? item.description ?? item.name` |
| `ContentTagClassifier` (~ř. 154) | `row.description ?: item.description ?: item.name` |
| `SupplierCodeCaptureHandler` (~ř. 86, poziční guard) | `row.description ?? item.description ?? item.name` |
| `RowHistoryEnricher::rowTextCandidates()` (~ř. 400) | kandidáti `[row.description, item.description, item.name]` |

Agregovaně na testovacím serveru: z 21 AI řádků s popisem se 12 liší od
názvu; zhruba polovina nese užitečný detail (fakturované období, číslo
služby rozlišující jinak stejné řádky), zbytek je šum (jednotka, označení
pokladny, záhlaví). Prompt v4.3.0 rozdíl `name` / `description`
nedefinuje a v ukázce má `description` u všech řádků.

Nezasaženo: ISDOC (`IsdocReader` plní jen `item.name`), export / round-trip
(`DocumentExporter` píše top-level `description`).

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — skladba textu řádku.** Neprázdný top-level `row.description`
  má přednost (účetní doklady, export). Jinak `item.name`; za oddělovač
  ` — ` se připojí `item.description`, pokud je po trimu neprázdný, liší se
  od názvu a není v něm obsažený (case-insensitive, `mb_stripos`). Chybí-li
  název, použije se samotný popis. Nic → `null`. Výsledek oříznout na 500
  znaků (`docs_core_rows.description` je `varchar(500)`).
- ✓ **D2 — jeden zdroj pravdy.** Statický helper
  `Shipard\Module\Core\Exchange\Document\CanonicalRowText::compose(array $row): ?string`.
  Používají ho všechna místa z tabulky výše. Náhled dostane hotový text
  ze serveru v `_resolve.rows[i].rowText`; frontend skladbu nezrcadlí.
- ✓ **D3 — prompt v4.4.0** (`czech_general`): definice `name` / `description`,
  ukázka s řádkem bez `description`.
- ✓ **D4** — editace textu řádku v review modalu je mimo rozsah.
- ✓ **D5** — existující doklady bez migrace.

## Co je potřeba udělat

### Commit 1 — helper a backend

1. Nový `modules/core/exchange/src/Document/CanonicalRowText.php` dle D1:

   ```php
   final class CanonicalRowText
   {
       public const SEPARATOR = ' — ';
       public const MAX_LENGTH = 500;

       /** @param array<string, mixed> $row canonical row */
       public static function compose(array $row): ?string
       {
           $top = self::clean($row['description'] ?? null);
           if ($top !== null) {
               return self::cap($top);
           }
           $item = is_array($row['item'] ?? null) ? $row['item'] : [];
           $name = self::clean($item['name'] ?? null);
           $desc = self::clean($item['description'] ?? null);
           if ($name === null) {
               return $desc === null ? null : self::cap($desc);
           }
           if ($desc === null || mb_stripos($name, $desc) !== false) {
               return self::cap($name);
           }
           return self::cap($name . self::SEPARATOR . $desc);
       }
       // clean(): string → trim, '' → null; ne-string → null
       // cap(): mb_substr na MAX_LENGTH
   }
   ```

2. `DocumentApplier::transformRows()` — `'description' => CanonicalRowText::compose($row)`.
   Komentář u pole přepsat (odkaz na helper a D1).
3. `SupplierCodeCaptureHandler` — `$canonicalText = CanonicalRowText::compose($row) ?? ''`.
   Guard musí skládat text **stejně** jako applier, jinak se capture tiše
   přeskakuje (viz Pasti).
4. `ContentTagClassifier` — text řádku pro prompt klasifikátoru přes helper.
5. `RowHistoryEnricher::rowTextCandidates()` — jako **první** kandidát
   `CanonicalRowText::compose($row)`, pak dosavadní tři (dedup zůstává).
   Historie vzniklá po této změně nese složený text; bez něj by exact match
   na vlastní historii selhal.
6. Testy:
   - nový `tests/Unit/Module/Core/Exchange/Document/CanonicalRowTextTest.php` —
     top-level přednost; jen název; název + odlišný popis; popis obsažený
     v názvu (i jiná velikost písmen); popis = název; jen popis; prázdné
     řetězce a whitespace; ne-string hodnoty; `item` chybí / není pole;
     ořez na 500 znaků u vícebajtového textu.
   - `DocumentApplierTest` — řádek `{name, description}` zapíše složený text;
     řádek bez `item` s top-level `description` beze změny.
   - `SupplierCodeCaptureHandlerTest` — guard projde, když finální řádek
     nese složený text.
   - `RowHistoryEnricherTest` — historie se složeným textem se napáruje
     exact matchem.
   - `ContentTagClassifierTest` — v promptu je název před popisem.

   Spouštět úzce: `vendor/bin/phpunit --filter 'CanonicalRowTextTest|DocumentApplierTest|SupplierCodeCaptureHandlerTest|RowHistoryEnricherTest|ContentTagClassifierTest'`.

### Commit 2 — náhled

1. `DocumentApplier::resolveAll()` — do `$rowResolve` (~ř. 557) přidat
   `'rowText' => CanonicalRowText::compose($row)` pro každý řádek (i bez
   `item`).
2. `DocumentExchangePreview.svelte` — v buňce názvu řádku zobrazit
   `resolve?.rows?.[i]?.rowText ?? row.item?.name ?? '—'`. Fallback na
   `item.name` jen pro případ, kdy preview vrátí canonical bez `_resolve`
   (`AnalysisController::previewMessage` bez applieru) — žádná klientská
   skladba.
3. Testy: `AnalysisControllerPreviewMessageTest` nebo `DocumentApplierTest`
   (preview) — `_resolve.rows[0].rowText` odpovídá helperu.
4. `cd frontend && timeout 90 npm run build`.

### Commit 3 — prompt v4.4.0 a dokumentace

1. `modules/core/mail/profiles/czech_general.jsonc`:
   - `prompt_version` a obě zmínky v `prompt_template` (`source.promptVersion`,
     ukázka) → `v4.4.0`.
   - Do PRAVIDLA přidat (znění lze uhladit, smysl zachovat):
     „`rows[].item.name` = text položky tak, jak je na řádku dokladu.
     `rows[].item.description` vyplň JEN tehdy, když doklad k řádku uvádí
     doplňující text (fakturované období, číslo služby, přípojky či
     smlouvy). NIKDY do něj nedávej záhlaví sloupců, jednotku, množství,
     označení pokladny, prodejny nebo skladu. Když takový text není, pole
     vynech.“
   - Do PRAVIDLA PRO ÚČTENKY: „U účtenky zpravidla stačí `item.name`
     (např. druh paliva); `item.description` vynech.“
   - V ukázce u druhého řádku (Doprava) `description` odebrat.
2. `ProfileSchemaDriftTest` — očekávaná verze `v4.4.0` (ř. ~89).
3. `modules/core/mail/docs/ai-prompts.md` — nadpis „Default prompt (v4.4.0)“,
   ř. ~61, changelog `### v4.4.0 (<datum>)` s popisem změny.
4. `docs/exchange-format.md` §7 — pravidlo textu řádku (D1) u Row object;
   u `_resolve.rows[i]` nový klíč `rowText` (informativní, počítá server).
5. `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` — komentář
   u `rows[].description` přepsat podle D1 (odkaz na `CanonicalRowText`).
6. `--filter 'ProfileSchemaDriftTest'`.

### Commit 4 — uzavření

`**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`, ve stejném
commitu jako poslední kód (lze sloučit s commitem 3).

## Mimo rozsah

- Editace textu řádku v review modalu (D4).
- Oprava už vystavených dokladů (D5).
- Změna `IsdocReader` a `DocumentExporter`.

## Pasti

- **Guard v `SupplierCodeCaptureHandler`** porovnává text kanonického řádku
  s finálním `docs_core_rows.description`. Změní-li se skladba jen
  v applieru, guard přestane sedět a dodavatelské kódy se tiše přestanou
  učit — žádná chyba, jen prázdná `economy_items_supplier_codes`.
- **`RowHistoryEnricher`** — bez složeného textu jako prvního kandidáta
  nová historie nenapáruje exact matchem vlastní dřívější řádky.
- **Top-level `description`** musí zůstat první — nese ho účetní doklad
  (`cmnbkp`) i export; dataset round-trip (`DatasetRoundTripTest`) by jinak
  začal řádky „obohacovat“ o popis položky.
- **Oddělovač** je em dash s mezerami (`' — '`), v PHP souboru UTF-8;
  v JS se neobjevuje (frontend jen zobrazuje `rowText`).
- **Prompt:** verze je v šabloně třikrát (pole + dvakrát v textu);
  `ProfileSchemaDriftTest` počítá výskyty. Do DS se profil propíše přes
  `ds-upgrade` (sync jen při vyšší verzi šablony) — bez bumpu se nic nestane.
- Změna promptu ovlivní jen nové analýzy; staré návrhy v DB mají dál
  `item.description` z v4.3.0 — proto je D1 (skladba) potřeba i po D3.

## Hotovo když

- [x] `CanonicalRowText` existuje, pokrytý testy; applier, guard,
      classifier i enricher ho používají. Kontrola:
      `grep -rn "item\['description'\]" modules/core/exchange/src/Document modules/core/exchange/src/Enrich modules/core/mail/src`
      najde jen helper sám a seznam jednotlivých kandidátů
      v `RowHistoryEnricher::rowTextCandidates()` (záměrně — složený text
      je první, surové zdroje zůstávají jako další kandidáti). `ItemResolver` /
      `ItemApplier` popis položky používají pro entitu položky, ne text řádku.
- [ ] Preview vrací `_resolve.rows[i].rowText`; modal zobrazuje tento text.
- [ ] Profil `czech_general` v4.4.0 s pravidly D3; `ProfileSchemaDriftTest` zelený.
- [ ] Docs (`exchange-format.md`, `ai-prompts.md`, komentář ve schématu) aktualizované.
- [ ] Ruční ověření na dev DS: návrh s řádkem `{name, description}` →
      modal i vystavený doklad ukazují stejný text; po `ds-upgrade`
      a nové analýze účtenky PHM je `item.description` prázdné.
- [ ] `**Stav:**` aktualizován, `python3 scripts/tasks-index.py`.
