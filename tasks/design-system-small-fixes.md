# Design system — drobnosti z checklistu #133 (tokeny, texty viewerů, Spisovna, přihlašovací obrazovky)

**Stav:** hotovo — 2026-11-10 (6 commitů; odchylky viz „Odchylky od zadání“); ruční proklik prošel

## Kontext / Cíl

Všechny části #133 jsou hotové; zbývá checklist „Další nesoulady“. Tenhle
task bere čtyři jeho body, které nepotřebují vlastní návrh — jen opravu
a tam, kde to jde, kontrolu, aby se chyba nevrátila (cíl #133: pravidla
vynutit nástrojem).

**Cíl:**

1. Žádný nedefinovaný `--shpd-*` token ve frontendu ani s fallbackem;
   kontrola v pre-commitu to hlídá. Text na plné barvě má token (D59).
2. Jedna implementace textu z katalogu popisků v `TableViewer` místo tří
   kopií `text()`.
3. Detail Spisovny (tab Obsah) ukazuje u metadat dokumentu popisky místo
   technických klíčů a data ve formátu `j. n. Y`; každé pole druhu
   dokumentu musí mít popisek (test).
4. Přihlašovací a stavové obrazovky sdílejí jednu komponentu „karta
   uprostřed“, která na telefonu nedoléhá ke krajům.

**Mimo rozsah** (zbývá v checklistu #133):

- hex barvy v komponentách (77 komponent, z toho 14× bílý text) — tady se
  token D59 použije jen místo dvou nedefinovaných tokenů;
- popisky detailu psané v PHP natvrdo česky (26 viewerů, i ve Spisovně:
  „Identifikace“, „Platnost od“, „Vytvořeno“…) — tady jen popisky polí
  metadat;
- hodnoty metadat jiné než data (částky, čísla) — beze změny;
- vzhled obsahu přihlašovací karty (logo, nadpis, formulář, tlačítka) —
  jen obal a karta;
- chyba stránky chatu, hlášky mimo `Notice`, reporty — vlastní tasky.

## Návaznost

- **#133** — D10 (kontroly v pre-commitu), D30 (typy obsahu detailu),
  D59; checklist „Další nesoulady“
- **#129** D14 — `scripts/check-css-tokens.py`
- **#137** task 3 — `TableViewer::detailText()`
- **#140** D27 — přílohy Spisovny v tabu Obsah

## Před implementací přečti

- `docs/design-system.md` — §3 Paleta servisní (Text), §6 CSS proměnné
  (pravidla), §13 Soubory
- `scripts/check-css-tokens.py` — celé
- `frontend/src/utils/themeColor.js` — jak vlastní vzhled nastavuje
  `--shpd-sidebar-bg-image`; `frontend/src/styles/variables.css` —
  komentář u něj
- `src/Core/Viewer/TableViewer.php` — `detailTabLabel()`, `detailText()`
- `CLAUDE.md` → *Backend — Vícejazyčnost server-driven labels*
- `docs/registry-mvp.md` §4.1 (`docKinds`)
- `modules/base/registry/src/RegistryDocumentsViewer.php` —
  `buildMetadataItems()`, `formatDate()`, `parseDate()`
- `frontend/src/components/exchange/RegistryExtractedPreview.svelte`
  (`fieldLabel()`, `formatValue()`) — jak to dělá náhled review
- `frontend/src/components/auth/*.svelte`,
  `frontend/src/components/ui/StatusScreen.svelte`, `ui/Card.svelte`

## Rozhodnutí (potvrzená v #133)

- ✓ **D59** Text na plné barvě má token `--shpd-color-text-on-fill`
  (`#ffffff` v obou motivech): text na tlačítku, aktivní buňce a podobných
  plochách v barvě primary, accent nebo stavové. Nahrazuje hex `#fff`
  v komponentách (pravidlo 1 v `docs/design-system.md` §6) a nedefinované
  tokeny `--shpd-color-on-primary` / `--shpd-color-text-on-accent`.

Ostatní kroky jen uplatňují existující pravidla (§6 pravidlo 1, konvence
popisků ze serveru) — nové rozhodnutí nepotřebují.

## 1. Nedefinované tokeny

**Stav dnes:** `check-css-tokens.py` hlídá jen `var(--x)` bez fallbacku.
S fallbackem projde i nedefinovaný token a tiše platí fallback. Takových
je sedm:

| Token | Použití | Nahradit |
|-------|---------|----------|
| `--shpd-font-mono` | 10× v 9 souborech (`exchange/*`, `registry/RegistryImportWizard`, `settings/*`) | `var(--shpd-font-family-mono)` bez fallbacku |
| `--shpd-color-bg-active` | `chrome/NavFlyoutStrip`, `chrome/NavTabStrip` | `var(--shpd-color-bg-hover)` |
| `--shpd-color-border-light` | `reports/ReportView` | `var(--shpd-color-border)` |
| `--shpd-color-primary-bg` | `settings/DsSetup` | `var(--shpd-color-bg-secondary)` |
| `--shpd-color-state-warning-text` | `viewer/AssetsDepreciationRunDialog` | `var(--shpd-color-text-secondary)` |
| `--shpd-color-on-primary` | `viewer/ViewerDetail` | `var(--shpd-color-text-on-fill)` (D59) |
| `--shpd-color-text-on-accent` | `reports/PeriodPicker` | `var(--shpd-color-text-on-fill)` (D59) |

Pravidlo: nahradit tím, co dnes skutečně platí (fallback) — vizuálně beze
změny. U `--shpd-font-mono` je fallback `monospace` nebo zkrácený stack;
`--shpd-font-family-mono` je úplný stack, rozdíl je jen v pořadí písem.

**Token D59:** `--shpd-color-text-on-fill: #ffffff;` ve `variables.css`
v sekci Text, ve světlém i tmavém motivu (tmavý motiv ho musí mít
explicitně, i když je hodnota stejná — ať je vidět, že se na něj
myslelo). Vlastní vzhled ho neodvozuje (barví jen sidebar, §9).

**Kontrola** (`scripts/check-css-tokens.py`):

- Použití = každé `var(--shpd-x …)` a `var(--dev-x …)`, s fallbackem
  i bez. U `var(--a, var(--b))` se hlídají **oba** tokeny.
- Definice navíc ve tvaru JS přiřazení do objektu:
  `tokens['--shpd-x'] =` (vzor `\[\s*['"](TOKEN)['"]\s*\]\s*=`). Tím se
  `--shpd-sidebar-bg-image` z `utils/themeColor.js` uzná jako definovaný
  a **žádný seznam výjimek není potřeba**. Kdyby se přesto našel token,
  který se definuje jinak, než skript umí poznat, rozšiř vzor definice,
  nepřidávej výjimku.
- Hláška: „Nedefinované CSS tokeny:“ a u tokenu s fallbackem poznámka, že
  fallback skrývá chybu. Rada na konci: nahradit existujícím tokenem nebo
  ho definovat — „dej fallback“ z rady zmizí.
- Docstring popisuje nové chování (proč i s fallbackem: tiše platí
  fallback, jako `--shpd-font-mono` nebo dřív
  `--shpd-color-alert-danger-bg`).

## 2. Texty viewerů

**Stav dnes:** `AiViewerBase`, `AssetsViewerBase` a `WorkOrdersViewerBase`
mají každý stejnou `text()` — čte plochý katalog `*.viewerLabels`
(`{<key>: {name}}`), nahradí `{param}`, jinak anglický fallback.
`TableViewer::detailText()` dělá totéž nad sekcí `texts` katalogu
`*.viewerDetailLabels`. `text()` má přes 230 volání.

- `TableViewer`: nová `protected function labelText(string $cfgItemId,
  string $key, string $englishFallback, array $params = []): string` pro
  plochý katalog a soukromý helper, který z pole definic vezme `name`,
  nahradí parametry a vrátí fallback. `detailText()` volá stejný helper
  nad sekcí `texts` — náhrada parametrů a fallback jsou na jednom místě.
- Tři báze: tělo `text()` nahradit voláním
  `return $this->labelText(self::LABELS_CFG_ITEM, $key, $englishFallback, $params);`
  (signatura beze změny, volání se nemění). Docblock `text()` jen
  odkáže na `labelText()`.
- Chování zůstává: bez cfgItem (nezkompilovaná konfigurace) i pro
  chybějící klíč anglický fallback.

## 3. Spisovna — popisky a data metadat

**Stav dnes:** `buildMetadataItems()` posílá jako popisek klíč pole
z `docKinds[kind].fields` (`insurer`, `policyNumber`…) a hodnotu tak, jak
je v JSON — data jako `2026-01-31`. Překlady existují jen ve frontendu
(`registry.field.*`, 21 klíčů) pro náhled review.

- Katalog `base.registry.viewerDetailLabels`: nová sekce `fields`
  (`{<klíč pole>: {name, name:cs, name:en}}`) se všemi poli ze všech
  druhů v `docKinds.jsonc`. České a anglické texty převzít z
  `frontend/src/i18n/{cs,en}.js` (`registry.field.*`), ať se náhled review
  a detail neliší. Komentář katalogu doplnit o sekci `fields`.
- `buildMetadataItems()`: popisek přes `labelText()` nad sekcí `fields`
  — buď obecný helper, který přijme název sekce (`detailText()` je pak
  jeho případ se sekcí `texts`), nebo soukromá metoda ve vieweru; vyber
  to, co nezdvojí logiku z kroku 2. Neznámý klíč (budoucí druh, `legacyKind`
  z importu) se ukáže jako klíč — stejně jako ve frontendu.
- Hodnota ve tvaru ISO data (`^\d{4}-\d{2}-\d{2}$`) přes existující
  `formatDate()`; ostatní hodnoty beze změny.
- Test `tests/Unit/Module/Base/Registry/RegistryDocKindsLabelsTest.php`:
  načte `docKinds.jsonc` a `viewerDetailLabels.jsonc` ze souborů repa;
  každé pole každého druhu má v `fields` položku s `name`, `name:cs`
  a `name:en`, a v `fields` nejsou klíče, které žádný druh nemá. Hláška
  pojmenuje druh a pole.
- Ve frontendu se nic nemění (`RegistryExtractedPreview` dál používá
  `registry.field.*` — canonical z vytěžení skládá až prohlížeč).
- Po změně katalogu `vendor/bin/shpd-ds ds-upgrade` jen na zdroji dat
  s třídou `free` (`shpd-server ds-list --json`).

## 4. Přihlašovací a stavové obrazovky

**Stav dnes:** `LoginScreen`, `SetPasswordScreen`, `OpAuthScreen`
a `ui/StatusScreen` mají každá vlastní kopii obalu a karty
(`.shpd-login` / `.shpd-status`: flex na střed, `min-height: 100vh`,
karta `max-width: 25rem`, padding `xl`, stín). Obal nemá odsazení, takže
na telefonu karta doléhá ke krajům obrazovky; `100vh` je na mobilu vyšší
než viditelná plocha.

- Nová `frontend/src/components/ui/CenteredScreen.svelte`: obal přes
  celou obrazovku (`min-height: 100dvh`, pozadí `bg-secondary`, padding
  `--shpd-space-md` ze všech stran) a karta (`max-width: 25rem`, padding
  `--shpd-space-xl`, `bg`, `radius-lg`, `shadow-md`). Obsah karty jako
  `children` snippet; volitelný `testid` prop na obal (StatusScreen má
  `data-testid="status-screen"`).
- Čtyři obrazovky na ní; jejich vlastní pravidla obalu a karty smazat,
  pravidla obsahu (logo, nadpis, formulář) zůstávají. Komentář
  v `OpAuthScreen` „vlastní kopie stylů“ zmizí.
- Na telefonu karta zůstává kartou se zaoblením a stínem, jen má okraj
  od krajů obrazovky. Pod `30rem` šířky obalu (D43, container query)
  může mít karta padding `--shpd-space-lg` místo `xl` — jen pokud se
  formulář jinak nevejde; rozhodni podle snímku na 360 px.
- `docs/design-system.md` §8: krátký odstavec „Obrazovka s kartou
  uprostřed“ (přihlášení, nastavení hesla, OpAuth, nedostupný zdroj dat
  → `CenteredScreen`, ne vlastní obal).

## 5. Dokumentace

- `docs/design-system.md` §3 Text: řádek `--shpd-color-text-on-fill`
  (D59); §6 pravidlo 1 doplnit „bílý text na plné barvě =
  `--shpd-color-text-on-fill`“; §13 Soubory: popis
  `check-css-tokens.py` („každý `var(--x…)` i s fallbackem musí mít
  definici“) a řádek `CenteredScreen.svelte`.
- `docs/registry-mvp.md` §4.1: popisky polí jsou v
  `viewerDetailLabels.fields`, každé pole druhu musí mít popisek (test).
- `CLAUDE.md` → *Backend — Vícejazyčnost*: u detailu vieweru zmínit
  `labelText()` vedle `detailTabLabel()` / `detailText()` (jedna věta).
- Checklist #133: body 1–4 se zaškrtnou při ověření (chat), ne v tasku.

## Commity

1. `fix(frontend): nedefinované CSS tokeny, token textu na plné barvě (#133, D59)`
   — krok 1 bez kontroly, `variables.css`
2. `chore: kontrola nedefinovaných tokenů i s fallbackem (#133, D10)` —
   `check-css-tokens.py`
3. `refactor: text z katalogu popisků na jednom místě v TableViewer (#133)`
   — krok 2
4. `fix(registry): popisky a data metadat v detailu dokumentu (#133)` —
   krok 3 včetně katalogu a testu
5. `fix(frontend): sdílená obrazovka s kartou, odsazení na telefonu (#133)`
   — krok 4
6. `docs: drobnosti #133 (#133, D59)` — krok 5 + `**Stav:**` tohoto tasku
   + `python3 scripts/tasks-index.py`

## Ověření

- `python3 scripts/check-css-tokens.py` projde; ručně: do kopie komponenty
  vlož `var(--shpd-neexistuje, red)` → skript selže a token vypíše; vrať.
- `python3 scripts/check-css-units.py`, `cd frontend && npm run build`,
  `npm run check:i18n`.
- `grep -rn -- "--shpd-font-mono\b\|--shpd-color-on-primary\|--shpd-color-text-on-accent" frontend/src`
  → nic.
- PHPUnit úzce: `vendor/bin/phpunit --filter 'ModelCatalogViewerTest|AiFunctionsViewerTest|RegistryDocumentsViewer|RegistryDocKindsLabelsTest'`;
  testy viewerů majetku a zakázek, pokud existují (`--filter` podle
  názvu třídy). Celá sada lokálně na konci.
- Detail dokumentu Spisovny (zdroj dat `free`): tab Obsah ukazuje
  „Pojišťovna“, „Platnost do“ místo klíčů, data `31. 1. 2026`;
  v angličtině anglické popisky.
- Majetek, Zakázky, Modely AI: detaily a seznamy mají stejné texty jako
  před změnou (cs i en).
- Přihlášení, nastavení hesla a stavová obrazovka (zastavený zdroj dat)
  na 360 px a na desktopu: karta s okrajem od krajů, nic nepřetéká,
  desktop vizuálně beze změny. Snímky přiložit k ověření.
- Navigace (záložky a flyout v classic/wild shellu), report, `DsSetup`,
  dialog odpisů, výběr období: vizuálně beze změny.

## Hotovo když

- [x] Žádný `var(--shpd-…)` ve `frontend/src` neodkazuje na nedefinovaný
      token, s fallbackem ani bez; kontrola v pre-commitu to hlídá
- [x] `--shpd-color-text-on-fill` v obou motivech a v `design-system.md`
- [x] `text()` ve třech bázích jen deleguje na `TableViewer::labelText()`;
      náhrada parametrů a fallback jsou v `TableViewer` jednou
- [x] Detail Spisovny: popisky metadat z katalogu, ISO data formátovaná;
      test pokrývá všechna pole všech druhů
- [x] Čtyři obrazovky na `CenteredScreen`, žádná vlastní kopie obalu
      a karty; na telefonu okraj od krajů, `100dvh`
- [x] Dokumentace podle kroku 5, `**Stav:**` aktualizovaný, index tasků
      přegenerovaný

## Pasti

- `check-css-tokens.py` jde celým stromem, ne jen staged soubory —
  commit 2 musí přijít až po commitu 1, jinak pre-commit selže.
- Tokeny z vývojářského dashboardu (`--dev-*`) kontrola hlídá taky;
  nové pravidlo s fallbackem může najít nedefinované `--dev-*` — oprav
  je stejně (fallback, který dnes platí), a uveď je v odchylkách.
- `viewerLabels` (plochý) a `viewerDetailLabels` (sekce `tabs` / `texts`
  / nově `fields`) jsou dva různé tvary katalogu — neslučovat je.
- Katalog `fields` se do `compiled.*.json` dostane až po `ds-upgrade`;
  bez něj detail ukáže anglický fallback = klíč pole. Fallback pro
  `fields` je proto klíč, ne anglický text z PHP (jinak by bylo nutné
  držet anglické texty i v PHP).
- `ds-upgrade` jen na zdrojích dat s třídou `free`; zdroje `realCopy`
  podle `shpd-server ds-list --json` nech být.
- Svelte styly jsou scoped: po přesunu obalu do `CenteredScreen` musí
  pravidla obsahu v obrazovkách dál chytit své prvky (třídy obsahu
  zůstávají ve své komponentě).

## Odchylky od zadání

- **Test katalogů:** `ViewerDetailLabelsCatalogTest` povoloval jen sekce
  `tabs` / `labels` / `texts` — sekce `fields` je přidaná do povolených
  (zadání s tím nepočítalo).
- **Helper sekce:** popisek pole jde přes obecný
  `TableViewer::detailSectionText()` (název sekce parametrem);
  `detailText()` je jeho případ se sekcí `texts`. `detailTabLabel()` zůstal
  beze změny.
- **Testy navíc:** `TableViewerLabelTextTest` (náhrada parametrů, fallback,
  dva tvary katalogu) a `RegistryDocumentsViewerMetadataTest` (popisky,
  data, neznámý klíč, katalog před `ds-upgrade`).
- **Písmo u `--shpd-font-mono`:** šest použití mělo fallback `monospace`
  (výchozí neproporcionální písmo prohlížeče); `--shpd-font-family-mono`
  začíná `ui-monospace, SFMono-Regular, Menlo…`, takže se tam písmo může
  lišit — není to jen pořadí písem jako u čtyř použití se zkráceným stackem.
- **`StatusScreen`:** `text-align: center` měla karta; zůstal ve
  `StatusScreen` na vnitřním obalu obsahu (`.shpd-status__body`),
  `CenteredScreen` kvůli tomu prop nemá.
- **Komentář „vlastní kopie stylů“:** v `SetPasswordScreen` a `OpAuthScreen`
  nezmizel beze stopy — nahradila ho poznámka, že obal a kartu dává
  `CenteredScreen` a pravidla obsahu mají stejné třídy jako `LoginScreen`
  (kopie pravidel obsahu jsou mimo rozsah).
- **Container query:** na 360 px se přihlášení, zapomenuté heslo, nastavení
  hesla i stavová obrazovka do karty s odsazením `xl` vejdou (karta 328 px,
  nic nepřetéká) — odsazení `lg` pod `30rem` se nepřidávalo.
- **Dokumentace navíc:** zmínky „bez fallbacku“ u kontroly tokenů opravené
  i v `docs/frontend.md`, `docs/dev/dev-daily.md` a `CLAUDE.md`
  (vývojářský dashboard).
- **Nedefinované `--dev-*`:** žádné se nenašly.
- **Zdroje dat:** `ds-upgrade` proběhl na třech zdrojích s třídou `free`
  podle `shpd-server ds-list` (`i9gn-e`, `nham-q`, `zey0-g`); ostatní
  zůstaly beze změny a katalog `fields` dostanou až s vlastním upgradem.

## Ověření při implementaci

- Kontrola tokenů: záměrně vložený `var(--shpd-neexistuje, red)` a token
  bez fallbacku skript vypsal (první s poznámkou o fallbacku), exit 1.
- Obrazovky (přihlášení, zapomenuté heslo, nastavení hesla, stavová —
  ta s podvrženou odpovědí 503 na `/_app/info`): desktop 1280 px je proti
  stavu před změnou pixelově shodný; na 360 px má karta 16 px od krajů.
- Spisovna: popisky a data ověřené nad zkompilovanou konfigurací volného
  zdroje dat česky i anglicky (viewer nad ukázkovým záznamem, bez
  databáze) — volné zdroje dat nemají dokument Spisovny, proklik detailu
  zůstává ruční.
- PHPUnit celá sada, `npm run build`, `check:i18n`, obě CSS kontroly.

## Ruční ověření (2026-11-10)

Firefox a telefon: přihlášení, zapomenuté heslo a nastavení hesla na
telefonu na výšku i na šířku (na šířku se karta roluje, celá dosažitelná
— očekávané); detail dokumentu Spisovny po `ds-upgrade` (popisky metadat
a data česky i anglicky); seznamy a detaily Majetku, Zakázek a AI česky
i anglicky; navigace classic / wild, report, výběr období, primární
tlačítko s nabídkou v detailu, dialog odpisů, průvodce nastavením — beze
změny. Neproporcionální písmo technických hodnot (`--shpd-font-family-mono`)
v Nastavení a review modalu schváleno.

Zvažované sjednocení přihlašovací karty s kartami v obsahu (rámeček,
zaoblení md, bez stínu) odloženo — karta zůstává plovoucí plochou podle
D35. Česky natvrdo psané nadpisy a popisky detailu Spisovny („Identifikace“,
„Číslo / značka“) patří k bodu checklistu #133 o popiscích ve viewerech.
