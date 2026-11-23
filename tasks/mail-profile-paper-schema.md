# Task: Schéma pro model = papírová podoba dokladu; změna schématu se dostane na zdroje dat

**Stav:** naplánováno — zadání 2026-11-23 (#166, nález z evaluace; D63–D66)

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

- [ ] schéma pro model je projekce bez polí pokynu, odvozená kódem
      z jediného schématu
- [ ] verze promptu `v4.13.0`; změna schématu bez změny verze neprojde
      testem
- [ ] výstup modelu s polem mimo projekci analýza odmítne
- [ ] záznam evaluace nese otisk schématu
- [ ] `dataset-verify` beze změny; cílené testy, pak celá unit sada
- [ ] dokumentace, stav tasku
