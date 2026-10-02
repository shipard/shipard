# Task: Srozumitelné chybové hlášky selhané AI analýzy na došlé poště

**Stav:** hotovo — implementováno a ověřeno na dev zdroji 2026-10-02 (tři commity); po nasazení nutný `ds-upgrade` kvůli nové cfgItem `core.mail.analysisErrorKinds`

**Cíl:** Když AI analýza zprávy selže, uživatel na došlé poště i na
Dashboardu vidí lidskou hlášku — co se stalo, jestli je to jeho chyba a co
má udělat — místo odznaku „Analýza selhala“ a technické hlášky schované
v Nastavení. Jeden katalog textů, jeden helper, stejná hláška všude.

Navazuje: druhý task stejného rozhodnutí (D6) — chyby předzpracování
(`preprocess_state = 40`, `preprocess_log.results`) — se navrhne zvlášť
po tomto. Související: `ai_analyzer#1` (tolerantní validace výstupu).

## Před implementací přečti

- `modules/core/mail/docs/ai-analysis.md` — stavy analýzy, `/failed`
- `docs/mail/api-contract.md` §9.6 (`POST /_mail/analysis/{ndx}/failed`)
- `docs/dashboard.md` — kontrakt karet (`title`, `subtitle`, `details`,
  `actions[].primary`), mail chybové karty
- `help/posta/prijem-posty.md` (odstavec „Analýza selhala“) a
  `docs/help-authoring.md`

## Kontext — jak to dnes vypadá

Analyzer posílá do `/failed` `error_type` + `error_message`;
`AnalysisController::failed` (~ř. 1266) obojí slije do
`core_mail_message_analyses.error_message` jako `"[typ] zpráva"`, typ
ani `retryable` zvlášť neukládá. Do stavu 70 se dostanou `schema_error`,
neopakovatelný `ai_error` a `config_error`.

Kde uživatel selhání vidí:

- seznam a hlavička zprávy — jen odznak „Analýza selhala“
- tab **Návrh** (`IncomingMessagesViewer::buildProposalTab`) — bere jen
  úspěšné analýzy, takže ukáže „Žádný návrh dokumentu — zpráva je
  klasifikovaná jako Ostatní“; zavádějící, `primary_type` zůstal na
  výchozí hodnotě, protože analýza nedoběhla
- tab **Analýzy** (`buildAnalysesTab`, ~ř. 627) — `error_message`
  načítá, ale nezobrazí
- **Dashboard** (`MailSuggestionsSource::buildErrorCard`, ~ř. 375) —
  titulek „Chyba analýzy e-mailu“, primární akce „Znovu analyzovat“
- **Nastavení → Analýzy zpráv** — surová hláška (jediné místo)

Druhá cesta téhož problému — výstup prošel analyzerem, ale ne serverovou
validací (`_validationError` v `canonical_json`): tab Návrh ukáže odznak
„Chyba extrakce“, Dashboard `buildInvalidOutputCard` (~ř. 314) stejný
obecný titulek, náhled (`DocumentExchangePreviewModal`) blok „AI extrakce
selhala“ se seznamem problémů a surovým výstupem.

Agregace selhání na testovacím serveru (2026-10-01, prompt v4.3.0): všech
6 zpráv ve stavu 70 má `schema_error`, ve čtyřech podobách — nepovolený
klíč (3×), neplatný JSON, příliš dlouhá hodnota (titulek zprávy),
hodnota mimo výčet (typ dokladu ve větvi registrových dokumentů).
`ai_error` zatím žádný; 1× `_validationError`. Nápověda přitom tvrdí
„typicky nečitelné PDF“ — neodpovídá.

Tvary hlášek z analyzeru (`ai_analyzer/schema.py`, `providers/anthropic_provider.py`,
`worker.py`), z nich se odvozuje kategorie:

```
[schema_error] output does not match schema: Additional properties are not allowed ('<klíč>' was unexpected) at [<cesta>]
[schema_error] output does not match schema: '<hodnota>' is too long at [<cesta>]
[schema_error] output does not match schema: '<hodnota>' is not one of [<výčet>] at [<cesta>]
[schema_error] output does not match schema: <cokoli jiného> at [<cesta>]
[schema_error] fenced JSON is invalid: <detail>
[schema_error] output is not valid JSON
[schema_error] output JSON must be an object at the top level
[ai_error] anthropic: output truncated at max_tokens=<n>
[ai_error] anthropic permanent: … | anthropic sdk: … | anthropic error: … | unsupported provider: …
[config_error] shpd rejected /result body (<status> <code>): …
```

`<cesta>` je Python list, např. `['document', 'extracted_json', 'customer', 'contact']`.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — rozpoznání kategorie:** PHP helper rozebere `[typ]` a u
  `schema_error` podtyp podle textu hlášky; neznámé tvary padají do
  obecné kategorie. Žádný nový sloupec — strukturovaný detail přijde
  případně se změnou kontraktu `/failed` (ukládání surového výstupu
  a tokenů), mimo tento task.
- ✓ **D2 — katalog textů:** pro každou kategorii titulek, vysvětlení
  a co dělat; jazykové varianty cs/en.
- ✓ **D3 — kde (všechna místa):** (a) tab Návrh — karta selhání místo
  „klasifikovaná jako Ostatní“, s rozbalitelnými technickými
  podrobnostmi; (b) tab Analýzy — krátký text u selhaného řádku;
  (c) Dashboard — chybová karta; (d) Nastavení → Analýzy zpráv beze změny.
  *Upřesnění (c):* `subtitle` mail karet nese odesílatele, proto karta
  dostane **titulek z katalogu** místo obecného „Chyba analýzy e-mailu“
  a vysvětlení + co dělat do existujícího rozbalovacího `details`
  (bez změny kontraktu karty).
- ✓ **D4 — chytřejší „Znovu analyzovat“:** reanalýza je doporučená, jen
  když má výchozí aktivní profil novější `prompt_version` než selhaná
  analýza; jinak hint, že opakování se stejnou verzí skončí stejně.
  Na Dashboardu je při doporučení primární akce reanalýza (jako dnes),
  jinak „Otevřít zprávu“ primární a reanalýza sekundární (zůstává).
  Akce v hlavičce detailu se nemění (už je `secondary`).
- ✓ **D5 — sjednocení s `_validationError`:** obě cesty mají v katalogu
  kategorii, v tabu Návrh je kreslí jedna komponenta a Dashboard karty
  berou titulek a texty z katalogu. Náhled návrhu si nechává svůj
  podrobný blok (problémy + surový výstup), jen text hlášky se srovná
  s katalogem.
- ✓ **D6 — rozsah:** tento task = AI analýza; předzpracování = druhý
  task (viz výše).
- Akce „Nahlásit“ zatím ne — jen text „dej nám vědět“ (mimo rozsah).

## Co je potřeba udělat

### Commit 1 — katalog a helper (D1, D2, D4)

**Katalog** `modules/core/mail/config/analysisErrorKinds.jsonc` →
cfgItem `core.mail.analysisErrorKinds` (registrace jako ostatní soubory
v `config/`, vzor `analysisStates.jsonc`). Klíč = kategorie, pole
`name` / `name:cs` / `name:en` (titulek), `description:*` (vysvětlení),
volitelně `detail:*` se zástupnými `{key}` / `{path}`. Návrh znění cs
(en obdobně):

| Kategorie | Titulek | Vysvětlení | Detail |
|---|---|---|---|
| `schemaAdditionalProperty` | AI vrátila data v nečekaném tvaru | Není to chyba ve zprávě ani v příloze, ale v nastavení analýzy Shipardu. | AI přidala pole „{key}“ ({path}), které formát dokladu nezná. |
| `schemaTooLong` | (stejný) | (stejné) | Hodnota v poli {path} je delší, než formát dovoluje. |
| `schemaEnum` | (stejný) | (stejné) | Hodnota v poli {path} není z povolených možností. |
| `schemaInvalidJson` | (stejný) | (stejné) | Odpověď AI není platný JSON. |
| `schemaOther` | (stejný) | (stejné) | — |
| `aiTruncated` | Odpověď AI se nevešla do limitu | Doklad je na jednu analýzu příliš rozsáhlý, typicky má hodně řádků. | — |
| `aiError` | Službě AI se nepodařilo zprávu zpracovat | Například kvůli nepodporované nebo poškozené příloze. | — |
| `configError` | Chyba propojení analyzátoru se Shipardem | Problém je v provozu Shipardu, ne ve zprávě. | — |
| `invalidOutput` | AI vrátila nepoužitelný návrh | Návrh neprošel kontrolou formátu dokladu a nedá se použít. | — |
| `unknown` | Analýza selhala | Neznámý druh chyby. | — |

Hint „co dělat“ (D4) je společný, ne per kategorie — dvě varianty
v katalogu pod rezervovanými klíči (nebo v helperu přes stejný
cfg mechanismus):

- doporučeno: „Analýza se mezitím aktualizovala — zkus Znovu analyzovat.“
- nedoporučeno: „Opakování se stejnou verzí analýzy skončí stejně. Doklad
  zadej ručně a dej nám vědět, o jakou zprávu šlo.“

**Helper** `AnalysisErrorPresenter` v `modules/core/mail/src/` (vedle
`AnalysisConfidenceResolver`):

- `fromErrorMessage(?string $errorMessage): AnalysisErrorInfo` — rozbor
  prefixu `[typ]` a tvarů z tabulky výše; `{path}` z Python listu bez
  prefixu `document.extracted_json` / `document`, spojený tečkou
  (`customer.contact`); `{key}` z `('<klíč>' was unexpected)`.
- `forInvalidOutput(): AnalysisErrorInfo` — kategorie `invalidOutput`.
- `isReanalysisRecommended(?string $failedPromptVersion): bool` —
  porovná s `prompt_version` výchozího aktivního profilu
  (`core_mail_ai_profiles` `is_active = 1 ORDER BY is_default DESC LIMIT 1`)
  přes `version_compare(ltrim(…, 'v'), …, '>')`; neparsovatelná nebo
  chybějící verze → `false`.
- `AnalysisErrorInfo` (readonly): `kind`, `title`, `description`,
  `detail` (nebo null), `hint`, `reanalysisRecommended`, `technical`
  (původní `error_message`).
- Texty podle jazyka cfg (stejně jako `name` u stavových odznaků).

Test `tests/Unit/Module/Core/Mail/AnalysisErrorPresenterTest.php`: každý
tvar z tabulky (syntetické hodnoty), dvě chyby za sebou / neznámý formát
→ fallback, `null` / prázdná hláška → `unknown`, porovnání verzí
(novější, stejná, `isdoc`, null).

Ověření: `php -l`, `vendor/bin/phpunit --filter AnalysisErrorPresenterTest`.

### Commit 2 — serverová data (D3a–c, D4, D5)

`modules/core/mail/src/IncomingMessagesViewer.php`:

- `buildProposalTab` — při `analysis_state = 70` načíst poslední
  analýzu se `status = 3` a do obsahu `proposal` přidat
  `failure: {kind, title, description, detail, hint, reanalysisRecommended, technical, analyzedAt, promptVersion}`;
  v tom případě neposílat klasifikaci pro text „klasifikovaná jako“.
  U `_validationError` (`ai_failed`) totéž pole s `forInvalidOutput()`
  a verzí promptu té analýzy.
- `buildAnalysesTab` — nový sloupec `error` („Chyba“) se `title`
  (+ `detail`, je-li) u selhaných řádků, jinak `—`.

`modules/core/mail/src/Feed/MailSuggestionsSource.php`:

- `buildErrorCard` — `fetchErrorRows` doplní `error_message`
  a `prompt_version` poslední selhané analýzy (jedním dotazem, žádné
  N+1); `title` z katalogu, `details` = řádky „Co se stalo“
  (`description` + `detail`) a „Co dělat“ (`hint`); `primary` podle
  `reanalysisRecommended`.
- `buildInvalidOutputCard` — totéž s `forInvalidOutput()`.

Testy: `IncomingMessagesViewerTest` (failure v tabu Návrh pro stav 70
i `_validationError`, sloupec v Analýzách), `MailSuggestionsSourceTest`
(titulek, `details`, primární akce v obou větvích D4).

Docs: `docs/dashboard.md` — mail chybové karty (titulek z katalogu,
`details`, pravidlo primární akce); `modules/core/mail/docs/ai-analysis.md`
— nová sekce „Chybové hlášky pro uživatele“ (katalog, helper, pravidlo D4).

Ověření: `vendor/bin/phpunit --filter 'IncomingMessagesViewerTest|MailSuggestionsSourceTest|AnalysisErrorPresenterTest'`.

### Commit 3 — frontend, nápověda, uzavření (D3a, D5)

- Nová komponenta `frontend/src/components/viewer/AnalysisFailureCard.svelte`
  (BEM `shpd-analysis-failure`): titulek, vysvětlení, detail, hint;
  rozbalitelné „Technické podrobnosti“ (`technical`, čas, verze promptu).
  Barvy přes CSS proměnné error stavu, vzor `shpd-extracted__badge--error`.
- `ViewerDetail.svelte` (~ř. 338, content `proposal`) — je-li
  `content.failure`, kreslit kartu místo `noProposal`; u `doc.ai_failed`
  kreslit kartu s `doc.failure` v kartě návrhu.
- `DocumentExchangePreviewModal` / i18n `exchange.preview.aiFailed.message`
  — text srovnat s katalogem `invalidOutput` (blok s problémy a surovým
  výstupem zůstává).
- i18n `cs.js` / `en.js`: jen statické popisky komponenty („Technické
  podrobnosti“, „Prompt“, …); texty hlášek jdou ze serveru.
- Nápověda `help/posta/prijem-posty.md` — přepsat odstavec „Analýza
  selhala“: co uživatel uvidí (karta v tabu Návrh, titulek karty na
  Dashboardu), že jde typicky o chybu na straně Shipardu, kdy má smysl
  Znovu analyzovat; pryč „typicky nečitelné PDF“. Popisek akce ověřit
  v `viewerDefaults.jsonc` („Znovu analyzovat“). Případně řádek ve
  `help/slovnicek.md`.
- `**Stav:**` → `hotovo` (nebo `částečně — zbývá ověření na dev zdroji`)
  + `python3 scripts/tasks-index.py`.

Ověření: `cd frontend && npm run check:i18n && timeout 90 npm run build`,
`python3 scripts/help-index.py --check`, na konci celá PHPUnit sada lokálně.

### Ověření (člověk)

Na dev zdroji se zprávou ve stavu 70 (případně vytvořenou ručně
reanalýzou s rozbitým profilem — jen na zdroji v režimu *volný*):
tab Návrh ukáže kartu selhání, tab Analýzy text, Dashboard titulek
z katalogu a primární akci podle D4. Na testovací server se dostane
běžným nasazením — tam stačí podívat se na existující selhání.

## Mimo rozsah

- Chyby předzpracování (`preprocess_state = 40`) — druhý task D6.
- Akce „Nahlásit“ / odeslání chyby vývojářům.
- Změna kontraktu `/failed` (strukturovaný detail chyby, surový výstup,
  tokeny a cena u selhání) a sloupec typu chyby.
- Tolerantní validace v analyzeru (`ai_analyzer#1`).
- Nastavení → Analýzy zpráv (zůstává technické).

## Pasti

- **Rozbor textu z Pythonu je křehký.** `ai_analyzer#1` plánuje hlásit
  všechny chyby (`iter_errors`) — formát se změní. Helper musí neznámý
  nebo vícenásobný tvar tiše poslat do `schemaOther` / `unknown`, nikdy
  výjimkou; pokrýt testem.
- **Selhané analýzy mají `profile` NULL** — D4 porovnává s výchozím
  aktivním profilem, ne s profilem analýzy.
- **`technical` může obsahovat hodnoty z dokladu** (např. příliš dlouhý
  titulek). Zobrazovat jen sbalené v detailu zprávy; do testů, docs
  a nápovědy jen syntetické příklady.
- **`primary_type` ve stavu 70 je výchozí `other`** — nepoužívat ho pro
  text „klasifikovaná jako“, a pozor na `buildErrorCard`, kde
  `primary_type = 'other'` degraduje kartu na `review` (chování zachovat).
- **Starší úspěšný návrh + nová selhaná reanalýza:** ověř, co dnes
  reanalýza dělá s předchozím návrhem; karta selhání se ukáže při
  `analysis_state = 70`, stávající vykreslení návrhu se nemění.
- **`subtitle` mail karet = odesílatel** — nepřepisovat (upřesnění D3c).
- **Tab Analýzy má popisky natvrdo česky** (stávající stav) — nový sloupec
  stejně, hodnoty z katalogu podle jazyka.
- **JS:** jen ASCII uvozovky, české texty do `i18n/*.js` nebo ze serveru.

## Hotovo když

- [x] Katalog `analysisErrorKinds` a `AnalysisErrorPresenter` s testy
      všech tvarů a fallbacku.
- [x] Tab Návrh ve stavu 70 i u `_validationError` ukazuje kartu
      selhání; zmizel text „klasifikovaná jako“.
- [x] Tab Analýzy má sloupec Chyba.
- [x] Dashboard karty mají titulek z katalogu, `details` a primární akci
      podle D4.
- [x] Text v náhledu srovnaný s katalogem; nápověda přepsaná,
      `help-index --check` prošel.
- [x] `docs/dashboard.md` a `ai-analysis.md` aktualizované.
