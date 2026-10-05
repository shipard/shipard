# Tisky — Fáze 3: vzhled a texty na tiscích

**Stav:** částečně — commit 1 hotový 2026-10-05 (modul `core.prints`, typ pole `color`, vzhled v záhlaví; `ds-upgrade` jen na ukázkovém zdroji); zbývají texty na tiscích, proměnné a dokumentace (commity 2–5)

> PRD pro Claude Code (5 commitů). Design: issue #90, komentář
> „Rozhodnutí: fáze 3 — vzhled a texty na tiscích (D46–D52)“; základ D9
> (tělo #90), D12 (obálka — `branding`, `texts`), D37 (e-mailové šablony).
> Navazuje na `tasks/prints-phase4.md` (odeslání e-mailem).

## Kontext

Tisky mají jeden střídmý layout bez možnosti přizpůsobení. Starý Shipard
měl dvě věci, které zákazníci reálně používali:

- **akcentovou barvu hlavičky** (firemní barva — pruh u titulku, linka
  pod hlavičkou, podklad loga; text vždy černý) a umístění loga,
- **texty na dokladech / sestavách** — krátké informace do tisku
  („příští týden máme dovolenou“, trvalý text v patičce) a hlavně vlastní
  těla e-mailů s proměnnými.

Fáze 3 dodá obojí obecně pro všechny tisky. Obálka `PrintData` má pro to
od fáze 1 připravené `branding` a `texts` (D12) a sandbox Twigu
pojmenovanou úzkou politiku `userTexts()`.

## Před implementací přečti

- Issue #90 — D6, D9, D12, D13, D37, D46–D52
- `docs/prints.md` celé; `docs/app-settings.md` §1 a „Jak přidat stránku“
  (nový typ pole = čtyři serverová místa + tři v `SettingsPage.svelte`)
- `docs/modules.md` — založení modulu, §10.4 `tableId`, `next-table-id`
- `src/Core/Prints/` — `PrintRunner` (`branding`, logo), `PrintData`,
  `PrintDefinition`, `PrintEmailRenderer`, `Twig/PrintSecurityPolicy.php`,
  `Twig/PrintTwigFactory.php`, `Twig/PrintTwigExtension.php`
- `modules/docs/core/prints/_layout/` — `doc-base.html.twig`, `doc-base.css`,
  `header.html.twig`, `email-*.txt.twig`; `_partials/`
- Deklarace tisků: `modules/docs/*/config/prints.jsonc`,
  `modules/economy/accounting/config/prints.jsonc`
- `src/Core/Settings/BrandingStorage.php`, `SettingsStore.php`,
  `src/Api/Controller/SettingsController.php`,
  `frontend/src/components/settings/SettingsPage.svelte`
- `modules/install/base/config/settingsSections.jsonc`
- `docs_core_heads.jsonc` (`doc_type`, `number_series`)

## Scope

**Uvnitř:**

- Nový modul `core.prints`.
- Stránka nastavení „Tisky“: akcentová barva hlavičky, umístění loga;
  nový typ pole `color` (D46).
- `branding` v obálce + layout dokladů (záhlaví a stránka).
- Tabulka **Texty na tiscích**, cfgItem slotů, deklarace `textSlots`,
  agenda a formulář v Nastavení (D47, D48).
- Výběr platných textů, zpracování Twig → Markdown → HTML, vykreslení
  slotů v layoutu, přepis e-mailového předmětu a těla (D49–D52).
- Nápověda proměnných ve formuláři textu (D51).
- Testy, `docs/prints.md`, nápověda.

**Mimo:**

- Import textů a voleb vzhledu ze starého Shipardu (samostatný task).
- Náhled textu nad konkrétním záznamem ve formuláři.
- Vlastní šablony per zdroj dat (D7), styly standardní / moderní,
  kulaté rohy, podpis, informace o úhradě, kódy položek, identifikátory
  osob (D46).
- Cílení textů na pokladnu, druh dokladu, osobu.
- HTML tělo e-mailu.

## 1. Modul `core.prints`

- `modules/core/prints/module.jsonc` — vždy aktivní (jako ostatní
  `core.*`), závislosti podle toho, co skutečně použije (`core.system`;
  `docs.core` **ne** — modul je obecný, cílení na doklady se řeší
  podmíněně, §5).
- Drží: tabulku textů, cfgItem slotů, stránku nastavení „Tisky“,
  položky Nastavení. Kód domény tisku zůstává v `src/Core/Prints/`;
  modulový kód (formulář, resolver textů) v `modules/core/prints/src/`.
- `tableId` přes `next-table-id` v rozsahu pro core.
- README modulu podle konvence ostatních modulů.

## 2. Vzhled (D46)

- Stránka nastavení **„Tisky“** (`settingsPages` v `core.prints`, scope
  `ds`), sekce `app` (vedle Aplikace — vzhled je branding) — pokud je
  vhodnější jiná existující sekce, vyber ji a zdůvodni v tasku:
  - `prints.accentColor` — typ **`color`** (nový): hodnota `#rrggbb`
    (validace regexem, malá písmena při uložení), prázdná = výchozí,
  - `prints.logoPlacement` — `select`: `left` (výchozí — dnešní chování)
    / `right`.
- **Nový typ pole `color`** podle `docs/app-settings.md` (whitelist
  v `ModuleDefinition::fromArray()`, větev v `savePage()` s validací,
  `localizePageDefinition()`, `DsSettingCommand::STRUCTURED_FIELD_TYPES`
  je-li třeba; v `SettingsPage.svelte` render = nativní
  `<input type="color">` + textové pole s hex hodnotou + „Výchozí“).
  Zapsat do `docs/app-settings.md`.
- **Obálka:** `branding` = `{logo, logoPlacement, accentColor}`;
  `accentColor` vždy vyplněný (výchozí neutrální světle šedá, např.
  `#c8c8c8` — zvol a zdokumentuj). Plní `PrintRunner` ze `SettingsStore`.
  `PrintData::fromArray()` přijme starší JSON bez nových klíčů (fixture,
  `--data`) a doplní výchozí hodnoty. `VERSION` builderů se nemění.
- **Layout dokladů** — jen záhlaví (`header.html.twig`); tělo stránky
  (`doc-base.html.twig`, `doc-base.css`) se nebarví:
  - akcent barví jen akcentové prvky hlavičky — svislý pruh u titulku,
    linku pod hlavičkou, podklad za logem (je-li logo průhledné);
    text zůstává černý / šedý,
  - logo vlevo = dnešní rozvržení; vpravo = titulek a číslo vlevo,
    logo vpravo,
  - barva do záhlaví inline (záhlaví nemá přístup k assetům); žádné
    skládání CSS z uživatelského řetězce mimo validovaný hex,
  - `-webkit-print-color-adjust: exact` na akcentových prvcích.
- Kontace (interní) dědí layout dokladů — akcent i logo platí i tam.

## 3. Texty na tiscích — data (D47, D48)

Tabulka **`core_prints_texts`** („Texty na tiscích“):

| Sloupec | Popis |
|---|---|
| `id`, `docState` | stavy `docStatesArchive` (Koncept, V pořádku, V opravě, V archivu, Smazáno); platí jen V pořádku |
| `name` | interní název pro agendu |
| `slot` | id slotu (cfgItem) |
| `text` | text (Markdown + proměnné; u e-mailových slotů prostý text + proměnné) |
| `prints` | `json` — pole id tisků; prázdné / NULL = všechny tisky se slotem |
| `doc_types` | `json` — pole typů dokladů; NULL = bez omezení |
| `number_series` | `json` — pole id číselných řad; NULL = bez omezení |
| `language` | jazyk tisku; NULL = všechny |
| `valid_from`, `valid_to` | platnost (NULL = neomezeno) |
| `order_pos` | pořadí ve slotu |
| `note` | poznámka |

- cfgItem **`core.prints.textSlots`** (v `core.prints`):

  | Slot | Kde | Druh |
  |---|---|---|
  | `header` | začátek těla dokumentu (pod záhlavím, před stranami) | HTML |
  | `beforeRows` | před tabulkou řádků | HTML |
  | `afterRows` | za tabulkou řádků (před rekapitulací a součty) | HTML |
  | `footer` | konec dokumentu (za poznámkami) | HTML |
  | `emailSubject` | předmět e-mailu — přepis | text |
  | `emailBody` | tělo e-mailu — přepis | text |

  S `name` / `name:*` a popisem umístění pro formulář.
- **Deklarace tisku:** nové volitelné pole `textSlots` (pole id slotů;
  chybí = žádné). Loader validuje proti cfgItemu; e-mailové sloty jen
  u tisků se `sendPurpose`. Doplnit: faktura, zálohová faktura, pokladní
  doklad, prodejka → všech šest; Kontace → žádné.
- **Agenda a formulář** v Nastavení (stejná sekce jako stránka Tisky):
  - seznam: název, slot, tisky, jazyk, platnost, stav; „platí dnes“
    jako indikátor,
  - formulář: název, slot (výběr), tisky (víc — jen tisky, které slot
    podporují), typy dokladů a číselné řady (víc — zobrazit jen když
    vybrané tisky jsou nad doklady, nebo není vybraný žádný), jazyk,
    platnost, pořadí, text (víceřádkové pole), poznámka, nápověda
    proměnných (§6),
  - validace při uložení: text se zkompiluje v příslušné politice
    sandboxu (§4) — syntaktická chyba nebo nepovolený prvek = 422
    s hláškou; `valid_from ≤ valid_to`; id tisků / typů / řad / slotu
    existují; e-mailový slot jen s tisky, které ho podporují.
- Výměnný formát: zatím ne (import samostatně).

## 4. Zpracování textu (D49, D50, D52)

`PrintTextResolver::resolve(PrintDefinition, array $record, string $language, \DateTimeImmutable $today): array`
→ `slot => [texty v pořadí]`

**Výběr:**
1. `docState = 40`, slot v `textSlots` deklarace.
2. `prints` prázdné nebo obsahuje id tisku.
3. `doc_types` / `number_series`: když jsou vyplněné a tabulka tisku je
   `docs_core_heads`, musí odpovídat `doc_type` / `number_series`
   záznamu; u jiné tabulky text s tímto omezením **neplatí**. Kontrola
   je podmíněná (modul nezávisí na `docs.core`) — mapování „tabulka →
   sloupce typu a řady“ drž na jednom místě, rozšiřitelné.
4. `language` NULL nebo = jazyk tisku.
5. `valid_from ≤ dnes ≤ valid_to` — **dnes = den tisku / odeslání**
   (D52), ne datum dokladu.
6. Řazení `order_pos`, `id`; **všechny** platné texty slotu (D48).

**Render:**
- HTML sloty: každý text zvlášť Twigem v politice
  **`PrintSecurityPolicy::userTexts()`** — tagy `if`; filtry `money`,
  `qty`, `pct`, `date`, `default`, `upper`, `lower`; funkce žádné; bez
  přístupu k metodám a vlastnostem objektů. Kontext `data`, `meta`,
  `language`. **Autoescape strategie pro Markdown** (vlastní escaper:
  `\` `` ` `` `*` `_` `[` `]` `(` `)` `#` `+` `-` `.` `!` `|` `<` `>` `{` `}` —
  ověř, že nic nerozbije čísla a data; např. tečku escapuj jen na začátku
  řádku za číslem, pokud by jinak vznikl seznam) → výstup projde
  **Markdownem** (`league/commonmark`, `html_input: escape`,
  `allow_unsafe_links: false`, GFM bez tabulek a obrázků — obrázky
  vypnout, render služba stejně nesmí na síť) → HTML. Texty slotu se
  spojí za sebou, každý v `<div class="print-text">`.
- E-mailové sloty: Twig ve stejné politice, **bez escapování** (výstup je
  prostý text, neprochází Markdownem); předmět bez konců řádků; víc
  textů ve slotu = spojení (předmět mezerou, tělo prázdným řádkem).
- Chyba při renderu jednoho textu (výjimka sandboxu, chybějící
  proměnná) **tisk nerozbije**: text se vynechá a do `messages` přijde
  varování `textError` s id textu.
- **Obálka:** `PrintRunner` po builderu vyplní `texts` =
  `{slot: string}` (HTML / text). V `--data` režimu se `texts` bere
  z JSON (nevolá se resolver).

**Vykreslení:**
- `doc-base.html.twig` vykreslí `texts.header`, `beforeRows`,
  `afterRows`, `footer` na místech podle tabulky slotů (`|raw` — výstup
  Markdownu s escapovaným HTML vstupem je bezpečný; pravidlo zapsat do
  `docs/prints.md`). Stránkové šablony bloky nepřepisují, takže sloty
  platí pro všechny tisky dokladů.
- Styl `.print-text`: běžná velikost textu, odstavce, tučné / kurzíva,
  odkazy jako text s URL, seznamy; žádná barva (akcent jen v hlavičce).
- **E-mail (D49):** `PrintEmailRenderer` — je-li `texts.emailSubject`
  neprázdné, použije ho místo `email-subject.txt.twig`; totéž pro tělo.
  Platí pro dialog Odeslat (návrh) i odeslání.

## 5. Proměnné (D51)

- Deklarace tisku: nové volitelné pole `textVariables` — pole cest
  (`data.document.number`, `data.dates.due`, `data.payment.amountToPay`,
  `data.payment.reference`, `data.document.title`, `data.customer.name`,
  `meta.title`, …). Pro tisky dokladů sdílený seznam (vyhni se kopírování
  do čtyř deklarací — např. pojmenovaná sada v `docs.core`, na kterou
  deklarace odkáže).
- Popisky z katalogů tisku (`var.<cesta>`), všechny jazyky tisku;
  úplnost hlídá test katalogů.
- Endpoint `GET /_prints/text-variables?prints=<id,…>&slot=<slot>` →
  `[{path, label, example}]` (průnik pro víc tisků; bez tisků = sada
  dokladů); `example` = ukázka zápisu včetně doporučeného filtru
  (`{{ data.dates.due|date }}`, `{{ data.payment.amountToPay|money }}`).
- Formulář textu: panel „Proměnné“ vedle pole textu, klik vloží zápis
  na pozici kurzoru. Komponenta formuláře (vzor
  `sentMessageTransport`).

## Testy

- **Unit:**
  - typ pole `color` (validace, normalizace, smazání),
  - `branding` v obálce (výchozí hodnoty, `fromArray` starého JSON),
  - `PrintDefinition` — `textSlots`, `textVariables` (neznámý slot,
    e-mailový slot bez `sendPurpose`),
  - `PrintTextResolver` — tisk, typ dokladu, řada, jazyk, platnost
    (hranice dnů), stav, pořadí, víc textů ve slotu, omezení na doklady
    u jiné tabulky,
  - politika `userTexts()` — povolené / zakázané prvky, Markdown escaper
    (data s `*`, `_`, `#`, čísla a data se nerozbijí), Markdown bez HTML
    a obrázků, nebezpečný odkaz,
  - chyba v textu → `textError`, tisk projde,
  - e-mailový přepis předmětu a těla, předmět bez konců řádků,
  - validace formuláře (kompilace v sandboxu → 422).
- **Render HTML nad fixture:** akcent v záhlaví (stránka ho nenese), logo
  vlevo / vpravo, sloty na správných místech, text s proměnnou.
- **Integrační (volný DS):** text platný jen pro fakturu a řadu se
  objeví na faktuře té řady a ne na pokladním dokladu; text mimo platnost
  se nevytiskne; e-mailový text přepíše návrh v `print-send --dry-run`.
- Gated Gotenberg: akcent se vytiskne (PDF obsahuje barvu — stačí ověřit
  render bez chyby a vizuálně ručně).

## Task breakdown

### Commit 1 — Modul `core.prints` a vzhled

§1, §2 (typ pole `color`, stránka Tisky, `branding`, layout), testy,
aktualizace fixture snapshotů.

**Hotovo když:** v Nastavení → Tisky jde nastavit barvu a umístění loga
a faktura je vytiskne; bez nastavení vypadá tisk jako dnes (jen s
neutrálním akcentem).

### Commit 2 — Texty: tabulka, sloty, agenda

§3 (tabulka, cfgItem slotů, `textSlots` v deklaracích, agenda,
formulář bez panelu proměnných, validace) a z §4 politika `userTexts()`
s kompilací textu — potřebuje ji validace formuláře; testy.

### Commit 3 — Texty: výběr a zpracování

§4 (`PrintTextResolver`, Markdown escaper, `league/commonmark`, `texts`
v obálce, sloty v layoutu, e-mailový přepis), testy.

**Hotovo když:** text „příští týden máme dovolenou“ s platností na týden
se objeví na faktuře pod řádky; vlastní tělo e-mailu s
`{{ data.document.number }}` přepíše výchozí v dialogu Odeslat.

### Commit 4 — Proměnné ve formuláři

§5 (`textVariables`, katalogy, endpoint, panel ve formuláři), testy.

### Commit 5 — Dokumentace a nápověda

- `docs/prints.md`: vzhled (`branding`), texty — sloty, výběr, zpracování,
  bezpečnost (`|raw` jen pro výstup Markdownu), proměnné; jak přidat
  slot / podporu slotů do nového tisku.
- `docs/app-settings.md`: typ pole `color`.
- README modulu `core.prints`.
- Nápověda: vzhled tisků (barva, logo), texty na tiscích (příklady:
  dovolená, trvalý text v patičce, vlastní e-mail s proměnnými) — podle
  pravidel nápovědy.
- Roadmapa M4 — řádek tisků (fáze 3 hotová).
- Hlavička tohoto tasku + `python3 scripts/tasks-index.py`.

## Rozhodnutí k designu

Zamčeno v #90: D46–D52. Upřesnění z PRD:

- Výchozí umístění loga vlevo (dnešní tisky se nezmění), výchozí akcent
  neutrální světle šedý.
- Texty mají stavy `docStatesArchive`; platí jen V pořádku.
- Omezení na typ dokladu a řadu platí jen u tisků nad `docs_core_heads`;
  u jiné tabulky takový text neplatí (ne „platí vždy“).
- Chyba v uživatelském textu tisk nerozbije — text se vynechá s varováním.
- `--data` render bere `texts` z JSON, resolver nevolá.
- Markdown bez obrázků a tabulek.

Upřesnění z plánování implementace (2026-10-05):

- **Akcent je jen v záhlaví.** Stránka nedostává `--accent` a tělo dokladu
  se nebarví.
- **Poloha slotů:** `header` je první prvek těla před blokem `title`,
  `footer` za poznámkami před podpisy. Všechny čtyři sloty leží mimo bloky
  layoutu, aby je stránkové šablony nepřepsaly.
- **`core_prints_texts` je v `keepOnReset`** — texty jsou konfigurace.
- **`core.prints` je v závislostech `install.base`**; `install.hosting`
  tisky nemá.
- **Sloty:** id a druh (HTML / text) drží enum v `src/Core/Prints` —
  deklarace tisku se validuje bez konfigurace. cfgItem
  `core.prints.textSlots` nese názvy a popisy, shodu hlídá test.
- **Jádro × modul:** `PrintRunner` zná jen rozhraní `PrintTextProvider`
  (`src/Core/Prints`); resolver v modulu ho implementuje
  a `PrintRunnerFactory` ho zapojí jen na zdroji, kde tabulka textů
  existuje. Twig → Markdown → HTML dělá jádro (potřebuje ho i validace).
- **Politika `userTexts()`** povolí i testy `defined`, `empty`, `null`,
  pokud je filtr `default` ve striktním sandboxu potřebuje — ověřit prvním
  testem.
- **Markdown escaper** escapuje zpětným lomítkem veškerou ASCII
  interpunkci (CommonMark to dovoluje u každého znaku), bez výjimky pro
  tečku.
- **Obrázky** vypíná vlastní renderer (jen alt text) —
  `allow_unsafe_links` je nezastaví.
- **`|raw`** přibude jen do politiky `templates()`; test hlídá, že ho
  šablony používají výhradně na `texts.*`.
- **Barva se validuje i při čtení** (`PrintRunner`,
  `PrintData::fromArray()`) — `ds-setting set` hodnotu nekontroluje.
- **`textVariables`** = seznam `{path, filter?}`, nebo odkaz na sdílenou
  sadu `@docs.core/_layout` (`text-variables.jsonc`). Endpoint bez `prints`
  vrací průnik přes všechny tisky se slotem.
