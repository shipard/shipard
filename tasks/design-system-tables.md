# Design system — tabulky v obalu, report a sub-tabulka jako datová mřížka

**Stav:** naplánováno — #138 task 3 (D44, D57)

## Kontext / Cíl

Třetí task #138 (zastřešující #133). Obal tabulky s posunem do strany
`TableScroll` vznikl v #139 task 1 pro řádky a rekapitulaci DPH dokladu.
Ostatní tabulky ve frontendu ho nemají, nebo mají vlastní kopii obalu.

Při průzkumu se ukázal střet: obal s posunem jen do strany je rolovací
oblast, která svisle neroluje — tabulka uvnitř pak nemůže mít hlavičku
držící při rolování dolů. Report ji tím ztratil už dnes (sticky `th`
uvnitř `.shpd-report__scroll` s `overflow-x: auto`, dolů roluje až
`.shpd-reports__body`); sub-tabulka formuláře ji drží jen proto, že má na
desktopu obal bez posunu. Proto D57: dva druhy tabulek.

**Cíl:**

1. `TableScroll` dostane režim `fill` pro datovou mřížku (D57).
2. Tabulky v toku obsahu v aplikaci na `TableScroll` (D44).
3. Report a sub-tabulka formuláře jako datová mřížka: obal vyplní výšku
   oblasti, roluje v obou osách, hlavička drží (D57). Reportu se tím vrátí
   držící hlavička.
4. Tabulky vývojářského dashboardu na `TableScroll` (D12, D57).
5. Dokumentace pravidla.

**Mimo rozsah:**

- seznam vieweru (`ViewerGrid`) a prohlížeč tabulek (`TableBrowser`) —
  datové mřížky, které vzoru D57 odpovídají už dnes (vyplní panel, rolují
  v obou osách, sticky hlavička uvnitř); jen komentář s odkazem na D57;
- tabulky v HTML, které posílá backend (`.snapshots-container` a podobné
  — dluh obdobný D30 v #138);
- pevné šířky v px (`colStyle` sub-tabulky, `min(280px, 50%)` filtru,
  `max-height: 320px` logů) — #138 task 4 (D46);
- vzhled tabulek (čáry, písmo, hustota) — jen obal a rolování.

## Návaznost

- **#133** — D7, D12, D44, D57
- **#139** task 1 — `TableScroll` (`tasks/design-system-document-parts.md`)
- **#138** task 1 — container queries; task 4 — rozměry v px
- **#53** — sub-tabulka formuláře (`docs/edit-forms.md` kap. 15)

## Před implementací přečti

- `docs/design-system.md` §8 Responzivita, §12 Tabulka a Úzká šířka
- `docs/edit-forms.md` kap. 15 (sub-tabulka), zvlášť popis tabulky
  a sticky hlavičky
- `frontend/src/components/ui/TableScroll.svelte`
- `frontend/src/components/viewer/ViewerGrid.svelte` — vzor datové
  mřížky (sticky hlavička, `border-collapse: separate`, proč)
- `frontend/src/components/reports/ReportsPage.svelte`, `ReportView.svelte`
- `frontend/src/components/form/FormSubTable.svelte`, `FormTab.svelte`,
  `FormEditor.svelte` (řetěz „fill“ sloupců — `.shpd-form-column--fill`)

## Rozhodnutí (potvrzená v #133)

- ✓ **D44** Každá tabulka v obsahu má vlastní obal s posunem do strany,
  vždy — sdílená komponenta. Stránka ani panel nikdy nerolují do strany.
- ✓ **D57** Upřesnění D44 — dva druhy tabulek. **Tabulka v toku obsahu**
  (detail, nastavení, dialogy, chat, vývojářský dashboard): `TableScroll`,
  posun jen do strany, hlavička nedrží. **Datová mřížka s držící
  hlavičkou** (seznam vieweru, prohlížeč tabulek, report, sub-tabulka
  formuláře): obal vyplní zbývající výšku své oblasti a roluje v obou
  osách, hlavička drží uvnitř. Obal s posunem jen do strany je rolovací
  oblast, která svisle neroluje — držící hlavičku by zrušil. Pro oba
  druhy: stránka ani panel nerolují do strany.
- ✓ **D12** Jedno pravidlo pro celý frontend včetně vývojářského
  dashboardu.

## 1. `TableScroll` s režimem `fill`

- Prop `fill` (boolean, výchozí `false`). S `fill`: `flex: 1;
  min-height: 0; overflow: auto` — obal vyplní zbytek flex sloupce rodiče
  a roluje v obou osách. Bez `fill` beze změny (jen `overflow-x`).
- Komentář: dva druhy tabulek podle D57, proč obal s posunem jen do strany
  ruší sticky hlavičku, že rodič `fill` musí být flex sloupec s určenou
  výškou (jinak obal naroste s obsahem a svisle neroluje); věta
  „rozšíří #138 task 3“ pryč.

## 2. Tabulky v toku obsahu (aplikace)

| Soubor | Dnes | Změna |
|---|---|---|
| `viewer/ViewerDetail.svelte` (typ obsahu `table`) | `.shpd-detail__table-wrap` s `overflow-x` | `TableScroll`, CSS obalu pryč |
| `chat/TableBlock.svelte` | `.shpd-md-table__scroll` s `overflow-x` | `TableScroll`, CSS obalu pryč |
| `account/SessionsPanel.svelte` | bez obalu | `TableScroll` |
| `settings/ContentTagsSettings.svelte` | bez obalu | `TableScroll` |
| `viewer/AssetsDepreciationRunDialog.svelte` (4 tabulky) | bez obalu | každá v `TableScroll` |

`label` jen u tabulky bez vlastního nadpisu sekce (region pro čtečku).

## 3. Report jako datová mřížka

Cíl: hlavička sloupců drží při rolování dolů, tabulka roluje do strany
ve svém obalu, stránka reportů ani panel do strany nerolují.

- Řetěz výšky: `.shpd-reports` (flex sloupec, `height: 100%`) →
  `.shpd-reports__body` → `.shpd-reports__result` → kořen `ReportView` →
  `TableScroll fill`. Každý článek flex sloupec s `flex: 1;
  min-height: 0`. `.shpd-reports__body` dál roluje svisle pro stavy bez
  výsledku (poznámky, chyby, načítání) — s výsledkem obsah vyplní
  přesně jeho výšku a body samo nepřeteče.
- `ReportView`: štítek stavu nahoře, `TableScroll fill` s tabulkou,
  zprávy „pod čarou“ pod obalem (`flex: none`). Při mnoha zprávách nesmí
  tabulku zmáčknout na pár řádků — zprávy dostanou vlastní svislé
  rolování s horní hranicí výšky (v rem nebo podílu výšky, ne px;
  hodnotu posoudit v prohlížeči a zdůvodnit v komentáři). U krátkého
  reportu se obal zmenší na obsah a zprávy jsou hned pod tabulkou.
- `.shpd-report__scroll` a jeho CSS pryč.
- **Dvouřádková hlavička** (skupina sloupců + podsloupce MD / D /
  Zůstatek): dnes mají oba řádky `th` `position: sticky; top: 0` —
  jakmile hlavička začne držet, druhý řádek by překryl první. Držet má
  celý `thead` (`position: sticky; top: 0` na `thead`, pozadí, `z-index`),
  ne jednotlivé `th`. Tabulka `border-collapse: separate;
  border-spacing: 0` jako `ViewerGrid` (s `collapse` čáry hlavičky
  odjedou s obsahem).
- Klik na řádek (drill-down, `onAction`) beze změny.

## 4. Sub-tabulka formuláře jako datová mřížka

- `FormSubTable`: tabulka v `TableScroll fill`; `.shpd-form-subtable__table-wrap`
  s `overflow: visible` a dočasná `@media (max-width: 48rem)` pryč.
  Kořen `.shpd-form-subtable` flex sloupec (`flex: 1; min-height: 0`),
  toolbar (Přidat, filtr) a stavové texty nad obalem.
- Řetěz výšky ve formuláři: tab se sub-tabulkou musí mít **určenou
  výšku** oblasti tabu, ne `min-height` — `.shpd-form-editor__content`
  je svislý scroller a s `min-height: 100%` by obsah tabu narostl, roloval
  by scroller a obal sub-tabulky by svisle nikdy nepřetekl. Postavit po
  vzoru řetězu „fill“ sloupců (`FormEditor` `:has(...)`, `FormTab`):
  `.shpd-form-editor__tab-content:has(.shpd-form-subtable)` flex sloupec
  s `height: 100%`, `.shpd-form-tab:has(.shpd-form-subtable)` `flex: 1;
  min-height: 0`.
- Sticky hlavička (`th` `position: sticky; top: 0`) zůstává — drží
  vůči obalu. Padding tabu zůstává kolem obalu.
- Totéž platí v `FormDialog` (dialog řádku read-only rodiče používá
  `FormSubTable`) — ověřit.
- Komentáře v `FormSubTable` (proč dřív `overflow: visible`) přepsat na D57.

## 5. Seznam vieweru a prohlížeč tabulek

Beze změny kódu. `ViewerGrid` a `TableBrowser`: do komentáře u obalu
věta, že jde o datovou mřížku podle D57 (vlastní obal s rolováním v obou
osách, infinite scroll a sticky hlavička na něm závisí), proto nepoužívá
`TableScroll`.

## 6. Vývojářský dashboard

Všechny tabulky jsou v toku stránky (stránka roluje celá, žádná sticky
hlavička) a stránky mají často několik tabulek pod sebou (detail běhu
devět) — druh „v toku obsahu“, `TableScroll` bez `fill`.

- `dev/components/DevTable.svelte` — tabulka uvnitř komponenty
  v `TableScroll` (pokryje stránky běhů, jobů, porovnání a detailu běhu,
  které ji používají).
- Samostatné `<table>` v `dev/`: `pages/DataSourcesPage.svelte`,
  `pages/testing/` (`ComparePage`, `DatasetsPage`, `DataSourcesPanel`,
  `E2eResult`, `FieldGroupTable`, `JobDetailPage`, `MessageRunsPage`,
  `RoundResult`, `RowsTable`, `RunDetailPage`, `VariantsPage`,
  `VerifyResult`), `pages/videos/VideosPage.svelte` — každá v `TableScroll`
  (import `../../components/ui/TableScroll.svelte` podle hloubky, jako
  `Button`). Seznam ověř grepem, ne podle tabulky.
- Tabulka, která je ve skutečnosti rozvržení formuláře nebo dvojic
  klíč–hodnota a nikdy nepřeteče, obal dostane taky — pravidlo je „každá
  tabulka“, výjimky nedělat.

## 7. Kontrola

Grep do ověření (ne do pre-commitu): každá `<table` v `frontend/src`
je uvnitř `TableScroll` nebo v souboru `ViewerGrid` / `TableBrowser`.
Jednoduchý skript nedělat — po tasku stačí ruční grep níže.

## 8. Dokumentace

- `docs/design-system.md`:
  - §8 Responzivita: odstavec o obalu tabulky — dva druhy (D57), věty
    „na ostatní tabulky ho rozšíří #138 task 3“ pryč;
  - §12 Tabulka: dva druhy, `TableScroll` / `TableScroll fill`, proč obal
    s posunem jen do strany ruší sticky hlavičku, `thead` sticky u
    víceřádkové hlavičky; totéž v Úzká šířka (věta o #138 task 3 pryč);
  - §13 Soubory: řádek `TableScroll` (`fill`, D57).
- `docs/edit-forms.md` kap. 15 (popis tabulky sub-tabulky): obal
  `TableScroll fill`, tab se sub-tabulkou má výšku oblasti, hlavička drží
  vůči obalu.
- `docs/reports.md`: pokud popisuje vzhled nebo rolování reportu, doplnit
  (jinak nic).
- `tasks/README.md` — řádek v sekci Design system.

## Commity

1. `feat(frontend): TableScroll s režimem fill, tabulky v obsahu v obalu (#138, D44, D57)` — §1, §2, §5
2. `fix(frontend): report jako datová mřížka, hlavička zase drží (#138, D57)` — §3
3. `feat(frontend): sub-tabulka formuláře jako datová mřížka (#138, D57)` — §4
4. `feat(dev): tabulky dashboardu v obalu s posunem do strany (#138, D12, D44)` — §6
5. `docs: dva druhy tabulek (#138, D44, D57)` — §8, `**Stav:**`,
   `python3 scripts/tasks-index.py`

## Ověření

```bash
cd frontend && npm test && npm run build
python3 scripts/check-css-tokens.py && python3 scripts/check-css-units.py
# každá tabulka v obalu (výpis projít ručně — u každého souboru TableScroll,
# nebo jde o ViewerGrid / TableBrowser)
grep -rln --include=*.svelte "<table" frontend/src | xargs grep -L "TableScroll"
```

V prohlížeči (Firefox), zdroj dat ukázkové sady (třída `free`):

- **report** (dlouhý, např. předvaha nebo hlavní kniha za rok, sloupce
  MD / D / Zůstatek): při rolování dolů drží celá dvouřádková hlavička,
  řádky se nepřekrývají; na úzkém okně a telefonu 390 px tabulka roluje
  do strany ve svém obalu, stránka ne; zprávy pod čarou viditelné, při
  mnoha zprávách tabulka nezmáčknutá; krátký report — zprávy hned pod
  tabulkou; drill-down z řádku otevře detail; přepnutí parametrů
  (období, v tisících) i stavy bez výsledku (načítání, chyba, bez
  období) vypadají jako dřív;
- **sub-tabulka formuláře** (řádky faktury s mnoha řádky, karta majetku
  s událostmi): hlavička drží, filtr a Přidat nahoře, na telefonu roluje
  tabulka do strany i dolů ve svém obalu, tab ani modal ne; dialog řádku
  read-only dokladu (`FormDialog`) totéž; formulář bez sub-tabulky
  (taby s poli, přílohy s fill sloupcem) beze změny;
- **detail s obecnou tabulkou**, **chat s tabulkou v odpovědi**,
  **Zabezpečení účtu** (relace), **Nastavení → štítky obsahu**, **dialog
  odpisů majetku** — na telefonu tabulka roluje do strany ve svém obalu,
  panel ani stránka ne;
- **seznam vieweru** beze změny (sticky hlavička, součtový footer,
  načítání dalších řádků);
- **vývojářský dashboard** — detail běhu a přehled běhů na úzkém okně:
  tabulky rolují do strany, stránka ne.

## Hotovo když

- [ ] `TableScroll` s `fill`, komentář s D57
- [ ] tabulky v toku obsahu v aplikaci v `TableScroll` (§2), vlastní CSS
      obalů pryč
- [ ] report: obal `fill`, sticky `thead`, zprávy pod obalem, hlavička
      drží
- [ ] sub-tabulka: obal `fill`, řetěz výšky tabu, dočasná media query pryč
- [ ] vývojářský dashboard v `TableScroll`
- [ ] grep z Ověření bez nálezu mimo `ViewerGrid` / `TableBrowser`
- [ ] `docs/design-system.md` §8, §12, §13, `docs/edit-forms.md`
- [ ] `**Stav:**` aktualizovaný, `tasks-index.py`

## Pasti

- **`overflow-x: auto` mění i svislou osu.** Prvek s `overflow-x: auto`
  a `overflow-y: visible` má spočítané `overflow-y: auto` — je rolovací
  oblastí a `position: sticky` uvnitř se drží jeho okraje. Proto D57.
- **`fill` bez určené výšky rodiče nic nedělá.** Když kterýkoli článek
  řetězu nemá `min-height: 0` nebo má `min-height` místo výšky, obal
  naroste s obsahem a roluje zase stránka. Ověřit, že roluje opravdu obal
  (posuvník u tabulky, ne u stránky).
- **Sticky dvou řádků.** `th` s `top: 0` ve dvou řádcích se překryjí —
  držet `thead`. Sticky `thead` funguje v Chromiu i Firefoxu; s
  `border-collapse: collapse` čáry hlavičky odjedou.
- **Infinite scroll a observery.** `ViewerGrid` a `TableBrowser` mají
  obal jako kořen observeru — nepřepínat je na `TableScroll`.
- **Sub-tabulka v dialogu.** `FormDialog` vnořuje `FormEditor` — řetěz
  výšky musí fungovat i tam, ověřit.
- **Prázdný stav sub-tabulky** (žádné řádky, filtru nic neodpovídá) —
  text nad / místo tabulky, nesmí po změně řetězu výšky zmizet ani
  ztratit odsazení.
