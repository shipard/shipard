# Task: Náhled návrhu — ruční volba kódu DPH řádků, místa plnění a režimu DPH

**Stav:** hotovo — kód, testy, docs a help 2026-10-01 (3 commity: piny v applieru, mapa rozhodnutí + jednoklik, náhled); ověřeno na dev zdroji 2026-10-01 (body 1–6)

**Issue:** #87 (task B; navazuje na task A `exchange-preview-vat-recompute.md`)

**Cíl:** Návrh přijaté faktury, u kterého applier kód DPH řádku neurčí
(`vat_code_unknown`) nebo ho určí špatně, jde dořešit v náhledu: uživatel
zvolí kód DPH řádku (i hromadně), případně místo plnění a režim DPH hlavičky.
Volba se uplatní v témže kontextu jako derivace, takže rekapitulace,
součty (`_resolve.computed` z tasku A) i doklad ji následují bez zvláštní
logiky. Volba se uloží (#76) a platí v modalu i při jednokliku.

Návaznost: roadmapa M2. Chybějící kódy číselníku (PDP mimo předměty 4 a 5,
zahraniční DPH) řeší #89 — nabídka kódů je bere z číselníku, takže se
v ní po #89 objeví samy.

## Před implementací přečti

- `tasks/exchange-received-reverse-charge.md` — derivace kódu (D1–D7 z #86),
  tabulka chování `decideRowVatCode()`, sekce Pasti
- `tasks/exchange-received-vat-place.md` — místo plnění z DIČ dodavatele
- `tasks/exchange-preview-vat-recompute.md` — `_resolve.computed`, náhledový plán
- `tasks/mail-review-decisions-persist.md` — persistence rozhodnutí (#76),
  whitelist cest, závod odpovědí `saveSeq`
- `docs/exchange-format.md` — §8.4 resolve kódu DPH, §9 `_resolve`
  a `userAction`

## Kontext — co je dnes

- **Přenos je z poloviny hotový.** `MessageProposalApplier::USER_ACTION_ROW_PATH_RE`
  (~ř. 64) povoluje `rows[i].vatCode` — expand (~ř. 775), sanitizace
  (~ř. 804) i persistence (`saveUserActions`, ~ř. 404) cestu přenesou do
  `_resolve.rows[i].vatCode.userAction`. `DocumentApplier::reconcile()`
  (~ř. 850) ale userAction kódu nečte — `vatCode` jen kopíruje do plánu.
  Cesty hlavičky (`vat.place`, `vat.mode`) whitelist nezná.
- **Kde vzniká rozhodnutí o kódu:** `vatContext()` (~ř. 1617) →
  `decideRowVatCode()` (~ř. 1877) v `resolveAll()`, tedy **před** reconcile;
  error `vat_code_unknown` z `resolveRowVatCode()` (~ř. 1998) zablokuje
  apply přes `hasErrors()`. Z téhož kontextu čtou `rowsCarryReverseCharge()`
  (D3 z #86), `recapCodesFromRows()`, `resolveRecapSource()` a `transform()`
  (`vat_place`).
- **Místo plnění:** `vatContext()` bere `vat.place` z canonicalu
  (`placeSource: "ai"`), u přijatého dokladu ho přebije `VatPlaceDerivation`
  z prefixu DIČ (`placeSource: "vatId"`); rozpor hlásí `appendVatHeaderIssues()`
  (~ř. 2142) jako `vat_place_derived`.
- **Režim DPH:** `transform()` (~ř. 1290–1340) — `vat.mode` z canonicalu,
  přebije ho `VatModeDerivation` (kromě `none`), `none` se samovyměřením
  se tiše přepne na `fromBase` (~ř. 1335). Korekci hlásí
  `appendVatModeIssue()` (~ř. 2445, `vat_mode_derived`), podezření
  `DocumentValidator::checkVatModeSuspect()` (`vat_mode_suspect`).
- **Náhled o uložených rozhodnutích neví:** `AnalysisController::previewMessage`
  (~ř. 1740–1810) je vrací frontendu (`userActions`), ale do `_resolve`
  je před `applier->preview()` nevkládá.
- **Hlavička náhledu ukazuje canonical:** `vatModeDisplay` / `vatPlaceDisplay`
  v `DocumentExchangePreview.svelte` (~ř. 91–96) čtou `canonical.vat.*`,
  ne efektivní hodnotu.
- **Badge kódu DPH** u řádku (~ř. 755) je neinteraktivní — `statusBadge()`
  bez `path` / `kind`; `ResolveDecisionPanel` je postavený na entitách s id.
- **Gating:** `DocumentExchangePreviewModal::allDecided()` (~ř. 183) kód
  DPH vynechává s komentářem „applier falls back to defaults“ — po #86
  neplatí.
- **Jednoklik:** karta feedu v pásmu *ready* (podle jistoty AI a pokrytí
  řádků, ne podle chyb resolve) nabídne **Použít** → `applyMessage(ndx, null)`
  → `MessageProposalApplier::apply()` bez uložených rozhodnutí, režim *safe*.
  Návrh s `vat_code_unknown` skončí `validation_failed`; `Dashboard.svelte`
  (`applyFlow`, ~ř. 224) i `ViewerDetail.svelte` (`applyProposal`, ~ř. 110)
  přepnou do modalu jen při `unresolved_required`, jinak `alert`.
  Stejnou cestou jde MCP nástroj `mail_draft_document`.

## Rozhodnutí k designu (potvrzená)

Číslování navazuje na D1–D7 tasku A (obojí #87).

- ✓ **D8 — mapa a tvary.** Stejná plochá mapa rozhodnutí jako #76:
  - `rows[i].vatCode` → `useCode:<klíč>` (klíč číselníku `world.vat`, např. `cz-218`);
  - `vat.place` → `useValue:domestic` | `useValue:intracom` | `useValue:thirdCountry`;
  - `vat.mode` → `useValue:fromBase` | `useValue:fromTotal` | `useValue:none`.

  Zrušení volby = smazat klíč. Whitelist, expand, sanitizace a merge
  se rozšíří na jednom místě (`MessageProposalApplier`); expand tvoří
  `_resolve.vat.place.userAction` / `_resolve.vat.mode.userAction`.
- ✓ **D9 — rozsah.** Volby platí jen ve větvi `derive` (přijatý doklad,
  `selfParty: customer`, zdroj s registrací DPH). Jinde se ignorují + info
  issue `vat_pin_ignored` (path dle volby).
- ✓ **D10 — kde se volby uplatní.**
  - místo z volby ve `vatContext()` přebije DIČ i AI (`placeSource: "user"`);
    druh plnění, derivace kódů i kontrola souladu dostanou toto místo;
  - kód z volby v `decideRowVatCode()` přebije derivaci, canonical i kód
    z historie (`matchedBy: "user"`); sazba z číselníku k DUZP
    (jako u odvozeného kódu, D4 z #86);
  - režim z volby v `transform()` přebije `VatModeDerivation`;
  - klíč cache `vatContext()` zahrne volby;
  - u zvolené hodnoty se nehlásí `vat_place_derived`, `vat_mode_derived`,
    `vat_mode_suspect` ani `vat_code_derived`.

  Rekapitulace (D3 z #86) i `_resolve.computed` (task A) pak volbu
  následují bez další logiky.
- ✓ **D11 — validace.**
  - kód mimo `_resolve.vatCodeOptions` (k čerstvému kontextu, po volbě
    místa) → error `vat_code_pin_invalid` na `rows.{i}.vat.code` (blokuje apply);
  - neplatná hodnota místa / režimu, neznámá akce → error `vat_pin_invalid`;
  - režim `none` zvolený ručně se samovyměřovacím kódem → **tiché přepnutí
    na `fromBase` jako dnes** (~ř. 1335), bez issue.
- ✓ **D12 — rozpor se signály.** Zvolený kód, který odporuje signálům
  dokladu (`VatCodeDerivation::conflict()`), vyhrává; warning
  `vat_code_pin_conflict` s důvodem z `conflict()`.
- ✓ **D13 — nabídka kódů** `_resolve.vatCodeOptions`, jednou na doklad
  (kandidáti závisí jen na hlavičce):

  ```jsonc
  "vatCodeOptions": [
    { "code": "cz-217", "label": "EU/Vstup/Služby/Základní", "pct": 21, "reverseCharge": true },
    { "code": "cz-218", "label": "EU/Vstup/Služby/Snížená",  "pct": 12, "reverseCharge": true }
  ]
  ```

  Kandidáti: země naší registrace, `input`, efektivní místo (po volbě),
  bez `hidden`, se sazbou platnou k DUZP (`resolveVatPct` nevyhodí výjimku —
  vyřadí `cz-390`–`cz-393` mimo 2015–2023). **Včetně** kráceného odpočtu
  (`reducedDeduction`) a dovozu zboží (`cz-415`) — derivace je nevybírá,
  ručně jsou legitimní. Mimo `derive` se blok neposílá.
- ✓ **D14 — efektivní hlavička** `_resolve.vat`:

  ```jsonc
  "vat": {
    "place": { "value": "intracom", "source": "vatId" },   // ai | vatId | user | default
    "mode":  { "value": "fromBase", "source": "derived" }  // ai | derived | user | default
  }
  ```

  `default` = canonical hodnotu nenese, platí výchozí applieru. Náhled
  zobrazuje efektivní hodnoty, ne canonical.
- ✓ **D15 — nový náhled po volbě.**
  - `previewMessage` vloží uložená rozhodnutí do `_resolve` canonicalu
    před `applier->preview()` (`expandUserActions` + `mergeUserActions`);
  - po změně volby DPH (`vat.*`, `rows[i].vatCode`) modal počká na uložení
    a náhled znovu načte **bez resetu** rozhodnutí a bez přepnutí do
    stavu načítání; při chybě uložení nenačítá (zůstane `saveError`);
  - změna místa plnění na klientu smaže volby kódů řádků — v témže novém
    objektu mapy, jedno uložení (kódy jiného místa by byly neplatné).

  Rozhodnutí o stranách a položkách nový náhled nespouštějí (beze změny).
- ✓ **D16 — UI.**
  - badge kódu DPH je klikací u každého řádku s `vatCode` blokem, když
    `vatCodeOptions` není prázdné — i u odvozeného a platného kódu;
    otevře popover s nabídkou (název, sazba, štítek samovyměření), aktuální
    volba zvýrazněná, „Zrušit výběr“;
  - hromadná volba „Kód DPH pro všechny řádky“ (od dvou řádků s nabídkou,
    vzor `bulkItemIndices`), jedno volání `onUserActionsChange`;
  - místo plnění a režim v sekci DPH hlavičky jako select s volbou
    „automaticky“ (= bez volby) a zdrojem efektivní hodnoty (z DIČ, z AI,
    odvozeno, zvoleno).
- ✓ **D17 — gating a jednoklik.**
  - `allDecided()`: řádek s `vatCode.status === 'notFound'` bez volby
    `rows[i].vatCode` = nerozhodnutý; komentář opravit;
  - **jednoklik pošle uložená rozhodnutí** (všechna, i strany a položky):
    `MessageProposalApplier::apply()` při `$clientResolveFlat === null`
    doplní rozhodnutí uložená k analýze; režim zůstává **safe** (dodavatele
    s IČO dál zakládá sám) — `autoCreateMode` se dál odvozuje z toho, zda
    poslal mapu klient, ne z doplněných rozhodnutí. Platí i pro MCP
    `mail_draft_document`;
  - jednoklik při `validation_failed` přepne do review modalu místo
    `alert` — `Dashboard.svelte` i `ViewerDetail.svelte`.

### Upřesnění z kódu při implementaci (2026-10-01)

- **D13 — nabídka žije v kontextu.** Kandidáti závisí jen na zemi, DUZP
  a efektivním místě, tedy na vstupech `vatContext()`; nabídka se počítá
  jednou uvnitř kontextu (`$ctx['codeOptions']`, `VatCodeDerivation::options()`)
  a táž množina validuje piny v `decideRowVatCode()`. Bez DUZP je prázdná
  (badge není klikací; takový doklad už dnes končí `vat_code_unknown`).
  Option nese navíc `reducedDeduction` a `supplyKind` pro štítky v UI.
- **D13 — počty k DUZP 2026:** `intracom` 4 (`cz-215`–`cz-218`),
  `thirdCountry` 4 (`cz-415`–`cz-418`), `domestic` 8 (13 − 5 bez platné
  sazby). Štítek je `fullName` compiled configu (už lokalizovaný).
- **D12 — `conflict()` hlídá i sazbu**: pin tuzemského kódu bez samovyměření
  s jinou sazbou, než má řádek, dá `vat_code_pin_conflict` („sazba kódu
  12 % neodpovídá sazbě řádku 21 %“). Žádoucí, volba platí.
- **Snížená sazba u samovyměření** (D4 z #86): bez volby dá derivace
  základní sazbu (`cz-217` / 21 %), ne `vat_code_unknown` — uživatel sazbu
  opraví volbou `cz-218`.
- **Režim DPH má jeden helper** `effectiveVatMode()` (`{value, source,
  reason, pinned}`) pro `transform()`, `appendVatModeIssue()` i
  `_resolve.vat.mode`; potlačení `vat_mode_suspect` při pinu je
  v `appendVatModeIssue()`. Pin `none` + samovyměření → `{fromBase,
  derived}` bez issue (D11).
- **D11 — neplatné hodnoty** hlásí `appendVatHeaderIssues()` pro všechny
  cesty (`vatPins()['invalid']`); řádkové `vat_pin_ignored` (D9) hlásí
  `resolveRowVatCode()` v nederivační větvi, hlavičkové
  `appendVatHeaderIssues()`. Zvolené místo potlačuje i `vat_place_unknown`.
- **D14 — `_resolve.vat`** se posílá pro všechny typy dokladů (hodnoty
  existují vždy), `vatCodeOptions` jen ve větvi derive. `buildSummary()`
  čte pevné klíče, bloky navíc nevadí (jako `computed` v tasku A).
- **Docs §11** tvrdily, že `/preview` klientský `_resolve` zahazuje — vstup
  se čte (`contentTag`, nově piny), výstup je čerstvý; věta opravena.
- **D14 — `auto`** (nález z ověření): select „Automaticky (…)“ ukazoval
  efektivní hodnotu, která je po volbě rovna volbě. `_resolve.vat.{place,mode}`
  nese navíc `auto` = hodnota bez volby; derivace místa proto běží i při
  pinu (čistá funkce, výsledek jde do `autoPlace`), pin se uplatní až na ni.

## Kroky a commity

### Commit 1 — `feat(exchange): ruční volba kódu DPH řádku, místa plnění a režimu přijatého dokladu — piny ve vatContext, vatCodeOptions, _resolve.vat (#87 D8–D14, 1/3)`

`modules/core/exchange/src/Document/DocumentApplier.php`:

- privátní `vatPins(array $canonical): array` — jediné čtení voleb
  z `$canonical['_resolve']`: `{place: ?string, mode: ?string, rows: array<int, string>, invalid: list<issue>}`.
  Prefixy `useValue:` / `useCode:` parsuje jen tady; neznámá hodnota /
  akce → do `invalid` (`vat_pin_invalid`, D11).
- `vatContext()`: volby do klíče cache; mimo `derive` → `vat_pin_ignored`
  (D9; issue přes `resolveAll()` / `appendVatHeaderIssues()` —
  `vatContext()` sám issues nepřidává); místo z volby před
  `VatPlaceDerivation` (`placeSource: "user"`, derivace místa se nevolá);
  kód z volby předat do `decideRowVatCode()`.
- `decideRowVatCode()`: nový parametr `?string $pinned`. Nejvyšší priorita:
  kód v nabídce → `effective = pinned`, `matchedBy: "user"`, `issue` null
  nebo warning `vat_code_pin_conflict` (D12); mimo nabídku → error
  `vat_code_pin_invalid` (D11). Derivace se počítá dál (`derived`,
  `reason` zůstávají pro zprávu konfliktu).
- `resolveRowVatCode()`: u `matchedBy: "user"` sazba z číselníku k DUZP
  (jako `derived`), `matchedBy: "user"` do výsledku; `supply_kind_derived`
  se u zvoleného kódu nehlásí (výsledek na štítku nestojí).
- nová `vatCodeOptions(array $vatCtx): array` (D13) nad
  `VatRateResolver::getVatCodes(země, 'input', místo)` — mapa míst přes
  `VatCodeDerivation::PLACE_MAP`; kódy bez platné sazby k DUZP vyřadit.
  Validace pinu v `decideRowVatCode()` používá **tutéž** funkci.
- `transform()`: režim z volby (D10) před `VatModeDerivation`; ochrana
  `none` + samovyměření (~ř. 1335) platí i proti volbě (D11).
- `appendVatHeaderIssues()` / `appendVatModeIssue()`: bez `vat_place_derived`
  při `placeSource: "user"`, bez `vat_mode_derived` při volbě režimu;
  `vat_mode_suspect` z validátoru při volbě režimu z issues vyřadit.
- `preview()` a `apply()`: `_resolve.vatCodeOptions` (jen `derive`)
  a `_resolve.vat` (D14) přes `withResolve()`. `reconcile()` beze změny —
  `resolvedRowVatCodes` už nese výsledek s volbou.
- `docs/exchange-format.md`: §9 — `userAction` u `rows[i].vatCode` a `vat.*`,
  bloky `vatCodeOptions` a `vat` v „Audit bloky navíc“, nové kódy issues
  v tabulce (`vat_code_pin_invalid`, `vat_pin_invalid`, `vat_pin_ignored`,
  `vat_code_pin_conflict`); §8.4 — tabulka chování s řádkem „volba
  uživatele“.
- Testy `tests/Unit/Module/Core/Exchange/Document/DocumentApplierVatPinsTest.php`
  nad skutečným `vat-cz.jsonc` (harness jako `DocumentApplierPreviewComputedTest`):
  - `vat_code_unknown` (snížená sazba u EU služeb) + pin `cz-218` →
    bez erroru, `matchedBy: user`, sazba 12 % k DUZP 2026, `computed`
    s párem `cz-208`;
  - pin přebije odvozený kód (`cz-217` → `cz-215`, zboží místo služeb)
    + `vat_code_pin_conflict`;
  - pin kráceného odpočtu `cz-118` u tuzemské faktury → přijat, bez
    konfliktu s `reducedDeduction`;
  - pin mimo nabídku (kód jiného místa, kód s neplatnou sazbou k datu,
    neexistující klíč) → `vat_code_pin_invalid`, apply `validation_failed`;
  - pin místa `domestic` u dodavatele s DIČ jiného státu EU → kódy se
    odvozují tuzemsky, `_resolve.vat.place.source: user`, bez
    `vat_place_derived`, `vat_place` hlavičky 0;
  - pin režimu `fromTotal` → `_resolve.computed` podle `fromTotal`, bez
    `vat_mode_derived`; pin `none` se samovyměřením → doklad `fromBase`,
    bez issue (D11);
  - vystavený doklad s pinem → `vat_pin_ignored`, chování beze změny;
  - neplatné hodnoty (`useValue:eu`, `useCode:`, `create`) → `vat_pin_invalid`;
  - `vatCodeOptions` pro `intracom` k DUZP 2026: 8 − 4 neplatné sazby
    (`cz-390`–`cz-393`) = 4 kódy; pro `domestic` obsahuje `cz-118` a ne
    `hidden`;
  - apply s piny ukládá zvolený kód a sazbu (payload `saveDocument()`).
- `vendor/bin/phpunit --filter 'DocumentApplier|VatCodeDerivation'`.

### Commit 2 — `feat(mail): piny DPH v mapě rozhodnutí, náhled s uloženými rozhodnutími, jednoklik je posílá (#87 D8, D15, D17, 2/3)`

- `modules/core/mail/src/MessageProposalApplier.php`:
  - whitelist: `USER_ACTION_VAT_PATHS = ['vat.place', 'vat.mode']` vedle
    `USER_ACTION_TOP_PATHS`; `expandUserActions()` → `_resolve.vat.{place|mode}.userAction`;
    `sanitizeUserActions()` je propustí; `mergeUserActions()` umí vnořené
    `vat` (jen klíče `userAction`, vzor větve `rows`);
  - `apply()`: při `$clientResolveFlat === null` načíst
    `decodeUserActions($analysis['user_actions_json'] ?? null)` a neprázdnou
    mapu zmergovat do `_resolve` (po enrichmentu, jako klientskou);
    `autoCreateMode` se dál odvozuje z `$clientResolveFlat` (null → safe).
- `src/Api/Controller/AnalysisController.php::previewMessage`: před
  `applier->preview()` zmergovat uložená rozhodnutí do `_resolve`
  (stejné dva helpery); pole `userActions` v odpovědi beze změny.
- Testy: `MessageProposalApplierTest` (expand/sanitize/merge `vat.*`,
  jednoklik s uloženým `useCode` projde, jednoklik s uloženým
  `useExisting` dodavatele ho použije a zbytek zakládá *safe*, explicitní
  mapa klienta má přednost a uložená se nedoplňuje),
  `AnalysisControllerSaveDecisionsTest` (cesty `vat.*` se uloží,
  neznámé zahodí), `AnalysisControllerPreviewMessageTest` (náhled
  s uloženým `useCode` nemá `vat_code_unknown`).
- `vendor/bin/phpunit --filter 'MessageProposalApplier|AnalysisController'`.

### Commit 3 — `feat(mail): náhled návrhu — výběr kódu DPH řádků, místa plnění a režimu; jednoklik do modalu při chybě validace; help; task hotový (#87 D15–D17, 3/3)`

- `frontend/src/components/exchange/VatCodeDecisionPanel.svelte` (nový):
  seznam z `vatCodeOptions` (label, sazba, štítek samovyměření), aktuální
  volba, „Zrušit výběr“, bulk režim s počtem řádků (vzor
  `ResolveDecisionPanel` props `bulkCount` / `bulkDecidedCount`).
- `DocumentExchangePreview.svelte`:
  - badge kódu DPH s `path = rows[i].vatCode` a vlastním popoverem
    (ne `ResolveDecisionPanel`); interaktivní i u `matched` (D16);
    `effectiveStatusKey/Label` pro `useCode:` (zvoleno + kód);
  - hromadné tlačítko „Kód DPH pro všechny řádky“;
  - sekce DPH hlavičky: select místa a režimu z `_resolve.vat` (fallback
    canonical, když `_resolve.vat` chybí), „automaticky“ = smazat klíč,
    text zdroje; změna místa v témže `next` smaže `rows[*].vatCode` (D15).
- `DocumentExchangePreviewModal.svelte`:
  - `handleUserActionsChange(next)`: když se mezi starou a novou mapou
    změnila cesta DPH (`/^vat\./`, `/^rows\[\d+\]\.vatCode$/`), po úspěšném
    `persist()` zavolat `refreshPreview()` — `previewMessage` bez resetu
    `userActions` a bez `loading`; vlastní sekvence proti závodu (vzor
    `saveSeq`), výsledek starší sekvence zahodit;
  - `allDecided()` dle D17, opravit komentáře v hlavičce souboru
    (Resolve decisions) i u funkce.
- `Dashboard.svelte` (`applyFlow`) a `ViewerDetail.svelte` (`applyProposal`,
  i větev po úspěšném preview ~ř. 160): `validation_failed` → otevřít modal
  jako u `unresolved_required`.
- `frontend/src/i18n/cs.js` + `en.js`: `exchange.preview.vatCode.*`
  (panel, bulk, stav „zvoleno“), `exchange.preview.vatChoice.*` (select,
  „automaticky“, zdroje); `npm run check:i18n`.
- Help (názvy ověřit v `cs.js`):
  - `help/posta/kdyz-ai-cte-spatne.md` — tabulka (~ř. 22–27): kód DPH
    a místo plnění / režim DPH do řádku „Přímo v náhledu“; krok 2 —
    „jediná část návrhu“ už neplatí; odstavec ~ř. 74 — při chybě kódu DPH
    vyber kód v náhledu;
  - `help/posta/kontrola-vytezeni.md` — odstavec **Reverse charge**:
    kód, místo plnění a režim jde v náhledu změnit, rekapitulace se
    přepočítá; zvolený kód platí i pro **Použít** na kartě;
  - `help/co-dnes-nejde.md` — položka o samovyměření (~ř. 168): snížená
    sazba se vybere v náhledu (ne „oprav v dokladu“), věta „Kód DPH se
    v náhledu změnit nedá“ pryč; zahraniční DPH a PDP mimo 4 a 5 zůstávají
    (#89);
  - `python3 scripts/help-index.py` (stránka `kontrola-vytezeni.md` má
    255 řádků — nepřidávat víc, než je nutné).
- `**Stav:**` → `hotovo` (nebo `částečně — zbývá ověření na dev zdroji`),
  `python3 scripts/tasks-index.py`; `cd frontend && timeout 90 npm run build`.

## Ověření na dev zdroji

Zdroj s režimem *volný* (zdroj a zprávy v chatu, mimo repo):

1. Návrh s `vat_code_unknown` → apply tlačítka zakázaná; volba kódu →
   nový náhled bez chyby, rekapitulace přepočítaná; „Vystavit koncept“ →
   doklad se zvoleným kódem a sazbou.
2. Hromadná volba u dokladu s ≥ 2 řádky → jedno uložení, všechny řádky
   se zvoleným kódem.
3. Změna místa plnění → volby kódů řádků zmizí, nabídka kódů je pro nové
   místo, `vat_place` dokladu odpovídá.
4. Volba kódu v modalu, zavřít, **Použít** na kartě → doklad se zvoleným
   kódem (D17).
5. Návrh s `vat_code_unknown` bez volby, **Použít** na kartě → otevře se
   modal (ne `alert`).
6. **Znovu analyzovat** → volby DPH zmizí (nová analýza).

## Mimo rozsah

- Chybějící kódy číselníku a nedaňový řádek — #89.
- Ruční změna `vat.reverseCharge` — pokryje ji volba kódu.
- Volby u vystavených dokladů a na zdroji neplátce (D9).
- Úprava částek, sazeb mimo kód, dat a textů řádků v náhledu.
- Texty „doklad dodavatele“ u vystavených dokladů (nález z ověření tasku A,
  `tasks/TODO.md`).

## Pasti

- **Pořadí v kontextu:** místo z volby musí být známé **před** derivací
  druhu plnění a kódů — druhý průchod `VatPlaceDerivation` (XI) se při
  volbě místa nespouští.
- **Jedna funkce nabídky.** `vatCodeOptions()` slouží nabídce i validaci
  pinu — dvě implementace by se rozešly (UI nabídne, server odmítne).
- **Klíč cache `vatContext()`** — bez voleb vrátí kontext bez pinu
  (`appendRecapSourceIssue()` běží před `resolveAll()`).
- **`withResolve()` staví `_resolve` z čerstvého resolve** — příchozí
  `userAction` v odpovědi nezůstanou; klient drží mapu sám (`userActions`),
  `_resolve` je jen výsledek.
- **Jednoklik a `autoCreateMode`:** doplněná uložená rozhodnutí nesmí
  přepnout režim na *strict* — jinak by jednoklik přestal zakládat
  dodavatele s IČO a otevíral modal i tam, kde dnes projde.
- **Persist před refresh.** Náhled čte uložená rozhodnutí — refresh před
  dokončením `POST /decisions` by ukázal stav bez nové volby. Selhání
  uložení = žádný refresh.
- **Refresh nesmí resetovat modal** — `loadPreview()` nuluje `userActions`
  a stav persistence; pro refresh samostatná funkce.
- **Řádky bez `vatCode` bloku** (text, kontace, řádek bez `vat` objektu) —
  badge zůstává neinteraktivní, do bulk se nepočítají.
- **Sazba zvoleného kódu** se bere z číselníku k DUZP, ne z `row.vat.pct`
  dodavatele (u samovyměření 0).
- **Index řádku** v mapě odpovídá indexu canonicalu; po nové analýze se
  mapa zahazuje (#76) — žádná migrace indexů.
- **Fixture a docs:** jen fiktivní dodavatelé a částky.

## Hotovo když

- [x] Applier: piny v `vatContext()` / `decideRowVatCode()` / `transform()`,
      `vatCodeOptions`, `_resolve.vat`, validace a potlačení issues dle
      D9–D14; testy `DocumentApplier|VatCodeDerivation` zelené
      (`DocumentApplierVatPinsTest`).
- [x] Mapa rozhodnutí s `vat.*`, náhled s uloženými rozhodnutími, jednoklik
      je posílá v režimu safe; testy `MessageProposalApplier|AnalysisController`
      zelené.
- [x] Náhled: výběr kódu (i hromadně), místa a režimu, refresh po uložení,
      gating; jednoklik do modalu při `validation_failed`; i18n parita; build.
- [x] `docs/exchange-format.md` a help aktualizované; `help-index.py`
      a `tasks-index.py` prošly.
- [x] Ověření na dev zdroji (body 1–6) — 2026-10-01.
