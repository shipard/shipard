# Obsahové štítky — karty k založení i z řádkových výjimek, učení pravidla jen z „čistých“ dokladů

**Stav:** hotovo — kód, testy a docs 2026-10-08; ověření na dev DS `lh6x-l` dle sekce níže

## Motivace

Přijatá faktura za pronájem kanceláře s doprovodnými službami
(anonymizovaný vzor z dev zdroje `lh6x-l`, režim volný): ~9 řádků —
nájemné, „služby“ k nájmu, elektřina, datové služby, 2× parkovné, vodné
a stočné, teplá voda. LLM klasifikace (`ContentTagClassifier`) dopadne
dobře: `primaryTag = premises.rent`, `rowExceptions` = elektřina →
`premises.electricity`, datové služby → `it.internet`, parkovné →
`vehicle.parking`, voda → `premises.water`.

Co se pak děje v review modalu:

- řádky nájmu → resolution `item` (uživatel založil položku Nájemné
  z karty **Položky k založení**),
- řádky s výjimkou → resolution `accountOnly`: účet se navrhne (502100,
  518202, 518100, 502200), položka ne — pro ty štítky žádná otagovaná
  položka neexistuje. V modalu to vypadá jako „bez návrhu“ (sloupec
  Položka je prázdný).

**Vada 1 — nikdy se nenabídne jejich založení.** Karta
`ContentTagSuggestionsSource` se staví výhradně z denormalizovaného
sloupce `core_mail_message_analyses.content_tag` = dokumentový
`primaryTag`. Štítky z `_resolve.contentTag.rowExceptions[]` do karet
nevstupují. Dokud nepřijde doklad od *jiného* dodavatele, kde je elektřina
hlavním obsahem, karta pro `premises.electricity` nevznikne — přestože
nabídka účetních položek (`accountingItemsDefault.jsonc`) má pro všechny
čtyři štítky startovní položku.

**Vada 2 — naučené pravidlo výjimky zahodí.** Po potvrzení takového
dokladu (10 → 40 nebo 0 → 40) se `ContentTagRuleCaptureHandler` naučí
pravidlo IČO → `premises.rent`. Pravidlo má přednost před LLM i před
persistnutým LLM blokem (`RowEnrichmentPipeline::resolveDocumentTag`,
D16) a **nenese rowExceptions** — všechny řádky dalších faktur (i těch už
analyzovaných, preview se počítá fresh) dostanou `premises.rent`
a parkovné se bude párovat na Nájemné.

## Před implementací přečti

- `docs/dashboard.md` §5.3 (ContentTagSuggestionsSource) a §6 (akce
  `materialize_content_tag`)
- `modules/core/mail/docs/ai-analysis.md` — sekce „Obsahová eskalace“
  (persist bloku `_resolve.contentTag`, učení pravidel)
- `tasks/content-tag-enrichment.md` — D5 (primaryTag + rowExceptions),
  D12 (pravidlo přeskakuje LLM), D16 (fresh re-check pravidla), D22
  (učení pravidel)
- `tasks/content-tag-ui.md` — D25/D26 (karta a materializace)
- `CLAUDE.md` → *Dokumentový systém* → `documentEventHandlers` /
  `stateChanged` (handler jede jen přes přechod, který Document naplní)

## Rozhodnutí (potvrzená)

- ✓ **D1 — Karty i ze řádkových výjimek.** `ContentTagSuggestionsSource`
  sbírá štítky otevřených návrhů nejen z `content_tag`, ale i z
  `_resolve.contentTag.rowExceptions[*].tag`. Jedna karta per štítek,
  počet „N dokladů čeká“ počítá **dokumenty** (doklad se dvěma řádky
  parkovného počítá jednou; doklad s primárním X a výjimkou Y se započítá
  do karty X i Y). Pre-step průchodu frontou (`QueueCategoriesPrompt`)
  jede nad stejnými kartami — žádná změna.
- ✓ **D2 — Učení pravidla jen z „čistých“ dokladů.** Pravidlo IČO →
  štítek se neučí, když LLM blok potvrzovaného dokladu nese neprázdné
  `rowExceptions`. Takový dodavatel (vícedruhový obsah) půjde vždy přes
  LLM klasifikaci — levná úloha. Stejná logika jako smazání learned
  pravidla u pestrého sortimentu (D22), jen o krok dřív.
- ✓ **D2a** — Doklad s výjimkami handler přeskočí *celý* (bez INSERT,
  bez statistik, bez DELETE) a zaloguje `ErrorLogger::info`. Varianta
  „smazat existující learned pravidlo“ není potřeba: doklad s existujícím
  pravidlem má v persistnutém bloku `tagSource: 'rule'` a handler ho
  vyřadí už dnes.
- ✓ **D2b** — Ruční (`user`) a seedovaná pravidla se uplatňují dál
  dokument-wide, včetně dodavatelů s pestrým obsahem. Je to volba
  uživatele; pipeline se nemění.
- ✓ **Mimo rozsah:** per-řádková akce „Založit položku“ přímo v review
  modalu (alternativa k D1 — karty jsou konzistentní s D25/D26
  a s průchodem frontou), sloupec `content_tag` zůstává primární štítek
  (filtrování analýz, ISDOC, learning), kvalita klasifikace jednotlivých
  řádků (teplá voda pod `premises.rent` místo `premises.water` — hraniční,
  nechat na LLM).

## Kroky

### 1. `ContentTagSuggestionsSource` — sběr štítků i z výjimek (D1)

`modules/core/exchange/src/Dashboard/ContentTagSuggestionsSource.php`

Nahradit `fetchOpenTagCounts()` dvojicí: dotaz vrací **otevřené analýzy**
(bez `GROUP BY`), agregace per štítek jde v PHP. Výběr otevřených návrhů
zůstává stejný (poslední úspěšná analýza per zpráva, `resolution IS
NULL`, zpráva v docState 10/20, `canonical_json IS NOT NULL`); místo
`content_tag IS NOT NULL` se vybírá cokoli, co nese primární štítek
**nebo** výjimky:

```php
/**
 * Otevřené návrhy s obsahovým štítkem — primárním (`content_tag`) nebo
 * v řádkových výjimkách (`_resolve.contentTag.rowExceptions[*].tag`,
 * D1 tasks/content-tag-row-exceptions.md). Agregace per štítek v PHP:
 * doklad se počítá do každého svého štítku právě jednou.
 *
 * @return list<array{tag: string, waiting: int, latest: mixed}>
 */
private function fetchOpenTagCounts(FeedContext $ctx): array
{
    $rows = $ctx->db->fetchAll(
        'SELECT `a`.`content_tag` AS `tag`,'
        . ' JSON_EXTRACT(`a`.`canonical_json`, \'$._resolve.contentTag.rowExceptions[*].tag\') AS `row_tags`,'
        . ' `m`.`received_at` AS `latest`'
        . ' FROM `' . self::MESSAGES_TABLE . '` `m`'
        . ' JOIN `' . self::ANALYSES_TABLE . '` `a` ON `a`.`id` = ( … stejný poddotaz … )'
        . ' WHERE `m`.`docState` IN %in'
        . ' AND `m`.`analysis_state` = %i'
        . ' AND `a`.`canonical_json` IS NOT NULL'
        . ' AND `a`.`resolution` IS NULL'
        . ' AND (`a`.`content_tag` IS NOT NULL'
        . '      OR JSON_EXTRACT(`a`.`canonical_json`, \'$._resolve.contentTag.rowExceptions[0]\') IS NOT NULL)',
        …
    );

    $agg = []; // tag => ['waiting' => int, 'latest' => mixed]
    foreach ($rows as $row) {
        $tags = [];
        $primary = trim((string) ($row['tag'] ?? ''));
        if ($primary !== '') {
            $tags[$primary] = true;
        }
        $decoded = json_decode((string) ($row['row_tags'] ?? ''), true);
        foreach (is_array($decoded) ? $decoded : [] as $t) {
            if (is_string($t) && $t !== '') {
                $tags[$t] = true;
            }
        }
        foreach (array_keys($tags) as $tag) {
            $agg[$tag]['waiting'] = ($agg[$tag]['waiting'] ?? 0) + 1;
            $agg[$tag]['latest'] = self::laterOf($agg[$tag]['latest'] ?? null, $row['latest'] ?? null);
        }
    }
    // řazení: waiting DESC, latest DESC (jako dřívější ORDER BY)
    …
}
```

`JSON_EXTRACT(…, '$…rowExceptions[*].tag')` vrací v MariaDB JSON pole
jako string (`["premises.electricity","it.internet","vehicle.parking",
"vehicle.parking",…]` — duplicity zůstávají, dedupe dělá PHP), nebo
NULL, když cesta chybí. Ověřeno na dev DS 2026-10-08 i pro test
`rowExceptions[0] IS NOT NULL`.

Zbytek zdroje (`coveredTags`, `buildCard`, goods.stock, štítky bez
mapování) beze změny. Sloupec `content_tag` se **nemění** — zůstává
primární štítek (learning, ISDOC, filtrování).

**Test** `tests/Unit/Module/Core/Exchange/Dashboard/ContentTagSuggestionsSourceTest.php`:
helper `context()` mockuje `fetchAll` podle `str_contains($sql,
'core_mail_message_analyses')` a vrací `tagRows` v tvaru agregace —
přepsat na tvar řádků analýz `{tag, row_tags (JSON string|null),
latest}`; stávající čtyři testy upravit (místo `waiting` dát N řádků).
Přidat:

- výjimka bez primárního pokrytí: doklad `content_tag = premises.rent`
  (pokrytý položkou), `row_tags = ["premises.electricity"]` → právě jedna
  karta `content_tag:premises.electricity` s podtitulkem „1 doklad čeká ·
  návrh: Spotřeba energie (elektřina) (502100)“;
- stejný štítek primárně u dokladu A a ve výjimce dokladu B → jedna
  karta, `waiting = 2`;
- dvě výjimky téhož štítku v jednom dokladu (2× parkovné) → `waiting = 1`;
- `row_tags = null` (rule-sourced blok bez výjimek) → chování jako dřív.

Taxonomii v mocku rozšířit o použité štítky (`premises.rent`,
`premises.electricity`, `vehicle.parking`); `ShippedFeedTexts` katalog
nad ní funguje beze změny.

### 2. `ContentTagRuleCaptureHandler` — neučit z dokladu s výjimkami (D2)

`modules/core/exchange/src/Enrich/ContentTagRuleCaptureHandler.php`,
za kontrolu `tagSource === 'llm'`:

```php
// Doklad s řádkovými výjimkami = dodavatel s vícedruhovým obsahem
// (nájem + energie + parkovné). Pravidlo IČO → štítek by výjimky
// zahodilo (pravidlo je dokument-wide, D12/D16) — neučit, dodavatel
// jde vždy přes LLM (D2 tasks/content-tag-row-exceptions.md).
$exceptions = $block['rowExceptions'] ?? null;
if (is_array($exceptions) && $exceptions !== []) {
    ErrorLogger::info('ContentTagRuleCaptureHandler: document with row exceptions, rule not learned', [
        'tag'        => $tag,
        'exceptions' => count($exceptions),
    ]);
    return;
}
```

Aktualizovat class docblock (odrážka k upsert logice: „LLM blok
s výjimkami → nic“).

**Test** `tests/Unit/Module/Core/Exchange/Enrich/ContentTagRuleCaptureHandlerTest.php`:
doklad z `aiExtraction` 10 → 40, canonical s LLM blokem
`rowExceptions: [{rowIndex: 2, tag: 'vehicle.parking'}]` → `executeSql`
se nevolá (žádný INSERT ani statistiky), i když pravidlo pro IČO
neexistuje. Druhý případ: existující learned pravidlo se stejným
štítkem + doklad s výjimkami → statistiky se **neinkrementují**
(handler skončí dřív). Stávající testy (čistý LLM blok učí, rule blok
neučí) zůstávají.

### 3. Dokumentace

- `docs/dashboard.md` §5.3 — zdroj štítků karty: primární `content_tag`
  **i** `rowExceptions` otevřených návrhů; agregace v PHP, doklad per
  štítek jednou; odkaz na tento task.
- `modules/core/mail/docs/ai-analysis.md` — odstavec o učení pravidel
  (kolem „IČO → štítek (origin `learned`, platné okamžitě)“): doplnit
  větu, že doklad s `rowExceptions` pravidlo neučí.
- `tasks/content-tag-enrichment.md` — u D22 jednořádková poznámka
  „zúženo tasks/content-tag-row-exceptions.md D2“ (rozhodnutí se
  nepřepisuje potichu).
- `help/polozky/obsahove-stitky.md`:
  - „Pravidla dodavatelů“ bod 1: doplnit, že pravidlo vznikne jen
    z dokladu, kde všechny řádky patří do jedné kategorie; faktura
    kombinující víc kategorií (nájem + energie + parkovné) pravidlo
    nezaloží a AI ji zařadí pokaždé znovu.
  - „Na co narazíš“: nový odstavec **Karta i pro vedlejší řádky** —
    karta v sekci Položky k založení se objeví i pro kategorie
    jednotlivých řádků (elektřina na faktuře za nájem), ne jen pro hlavní
    obsah dokladu.
  - Názvy sekcí a tlačítek ověřit ve zdroji popisků, ne odhadovat.
  - Poté `python3 scripts/help-index.py`.
- `tasks/README.md` — řádek v sekci *Došlá pošta (core.mail)* za
  `mail-isdoc-content-tags.md`:
  `| \`content-tag-row-exceptions.md\` | Obsahové štítky: karty Položky k založení i ze řádkových výjimek (\`rowExceptions\`), pravidlo dodavatele se neučí z dokladu s výjimkami |`

### 4. Uzavření

`**Stav:**` v tomto souboru → `hotovo` ve stejném commitu jako kód,
`python3 scripts/tasks-index.py`, `python3 scripts/check-sensitive.py`.

## Commit strategie

1. `fix(exchange): karty Položky k založení i ze řádkových výjimek štítků` —
   krok 1 + test.
2. `fix(exchange): pravidlo dodavatele se neučí z dokladu s řádkovými výjimkami` —
   krok 2 + test.
3. `docs(exchange): obsahové štítky — výjimky řádků v kartách a učení pravidel` —
   krok 3 + 4 (nebo sloučit s 2).

Po každém commitu `vendor/bin/phpunit --filter
'ContentTagSuggestionsSourceTest|ContentTagRuleCaptureHandlerTest'`;
celá sada až na konci.

## Pasti

- Mock v `ContentTagSuggestionsSourceTest` rozlišuje dotazy přes
  `str_contains($sql, 'core_mail_message_analyses')` a
  `'economy_items'` — nový dotaz musí podřetězec zachovat, jinak test
  tiše vrátí `[]` a projde na prázdno.
- `JSON_EXTRACT` s `[*]` na chybějící cestě vrací NULL, ne prázdné pole —
  v PHP vždy `is_array` guard. Hodnota přijde jako **string** (Dibi
  nevrací JSON typ), `json_decode` je nutný.
- `content_tag IS NOT NULL` ve `WHERE` **nesmí** zůstat jako jediná
  podmínka — doklad, kterému LLM dalo `primaryTag: null` ale výjimky ano,
  dnes `parseOutput` sice nepersistuje (blok se zapisuje jen s primárním
  štítkem), do budoucna ale podmínka `OR rowExceptions[0] IS NOT NULL`
  stojí málo.
- Handler učení se spouští jen přes `stateTransition` naplněný Document
  třídou (`DocDocument::trackStateChange`) — jednotkový test volá
  `onStateChanged` napřímo, to stačí (změna je uvnitř handleru, ne
  v registraci).
- Rule-sourced blok (`tagSource: 'rule'`) výjimky nenese ani po tomto
  tasku — pravidlo zůstává dokument-wide (D2b). U dodavatele s ručním
  pravidlem se karty z výjimek tedy neobjeví; to je v pořádku.
- `sourceLimit` a strop sekce (`FeedCollector::sortAndCap`) beze změny —
  agregace per štítek dává jednotky karet.
- Do testů ani docs žádné názvy dodavatelů, čísla dokladů, částky ani
  DS ID — anonymizovaný vzor výše stačí.

## Ověření na dev DS (read-only, po nasazení)

Dev zdroj `lh6x-l` (režim volný) má čtyři otevřené návrhy jednoho
dodavatele s primárním `premises.rent` (pokrytý položkou) a výjimkami
`premises.electricity` a `premises.water` ve všech čtyřech, `it.internet`
a `vehicle.parking` (2 řádky) ve dvou; pravidlo pro jeho IČO zatím
neexistuje. Dotaz z kroku 1 ověřen přes Dibi nad tímto DS 2026-10-08
(read-only): `row_tags` chodí jako string s JSON polem, `latest` jako
`Dibi\DateTime`. Navíc jeden návrh `vehicle.service` s výjimkami
`vehicle.parts` a `vehicle.consumables` — kartují, pokud štítky nemají
položku.

- `GET /_ui/dashboard` → sekce Položky k založení nese čtyři nové karty:
  Elektřina a Vodné a stočné „4 doklady čekají“, Internetové připojení
  a Parkovné „2 doklady čekají“; návrhy 502100 / 502200 / 518202 / 518100.
- Po materializaci karty Elektřina: karta zmizí, preview všech čtyř
  návrhů ukáže u řádku elektřiny resolution `item` (bez reanalýzy).
- Po potvrzení jednoho z dokladů (mutace — po schválení v chatu):
  `SELECT COUNT(*) FROM core_exchange_tag_rules WHERE company_id = …`
  zůstává 0 a v logu je `rule not learned` s počtem výjimek.

## Hotovo když

- [x] Karta Položky k založení vzniká i pro štítek, který je jen ve
      výjimkách řádků; doklad se do každé karty počítá právě jednou.
- [x] Doklad s `rowExceptions` pravidlo IČO → štítek neučí (ani INSERT,
      ani statistiky), log info.
- [x] Testy `ContentTagSuggestionsSourceTest` a
      `ContentTagRuleCaptureHandlerTest` rozšířené dle kroků 1–2, celá
      sada zelená.
- [x] `docs/dashboard.md`, `ai-analysis.md`, poznámka u D22,
      `help/polozky/obsahove-stitky.md` + `help-index.py`.
- [x] Řádek v `tasks/README.md`, `**Stav:** hotovo`, `tasks-index.py`,
      `check-sensitive.py` prošly.
- [x] Ověření na dev DS dle sekce výše — Anna 2026-10-08.
