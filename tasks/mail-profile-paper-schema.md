# Task: Schéma pro model = papírová podoba dokladu; změna schématu se dostane na zdroje dat

**Stav:** hotovo — 2026-11-23 (#166 D63–D66); zbývá `ds-upgrade` ostatních zdrojů dat a evaluace tří sad člověkem

**Issue:** #166

**Cíl:** Model při analýze pošty vidí jen pole, která papírový doklad
opravdu nese — ne reference a pole pokynu určená skriptům. A změna
schématu, které model vidí, se spolehlivě dostane na existující zdroje dat
a je poznat v záznamu evaluace.

## Před implementací přečti

- issue #166 — komentář „Evaluace 2026-11-23 a nález“, rozhodnutí D30–D39,
  D51–D53, D60, D61
- `docs/document-interpretation.md` (vstup, hotový doklad),
  `docs/exchange-format.md` § 5
- `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc` + `.json`
- `modules/core/mail/profiles/czech_general.jsonc` (`prompt_version`,
  `output_schema` s vloženou kopií schématu dokladu)
- `modules/core/mail/src/MailAiProvisioner.php` —
  `ensureDefaultProfile()`, `syncProfileFromTemplate()`,
  `loadProfileTemplate()`; `src/Command/DataSource/DsUpgradeCommand.php`
  (synchronizace profilu), `AiProfileReloadCommand.php`
- `modules/core/mail/src/Analysis/OutputParser.php`,
  `SchemaValidationException.php`, `PromptRenderer.php`
- `modules/core/mail/src/MessageProposalApplier.php`
  (`withoutPartnerBankReference()`, zahození `source.ref`),
  `src/Api/Controller/AnalysisController.php`
- `modules/core/mail/src/Eval/` — `EvalRun.php` (`promptHash`),
  `EvalRunComparison.php`, `Browse/EvalRunSummary.php`
- `tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php`,
  `tests/Unit/Module/Core/Exchange/Schema/SchemaDriftTest.php`

## Kontext

- Profil pošty nese ve `output_schema` doslovnou kopii schématu dokladu
  (strážní test vyžaduje shodu). Etapy 3–5 z #166 do schématu přidaly
  pole určená skriptům: reference strany a položky (`ref`), `numberSeries`,
  `partner`, `rows[].partner`, `vat.registration`,
  `payment.ownBankAccount`, `correction.originalDoc`, `source.ref`,
  `supplier.bankAccount.ref`, jednotku číslem, mód zaokrouhlení; pokladna,
  dimenze a účet řádku jsou reference.
- Synchronizace profilu při `ds-upgrade` se řídí verzí promptu
  (`syncProfileFromTemplate()`); verze zůstala `v4.12.0`, takže
  **existující zdroje dat mají staré schéma** a `ds-upgrade` ho nepřepíše.
  Profil přežije i `ds-reset` (`keepOnReset`). Nové schéma má jen nově
  založený zdroj dat.
- Evaluace 2026-11-23 proto běžela nad starým schématem; `promptHash`
  v záznamu běhu schéma nezahrnuje, z výsledku to poznat nejde.
- Na nově založeném zdroji model vidí i pole pokynu. Návrh z pošty
  z výstupu modelu zahazuje jen `source.ref`
  a `supplier.bankAccount.ref`; ostatní reference by interpretace použila
  s předností před hledáním (D33) — vymyšlená reference by doklad potichu
  navázala na cizí záznam.

## Rozhodnutí k designu (potvrzená, #166)

- ✓ **D63 — Schéma pro model je projekce.** Model dostává schéma dokladu
  bez polí pokynu — „papírovou podobu“. Projekci odvozuje kód z jediného
  schématu dokladu (D3 platí: schéma je jedno, model z něj vidí výřez).
- ✓ **D64 — Co v projekci není:** nic, co papírový doklad nenese —
  reference na záznamy, pole určená skriptům a generátorům, volby uložení.
  Signály D39 (`item.ourCode`, účet řádku holým číslem,
  `vat.registrationCountry`) v projekci **jsou**.
- ✓ **D65 — Změna projekce znamená novou verzi promptu.** Strážní test
  nedovolí změnit schéma, které model vidí, bez zvednutí `prompt_version`
  — synchronizace profilu se verzí řídí dál.
- ✓ **D66 — Otisk schématu v záznamu evaluace,** vedle otisku promptu.

## Upřesnění pro implementaci

- **U1 — Označení v jediném schématu.** Pole, která do papírové podoby
  nepatří, nesou ve schématu dokladu značku (návrh: `"x-paper": false`
  u vlastnosti; `.jsonc` i `.json`). Projekce (`návrh: Schema\PaperSchema`
  v `core.exchange`) je mechanická: značené vlastnosti vynechá,
  a kde je typ „reference, nebo objekt“ (strana, položka), nechá jen
  objekt. Validátor formátu značku ignoruje.
- **U2 — Co se značí** (výchozí seznam; projdi celé schéma a doplň, co
  papír nenese — rozhodnutí u sporných polí zapiš do poznámek):
  - `numberSeries`, `partner`, `rows[].partner`, `cashDesk`,
    `dimensions`, `rows[].dimensions`;
  - `ref` strany, položky a `supplier.bankAccount.ref`; `source.ref`;
  - `vat.registration`, `payment.ownBankAccount`,
    `correction.originalDoc`;
  - `totals.totalRoundingAuto`, `totals.totalRoundingMode`,
    `vat.roundingMode` (mód z papíru odhaduje interpretace);
  - `rows[].unit` jen text; `rows[].account` jen holé číslo (signál D39),
    ne reference;
  - `applyOptions` a `_resolve`, pokud je model dnes ve schématu má
    a prompt je nepoužívá — ověř proti promptu a proti schématu
    uloženému ve zdrojích dat (stav před #166 je dobré měřítko: projekce
    se od něj má lišit jen tím, co se ve formátu opravdu změnilo pro
    papír).
- **U3 — Profil.** `output_schema` v `czech_general.jsonc` nese projekci
  místo plné kopie; `ProfileSchemaDriftTest` porovnává vloženou kopii
  s `PaperSchema` nad aktuálním schématem dokladu. Registrové schéma
  (druhá větev `oneOf`) beze změny.
- **U4 — Verze a strážce (D65).** `prompt_version` → `v4.13.0`. Nový test:
  otisk schématu, které model vidí (celé `output_schema` profilu), je
  zapsaný u verze (v profilu nebo v testu); změna otisku bez změny verze
  test shodí se zprávou, co udělat. Po `ds-upgrade` tak nové schéma
  dostanou všechny zdroje dat.
- **U5 — Výstup modelu.** Ověř, že `OutputParser` výstup validuje proti
  uloženému schématu profilu tak, že pole mimo projekci (např.
  `supplier.ref`) analýzu **odmítne** (ne tiše propustí) — doplň test.
  Zahazování `source.ref` a reference účtu v `MessageProposalApplier`
  a `AnalysisController` nech (pojistka pro REST a starší data).
  Obohacení řádků z historie (`item.ref`, účet referencí) běží až po
  analýze a tímhle se nemění.
- **U6 — Evaluace (D66).** `effective.schemaHash` v záznamu běhu
  (`EvalRun`), v souhrnu a v porovnání běhů (rozdílné schéma je vidět
  stejně jako rozdílný prompt). Starší záznamy pole nemají — porovnání to
  musí snést.
- **U7 — Dokumentace.** `docs/document-interpretation.md` (vstup:
  papírová podoba = projekce schématu, seznam vynechaných polí odkazem na
  značku), `docs/exchange-format.md` § 5 (značka `x-paper`), dokumentace
  profilu a synchronizace (kde je popsaný `ai-profile-reload`),
  `CLAUDE.md` — pravidlo „změna schématu, které vidí model = nová verze
  promptu“.

## Co je potřeba udělat

1. Značky ve schématu, `PaperSchema` + unit testy projekce (žádná
   reference, žádné pole z U2; signály D39 zůstaly).
2. Profil: projekce v `output_schema`, verze, strážní testy (U3, U4).
3. Výstup modelu (U5), evaluace (U6).
4. Dokumentace (U7), `**Stav:**`, `python3 scripts/tasks-index.py`.

### Ověření na dev zdroji (zdroje s třídou dat `free`)

1. Po `ds-upgrade` volného zdroje: profil má verzi `v4.13.0` a schéma bez
   polí z U2 (dotaz do `core_mail_ai_profiles`).
2. `dataset-verify` tří veřejných sad beze změny (uložené návrhy sad se
   nemění).
3. Integrační testy pošty a výměnného formátu bez selhání.
4. **Evaluace tří veřejných sad nad novým schématem — pouští člověk.**
   Měřítko jsou běhy z 2026-11-23 (staré schéma): shoda polí, částky,
   počty řádků, cena. Rozdíl ve vstupních tokenech zapiš.

## Mimo rozsah

- Změna pravidla synchronizace profilu (řízení verzí zůstává).
- Odstranění signálů D39.
- Schémata ostatních formátů a registrových dokumentů.
- Text promptu — mění se jen verze; zmiňuje-li prompt pole, které
  z projekce mizí, zapiš to do poznámek a zastav se.

## Pasti

- Schéma dokladu má tři kopie (`.jsonc`, ručně držené `.json`, profil) —
  značka patří do prvních dvou, profil nese projekci.
- `additionalProperties: false`: uložené návrhy a hotové doklady nesou
  pole mimo projekci (reference z obohacení, `_resolve`) — projekcí se
  validuje **jen výstup modelu**, nikdy canonical v dalších krocích.
- Profil je v `keepOnReset` — čerstvý seed ho nevymění, mění ho jen
  `ds-upgrade` (podle verze) a `ai-profile-reload --force`.
- PHPUnit s úzkým `--filter`, grep s `--include`.

## Hotovo když

- [x] schéma pro model je projekce bez polí pokynu, odvozená kódem
      z jediného schématu
- [x] verze promptu `v4.13.0`; změna schématu bez změny verze neprojde
      testem
- [x] výstup modelu s polem mimo projekci analýza odmítne
- [x] záznam evaluace nese otisk schématu
- [x] `dataset-verify` beze změny; cílené testy, pak celá unit sada
- [x] dokumentace, stav tasku

## Poznámky k implementaci

### Model schéma nedostává — projekce je brána na výstupu

Zadání mluví o schématu, které „model vidí“. Šablona promptu proměnnou
`output_schema` nevykresluje a volání nemá strukturovaný výstup: model se
řídí ukázkou JSON v promptu (ta klíče mimo ukázku zakazuje) a `output_schema`
čte jen `OutputParser`. Rozhodnutí D63–D66 to nemění, mění to očekávání:

- projekce nic „nenapovídá“, jen **odmítá** (U5) — to je její přínos;
- vstupní tokeny se nemění (bod 4 ověření: rozdíl má být nula);
- evaluace z 2026-11-23 platí pro kvalitu extrakce beze změny — běžela
  nad starým schématem jen ve smyslu validace výstupu;
- popisy „never fill this field from a paper document“ u polí schématu se
  k modelu nikdy nedostaly.

### Stav uložený ve zdrojích dat (měřítko z U2)

Všech 21 zdrojů dat dev serveru s profilem mělo stejné schéma — stav
commitu s `v4.12.0` z 2026-11-12. Proti dnešnímu schématu mu chyběla nejen
pole z #166, ale i pole z #159 (`vat.rateSource`, `dates.vatRateDate`,
`rateDate` řádku a rekapitulace), která přibyla také bez nové verze.
A naopak **propouštělo** volný objekt `_resolve` a `applyOptions`
(`targetDocState`, `numberSeriesId`, `importOwnBankAccount` …). Prompt ani
jedno nezmiňuje, z projekce podle U2 mizí.

### Značka a projekce (U1)

- `"x-paper": false` vlastnost vynechá, `"x-paper": { … }` ji nahradí
  uvedeným schématem. Náhradu potřebují dvě pole, kde papír nese jiný tvar
  než pokyn: `rows[].unit` (jen text) a `rows[].account` (řetězec číslic —
  signál D39; tvar shodný se schématem před #166).
- Strana a položka řádku: uzel s typem „objekt, nebo reference“ zůstane
  jen objektem — pravidlo kódu, bez značky.
- Po projekci osiří `$defs/Reference` a odstraní se; `$id` a `title`
  projekce nesou příponu (`….v1.paper.json`, „— paper form“), aby šla
  v uloženém profilu poznat od plného schématu.
- Vynechat povinnou vlastnost, značka jinde než u vlastnosti nebo s jinou
  hodnotou než `false` / objekt je výjimka — chyba ve schématu se ukáže
  v testech, ne tichým výsledkem.
- `Attachment.ref` (`att:12`) není reference na záznam a zůstává.

### Co se značí — sporná pole (U2)

Výchozí seznam z U2 beze změny. Navíc, po potvrzení v chatu 2026-11-23
(D64 „nic, co papír nenese, včetně voleb uložení“ má přednost před
měřítkem „jen co se změnilo“):

| Pole | Proč není v papírové podobě |
|------|-----------------------------|
| `_resolve`, `applyOptions`, `savedDocId` | volby uživatele a uložení, id po uložení — doplňuje server |
| `source.message`, `source.raw` | vazbu na zprávu a syrový payload doplňuje server |
| `balanceParty` | ruční plátce pro saldokonto — volba uživatele |
| `fiscalPeriodType` | jen importní mód |
| `vat.recapSource`, `vat.calcSource`, `vat.controlStatementMode` | volby uložení (autoritu rekapitulace odvozuje interpretace) |
| `vat.rateSource`, `dates.vatRateDate`, `rows[].vat.rateDate`, `vatRecap[].rateDate` | přepínač a vlastní data sazby (#159) — nastavení dokladu |
| `rows[].operation` | pohyb řádku určuje systém |

Ponecháno, protože to papír nést může nebo to ukázka v promptu má:
`cashDirection`, `exchangeRate`, `dates.accountingDate`, `notes.internal`,
`rows[].computed`, `rows[].accSide` a symboly / splatnost řádku,
`vatRecap[].isReversePair`, `vatRecap[].reverseChargeCode`, `attachments[]`.

Riziko odmítnutí dnešních výstupů: žádné pole ze seznamu se nevyskytuje
v uložených výstupech modelu (viz Ověření).

### Profil a strážce verze (U3, U4)

- Blok dokladu v `czech_general.jsonc` se nepíše ručně:
  `php scripts/profile-paper-schema.php` (`--check` jen ověří).
- Otisk celého `output_schema` (`Schema\SchemaFingerprint` — sha256 nad
  JSON se seřazenými klíči, nezávislý na formátování) je u verze zapsaný
  v `ProfileSchemaDriftTest::SCHEMA_FINGERPRINTS`; řádky se jen přidávají.
  Změna otisku bez nové verze test shodí a vypíše, co udělat.
- Text promptu: změněny jen tři výskyty verze (pole profilu a dva
  v šabloně). Prompt žádné z mizejících polí nezmiňuje — podmínka
  „zastav se“ z Mimo rozsah nenastala.

### Výstup modelu (U5)

`OutputParser` se nemění — validuje proti schématu profilu
s `additionalProperties: false`, takže stačí projekce. Test nad skutečným
profilem: 17 případů odmítnutí (reference strany, položky, účtu partnera,
řada, partner, registrace, vlastní účet, původní doklad, dimenze, mód
zaokrouhlení, `source.ref`, `applyOptions`, `_resolve`, strana a položka
referencí, účet `"@518100"`, jednotka číslem) a průchod signálů D39.
Sdílená fixtura dokladu nesla `source.message` (doklad po zpracování
serverem) — test ji pro výstup modelu odebírá.

### Evaluace (U6)

`effective.schemaHash` v `EvalRun`, `EvalRunSummary` a v porovnání
(`runs.*.schemaHash`, `sameSchema` a nově i `samePrompt` — `null`, když
otisk některý záznam nenese). Lidský výpis porovnání hlásí rozdílný prompt
i schéma, výpis běhu má otisk za verzí promptu. Dashboard: krátký otisk
v řádku běhu (seznam, detail, porovnání), u staršího běhu pomlčka.

### Ověření

1. `ds-upgrade` čtyř volných zdrojů (`jfpl-7`, `gsbg-b`, `im3v-h`,
   `blkm-i`): profil `v4.12.0 → v4.13.0`, otisk schématu v databázi shodný
   s otiskem v testu, žádné pole z U2. Ostatní zdroje dat upgradované
   nejsou.
2. Přehrání uložených výstupů modelu novým schématem (zdarma, bez volání
   AI): 154 výstupů na sedmi volných zdrojích, odmítnutý žádný.
3. Tři veřejné sady nad zdroji testovacího kola (`test-round
   --only=datasets`): čerstvý seed, `dataset-verify` a proklikání — 9 kroků
   `ok`, stejně jako před změnou. Profil po `ds-reset` zůstal `v4.13.0`
   (`keepOnReset`).
4. Integrační testy pošty a výměnného formátu nad `blkm-i`: 218 testů,
   6 přeskočených, bez selhání.
5. Celá unit sada 12 268 testů zelená; `npm run build`, `npm test`,
   `check:i18n`. Řádek běhu na dashboardu ověřený v headless prohlížeči
   (běh bez otisku i s otiskem).

**Zbývá člověku:**

- `ds-upgrade` ostatních zdrojů dat (profil se přepíše na `v4.13.0`);
  na kopiích reálných dat a na produkci je to jediný způsob, jak se tam
  nové schéma dostane.
- Evaluace tří veřejných sad nad novým schématem (bod 4 ověření v zadání).
  Očekávání: metriky v šumu proti běhům z 2026-11-23, vstupní tokeny beze
  změny (model schéma nedostává), žádné `schema_error`; záznam ponese
  `schemaHash` `64a1f43d…`. Zdroj dat evaluace musí být před během
  upgradovaný — otisk v záznamu to nově prokáže.
