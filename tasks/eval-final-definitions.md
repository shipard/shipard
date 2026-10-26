# Doladění sad: poznámka informativní, DIČ × VAT ID, strážce snímku hlavní knihy

**Stav:** hotovo — 2026-10-26 (#103 D114–D116); závěrečný běh tří sad ×3 pouští člověk z dashboardu


## Kontext / Cíl

Evaluace s promptem v4.10.0 (#103, 2026-10-25, kolo 3) nechaly tři
otázky definice. Po tomto tasku následuje **závěrečný běh se třemi
opakováními** (pouští člověk z dashboardu); když neukáže nic
systematického, je doladění veřejných sad podle D85 hotové.

| Odchylka (kolo 3) | Oprava |
|-------------------|--------|
| `notes.onDocument` — model u dokladů s doložkou DPH vynechá i skutečnou poznámku (0301, 0502, 0701), u účtenky vezme patičku | D114: poznámka je v evaluaci informativní |
| `supplier.taxId` u německých dodavatelů (doklad tiskne jen „VAT ID“), `customer.taxId` na anglické faktuře | D115: `taxId` = národní daňové číslo |
| kopie snímku hlavní knihy v sadách zůstaly po `corpus-print-fidelity` zastaralé | D116: strážce |

## Návaznost

- **#103** D114–D116 (komentář 2026-10-25), D14 (papírová projekce),
  D8 (výsledky v repu), D85 (doladění = přestat nacházet chyby)
- `docs/test-corpus.md` § 8 → Význam sporných polí

## Před implementací přečti

- `docs/test-corpus.md` § 8, § 13, § 17, § 18
- `tests/corpus/projection.jsonc` (`nullableNodes` jako vzor značky uzlů)
- `modules/core/mail/src/Eval/EvalMetrics.php`, `EvalReport.php`,
  `EvalRunComparison.php`, `Browse/EvalRunSummary.php`
  a frontend prohlížeče výsledků (`frontend/src/dev/…` — výpis metrik
  a odchylek běhu)
- `tests/corpus/cz/identities.jsonc` (`taxId`, `vatId`),
  `tools/invoice-generator/invoicegen/expected.py` (odvození stran)
- `modules/core/exchange/src/Resolve/PartyResolver.php` (párování
  `companyId` → `vatId` → `taxId`), `PartyToPersonCanonical.php`
- `modules/core/mail/profiles/czech_general.jsonc` (pravidlo
  identifikátorů, ukázka `supplier.taxId` / `vatId`)
- `tests/Unit/Corpus/CorpusExpectedTest.php`,
  `tests/corpus/cz/profiles/*/approved/general-ledger.json`,
  `tests/datasets/cz/*/expected/general-ledger.json`

## Rozhodnutí

- **D114 — Poznámka je v evaluaci informativní.** Uzel projekce se značkou
  „informativní“ (nový seznam `informativeNodes` v `projection.jsonc`,
  jediný zdroj; teď jen `notes.onDocument`) se porovnává a vykazuje
  **zvlášť** (`fields.informative.*`), ale nezapočítává se do
  `fields.all.*` ani do `documents.clean`. Prohlížeč výsledků ukáže
  informativní odchylky v samostatném oddílu. Výsledek běhu nese
  `metricsVersion: 2`; porovnání běhů s různou verzí metrik na rozdíl
  upozorní (historické běhy se nepřepočítávají ani nepřepisují).
  Pravidlo D109 v promptu zůstává.
- **D115 — `taxId` je národní daňové číslo.** U českého subjektu je DIČ
  totéž co VAT ID — `taxId` = `vatId` (i když doklad tiskne jen „VAT ID:
  CZ…“). U zahraničního subjektu `taxId` jen tehdy, když doklad uvádí
  národní daňové číslo (Steuernummer apod.); jinak `null`, `vatId` zůstává.
  Oprava identit / odvození očekávání **a** pravidlo v promptu (v4.11.0).
  Nejdřív ověřit, že párování osob (`PartyResolver`) ani založení osoby
  z dokladu (`PartyToPersonCanonical`) na `taxId` zahraničního subjektu
  nespoléhá — `vatId` se páruje dřív; výsledek ověření do závěrečné zprávy.
- **D116 — Kopie snímku hlavní knihy v sadě = schválený snímek.**
  `tests/datasets/cz/<sada>/expected/general-ledger.json` musí být
  bajtově shodná s `tests/corpus/cz/profiles/<profil>/approved/general-ledger.json`;
  hlídá strážce korpusu (PHPUnit) i build (chyba, ne tiché srovnání).
  Stejně pro další kopie schválených souborů v sadách, pokud existují.

## 1. Informativní uzly (D114)

- `projection.jsonc`: `informativeNodes: ["notes.onDocument"]` s komentářem
  (význam, D114); čtení v `Projection` (PHP) i v generátoru, je-li potřeba.
- `EvalMetrics`: informativní cesty mimo `fields.all.*` a `documents.clean`,
  vlastní `fields.informative.{n,hit,wrong,missing,extra}`; `metricsVersion: 2`
  ve výsledku běhu.
- Report CLI, souhrn v prohlížeči a porovnání běhů: oddíl informativních
  odchylek; varování „různá verze metrik“ v porovnání.
- Unit testy: metriky s informativní odchylkou (do `all` se nepočítá,
  doklad zůstává čistý), varování porovnání.

## 2. DIČ × VAT ID (D115)

- Identity / odvození: zahraniční subjekty (`de`, `us`) `taxId: null`,
  `vatId` beze změny; české beze změny (`taxId` = `vatId`). Přegenerovat
  korpus a sady.
- Prompt v4.11.0: k pravidlu identifikátorů jedna věta — u českého subjektu
  je DIČ totéž co VAT ID (vyplnit obě pole); u zahraničního `taxId` jen
  národní daňové číslo uvedené na dokladu. Changelog v `ai-prompts.md`.
- ISDOC: ověřit, že round-trip a import ISDOC (`PartyTaxScheme`) dávají
  stejný výsledek jako očekávání (zahraniční `taxId` null).

## 3. Strážce snímku (D116)

- Test ve strážci korpusu nad všemi sadami; build při nesouladu selže
  s cestou obou souborů.

## Kroky

1. D116 → commit `test(corpus): kopie schválených snímků v sadách hlídané strážcem (#103 D116)`
2. D114 → commit `feat(eval): informativní uzly projekce — poznámka mimo shodu polí, metricsVersion 2 (#103 D114)`
3. D115 (ověření párování, identity, přegenerování, prompt v4.11.0) → commit
   `fix(corpus): taxId jako národní daňové číslo, prompt v4.11.0 (#103 D115)`
4. Dokumentace (§ 8 význam `taxId` a informativní uzly, § 18 metriky,
   changelog promptu), `**Stav:**`, `python3 scripts/tasks-index.py`
   → commit `docs: informativní uzly, taxId, strážce snímku (#103 D114–D116)`

## Hotovo když

- [x] Strážce selže při rozdílné kopii snímku (test), nad repem projde.
- [x] `notes.onDocument` se nepočítá do shody polí ani čistých dokladů;
      vykazuje se zvlášť; porovnání běhů upozorní na rozdílnou verzi metrik.
- [x] Zahraniční subjekty v očekávání `taxId: null`; české `taxId` = `vatId`;
      prompt v4.11.0 s pravidlem; ověření párování v závěrečné zprávě.
- [x] `dataset-verify` nad třemi sadami (zdroje „korpus“): kontroly 1–6
      `ok`, snímek deníku beze změny.
- [x] `vendor/bin/phpunit --filter` na dotčené testy (Eval*, Projection*,
      CorpusExpected*, PromptProjectionCoverage*, PartyResolver*,
      IsdocCorpusRoundTrip*), testy generátoru, `npm test` a `npm run build`
      ve `frontend/` (prohlížeč výsledků), `npm run check:i18n`.
- [x] `shpd-ds ai-profile-reload --dry-run` na zdroji „korpus“: v4.10.0 → v4.11.0.
- [x] Dokumentace, `**Stav:**`, index tasků.
- [x] **Žádná evaluace v tomto tasku** — závěrečný běh pouští člověk.

## Hotovo 2026-10-26

- D116: `DatasetExpectationsTest::testApprovedCopiesMatchCorpus` (každý
  soubor z `approved/`, sirotek bez zdroje) a build sad: rozdíl kopie proti
  `approved/` je `DatasetBuildError` s oběma cestami, převzetí jen
  `build.py --refresh-approved` (rozhodnuto v chatu 2026-10-26).
- D114: `informativeNodes` v projekci, `FieldOutcome::$informative`,
  `fields.informative.*` + `informativeByField`, `metricsVersion: 2`,
  oddíl v reportu, varování porovnání, karta a skupina v prohlížeči.
  Všechny stávající běhy mají od změny projekce jinou verzi sady.
- D115: identity de `taxId` null, kontrola identit podle země, věrnost
  tisku i pro `taxId`, prompt v4.11.0, čtečka ISDOC (`TIN`, cz DIČ = VAT
  ID, jinak null). Kontrola identit je sdílená s demem — partneři
  it-servis (nitra, tatranka, wolkenschiff, noordwolk) `taxId` null
  a sada přestavěná (commit `fix(demo)`), PDF beze změny.

### Ověření párování (D115)

`PartyResolver` zkouší `companyId` → `vatId` → `taxId` a `null` sondu
přeskočí (`PartyResolverTest::testForeignPartyWithoutTaxIdMatchesByVatId`);
`PartyToPersonCanonical` prázdný klíč vynechá, `PersonApplier` ukládá
`tax_id = ''`, `VatDocumentSelection` bere `vat_id ?? tax_id`,
`IsdocDocumentCollector` `taxId ?? vatId ?? companyId` — na `taxId`
zahraničního subjektu nic nespoléhá. ISDOC: korpus má ISDOC jen u českých
dodavatelů (round-trip beze změny); čtečka dřív dávala zahraniční straně
`taxId` = VAT ID, od D115 jen `TIN`.

## Pasti


- **Evaluační zdroje dat nepoužívat.**
- **Historické výsledky v `tests/eval/` nepřepisovat** — metriky v nich
  zůstávají ve verzi 1.
- **Zmrazená fixtura** `tests/Fixtures/Eval/cz-vat-payer-2026-10-16/` nese
  starou projekci — beze změny, testy nad ní musí dál procházet.
