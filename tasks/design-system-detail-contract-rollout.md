# Design system — kontrakt detailu, task B: převod zbývajících viewerů

**Stav:** hotovo — 2026-10-26, #136 task B (D18–D25): všech 49 viewerů přes builder, `NOT_CONVERTED` zrušený, `renderDetail()` `final`, úklid §1; odchylky níže

## Kontext / Cíl

Druhá část #136 (zastřešující #133). Task A
(`tasks/design-system-detail-contract.md`) zavedl builder detailu
v `TableViewer`, výchozí hlavičku, obecný štítek stavu, obecné přílohy,
sdílenou sekci příloh na frontendu a kontraktní test. Převedl doklady,
Majetek a Zakázky. Zbývá 49 viewerů v `DetailContractTest::NOT_CONVERTED`.

**Cíl:**

1. Převést všech 49 viewerů na builder: hlavička z deklarace /
   `displayPattern` nebo z háčku `detailHeader()`, štítky navíc
   v `detailBadges()`, taby v `detailTabs()`, přílohy obecně.
2. Zrušit zkopírované štítky stavu, dotazy na přílohy a ručně vložené
   ikony, které se shodují s `viewers[].icon`.
3. Vyprázdnit `NOT_CONVERTED`, `TableViewer::renderDetail()` udělat
   `final`, kontraktní test zjednodušit.
4. Úklid z ověření tasku A (§1).

**Mimo rozsah:**

- nové údaje v detailech — převod přenáší dnešní obsah tabů beze změny
  (kromě příloh, které teď dostanou i tabulky, kde chyběly — D8);
- nové štítky; mění se jen to, kdo štítek stavu staví;
- typografie a karty obsahu (#137), úzká šířka (#138);
- `FormStateBadge` (formulář), řádky seznamu (`renderRow`, proužek stavu).

## Návaznost

- **#133** — D8, D13, D14, D18–D24
- `tasks/design-system-detail-contract.md` (task A) — builder, háčky,
  `RecordPattern`, `RecordAttachments`, `DetailAttachments.svelte`,
  `DetailContractTest`, `ViewerDetailContractTest`
- **#137** — sdílené prvky; tento task jen přesune duplicitní pomocnou
  funkci frontendu (§1.3)

## Před implementací přečti

- `tasks/design-system-detail-contract.md` — celý, včetně poznámek
  k implementaci na konci
- `src/Core/Viewer/TableViewer.php` — `renderDetail()` a háčky
- `docs/frontend.md` — §7 Formát detail panelu
- `docs/design-system.md` — §12 Detail záznamu
- `tests/Unit/Core/Viewer/DetailContractTest.php`,
  `tests/Integration/Api/ViewerDetailContractTest.php`
- vzory převodu z tasku A: `DocsHeadsViewer` (hlavička v PHP, vlastní
  skupiny příloh), `AssetsViewer` (deklarace, tab Přílohy, akce),
  `KindsViewer` (jen `detailTabs()`)

## Rozhodnutí (potvrzená v #133)

- ✓ **D18** Detail skládá `TableViewer`; `renderDetail()` po převodu
  zanikne (zde: `final`).
- ✓ **D19** Výchozí hlavička z deklarace `detailHeader` nebo
  `displayPattern`; titulek je název, kód nebo číslo do podtitulku.
- ✓ **D20** Štítek stavu přidá builder; „V pořádku“ (`detailBadge:
  false`) se v hlavičce neukazuje; Archiv, Smazáno, K řešení, Hotovo,
  Odeslaná, Podáno a další zůstávají.
- ✓ **D21** Přílohy obecně, umístění `overview` / `tab` / `content`.
- ✓ **D22** Odeslaná pošta jen na konci Přehledu.
- ✓ **D23** Kontraktní test, seznam nepřevedených jen ubývá.
- ✓ **D24** Task B = převod zbytku, seznam na nulu.
- ✓ **D25** Upřesnění D20: výjimka jen pro „V pořádku“. „Zaúčtováno“
  u bankovních pohybů (`economy.bank.txStates`) se v hlavičce ukazuje —
  nese účetní stav. `txStates.jsonc` se nemění.

## 1. Úklid z ověření tasku A

1. **Osiřelý docblock** v `modules/docs/core/src/DocsHeadsViewer.php`
   nad `detailHeader()` — zbytek popisu zrušené `buildDetailHeader()`
   (zmiňuje `renderDetail`, `badges`, `@return array{title…}`). Smazat;
   platný docblock `detailHeader()` zůstává.
2. **Fallback titulku na `displayPattern`.** `TableViewer::detailHeader()`
   dnes při prázdném výsledku deklarovaného titulku skočí rovnou na
   `#id`. Nově: deklarace → `displayPattern` tabulky → `#id`. Využije se
   u tokenů AI (§8, `{note}` → `{token_prefix}`). Test do
   `TableViewerDetailBuilderTest`.
3. **Duplicitní `contentHasAttachments()`** ve `ViewerDetail.svelte`
   a `ViewerDetailModal.svelte` → jedna exportovaná funkce
   v `frontend/src/components/viewer/detailContent.js` (+ test
   v `npm test`, pokud mají čisté funkce vieweru testy — vzor najdi
   vedle).

## 2. Postup převodu jednoho vieweru

Pro každý viewer:

1. dnešní `renderDetail()` rozdělit: dotaz → `loadDetailRecord()` (jen
   když potřebuje JOINy nebo jiný zdroj než `SELECT *`), taby →
   `detailTabs()`, akce → `detailActions()`;
2. hlavička: když jde vyjádřit vzorem nad sloupci tabulky, deklarace
   `detailHeader` v `module.jsonc` (nebo nic, stačí-li `displayPattern`);
   jinak háček `detailHeader()` — vzor `DocsHeadsViewer`;
3. štítky kromě stavu → `detailBadges()`; vlastní stavba štítku stavu
   pryč. Funkce na štítek stavu (`buildStateBadge`, `stateBadge`,
   `resolveStateStyle`…) smazat jen tam, kde slouží výhradně hlavičce —
   řádky seznamu je často používají taky;
4. přílohy: vlastní dotaz na `core_attachments_files` a blok
   `attachment-grid` z obsahu pryč, dodá je builder; u `content` viewer
   kreslí přílohy dál sám a v `module.jsonc` to deklaruje;
5. ikona: `'icon' => '…'` v hlavičce smazat, když je stejná jako
   `viewers[].icon`, nebo když se liší bez důvodu (sjednotí se s ikonou
   řádku seznamu). Ponechat jen ikonu, která se mění podle záznamu
   (Osoby: firma / osoba);
6. odebrat viewer z `NOT_CONVERTED`, upravit jeho testy.

Obsah tabů se nemění. Kde viewer skládal tab Přehled nad skupinami
vlastností ručně, použít `propertiesTab()`.

## 3. Viewery s vlastní hlavičkou

Hlavička jde do `detailHeader()` (titulek a podtitul jako dnes), štítky
podle tabulky:

| Viewer | Hlavička | `detailBadges()` | Přílohy | Pozn. |
|---|---|---|---|---|
| `base.persons` | jméno; podtitul jako dnes; ikona podle typu (firma / osoba) | Vlastní | `overview` (nově) | |
| `base.registry.documents` | jako dnes | druh dokumentu | `content` | tab Přílohy staví viewer sám |
| `core.ai.functions` | název funkce / id | OK / Chyba nastavení | `overview` | tabulka bez stavů |
| `core.mail.incoming` | předmět; podtitul jako dnes | typ zprávy, stav analýzy, stav předzpracování | `content` | |
| `core.mail.sent` | předmět; podtitul jako dnes | stav doručení, pojistka | `content` | stav „Odeslaná“ zůstává (D20) |
| `economy.bank.statements` | jako dnes | rekonciliace | `overview` | dnešní vlastní přílohy pryč |
| `economy.bank.transactions` | jako dnes | Chyba účtování | `overview` | štítek stavu „Zaúčtováno“ zůstává (D25); ikona z deklarace (`money-transfer` místo `wallet`) |
| `economy.items` | deklarace `{name}` / `{code}` | — | `overview` | dnešní vlastní přílohy pryč; podtitul bez slova „Kód“; ikona z deklarace (`items` místo `box`) |
| `tasks.core` | jako dnes | priorita, Po termínu | `overview` | štítek stavu z builderu místo `resolveState*` |

## 4. Deklarace — název v titulku, kód nebo číslo v podtitulku

`displayPattern` spojuje kód a název; hlavička je rozdělí (D19):

| Viewer | `detailHeader` |
|---|---|
| `economy.codebooks.cashDesks` | `{name}` / `{code}` |
| `economy.codebooks.paymentTerminals` | `{name}` / `{code}` |
| `economy.codebooks.transports` | `{name}` / `{code}` |
| `economy.codebooks.bankAccounts` | `{name}` / `{code}` |
| `economy.codebooks.warehouses` | `{name}` / `{code}` |
| `economy.codebooks.costCenters` | `{name}` / `{code}` |
| `economy.accounting.accounts` | `{name}` / `{number}` |
| `core.system.users` | `{full_name}` / `{login}` |
| `core.units` | `{name}` / `{shortcut}` |
| `core.mail.mailboxes` | `{name}` / `{mailbox_id}` |
| `hosting.core.aiTokens` | `{note}` / `{token_prefix}` (bez poznámky titulek z `displayPattern`, §1.2) |
| `hosting.core.aiUsage` | `{model}` / `{created}` |

## 5. Výchozí hlavička z `displayPattern` — bez deklarace

Stačí převést taby: `base.registry.binders`, `core.ai.backends`,
`core.alerts.alerts`, `core.chat.conversations`, `core.exchange.tagRules`,
`core.mail.senders`, `core.mail.aiProfiles`, `core.mail.senderRules`,
`core.mail.preprocessRules`, `core.prints.texts`,
`docs.core.numberSeries`, `economy.accbal.balances`,
`economy.accbal.balanceAccounts`, `economy.codebooks.fiscalYears`,
`economy.codebooks.vatRegistrations`, `economy.items.kinds`,
`economy.vat.reportPeriods`, `economy.vat.filings`,
`economy.vat.deductionCoefficients`, `hosting.core.servers`,
`hosting.core.dataSources`, `hosting.core.mailRouters`.

Technická nastavení (pravidla, odesílatelé, koeficient s titulkem roku)
mají titulkem hodnotu ze vzoru — vědomě, převod je nemá přejmenovávat.

## 6. Hlavička v PHP — záznamy bez přirozeného názvu (návrh)

`displayPattern` by tu dal titulek typu „311000 1 234,00“. Odsouhlaseno
v #136:

| Viewer | Titulek | Podtitul |
|---|---|---|
| `economy.accbal.cases` (případ) | partner, bez partnera „Bez partnera“ | saldokonto · VS · období |
| `economy.accbal.ledger` (pohyb) | partner, bez partnera text pohybu, pak účet | účet · částka s měnou |
| `economy.accounting.journal` (řádek deníku) | text řádku, bez textu název účtu | číslo a název účtu · datum |
| `economy.exchangeRates.lists` | typ lístku | platnost od – do · číslo lístku |
| `hosting.core.dsUsers` | jméno uživatele | login · zdroj dat |
| `core.ai.modelCatalog` (bez tabulky) | název modelu | dodavatel · id |

Prázdné části podtitulu se vynechají i s oddělovačem (`RecordPattern`
nebo `implode` nad neprázdnými). Data dává dnešní dotaz detailu
(`loadDetailRecord()`).

## 7. Konec převodu: `final` a test

Poslední commit:

- `TableViewer::renderDetail()` → `final public function`;
- `DetailContractTest`: `NOT_CONVERTED` a test „přepisují přesně
  nepřevedené“ pryč, místo nich test, že `renderDetail()` je `final`;
  ostatní testy (placeholdery, viewer bez tabulky, umístění příloh)
  zůstávají;
- `ViewerDetailContractTest`: bez odkazu na `NOT_CONVERTED` — kontroluje
  všechny tabulkové viewery;
- komentáře v kódu a docs, které zmiňují „nepřevedené viewery“ nebo
  `NOT_CONVERTED`, upravit.

## 8. Dokumentace

- `docs/frontend.md` §7: hlavička už není volitelná (každý detail ji
  má), zmínky o nepřevedených viewerech pryč; Registrace vieweru
  — `renderDetail()` je `final`.
- `docs/design-system.md` §12: „u každého převedeného vieweru“ → „u
  každého vieweru“; kde se zmiňují konkrétní viewery s hlavičkou,
  zobecnit.
- `CLAUDE.md`, oddíl Viewery — detail přes builder: `NOT_CONVERTED`
  pryč, `renderDetail()` je `final`.
- `docs/doc-states.md`: u klíče `detailBadge` jedna věta, že jde
  o stav záznamu, ne o účetní stav — „Zaúčtováno“ ho nemá (D25).

## Commity

1. `chore: úklid po kontraktu detailu (#136)` — §1
2. `refactor(base): detail Osob a Registru přes builder (#136)`
3. `refactor(core.mail): detail pošty a nastavení pošty přes builder (#136)`
4. `refactor(core): detail AI, upozornění, chatu, výměny, tisků, uživatelů a jednotek přes builder (#136)`
5. `refactor(docs.core, economy.items): číselné řady a položky přes builder (#136)`
6. `refactor(economy.codebooks, economy.vat): číselníky a DPH přes builder (#136)`
7. `refactor(economy.bank): výpisy a pohyby přes builder (#136)`
8. `refactor(economy.accbal, economy.accounting, economy.exchangeRates): saldokonto, deník a kurzy přes builder (#136)`
9. `refactor(hosting.core): detail hostingu přes builder (#136)`
10. `refactor(tasks.core): detail úkolu přes builder (#136)`
11. `refactor(core): renderDetail() final, kontraktní test bez výjimek (#136)`
12. `docs: kontrakt detailu pro všechny viewery (#136)` — včetně `**Stav:**`
    a `python3 scripts/tasks-index.py`

Commity 2–10 každý odebírá své viewery z `NOT_CONVERTED`; pořadí jde
měnit, commit 11 je poslední z kódu.

## Ověření

```bash
vendor/bin/phpunit --filter 'TableViewerDetailBuilderTest|DetailContractTest'
vendor/bin/phpunit --filter '<testy převáděného modulu>'   # po každém commitu
SHIPARD_INTEGRATION_DS_PATH=… vendor/bin/phpunit --testsuite Integration --filter ViewerDetailContractTest
cd frontend && npm run build && npm run check:i18n && npm test
python3 scripts/check-css-units.py && python3 scripts/check-css-tokens.py
```

Po commitu 11 jedno širší spuštění unit testů viewerů:
`vendor/bin/phpunit --filter 'Viewer'`.

V prohlížeči (dev zdroj dat s třídou `free`):

- Osoba s přílohou — Přílohy na konci Přehledu (dřív chyběly); vlastní
  firma se štítkem Vlastní; ikona firma / osoba;
- Položka a bankovní výpis s přílohou — přílohy jen jednou, ve sdílené
  sekci;
- Došlá a Odeslaná pošta, dokument spisovny — přílohy jako dřív, ne
  dvakrát;
- číselník (pokladna, středisko) — název v titulku, kód v podtitulku,
  záznam V pořádku bez štítku, archivovaný se štítkem;
- případ saldokonta, pohyb, řádek deníku, kurzovní lístek — hlavička
  podle §6;
- úkol — stav, priorita, Po termínu;
- bankovní pohyb — „Zaúčtováno“ se štítkem (D25), Chyba účtování navíc.

## Hotovo když

- [x] úklid §1 (docblock, fallback titulku, sdílená `contentHasAttachments`)
- [x] všech 49 viewerů přes builder, `NOT_CONVERTED` zrušený
- [x] `TableViewer::renderDetail()` je `final`, kontraktní test bez výjimek
- [x] žádný viewer nestaví štítek stavu ani nečte přílohy záznamu pro detail sám (kromě `content`)
- [x] integrační kontrakt zelený na dev zdroji dat (`lh6x-l`, 2026-10-26)
- [x] dokumentace (`frontend.md`, `design-system.md`, `doc-states.md`, `CLAUDE.md`)
- [x] `**Stav:**` aktualizovaný, `tasks-index.py`

## Odchylky při implementaci

- **Podtitul shodný s titulkem se vynechá** (builder, navíc k §1.2): token
  AI bez poznámky má v titulku i podtitulku `token_prefix`; totéž ručně
  v `AiFunctionsViewer` (funkce bez deklarace) a `DsUsersViewer`.
- **Ikona z deklarace i u hlavičky z háčku**: `DetailHeader` bez ikony
  dostane `viewers[].icon` v builderu — vlastní ikonu dává jen hlavička
  závislá na záznamu (Osoby). Odhalil integrační kontrakt (detail Funkcí
  AI bez ikony); samostatný commit `fix(core)`.
- **Styl štítku stavu pošty** podle sady stavů: Došlá a Odeslaná pošta
  dřív mapovaly `archive` / `trash` na `neutral`, builder posílá
  `stateStyle` beze změny (frontend styly umí) — archivovaná zpráva má
  štítek ve stylu archivu.
- **Testovací fixtury**, které `renderDetail()` přepisovaly
  (`ViewerControllerGuardTest::ConfigOnlyViewer`,
  `PrintsApiTest::PrintsApiActionViewer`), přes háčky — `final` by je
  shodil; zadání je neznalo.
- **Případ saldokonta**: `loadDetailRecord()` vrací sloučený záznam
  (`id`, `key`, `case` + jména), hlavička, Přehled i akce ho čtou jednou —
  bez mema per id.
- **Katalog modelů**: podtitul „dodavatel · id“ bere `vendor` z katalogu
  beze změny (`anthropic`), test upraven.
- **Fallbacky titulku** „(bez názvu)“ u Položek zanikly s deklarací
  (`{name}` → `displayPattern` → `#id`); Odeslaná pošta bez předmětu má
  titulek „(bez předmětu)“ místo hlavičky bez titulku.
- `ReportPeriodsViewer::filings()` a `AiFunctionsViewer` (`preview()`)
  se volají pro taby i akce / štítky: podání v memu nejsou (jeden
  dotaz navíc), plán volání AI funkce ano.

## Pasti

- **Placeholdery deklarace musí být sloupce tabulky** (`DetailContractTest`)
  — aliasy z JOINů (`partner_name`, `user_name`) v deklaraci nejdou; takový
  titulek patří do `detailHeader()`.
- **Dvojí přílohy** u Položek a Bankovních výpisů, když se z obsahu
  zapomene odstranit vlastní blok příloh.
- **`content` je povinný** u Došlé a Odeslané pošty a dokumentů spisovny
  — jinak builder přidá přílohy do Přehledu podruhé.
- **Štítek stavu ve dvou sadách stavů viewerů**: `$docStatesCfgItem`
  vieweru a `docStates.cfgItem` tabulky se dnes všude shodují; když by
  se při převodu někde lišily, vyhrává tabulka (builder).
- **`core.ai.functions`** — ověř, nad čím je záznam detailu (řádek
  nastavení vs. funkce z konfigurace) a že `loadDetailRecord()` vrací
  totéž co dnes.
- **`economy.accbal.cases`** — detail je o případu (klíči), záznamem je
  řádek ledgeru; `loadDetailRecord()` musí vrátit vše, co dnes skládá
  `CaseQuery`, nebo nechat výpočet v `detailTabs()`.
- **Funkce štítku stavu pro řádky** (`stateStyleOf` a spol.) zůstávají —
  mazat jen kód, který sloužil hlavičce.
- **`final` rozbije třídy mimo hlavní strom** (moduly třetích stran), které
  `renderDetail()` přepisují — zmínit v `docs/frontend.md` u změny
  kontraktu.
- **Viditelné změny** mimo převod samotný: Přílohy u tabulek, kde dřív
  nebyly (Osoby, Jednotky, Druhy položek, Číselné řady, Podání DPH,
  hosting); ikony Položek a Bankovních pohybů podle deklarace; „V pořádku“
  bez štítku u všech číselníků.
