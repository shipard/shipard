# Task: Přenesení daňové povinnosti u přijatých dokladů — kód DPH odvozený ze signálů

**Stav:** částečně — commit 1/3 (číselník `supplyKind` / `reducedDeduction` + `VatCodeDerivation`); applier, prompt a help následují

**Issue:** #86

**Cíl:** AI návrh přijaté faktury se samovyměřením (služby a zboží z EU,
služby ze třetí země, tuzemské PDP) dostane správný kód DPH z číselníku
země naší registrace (např. `cz-217`), sazbu z našeho číselníku a
přepočítanou rekapitulaci s oddaňovacím párem. Dnes takový návrh končí
chybou `vat_code_unknown` a nejde použít.

Účetní a daňová strana je hotová (#14, `docs-vat-totals-reverse-charge.md`):
kódy s `reverseVatCode` se počítají, párují, účtují i vykazují. Chybí jen
vstup — AI extrakce, ISDOC a applier.

Návaznost: roadmapa M0 — „Hotovo když“ výslovně zahrnuje faktury s reverse
charge; tohle je mezera v cestě z došlé pošty. Navazující (mimo tento task):
výběr kódu DPH v náhledu návrhu (D6).

## Před implementací přečti

- `docs/exchange-format.md` — `vat` objekt (~ř. 200), `RowVat` (§7, ~ř. 560),
  resolve kódu DPH (~ř. 690), autorita rekapitulace (~ř. 300)
- `docs/vat-calculation.md` §4 (speciální kódy) a §5 (autorita rekapitulace)
- `modules/core/exchange/src/Document/VatModeDerivation.php` — vzor
  deterministické derivace nad canonicalem (canonical se nemění, korekce
  jde do `_resolve.issues`)
- `modules/core/mail/docs/ai-prompts.md` — workflow ladění promptu, changelog
- předchozí prompt task `exchange-contact-name.md` — sekce Pasti (tři kopie
  schématu, escapování `prompt_template`, verze promptu)

## Kontext — diagnostika

Dva přijaté doklady dodavatelů z jiných členských států EU (služby, EUR,
na dokladu „reverse charge“, DPH 0). Review modal hlásí
`rows.0.vat.code — Neznámý kód DPH „…“`. Model vrátil (anonymizováno,
hodnoty polí přesně):

| pole | doklad A (v4.3.0) | doklad B (v4.5.0) | správně |
|---|---|---|---|
| `rows[].vat.code` | `reverse-charge` | `eu-reverse` | `cz-217` |
| `rows[].vat.pct` | 0 | 0 | 21 (z našeho číselníku) |
| `vat.registrationCountry` | země dodavatele | země dodavatele | `cz` |
| `vat.place` | `crossBorder` | `eu` | `intracom` |
| `vat.mode` | `reverseCharge` | `fromBase` | `fromBase` |
| `vatRecap` | — | 1 řádek 0 %, daň 0, `isReversePair: true` | přepočítat |

Příčiny, vrstvené:

1. **Prompt číselník neobsahuje.** Jediný vzor je `cz-110`, `RowVat.code`
   je volný string → model kód vymyslí.
2. **`registrationCountry` = země dodavatele** — prompt sémantiku
   nevysvětluje a `IsdocReader.php` (~ř. 138) ji plní natvrdo zemí
   dodavatele. `DocumentApplier::vatCountryForCode()` (~ř. 1357) dává
   `registrationCountry` přednost před prefixem kódu, takže **i správný
   `cz-217` by se hledal v cizím číselníku** a skončil `notFound`.
   `resolveVatRegistrationFor()` (~ř. 2033) navíc vrátí `null` → doklad
   by neprošel potvrzením (`vat_registration` povinná při stavu 40).
3. **Tichý fallback enumů** — `VAT_PLACE_MAP` (~ř. 96) zná jen
   `domestic` / `intracom` / `thirdCountry`, cokoli jiného spadne na
   `0 Tuzemsko`; neznámý `vat.mode` na `fromBase`. Bez issue.
4. **Rekapitulace dodavatele jako autorita** — `resolveRecapSource()`
   (~ř. 1397) by 0% rekapitulaci převzal jako `declared` (0% řádky
   aritmetickou kontrolou projdou). Se správným kódem by samovyměření
   zmizelo: rekapitulace dodavatele je z jeho pohledu.

Chyba `vat_code_unknown` má `severity: error` a blokuje apply
(`validation_failed`) — nic se tedy špatně nezaúčtovalo. V náhledu ale kód
DPH změnit nejde, návrh je slepá ulička.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — kód určuje systém, ne model.** AI vrací jen sémantické signály,
  kód deterministicky vybere applier z číselníku země registrace
  (`VatCodeDerivation`, vzor `VatModeDerivation`). Model nezná per-country
  klíče — směr k více zemím EU.
  **Upřesnění granularity (při psaní tasku):**
  - hlavička `vat.reverseCharge` (bool | null) — daň přiznává příjemce;
  - řádek `rows[].vat.supplyKind` (`goods` | `services` | null) — rozhoduje
    jen mimo tuzemsko;
  - řádek `rows[].vat.reverseChargeCode` (string | null) — kód předmětu
    plnění pro kontrolní hlášení u tuzemského PDP (`4` stavební práce,
    `5` příloha 5 …).

  Smíšený doklad (část řádků PDP, část s DPH) je mimo rozsah.
- ✓ **D2 — `registrationCountry` u `selfParty: customer`** se vždy odvodí
  z naší registrace DPH (stejná volba jako výchozí hodnota formuláře,
  `DocsHeadsFormBase` ~ř. 145 = první aktivní registrace). Hodnota z AI
  i ISDOC se ignoruje; při rozporu info issue. `IsdocReader` pole přestane
  plnit zemí dodavatele.
- ✓ **D3 — autorita rekapitulace.** Když má kterýkoli položkový řádek
  (po derivaci) kód s `reverseVatCode`, rekapitulace je vždy `computed`
  + info issue `recap_source_computed_fallback` s důvodem. Explicitní
  `vat.recapSource: "declared"` (import ze starého Shipardu, s páry)
  zůstává beze změny.
- ✓ **D4 — sazba samovyměřených řádků** z našeho číselníku k DUZP,
  kategorie `standard` (snížená sazba u samovyměření mimo rozsah —
  uživatel opraví v dokladu). `pct: 0` z dokladu dodavatele se u nich
  ignoruje. U tuzemských řádků bez PDP rozhoduje sazba z dokladu.
- ✓ **D5 — enumy** `vat.place` (`domestic` | `intracom` | `thirdCountry`
  | null) a `vat.mode` (`fromBase` | `fromTotal` | `none` | null) jako
  `enum` ve schématu (všechny tři kopie) **a** výslovně v textu promptu;
  applier u neznámé hodnoty (import z jiných zdrojů) přidá warning
  `vat_place_unknown` / `vat_mode_unknown` místo tichého fallbacku.
- ✓ **D6 — výběr kódu DPH v náhledu návrhu** (userAction per řádek) —
  **samostatný navazující task**, ne tady.
- ✓ **D7 — rozsah:** EU služby (`cz-217`), EU zboží (`cz-215`), tuzemské
  PDP (`cz-115` / `cz-117`), služby ze třetí země (`cz-417`), a tuzemské
  kódy bez PDP. Otevřené: zahraniční DPH naúčtovaná dodavatelem (hotel,
  PHM v cizině — není samovyměření), PDP kódy, které číselník nemá
  (mobilní telefony, povolenky …), dovoz zboží (jde přes celní doklad).

## Algoritmus derivace (D1, D4)

Běží v `DocumentApplier::resolveAll()` pro každý položkový řádek **jen
u `selfParty: customer`** (směr `input`). Vystavené doklady, účetní doklady
a doklady bez naší registrace DPH jdou dnešní cestou.

Vstup: země registrace (D2), DUZP (fallback datum vystavení), místo
plnění (`vat.place` → název v číselníku: `domestic`→`domestic`,
`intracom`→`intracom`, `thirdCountry`→`foreign`), `vat.reverseCharge`,
řádkové `pct`, `supplyKind`, `reverseChargeCode`.

Kandidáti = `VatRateResolver::getVatCodes(země, 'input', místo)` bez
`hidden` a bez `reducedDeduction` (krácený odpočet se nikdy neodvozuje), pak:

- **samovyměření** (`reverseCharge === true`, nebo místo ≠ domestic):
  kandidáti s `reverseVatCode`, kategorie `standard`; mimo tuzemsko navíc
  `supplyKind` řádku; v tuzemsku `reverseChargeCode` řádku;
- **bez samovyměření v tuzemsku:** kandidáti bez `reverseVatCode`, jejichž
  sazba k datu (`resolveVatPct`) = `pct` řádku (±0,001). Nerozhoduje název
  kategorie — `reduced1`/`reduced2` platily jen 2015–2023.

Výsledek je kód, **jen když zbyde právě jeden kandidát**; jinak `null`
s důvodem (pro zprávu issue).

Upřesnění při implementaci (2026-09-30):

- `vat.place` null = tuzemsko.
- **Zahraniční DPH:** místo ≠ tuzemsko, `reverseCharge` není `true`
  a `pct` řádku > 0 (hotel, PHM v cizině) → `null` s důvodem, ne
  samovyměření. Jinak by se základ s cizí daní zdanil podruhé; případ je
  D7 otevřený, návrh skončí `vat_code_unknown` a doklad se založí ručně.
- **Zdroj bez registrace DPH** (`ownVatRegistration()` nic nenajde):
  derivace ani D2 se neuplatní, kód i země jdou dnešní kaskádou.
- Soulad existujícího kódu se signály ověřuje `VatCodeDerivation::conflict()`
  (stejná třída, čte definici kódu z `VatRateResolver::getVatCode()`).
- **`vat.mode: "none"` + samovyměření** (nález při ověřování): model
  u faktury s DPH 0 vrátil `none`; doklad Bez DPH rekapitulaci nestaví
  a samovyměření by se ztratilo. Applier v takovém případě vynutí
  `fromBase` + warning `vat_mode_derived`; prompt říká, že `none` patří
  jen dokladu zcela bez DPH.

Chování v `resolveAll()`:

| vstupní `vat.code` | derivace | výsledek |
|---|---|---|
| prázdný | kód | `matched`, `matchedBy: "derived"`, **bez issue** (jinak by šum byl u každé faktury) |
| platný a v souladu se signály | cokoli | beze změny (dnešní chování) |
| neznámý, nebo v rozporu se signály | kód | odvozený kód, warning `vat_code_derived` (původní hodnota ve zprávě) |
| neznámý / prázdný | `null` | error `vat_code_unknown`, zpráva doplněná o důvod a „doklad založ ručně“ |

„V souladu“: `place` kódu = místo z hlavičky; má `reverseVatCode` ⇔
`reverseCharge` (když není null); `supplyKind` sedí (když je na obou
stranách); u tuzemska bez PDP sedí sazba. Null signál se nekontroluje.

Řádek bez kódu, bez sazby a bez signálů v hlavičce (`place`,
`reverseCharge` null) zůstává jako dnes — bez `vatCode` v `_resolve`
a bez issue; error `vat_code_unknown` u prázdného kódu vzniká jen tam,
kde derivace měla z čeho vycházet.

## Co je potřeba udělat

### Commit 1 — číselník a derivace (world.vat, exchange)

`modules/world/vat/config/vat-cz.jsonc`:

- `supplyKind: "goods"` / `"services"` na všechny kódy s `place`
  `intracom` a `foreign`, vstup i výstup (podle `fullName`: „Zboží“ →
  goods, „Služby“ a „Ostatní“ → services; `cz-201`/`cz-202`, `cz-401`
  obdobně).
- `reducedDeduction: 1` na `cz-118`, `cz-119`, `cz-341`, `cz-342`.
- `VatRateResolver::validateCountryConfig()` (~ř. 110): `supplyKind` jen
  z výčtu `goods` / `services`.

Nová třída `modules/core/exchange/src/Document/VatCodeDerivation.php`
podle algoritmu výše — závisí jen na `VatRateResolver`, vrací
`{code: ?string, reason: ?string}`. Docblock s odkazem na tento task.

Testy: `tests/Unit/Module/Core/Exchange/Document/VatCodeDerivationTest.php`
nad **skutečným** `vat-cz.jsonc` (vzor `VatReverseCodeRatesTest`):
EU služby → `cz-217`, EU zboží → `cz-215`, třetí země služby → `cz-417`,
PDP 4 → `cz-115`, PDP 5 → `cz-117`, tuzemsko 21 → `cz-110`,
12 (2026) → `cz-111`, 0 → `cz-112`, 15 (2020) → `cz-301`; nikdy krácený
kód; `supplyKind` null mimo tuzemsko → null s důvodem; PDP bez
`reverseChargeCode` → null; neexistující `reverseChargeCode` (`12`) → null.
`VatRateResolverTest` — konfigurace dál validní, neplatný `supplyKind`
chycen.

Ověření: `php -l`, `vendor/bin/phpunit --filter 'VatCodeDerivationTest|VatRateResolverTest|VatReverseCodeRatesTest'`.

### Commit 2 — schéma, applier, ISDOC (D1–D3, D5)

Schéma ve **třech kopiích** (`shpd.docs.document.v1.jsonc` `vat` ~ř. 107
a `RowVat` ~ř. 370; `.json` ~ř. 58 a ~ř. 247; inline v
`modules/core/mail/profiles/czech_general.jsonc` ~ř. 337 a ~ř. 925):

- `vat.place` → `enum` `["domestic", "intracom", "thirdCountry", null]`
- `vat.mode` → `enum` `["fromBase", "fromTotal", "none", null]`
- nové `vat.reverseCharge` `{"type": ["boolean", "null"]}`
- nové `RowVat.supplyKind` `enum ["goods", "services", null]`,
  `RowVat.reverseChargeCode` `{"type": ["string", "null"]}`

`DocumentApplier`:

- **Zapojení:** applier drží jen `VatCodeResolver`, ne `VatRateResolver`
  → nový konstruktorový parametr `VatCodeDerivation`, ve factory
  `create()` `new VatCodeDerivation($vatRateResolver)`; v testech
  `buildApplier()` volitelný parametr, výchozí = skutečná derivace nad
  `vat-cz.jsonc` (mock `ConfigRuntime`). Pro D4 (`pct` k datu) potřebují
  nové testy i skutečný `VatCodeResolver` nad tímtéž číselníkem —
  výchozí mock v testu vrací `pct: null`.
- **Pořadí volání:** `appendRecapSourceIssue()` (~ř. 279/317) běží
  **před** `resolveAll()` a `resolveRecapSource()` se volá znovu
  z `transform()`. Odvozené kódy proto nežijí v `resolveAll()`, ale
  v líně počítaném a cachovaném „DPH kontextu dokladu“ (vzor
  `applyOptionsCache`): země + id naší registrace, DUZP, odvozený kód
  a důvod per řádek. Čtou ho `resolveAll()`, `resolveRecapSource()`,
  `recapCodesFromRows()` i `resolveVatRegistrationFor()`.
- **D2:** nová privátní metoda „země naší registrace“ (první aktivní
  `economy_codebooks_vat_registrations` podle `country`, `id` — stejné
  pořadí jako výchozí hodnota formuláře,
  `DocsHeadsFormBase::resolveVatRegistrationOptions()`). U `selfParty: customer` ji použít v `resolveAll()`
  (~ř. 553), `resolveRecapSource()` (~ř. 1420) i
  `resolveVatRegistrationFor()` (~ř. 2033) místo
  `vat.registrationCountry`. Rozdílná neprázdná hodnota v canonicalu →
  info issue `vat_registration_country_derived` na `vat.registrationCountry`.
  `vatCountryForCode()` kaskáda (registrace → prefix → země dodavatele)
  zůstává pro ostatní doklady.
- **D1:** v `resolveAll()` (~ř. 598) před `VatCodeResolver::resolve()`
  zavolat derivaci dle tabulky výše; odvozený kód resolvnout stejným
  resolverem (doplní `pct` k datu — D4).
- **D3:** `resolveRecapSource()` — pokud je `vat.recapSource` jiný než
  explicitní `declared` a některý řádek má (po derivaci) kód
  s `reverseVatCode` → `computed` + fallback důvod „přenesení daňové
  povinnosti — rekapitulace dodavatele se nepřebírá“.
  `recapCodesFromRows()` (~ř. 1490) musí brát **odvozené** kódy, ne
  `rows[].vat.code` z canonicalu (jinak tuzemská rekapitulace bez kódů
  ztratí převzetí).
- **D5:** neznámá hodnota `vat.mode` / `vat.place` → warning
  `vat_mode_unknown` / `vat_place_unknown` (fallback hodnota v transformu
  zůstává). **Ne v `transform()`** (~ř. 1133/1137) — ten běží až
  v transakci apply po sestavení `_resolve` a preview ho nevolá, warning
  by se nikdy nezobrazil. Patří do nové `appendVatHeaderIssues()` volané
  z `preview()` i `apply()` vedle `appendVatModeIssue()` (~ř. 278/316);
  tam vzniká i info issue D2 `vat_registration_country_derived`.

`IsdocReader.php` (~ř. 137): `vat.registrationCountry` nevyplňovat
(`null`); test `IsdocReaderTest` (~ř. 169) upravit.

Testy v `DocumentApplierTest`:

- nové: EU služby bez kódu → `cz-217`, `pct` 21, `vat_place` 1, registrace
  `cz`, rekapitulace přepočítaná, bez `vat_code_unknown`; vymyšlený kód
  (`eu-reverse`) + signály → `cz-217` + `vat_code_derived`;
  `registrationCountry` dodavatele → registrace z DB + info issue;
  PDP 4 → `cz-115`; nepodporovaný případ → `vat_code_unknown` s důvodem;
  0% rekapitulace dodavatele + reverse kód → `vat_recap_source` 0;
  explicitní `declared` s páry zůstává 1; `place: "eu"` → warning.
- přepsat (kódují staré chování pro přijaté doklady):
  `testExplicitVatRegistrationCountryBeatsCodePrefix` (~ř. 2067),
  `testRecapCodeResolvesWithSupplierCountryWhenNoPrefix` (~ř. 1336),
  `testRowVatCountryFallsBackToCodePrefixWhenVatObjectMissing` (~ř. 1996)
  — ověřit, které se týkají `selfParty: customer`, a buď převést na
  vystavený doklad, nebo změnit očekávání na D2.
- regrese zelená: `testDeclaredRecap*`, `testReceivedDocument*`,
  `testRecapWithoutCode*`, `VatModeDerivationTest`.

Docs `docs/exchange-format.md`: nová pole a jejich sémantika, hodnoty
`vat.place` (canonical názvy, ne klíče `docs.core.vatPlaces`), sekce
„Odvození kódu DPH u přijatých dokladů“ (tabulka chování), D2 a D3
u autority rekapitulace; nové issue kódy do tabulky issues; **příklady
`highEU` (~ř. 268, 564, 772) → `cz-110`**. Docblock
`VatCodeResolver.php` (~ř. 27) — příklad kódu `cz-110`.

Ověření: `php -l`, `vendor/bin/phpunit --filter 'DocumentApplierTest|DocumentApplierNoItemTest|DocumentValidatorTest|IsdocReaderTest|SchemaDriftTest|ProfileSchemaDriftTest|SchemaValidatorTest'`.

### Commit 3 — prompt v4.6.0, uživatelská dokumentace, uzavření

`modules/core/mail/profiles/czech_general.jsonc`:

- `prompt_version` → `v4.6.0` (změna schématu = minor), v textu obě
  zmínky `v4.5.0`.
- Pravidla DPH v `prompt_template` (nové body k ostatním pravidlům
  o `"vat"`):
  - `"vat".registrationCountry` vynech (null) — určí ho systém;
  - `"vat".place` jen `domestic` (dodavatel z ČR), `intracom` (dodavatel
    z jiného státu EU), `thirdCountry` (mimo EU) — jiné hodnoty nejsou
    povolené;
  - `"vat".reverseCharge: true`, když doklad uvádí, že daň přiznává nebo
    odvádí příjemce („reverse charge“, „přenesení daňové povinnosti“,
    „daň odvede zákazník“, odkaz na čl. 196 směrnice, § 92a) — i když je
    na dokladu DPH 0;
  - `rows[].vat.code` a `vatRecap[].vatCode` vždy null — kód určí systém;
    `rows[].vat.pct` opisuj z dokladu (i 0);
  - `rows[].vat.supplyKind` u dodavatele mimo ČR: `goods` / `services`;
  - `rows[].vat.reverseChargeCode` jen u tuzemského přenesení: `4`
    stavební a montážní práce, `5` zboží z přílohy 5 (šrot, odpad),
    jinak číslo předmětu plnění z dokladu;
  - `vatRecap[].isReversePair` vynech.
- Ukázka: `vat` s `"reverseCharge": false`, řádky s `"code": null`,
  rekapitulace s `"vatCode": null`.

Testy verzí: `ProfileSchemaDriftTest::testProfileMetadata` (ř. 89),
`AIAnalyzerProvisionerTest` (ř. 177) → `v4.6.0`; stale kontroly
(ř. 113 / 140) rozšířit o `v4.5.0`.

`modules/core/mail/docs/ai-prompts.md`: nadpis a zmínky verze →
v4.6.0, changelog `### v4.6.0` — důvod (diagnostika výše, bez
identifikace zdroje), nová pole, odkaz na tento task.

Uživatelská dokumentace (ve stejném commitu jako změna chování):

- `help/co-dnes-nejde.md` — **smazat** položku „Reverse charge
  (samovyměření) v rekapitulaci DPH“ (~ř. 127). Do části o návrzích
  z pošty přidat krátkou položku s tím, co AI návrh dál neumí (D4, D7):
  snížená sazba u samovyměření (návrh dá základní — oprav v dokladu),
  faktura se zahraniční DPH, PDP mimo stavební práce a přílohu 5 → návrh
  skončí chybou kódu DPH, doklad založ ručně.
- `help/posta/kontrola-vytezeni.md` (~ř. 172) — odstavec **Reverse
  charge** přepsat: co zkontrolovat (kód DPH řádků, v rekapitulaci řádek
  daně a oddaňovací řádek, **Celkem** = základ) a že kurz cizí měny se
  doplňuje v dokladu.
- `help/posta/kdyz-ai-cte-spatne.md` — v tabulce (~ř. 24) kód DPH **není**
  opravitelný v náhledu (patří k „Až v dokladu“ — D6 ještě není);
  odstavec o známých chybách (~ř. 71) bez samovyměření.
- Názvy sekcí a tlačítek ověřit ve zdroji popisků (`frontend/src/i18n/cs.js`,
  `module.jsonc`). Pak `python3 scripts/help-index.py`.

`**Stav:**` tohoto tasku → `hotovo` (nebo `částečně — zbývá ověření`)
+ `python3 scripts/tasks-index.py`.

Ověření: `vendor/bin/phpunit --filter 'ProfileSchemaDriftTest|AIAnalyzerProvisionerTest|AiProfileReloadCommandTest|DsUpgradeCommandTest|AIProfileDocumentTest'`,
na konci celá sada lokálně.

### Ověření na dev zdroji

Zdroj s režimem *volný* (zdroj a zprávy jsou v chatu, mimo repo).

1. `vendor/bin/shpd-ds ds-upgrade` → profil `czech_general` v
   `core_mail_ai_profiles` má `prompt_version = v4.6.0`.
2. U obou zasažených zpráv „Znova analyzovat“ → nová analýza `status = 2`,
   v `canonical_json` `vat.place = intracom`, `vat.reverseCharge = true`,
   `rows[].vat.supplyKind = services`, kódy null.
3. Náhled návrhu bez chyby kódu DPH; `_resolve.rows[].vatCode` =
   `cz-217`, `matchedBy: derived`, `pct` 21.
4. „Vystavit koncept“ → doklad: `vat_place` 1, registrace `cz`,
   rekapitulace přepočítaná se dvěma řádky (`cz-217` nárok,
   `cz-207` pár), **Celkem** = základ. Kurz doplnit ručně a potvrdit —
   deník a živé výstupy DPH ukazují ř. 5 a ř. 43.

## Mimo rozsah

- D6 — výběr kódu DPH v náhledu (samostatný task).
- Otevřené body D7 (zahraniční DPH, další PDP kódy, dovoz zboží),
  snížená sazba u samovyměření, smíšené doklady.
- Automatický kurz ČNB k DUZP — dnes se kurz u cizí měny zadává v dokladu
  ručně (povinný až při potvrzení).
- Vystavené doklady (`selfParty: supplier`) a `DocumentExporter` —
  nová pole se neexportují (round-trip nese kód).

## Pasti

- **Názvy míst se liší:** canonical `thirdCountry` = číselník `foreign`;
  `intracom` stejně. Mapovat na jednom místě.
- **Krácený odpočet** (`cz-118` …) má stejnou kategorii, místo i sazbu
  jako `cz-110` — bez `reducedDeduction` by derivace nebyla jednoznačná.
- **Kategorie vs. sazba:** tuzemské kódy vybírat podle sazby k datu, ne
  podle názvu kategorie (`reduced1`/`reduced2` jen 2015–2023).
- **`RowHistoryEnricher` doplní kód z historie** (~ř. 243), když je
  prázdný — do applieru pak přijde kód. Proto kontrola souladu se
  signály; jinak by stará chybná historie přebila derivaci.
- **`recapCodesFromRows()` čte canonical** — po změně promptu tam kódy
  nejsou; bez přepnutí na odvozené kódy domácí faktury přestanou brát
  rekapitulaci jako převzatou (regrese #75).
- **Model schéma nevidí.** `enum` bez výslovného výčtu v textu promptu
  = `schema_error` celé analýzy (stejná past jako `contact.name`).
- **Testy kódují starou kaskádu země** (registrace → prefix → dodavatel);
  u přijatých dokladů ji D2 mění záměrně, u vystavených ne.
- **Zdroj bez registrace DPH** (neplátce): derivace ani D2 se neuplatní,
  chování jako dnes (`vat-payer-01-non-vat-payer.md`).
- **Explicitní `declared`** (import ze starého Shipardu) se nesmí změnit —
  nese páry a je autoritou z našich knih.
- **Tři kopie schématu, `prompt_template` jako jeden JSON řetězec,
  verze promptu třikrát, sync profilu jen při vyšší verzi** — viz Pasti
  v `exchange-contact-name.md`. Editace přes Python
  (`io.open(..., encoding='utf-8')`, `assert s.count(old) == 1`).
- **Fixture a docs:** jen fiktivní dodavatelé a částky, žádná data
  z diagnostiky.
- **`transform()` nevidí do `_resolve.issues`** — běží po jeho sestavení
  a v preview vůbec; issue z transformu se ztratí (viz D5 výše).
- **`appendRecapSourceIssue()` běží před `resolveAll()`** — odvozené kódy
  musí být dostupné nezávisle na pořadí (cachovaný kontext).
- **Náhled ukazuje `pct` z canonicalu** (`DocumentExchangePreview.svelte`
  ~ř. 732): u samovyměřeného řádku vypíše „0 % ✓“, odvozených 21 % je jen
  v `_resolve.rows[].vatCode`. Vědomě mimo rozsah — patří k D6.

## Hotovo když

- [x] `vat-cz.jsonc` má `supplyKind` a `reducedDeduction`; validace
      konfigurace zelená.
- [x] `VatCodeDerivation` s testy nad skutečným číselníkem.
- [x] Applier: derivace kódu, D2 registrace, D3 rekapitulace, D5 warningy;
      testy nové i přepsané zelené.
- [x] `IsdocReader` neplní `registrationCountry`.
- [x] Tři kopie schématu v souladu (`SchemaDriftTest`,
      `ProfileSchemaDriftTest`).
- [x] Prompt v4.6.0 s pravidly; testy verzí; changelog.
- [x] `docs/exchange-format.md` bez `highEU`, s novými poli a derivací.
- [x] Help: smazaná položka v `co-dnes-nejde.md`, nová položka s limity,
      `kontrola-vytezeni.md` a `kdyz-ai-cte-spatne.md` aktualizované,
      `help-index.py` prošel.
- [ ] Ověření na dev zdroji: oba doklady projdou s `cz-217`
      a přepočítanou rekapitulací s párem.
