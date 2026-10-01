# Task: Druh plnění řádku přijatého dokladu ze zahraničí

**Stav:** hotovo — implementováno a ověřeno na dev zdroji 2026-10-01 (commit 1: taxonomie, derivace, applier, docs; commit 2: prompt v4.6.2, help)

**Issue:** #88 (navazuje na #86, `exchange-received-reverse-charge.md`, `exchange-received-vat-place.md`)

**Cíl:** Přijatá faktura od dodavatele mimo ČR, která DPH vůbec nezmiňuje
(typicky americký SaaS bez DIČ), dostane v návrhu správný kód DPH
samovyměření (`cz-417`) místo chyby „u plnění mimo tuzemsko chybí druh
plnění (zboží / služba)“. K tomu dvě pojistky proti tichému chybnému
samovyměření: dovoz zboží a plnění se zvláštním místem plnění se
neodvozují.

## Před implementací přečti

- `tasks/exchange-received-reverse-charge.md` — algoritmus derivace kódu
  (D1/D4), tabulka chování, D7 (rozsah a otevřené body), Pasti
- `tasks/exchange-received-vat-place.md` — efektivní místo ve
  `vatContext()`, `XI` a `supplyKinds`, Pasti (místo před kódem, klíč cache)
- `tasks/content-tag-enrichment.md` — taxonomie štítků, D2 (břitva),
  D16 (fresh běh bez LLM při preview/apply)
- `modules/core/exchange/src/Document/VatCodeDerivation.php` — `derive()`
  (větev mimo tuzemsko ~ř. 115)
- `modules/core/exchange/src/Document/DocumentApplier.php` —
  `vatContext()` (~ř. 1431), `rowSupplyKinds()` (~ř. 1533),
  `decideRowVatCode()` (~ř. 1594), `appendVatModeIssue()` (~ř. 2117),
  továrna `create()` (~ř. 184)
- `modules/core/exchange/src/Enrich/RowEnrichmentPipeline.php` —
  `resolveDocumentTag()` a `applyTagToRows()` (~ř. 160–260): tvar bloku
  `_resolve.contentTag` (`tag`, `tagSource`, `rowExceptions`)
- `docs/exchange-format.md` — § „Odvození kódu DPH u přijatých dokladů“
  (~ř. 803), tabulka issues (~ř. 986)
- `modules/core/mail/docs/ai-prompts.md` — verze, changelog

## Kontext — diagnostika

Přijatá faktura za předplatné softwaru od dodavatele z USA, bez DIČ,
v USD, jeden řádek, na dokladu **žádná zmínka o DPH**. Prompt v4.6.1
vrátil (anonymizováno, hodnoty polí přesně):

| pole | hodnota |
|---|---|
| `supplier.country` | `us` |
| `supplier.vatId` | chybí |
| `vat.mode` | `none` |
| `vat.place` | `thirdCountry` |
| `vat.reverseCharge` | chybí |
| `rows[0].vat` | `{code: null, pct: 0}` — bez `supplyKind` |
| `_resolve.contentTag` | `it.software`, `tagSource: llm` |

Derivace jde správně cestou samovyměření (místo ≠ tuzemsko, `pct` 0),
ale bez `supplyKind` nerozliší `cz-415` / `cz-417` →
`vat_code_unknown`. Zbytek řetězu už funguje: `none` se u řádků se
samovyměřením přepne na `fromBase` (`vat_mode_derived`), rekapitulace se
přepočítá (D3 z #86).

Na dev zdroji (sedm analýz v4.6.x se zahraničním dodavatelem) mají
`supplyKind` vyplněný všechny doklady, které DPH nebo reverse charge
zmiňují. Chybí jen u dokladu bez zmínky o DPH: model zvolí `none`
(pravidlo promptu „dodavatel neplátce, žádná zmínka o DPH“ to doslova
splňuje) a s ním zahodí DPH signály řádku. Ukázka v promptu má jen
tuzemský řádek se `supplyKind: null`.

Při diagnostice vyšly dva další nálezy:

- **Dovoz zboží:** derivace u třetí země + `goods` vrací `cz-415`
  (`VatCodeDerivationTest` ř. 51 to tvrdí), ačkoli D7 v #86 má dovoz
  zboží mezi otevřenými body. `cz-415` / `cz-405` (ř. 7) je samovyměření
  dovozce podle § 23 odst. 3; běžná s.r.o. platí DPH při dovozu celnímu
  úřadu (nebo přes dopravce) a odpočet uplatní z celního dokladu (JSD).
  Faktura dodavatele samovyměření nenese.
- **Zvláštní místo plnění:** služba ze zahraničí bez daně, jejíž místo
  plnění není v ČR (ubytování, doprava osob, stravování, služby
  k nemovitosti, mýto, parkování), by s `supplyKind: services` dnes
  dostala `cz-417` / `cz-217` — samovyměření tam, kde se daň v ČR
  nepřiznává.

## Rozhodnutí k designu (potvrzená 2026-10-01)

- ✓ **D1 — prompt v4.6.2:** `supplyKind` se vyplňuje u **každého**
  položkového řádku dodavatele mimo ČR bez ohledu na `vat.mode` (i u
  `none`, bez DIČ, bez sazby); software, předplatné a cloud jsou služby.
  `vat.reverseCharge` se nevynechává — bez zmínky `false`. Pravidlo
  `none` dostane větu, že u zahraničního dodavatele neruší `supplyKind`.
  Nová zahraniční ukázka se nepřidává (délka promptu).
- ✓ **D2 — systémový fallback z content tagu:** když řádek nemá
  `supplyKind` a efektivní místo ≠ tuzemsko, applier vezme druh plnění
  ze štítku řádku (`rowExceptions[idx] ?? tag` z `_resolve.contentTag`)
  a přidá warning `supply_kind_derived`. Druh nese taxonomie
  `core.exchange.contentTags` v novém atributu `crossBorderSupply`, jen
  u štítků, kde spolehlivě platí obecné pravidlo místa plnění
  (§ 9 odst. 1) — whitelist níže. Opraví i existující návrhy bez nové
  AI analýzy (`enrichFresh` blok persistuje).
- ✓ **D3 — dovoz zboží se neodvozuje:** třetí země + `goods` →
  `null` s důvodem „dovoz zboží — DPH se vyměřuje z celního dokladu,
  ne z faktury dodavatele“. `cz-415` jen ručně.
- ✓ **D4 — veto zvláštního místa plnění:** štítek s `crossBorderSupply:
  "special"` na řádku a efektivní místo ≠ tuzemsko → derivace vrátí
  `null` s důvodem „místo plnění se řídí zvláštním pravidlem (…) —
  samovyměření se neodvozuje“. Platí i při `supplyKind` z AI a i při
  `reverseCharge: true` (chyba je lepší než tiché špatné samovyměření).
- ✓ **D5 — forma:** tento task, dva commity, issue navazující na #86.

## Atribut `crossBorderSupply` (D2, D4)

`modules/core/exchange/config/contentTags.jsonc`, nový nepovinný klíč
na záznamu štítku. Hodnoty:

| hodnota | význam |
|---|---|
| `"services"` | druh plnění pro fallback D2 |
| `"goods"` | druh plnění pro fallback D2 |
| `"special"` | veto D4 — samovyměření se mimo tuzemsko neodvozuje |
| chybí | žádný fallback ani veto (dnešní chování) |

Mapování (potvrzené):

- `services`: `it.software`, `it.hosting`, `it.phone`, `it.internet`,
  `services.accounting`, `services.legal`, `services.marketing`,
  `services.shipping`
- `goods`: `it.hardware`, `office.supplies`, `office.equipment`,
  `office.cleaning`, `goods.stock`, `vehicle.parts`,
  `vehicle.consumables`, `people.workwear`
- `special`: `travel.accommodation`, `travel.fares`, `people.catering`,
  `premises.rent`, `premises.electricity`, `premises.gas`,
  `premises.water`, `premises.maintenance`, `premises.security`,
  `vehicle.toll`, `vehicle.parking`
- bez atributu: ostatní (`vehicle.fuel`, `vehicle.service`,
  `vehicle.washing`, `vehicle.insurance`, `services.training`,
  `services.postage`, `services.banking`, `services.waste`,
  `people.gifts`, `people.benefits`, `admin.*`)

Komentář v hlavičce souboru: význam atributu, odkaz na tento task;
`goods` u třetí země derivaci stejně nedá kód (D3), slouží pro EU zboží.
Atribut patří do taxonomie (sémantika obsahu), **ne** do
`economy.items.contentTagDefaults` (účetní předvolby, `vatHint`).

## Chování

Vše jen při `vatContext().derive = true` (přijatý doklad, naše registrace
DPH). Vystavené, účetní doklady a zdroj neplátce beze změny.

### `VatCodeDerivation::derive()`

Nový poslední parametr `?string $tagSupply = null` (hodnota
`crossBorderSupply` štítku řádku). Funkce zůstává čistá — taxonomii
nečte, dostane jen hodnotu.

Ve větvi mimo tuzemsko (po kontrole zahraniční DPH, před filtrem
`supplyKind`), v tomto pořadí:

1. `$tagSupply === 'special'` → fail D4.
2. `supplyKind` prázdné → fail jako dnes (fallback D2 řeší applier,
   derivace dostane už efektivní `supplyKind`).
3. `cfgPlace === 'foreign'` a `supplyKind === 'goods'` → fail D3.
4. dál beze změny (filtr `supplyKind`, `pick`).

`conflict()` se nemění — kód z historie řádků (`RowHistoryEnricher`)
pochází z dokladu, který potvrdil člověk.

### `DocumentApplier`

- **Efektivní `supplyKind` řádku** = `rows[i].vat.supplyKind` z canonicalu,
  jinak (jen když je efektivní místo ≠ `domestic`) `crossBorderSupply`
  štítku řádku, pokud je `goods` / `services`. Štítek řádku =
  `rowExceptions` pro index řádku, jinak `tag` bloku
  `_resolve.contentTag`. Bez bloku → bez fallbacku.
- **Pořadí ve `vatContext()`:** efektivní místo potřebuje druhy plnění
  (`XI`), fallback potřebuje efektivní místo. Postup: (1)
  `VatPlaceDerivation` s druhy z canonicalu (dnešní `rowSupplyKinds()`),
  (2) efektivní místo, (3) fallback druhu plnění, (4) jen když fallback
  něco doplnil a dodavatel má prefix se `supplyKinds` (dnes `XI`),
  derivaci místa spustit znovu s efektivními druhy. Druhý průchod je
  jediná výjimka — žádný cyklus.
- `decideRowVatCode()` dostane efektivní `supplyKind` a `tagSupply`;
  kontext řádku nese navíc `supplyKind`, `supplyKindSource: "ai" | "tag"
  | null`, `tag`.
- **Issue `supply_kind_derived`** (warning, path `rows.N.vat.supplyKind`)
  u každého řádku se `supplyKindSource: "tag"`: „Druh plnění řádku
  doplněn podle štítku „{tagLabel}“ ({služby | zboží}).“ Label česky
  z taxonomie (`name:cs`), fallback klíč štítku. Přidává se tam, kde
  `vat_code_*` issues řádků (stejný průchod preview i apply).
- **Klíč cache `vatContext()`** doplnit o `_resolve.contentTag.tag`
  a `rowExceptions` — jinak cache vrátí kontext bez fallbacku.
- Canonical se nemění (vzor `VatModeDerivation`); efektivní druh je jen
  v kontextu a v `_resolve`.
- Taxonomie: továrna `create()` předá do applieru mapu
  `tag → crossBorderSupply` z `$config->cfgItem('core.exchange.contentTags')`
  (neznámá hodnota atributu se ignoruje). Testy konstruují applier
  s mapou přímo.

### Tabulka výsledků

| místo | `supplyKind` AI | štítek | výsledek |
|---|---|---|---|
| třetí země | null | `services` | `cz-417` + `supply_kind_derived` |
| třetí země | null | `goods` | `vat_code_unknown` (D3) + `supply_kind_derived` |
| třetí země | `goods` | cokoli kromě `special` | `vat_code_unknown` (D3) |
| EU | null | `goods` | `cz-215` + `supply_kind_derived` |
| EU | null | `services` | `cz-217` + `supply_kind_derived` |
| EU / třetí země | `services` | `special` | `vat_code_unknown` (D4) |
| EU / třetí země | null | bez atributu / bez štítku | `vat_code_unknown` jako dnes |
| EU | `services` | `goods` | AI má přednost → `cz-217`, bez issue |
| tuzemsko | cokoli | cokoli | beze změny (fallback ani veto se neuplatní) |

## Co je potřeba udělat

### Commit 1 — taxonomie, derivace, applier, docs

- `contentTags.jsonc`: atribut podle mapování, komentář v hlavičce.
- `VatCodeDerivation`: parametr `tagSupply`, kroky D3 a D4, docblock
  (D7 z #86: dovoz zboží už není „otevřený“, ale vědomě se neodvozuje).
- `DocumentApplier`: efektivní `supplyKind`, pořadí ve `vatContext()`,
  klíč cache, issue `supply_kind_derived`, mapa z taxonomie v továrně.
- `docs/exchange-format.md`: v § „Odvození kódu DPH…“ (~ř. 803) odstavec
  o fallbacku druhu plnění ze štítku, D3 a D4 (úprava věty „třetí země
  `cz-415` / `cz-417`“ — `cz-415` se neodvozuje), odstavec „Mimo rozsah“
  aktualizovat; `supply_kind_derived` do tabulky issues (~ř. 986).
- `tasks/content-tag-enrichment.md` se needituje (momentka); popis
  atributu patří do komentáře taxonomie a do `docs/exchange-format.md`.

Testy:

- `VatCodeDerivationTest`: případ „třetí země zboží“ (ř. 51) přesunout
  mezi fail případy (důvod obsahuje „celního dokladu“); nové fail
  případy `tagSupply: special` pro EU i třetí zemi, i s
  `reverseCharge: true`; `special` v tuzemsku nic nemění (`cz-110`);
  EU zboží dál `cz-215`.
- `ContentTagsDriftTest`: `crossBorderSupply` jen z hodnot `goods`,
  `services`, `special`; štítky z mapování výše ho mají (drift guard
  proti tichému smazání).
- `DocumentApplierTest` (fiktivní dodavatelé a částky): scénář
  z diagnostiky (`us`, bez DIČ, `mode: none`, `thirdCountry`, bez
  `supplyKind`, štítek `it.software`) → `cz-417`, `vat_mode` 1,
  `supply_kind_derived`, rekapitulace přepočítaná; totéž bez bloku
  `contentTag` → `vat_code_unknown` jako dnes; `rowExceptions` přebíjí
  štítek dokladu; AI `services` + štítek `goods` → `cz-217` bez issue;
  `travel.accommodation` + `services` + `thirdCountry` → `vat_code_unknown`
  s důvodem D4; tuzemský doklad se štítkem `special` beze změny;
  vystavený doklad beze změny.
- Regrese zelená: `VatPlaceDerivationTest`, `testReceived*`,
  `testDeclaredRecap*`, `RowEnrichmentPipelineTest`,
  `ContentTagClassifierTest` (prompt taxonomie čte jen `name`).

Ověření: `php -l` na změněných souborech,
`vendor/bin/phpunit --filter 'VatCodeDerivationTest|VatPlaceDerivationTest|ContentTagsDriftTest|DocumentApplierTest|DocumentApplierNoItemTest|RowEnrichmentPipelineTest|ContentTagClassifierTest'`.

### Commit 2 — prompt v4.6.2, help, uzavření

`modules/core/mail/profiles/czech_general.jsonc`:

- `prompt_version` → `v4.6.2` (změna textu bez změny schématu = patch),
  v textu `source.promptVersion` → `v4.6.2`.
- Pravidlo `"rows"[].vat.supplyKind`: vyplň u **každého** položkového
  řádku dodavatele mimo ČR — i když doklad DPH vůbec neřeší (`"vat"`.mode
  `"none"`, bez DIČ, bez sazby): `"goods"` (zboží) nebo `"services"`
  (služby, včetně softwaru, předplatného a cloudových služeb); u dodavatele
  z ČR null.
- Pravidlo `"vat".mode`: doplnit, že `"none"` u zahraničního dodavatele
  neruší `"supplyKind"` řádků ani `"vat".reverseCharge`.
- Pravidlo `"vat".reverseCharge`: „pole nevynechávej“.

Testy verzí: `ProfileSchemaDriftTest` (ř. 89) a `AIAnalyzerProvisionerTest`
(ř. 177) → `v4.6.2`; stale kontroly (ř. 113 a 142) doplnit o `v4.6.1`.

`modules/core/mail/docs/ai-prompts.md`: nadpis „Default prompt“ (ř. 27)
a zmínky verze (ř. 61, příklad bumpu ř. 187) → `v4.6.2`; changelog
`### v4.6.2` nad v4.6.1 — důvod (diagnostika výše, bez identifikace
dodavatele), odkaz na tento task a na `supply_kind_derived`; poznámka,
že server od tohoto tasku doplní druh plnění i bez promptu (D2).

Uživatelská dokumentace (stejný commit):

- `help/posta/kontrola-vytezeni.md`, odstavec **Reverse charge** (~ř. 172):
  u faktury ze zahraničí, která DPH nezmiňuje (typicky software
  a předplatné z USA), Shipard samovyměření dovodí sám; když druh plnění
  (zboží / služba) neuvedla AI, doplní ho podle kategorie dokladu
  a ukáže to upozorněním.
- `help/co-dnes-nejde.md`, položka „Samovyměření (reverse charge) má
  v návrhu své meze“ (~ř. 166): doplnit, že návrh nezpracuje **dovoz
  zboží ze třetí země** (DPH se řeší z celního dokladu) a služby se
  zvláštním místem plnění v zahraničí (ubytování, jízdenky, stravování,
  nájem a služby k nemovitosti, mýto, parkování) — skončí chybou kódu
  DPH, doklad se založí ručně.
- Názvy ověřit ve zdroji popisků (`contentTags.jsonc` `name:cs`,
  `vat-cz.jsonc` `name:cs`). Pak `python3 scripts/help-index.py`.

`**Stav:**` → `hotovo` (nebo `částečně — zbývá ověření`)
+ `python3 scripts/tasks-index.py`.

Ověření: `vendor/bin/phpunit --filter 'ProfileSchemaDriftTest|AIAnalyzerProvisionerTest|AiProfileReloadCommandTest|DsUpgradeCommandTest'`,
na konci celá sada lokálně.

### Ověření na dev zdroji

Zdroj s režimem *volný*, zpráva je v chatu (mimo repo).

1. Po commitu 1, **bez nové analýzy** (stará v4.6.1): náhled návrhu
   ukáže `cz-417`, warning `supply_kind_derived` a `vat_mode_derived`,
   bez `vat_code_unknown` — ověřuje D2 nezávisle na promptu.
2. `vendor/bin/shpd-ds ds-upgrade` → profil `czech_general` má
   `prompt_version = v4.6.2`.
3. „Znova analyzovat“ → nová analýza vrátí `supplyKind: services`,
   náhled bez `supply_kind_derived` (ověřuje D1).
4. „Vystavit koncept“ → `vat_place` 2 (Zahraničí), rekapitulace
   `cz-417` + pár `cz-407`; živé výstupy DPH ř. 12 a ř. 43.
5. Regrese: doklady z #86 (EU služby s DIČ, `IE…` mimo EU) dál `cz-217`,
   bez `supply_kind_derived`.

## Mimo rozsah

- Výběr kódu DPH v náhledu návrhu (D6 z #86) — samostatný task.
- Zaúčtování dovozu zboží z celního dokladu (JSD) a samotná faktura
  dodavatele u dovozu (účtování mimo DPH) — zůstává ruční.
- Rozlišení druhu plnění podle textu řádku bez štítku (heuristika
  klíčových slov) — fallback jde jen přes taxonomii.
- Úprava zobrazovaných názvů kódů `cz-4xx` („DOVOZ/…/Ostatní“ = služby
  ze třetí země) — převzaté ze starého Shipardu, beze změny.
- `conflict()` a kód z historie řádků — veto D4 se na něj nevztahuje.

## Pasti

- **Blok `_resolve.contentTag` nemusí existovat** — pipeline štítkuje jen
  doklad s nepokrytým item řádkem (`hasUncoveredItemRow`). Pokrytý doklad
  (Vrstva 0) fallback nedostane; typicky ale nese kód z historie řádků.
  Žádný vlastní LLM běh v applieru.
- **Štítek řádku, ne dokladu** — `rowExceptions[idx]` má přednost; index
  je index v `canonical.rows` (stejně jako v `applyTagToRows()`), ne
  pořadí položkových řádků.
- **Pořadí místo ↔ druh plnění** — fallback potřebuje efektivní místo,
  místo u `XI` potřebuje druh. Jen jeden doplňkový průchod derivace
  místa (viz Chování); jinak u `XI` + fallback vznikne nekonzistence
  místa a kódu.
- **Klíč cache `vatContext()`** — bez štítku a `rowExceptions` v klíči
  vrátí cache kontext bez fallbacku (stejná past jako DIČ v
  `exchange-received-vat-place.md`).
- **Veto D4 před D2 i před `supplyKind` z AI** — kontroluje se hodnota
  štítku, ne druh plnění; jinak by `services` z AI veto obešlo.
- **D3 mění chování** — `cz-415` dnes vzniká a test ho tvrdí; po změně
  nesmí zůstat jinde v testech ani v docs jako odvozený kód.
- **Atribut do taxonomie, ne do `contentTagDefaults`** — sémantika obsahu
  je nezávislá na osnově; `vatHint` je účetní předvolba.
- **Prompt taxonomie štítků** (`ContentTagPrompt::taxonomyBlock`) čte jen
  `name` — nový atribut se do LLM promptu nedostane a nemá.
- **Prompt:** `prompt_template` je jeden JSON řetězec, verze promptu na
  více místech, sync profilu jen při vyšší verzi — viz Pasti
  v `exchange-contact-name.md`. Editace přes Python
  (`io.open(..., encoding='utf-8')`, `assert s.count(old) == 1`).
- **Fixture a docs:** jen fiktivní dodavatelé a částky, žádná data
  z diagnostiky.

## Hotovo když

- [x] `contentTags.jsonc` má `crossBorderSupply` podle mapování; drift
      test hlídá hodnoty i whitelist.
- [x] `VatCodeDerivation`: D3 (dovoz zboží) a D4 (veto) s testy nad
      skutečným číselníkem.
- [x] Applier: efektivní `supplyKind` ze štítku řádku, pořadí ve
      `vatContext()`, klíč cache, `supply_kind_derived`; testy nové
      i regresní zelené.
- [x] `docs/exchange-format.md`: fallback, D3, D4, issue v tabulce.
- [x] Prompt v4.6.2, testy verzí, changelog.
- [x] Help `kontrola-vytezeni.md` a `co-dnes-nejde.md` aktualizované,
      `help-index.py` prošel.
- [x] Ověření na dev zdroji: faktura ze třetí země bez zmínky o DPH má
      bez nové analýzy `cz-417` a ř. 12 + ř. 43.
