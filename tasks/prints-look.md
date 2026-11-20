# Vzhled tisku dokladů — typografické role, kontakty v zápatí (D125–D134)

**Stav:** částečně — implementace hotová 2026-11-20 (5 commitů, #162 D125–D134), ručně proklikané 2026-11-20 (výsledek v #162); úpravy po prokliku v `tasks/prints-look-fixes.md` (D135–D140); zbývá revize `sk` / `de` popisku datové schránky kolegou (sekce „Formulace k revizi“) a zavření #100 po dokončení navazujícího tasku

## Kontext / Cíl

Tisk dokladů (#90) vznikl dřív než pravidla designového systému a porušuje
§11 Typografie: nadpisy Dodavatel / Odběratel verzálkami a šedě, šedé
popisky (`dt`, popisky součtů), hlavičky tabulek 7,5 pt šedě, šedé textové
řádky, tučné 700, rámeček kolem Odběratele. Kontakty vlastní firmy jsou
v bloku Dodavatel, zápatí nese jen název firmy (#100).

**Cíl:** tisk dokladu s rolemi page / title / heading / body / meta v pt,
šedá jen pro doplňky, bez rámečku Odběratele, kontakty vlastní firmy
v zápatí (nejvýš 2 řádky) se čtyřmi přepínači na stránce Tisky, web
a datová schránka ve snapshotu strany.

**Rozsah:** sdílený layout `modules/docs/core/prints/` — faktura vydaná,
zálohová faktura, pokladní doklad, prodejka. Kontace dědí
`doc-base.html.twig` (a tím `doc-base.css`), zápatí má vlastní — změní se
jen písmem. Obsah tisků (údaje, pořadí bloků) se nemění.

**Mimo rozsah:** vložení ISDOC do PDF (#119 fáze 2); popisek „K úhradě“
u záporné částky (#152); zápatí Kontace.

## Návaznost

- **#162** — D125–D134; přebírá **#100** (zavřít po dokončení s odkazem)
- **#90** — tisky, vzhled z nastavení (D46), snapshoty stran (D5, D14)
- **#133 / #135** — typografické role (§11 `docs/design-system.md`)

## Před implementací přečti

- `docs/design-system.md` §11 Typografie (role, pravidla šedé a tučného)
- `docs/prints.md` — obálka `PrintData` (`branding`), §5 Šablony, §12.1 Vzhled
- `docs/app-settings.md` — stránky nastavení, typy polí, odstavec „Další typy
  polí (`checkbox`, `textarea`)…“ (výčet míst pro nový typ)
- `docs/help-authoring.md` — nápověda se mění ve stejném commitu jako funkce
- `tasks/prints-phase3.md` — vzor: nastavení Tisky → `PrintRunner` → `branding`

## Rozhodnutí (potvrzená v #162)

- ✓ **D125** Kapitola **Tisk dokladu** v `docs/design-system.md` — role
  a pravidla §11 převedená do pt; platí pro všechny tisky nad sdíleným
  layoutem dokladu.
- ✓ **D126** Role tisku: page 13 pt (titulek v záhlaví), title 11,5 pt
  (K úhradě), heading 10,5 pt (název strany), body 9 pt (vše ostatní vč.
  hlaviček tabulek), meta 7,5 pt (doplňky, zápatí). Jedna tučná tloušťka:
  600, pokud ji písmo v render službě má jako řez, jinak 700.
- ✓ **D127** Nadpis Dodavatel / Odběratel: body, normální tloušťka, černě,
  bez verzálek; tučný je jen název strany (heading).
- ✓ **D128** Popisky, hlavičky tabulek (body, tučně), popisky součtů
  a textové řádky černě. Šedá jen doplňkům: kontakty v zápatí, přepočet
  v domácí měně a kurz, sleva na řádku, popisek QR, zápatí.
- ✓ **D129** Rámeček kolem Odběratele zaniká bez náhrady.
- ✓ **D130** Zápatí nejvýš 2 řádky, bez názvu firmy. Vlevo 1. řádek
  **e-mail · telefon**, 2. řádek **web · datová schránka**; prázdné údaje se
  vynechají, prázdný řádek zmizí; datová schránka s popiskem, ostatní bez.
  Vpravo Vystavil (jen s autorem) a Strana x / y. Meta, šedě.
- ✓ **D131** Zápis v obchodním rejstříku zůstává v bloku Dodavatel; blok
  Dodavatel = název, adresa, IČO / DIČ, zápis v rejstříku (kontaktní řádek
  zaniká).
- ✓ **D132** Stránka Tisky: čtyři zaškrtávátka (e-mail, telefon, web,
  datová schránka v zápatí), výchozí vše zapnuté; tiskne se jen vyplněný
  údaj. Nový typ pole stránek nastavení `checkbox`.
- ✓ **D133** Snapshot strany dokladu nese i web a ID datové schránky. Tisk
  čte jen snapshot; doklady vystavené dřív mají jen e-mail a telefon.
- ✓ **D134** #162 převezme celé #100.

## 1. Typ pole `checkbox` na stránkách nastavení (D132)

Výčet míst podle `docs/app-settings.md` (vzor posledního typu `color`):

- `ModuleDefinition::fromArray()` — `checkbox` do whitelistu typů polí.
- `SettingsController::savePage()` — hodnota `true` / `false` (JSON
  boolean); jiný typ = 422 `INVALID_VALUE`. Ukládá se vždy explicitní
  hodnota (i shodná s výchozí) — nenastavený klíč = výchozí z deklarace.
- `localizePageDefinition()` — propustit atribut `default` (boolean,
  chybí = `false`) na klienta.
- `DsSettingCommand` — `checkbox` do `STRUCTURED_FIELD_TYPES` **nepatří**;
  `ds-setting set <klíč> true|false` uloží boolean, jiná hodnota = chyba
  s výčtem povolených. `get` vypíše `true` / `false`.
- `SettingsPage.svelte` — `isSavedViaPage`, `splitValues`, render přes
  sdílený `ui/Checkbox.svelte` (popisek vedle zaškrtávátka, nápověda pod
  ním jako u ostatních polí); nenastavená hodnota = `default`.
- Testy: whitelist, `savePage` (boolean projde, řetězec `"1"` ne),
  `ds-setting set` (true / false / nesmysl), lokalizace `default`.
- `docs/app-settings.md`: popis typu `checkbox` (hodnota, `default`,
  CLI), odstavec „Další typy polí“ upravit — zbývá `textarea`.

## 2. Web a datová schránka ve snapshotu strany (D133)

- `PersonSnapshotBuilder` (docs.core): `contact` nově
  `{email, phone, web, gov_e_box_id}` ze sloupců osoby `web`
  a `gov_e_box_id`. Klíče jen přibývají, tvar se jinak nemění.
- Zjisti další místa, která snapshot strany skládají nebo ověřují
  (`DocumentApplier`, import snapshotů ze starého Shipardu, výměnný formát
  — `docs/exchange-format.md`, datové sady / `dataset-verify`). Kde je
  výčet klíčů `contact` pevný, nové klíče volitelné; žádné dopočítávání
  snapshotu u existujících dokladů (D133).
- Test builderu: osoba s webem a datovou schránkou → oba klíče; bez nich
  → `null`.

## 3. Kontakty v zápatí a přepínače (D130, D132)

**Nastavení** (`modules/core/prints/module.jsonc`, stránka
`printsAppearance`) — čtyři pole typu `checkbox`, `default: true`, v tomto
pořadí: `prints.footer.email`, `prints.footer.phone`, `prints.footer.web`,
`prints.footer.dataBox`. Popisky cs / en: „E-mail v zápatí“ / „E-mail in
footer“, „Telefon v zápatí“, „Web v zápatí“, „Datová schránka v zápatí“;
nápověda (stačí u prvního, nebo u každého stejná): „Údaj z karty vlastní
firmy. Prázdný se netiskne.“

**Obálka** — `PrintData` dostane `branding.footerContacts`: seznam
zapnutých položek z pevného výčtu `email`, `phone`, `web`, `dataBox`
(konstanta, pořadí výčtu). `fromArray()`: chybějící klíč = všechny čtyři
(starší JSON pro `--data`), neznámá hodnota = `InvalidArgumentException`.
`PrintRunner` čte čtyři klíče přes `SettingsStore` (nenastavený = zapnuto).
`docs/prints.md` — tabulka obálky a §12.1.

**Zápatí** (`_layout/footer.html.twig`):

- vlastní strana jako dnes (`tradeDir == 2` → `customer`, jinak
  `supplier`), údaje z jejího `contact`;
- vlevo nejvýš dva řádky: 1. `email · phone`, 2. `web · Datová schránka:
  <id>`; položka jen když je zapnutá **a** vyplněná; prázdný řádek se
  nevykreslí; jednotlivá položka se nezalamuje (`white-space: nowrap`),
  oddělovač ` · `;
- telefon přes filtr `phone(address.country)` jako dnes v bloku
  Dodavatel; web tak, jak je uložený;
- vpravo beze změny Vystavil + Strana; sloupce zarovnané dolů (jednořádková
  levá i pravá strana sedí na posledním řádku);
- název firmy zaniká; meta 7,5 pt, `#666`.
- Katalog `_layout/messages.jsonc`: `footer.dataBox` — cs „Datová
  schránka“, en „Data box“, sk „Dátová schránka“, de „Datenpostfach“
  (sk / de k revizi kolegou jako v `tasks/prints-languages.md`).

**Blok Dodavatel** (`_partials/party.html.twig`, `parties.html.twig`):
kontaktní řádek (telefon · e-mail) zaniká; parametr `showContact`
přejmenovat na `showRegistration` (zápis v rejstříku jen u dodavatele,
D131) — i v `economy/accounting/prints/docJournal/page.html.twig`.

**Testy** (`DocPrintTemplatesTest` a obálka):

- zápatí: pořadí e-mail · telefon / web · datová schránka, popisek
  datové schránky ve všech čtyřech jazycích, bez názvu firmy;
- vypnutý přepínač → položka chybí; všechny vypnuté → jen pravý sloupec;
- jen web vyplněný → jediný řádek (žádný prázdný první);
- snapshot bez `web` / `gov_e_box_id` (starší doklad) → jen e-mail
  a telefon;
- `tradeDir` 2 → kontakty odběratele (vlastní firma);
- blok Dodavatel bez telefonu a e-mailu, se zápisem v rejstříku;
- stávající testy, které čekají název firmy v zápatí, upravit.
- `PrintData`: `footerContacts` výchozí / neznámá hodnota / `toArray()`.

**Nápověda** (stejný commit): `help/faktury-vydane/vzhled-tisku.md`
(nový oddíl Kontakty v zápatí, klíčová slova: kontakty na faktuře,
telefon v patičce, datová schránka na faktuře, …), `help/co-dnes-nejde.md`
(odstavec „Kontakty tvé firmy v zápatí dokladu nejsou“ zaniká; zmínky o
textu „Konec dokumentu“ jako náhradě), další výskyty: `grep -rn -i
"zápatí\|patičk" help/`. Pak `python3 scripts/help-index.py`.

## 4. Vzhled podle rolí (D126–D129, D131)

`_layout/doc-base.css` — role jako vlastní proměnné na začátku souboru
(`--print-font-page` 13pt, `--print-font-title` 11.5pt,
`--print-font-heading` 10.5pt, `--print-font-body` 9pt, `--print-font-meta`
7.5pt, `--print-weight-strong`, `--print-color-meta` `#666`) a pravidla
jen přes ně:

| Prvek | Dnes | Nově |
|---|---|---|
| `body` | 9pt | body |
| `h2` (Dodavatel / Odběratel) | 7,5pt, verzálky, `#666` | body, normální, černě, bez verzálek a `letter-spacing` (D127) |
| `.party-name` | 10,5pt 700 | heading, strong |
| `.party--customer` | rámeček + padding | zaniká (D129); třídu z `parties.html.twig` odebrat |
| `.party-detail` (zápis v rejstříku) | 8pt `#666` | body, černě — identifikátor (§11) |
| `dt` | `#666` | černě (D128) |
| `thead th` | 7,5pt `#666` | body, strong, černě; linka pod hlavičkou zůstává |
| `.row-text td` | `#666` | černě |
| `.row-discount` | 7,5pt `#666` | meta, šedě (doplněk) |
| `.doc-recap-table` | 8pt | body |
| `.doc-rate` (kurz) | 8pt `#666` | meta, šedě |
| `.doc-totals th` | `#666` | černě |
| `.doc-totals-due` | 11,5pt 700 | title, strong |
| `.doc-totals-home` (přepočet) | 8pt | meta, šedě (popisek i částka) |
| `.doc-vat-note` | 8pt | body |
| `.doc-note-nontax` | 700 | strong |
| `.doc-qr p` | 7pt `#666` | meta, šedě |
| `.doc-signature` | 7,5pt `#666` | meta, černě (popisek) |
| `.doc-subject` | 10pt | body — **k posouzení při prokliku** (viz Ověření) |
| `.print-text h1–h6` | 700 | strong |

Záhlaví (`header.html.twig`) a zápatí jsou samostatné HTML dokumenty
s inline styly — proměnné z `doc-base.css` tam nejsou. Hodnoty napiš
literálně a v komentáři odkaž na role v `doc-base.css`: titulek page
13 pt strong, číslo dokladu 13 pt normální, zápatí meta.

**Tloušťka strong (D126):** vyrenderuj tisk s `font-weight: 600`
a `pdffonts` ověř, který řez Noto Sans se vložil. Je-li to SemiBold, strong
= 600; jinak (Bold nebo syntetické ztučnění) 700. Výsledek zapiš do
kapitoly Tisk dokladu i do Odchylek.

## 5. Dokumentace (D125)

- `docs/design-system.md`: nová kapitola **13. Tisk dokladu** (stávající
  13 Soubory → 14, 14 Plánovaná rozšíření → 15; odkazy `§13` / `§14` na
  design-system v `docs/`, `tasks/`, `help/` a `CLAUDE.md` přečíslovat —
  grep). Obsah: tabulka rolí v pt s prvky, pravidla (stejná jako §11,
  šedá jen doplňky — výčet, žádný rámeček u stran, jedna tučná tloušťka),
  záhlaví a zápatí jako samostatné dokumenty s literálními hodnotami,
  zápatí nejvýš 2 řádky (D130), akcent jen v záhlaví (#90 D46). §13 Soubory
  doplnit o `doc-base.css`.
- `docs/prints.md`: obálka (`footerContacts`), §12.1 (přepínače),
  popis zápatí (§5), snapshot (`contact`).
- `CLAUDE.md` — řádek `docs/prints.md` v tabulce dokumentů (stránka
  Tisky: + kontakty v zápatí), `docs/app-settings.md` (+ `checkbox`).
- `tasks/README.md` — řádek v sekci Tisky je přidaný; `**Stav:**`
  + `python3 scripts/tasks-index.py`.

## Commity

1. `feat(settings): typ pole checkbox na stránkách nastavení (#162, D132)` — krok 1
2. `feat(docs): web a datová schránka ve snapshotu strany dokladu (#162, D133)` — krok 2
3. `feat(prints): kontakty vlastní firmy v zápatí, přepínače na stránce Tisky (#162, D130–D132)` — krok 3 vč. nápovědy
4. `feat(prints): vzhled tisku dokladu podle typografických rolí (#162, D126–D129)` — krok 4 + nápověda, pokud zmiňuje rámeček (`vzhled-tisku.md` „rámeček odběratele“)
5. `docs: kapitola Tisk dokladu v designovém dokumentu (#162, D125)` — krok 5 + `**Stav:**` + index

## Ověření

Na zdroji dat s třídou `free` a demo sadou (plátce DPH; po kroku 2
přeseedovat, ať snapshoty nesou web a datovou schránku — vlastní Osobě je
případně doplň). `print-run … --format=pdf`, PDF → PNG (`pdftoppm -r 100`)
a snímky do Odchylek:

- faktura vydaná plátce `cs` a `en`, faktura v cizí měně (rekapitulace
  s domácí měnou — šířka tabulky s písmem body), neplátce / doklad bez DPH,
  zálohová faktura, opravný doklad, pokladní doklad, prodejka, Kontace,
  vícestránkový doklad (zápatí na každé straně);
- zápatí: všechny čtyři údaje / jen web / vše vypnuté — 2, 1, 0 řádků
  vlevo; zápatí se vejde do dolního okraje (2 cm) bez oříznutí;
- `pdffonts`: jeden tučný řez (D126);
- stránka Tisky: zaškrtávátka, Uložit, znovu načíst — hodnoty drží,
  nenastavený klíč = zapnuto; `ds-setting get/set` pro jeden klíč.
- PHPUnit cíleně: `DocPrintTemplatesTest|PrintData|PersonSnapshotBuilder|SettingsController|ModuleDefinition|DsSetting`, pak celá sada; `npm run build`, `npm test`.

**K posouzení při prokliku (Anna):** velikost předmětu dokladu
(`.doc-subject` body vs. heading), zápis v rejstříku černě vs. šedě.

## Hotovo když

- [x] Typ pole `checkbox` (server, CLI, `SettingsPage`), testy, `docs/app-settings.md`
- [x] Snapshot strany s `contact.web` a `contact.gov_e_box_id`
- [x] Zápatí podle D130 se čtyřmi přepínači (D132), blok Dodavatel podle D131
- [x] `doc-base.css` podle tabulky, záhlaví a zápatí s literálními rolemi, tloušťka strong ověřená
- [x] Kapitola 13 Tisk dokladu, přečíslování, `docs/prints.md`, `CLAUDE.md`
- [x] Nápověda + `help-index.py`; ověření tisků v Odchylkách
- [x] `**Stav:**`, `tasks-index.py`
- [ ] Proklik (Anna) — velikost předmětu dokladu, zápis v rejstříku černě vs. šedě
- [ ] Revize `sk` / `de` popisku datové schránky; komentář do #162, zavřít #100 s odkazem

## Pasti

- Chromium v záhlaví a zápatí přepisuje prvky s třídami `title`, `date`,
  `url`, `pageNumber`, `totalPages` — nové třídy zápatí pojmenuj jinak
  (`foot-contacts`, ne `url` pro web).
- Zápatí je samostatný dokument bez `doc-base.css`; inline styly musí
  odpovídat rolím ručně.
- Sandbox Twigu je striktní (`PrintSecurityPolicy`) — nový filtr nebo
  funkce v šabloně jen přes výčet; pro zápatí by neměly být potřeba.
- `tradeDir` 2 (pokladní doklad výdej): vlastní firma je odběratel —
  kontakty v zápatí jsou její, ne partnera.
- Kontace dědí `doc-base.css` — po kroku 4 zkontroluj, že `doc-journal.css`
  nepřebíjí role nekonzistentně (jen zapsat, zápatí Kontace mimo rozsah).
- Snapshoty v datových sadách: změna `PersonSnapshotBuilder` může změnit
  výstup `dataset-dump` / `dataset-verify` — ověř a případné schválené
  snímky sad aktualizuj s důvodem v commitu.

## Odchylky od zadání a výsledek ověření

Implementace 2026-11-20, pět commitů podle sekce Commity.

### Odchylky

- **Pole ID datové schránky ve formuláři Osoby** (navíc, odsouhlaseno při
  implementaci): sloupec `gov_e_box_id` plnil jen import a detail ho jen
  zobrazoval — ručně založená vlastní firma by přepínač datové schránky
  neměla čím naplnit. Pole je v sekci Kontakt pod Webovou stránkou, bez
  kontroly tvaru (`world.validations` pro něj pravidlo nemá).
- **Nápověda pole se jmenuje `hint`**, ne `help`; text „Údaj z karty
  vlastní firmy. Prázdný se netiskne.“ je jen u prvního zaškrtávátka.
- **Řádek zaškrtávátka na stránce nastavení nemá popisek vlevo** — nese ho
  sdílený `Checkbox` (je sám `<label>`), stojí ve sloupci polí.
- **Tloušťka strong = 700.** `font-weight: 600` vložil v render službě
  `NotoSans-Bold` — služba má Noto Sans jen v řezech Regular a Bold
  (`pdffonts`). Po změně mají všechny tisky jeden tučný řez (u faktury
  s odpočtem zálohy navíc Italic kurzívního řádku).
- **Jedna tučná tloušťka i mimo tabulku zadání:** z proměnné ji bere také
  vodoznak a dvě pravidla Kontace (`doc-journal.css`, součtový a chybový
  řádek). Třída `party--customer` zmizela i ze šablony Kontace.
- **Horní index poznámky DPH** je meta 7,5 pt (dřív 6,5 pt) — tisk nemá
  šestou velikost.
- **Výčet klíčů `contact` byl pevný i v `DocumentApplier`** (snapshot
  partnera z importního módu): `web` bere z payloadu, `gov_e_box_id` je
  vždy `null` — kanonická strana dokladu ID datové schránky nenese
  a schéma výměnného formátu se nemění. Detail dokladu v aplikaci
  (`documentParts.js`) dál ukazuje jen e-mail a telefon.
- **Datové sady se nemění** — past ze zadání se nenaplnila: `dataset-dump`
  ani `dataset-verify` snapshoty nečtou. Seed → dump → diff demo sady
  ukázal jen rozdíl nesouvisející s tímto taskem (viz níže).
- **`DocPrintStylesTest`** (navíc): hlídá, že styly tisku berou velikost,
  tloušťku a šedou jen z rolí a že literály záhlaví a zápatí proměnným
  odpovídají.
- **Dokumentace nad rámec výčtu:** `docs/docs-mvp.md` (tvar snapshotu),
  `docs/exchange-format.md`, `docs/cli.md`, `docs/modules.md` (zastaralý
  výčet typů polí), `modules/core/prints/README.md`,
  `help/osoby/zalozeni-osoby.md` (sekce se jmenuje *Kontakt*, ne
  *Kontaktní údaje*).

### Ověření tisků

Volný zdroj dat `rpud-r` (demo sada it-servis; vlastní firmě doplněné ID
datové schránky v kopii sady, sada v repozitáři beze změny), PDF přes
render službu, `pdftoppm -r 100`, prohlédnuto před a po. Snímky se do
repozitáře neukládají.

| Tisk | Zdroj | Výsledek |
|---|---|---|
| faktura vydaná `cs` | doklad | role podle tabulky, strany bez rámečku, zápatí 2 řádky |
| faktura vydaná `en` | doklad, `--language=en` | popisky anglicky, „Data box: …“ v zápatí |
| faktura v cizí měně | doklad (EUR, jazyk podle partnera `sk`) + fixture s odpočtem zálohy | rekapitulace se šesti sloupci v písmu body se vejde; sloupec součtů je užší než dřív, ale nezalamuje se |
| neplátce | fixture bez DPH | bez sloupců DPH a rekapitulace |
| zálohová faktura | doklad + fixture | věta o nedaňovém dokladu tučně |
| opravný doklad | fixture | původní doklad a důvod opravy v písmu body |
| pokladní doklad příjem / výdej | fixture / doklad | u výdeje (`tradeDir` 2) kontakty vlastní firmy = odběratele; podpisová pole meta černě |
| prodejka | doklad | beze změny rozvržení |
| Kontace | doklad + fixture | zdědila role; vlastní zápatí s názvem účetní jednotky (mimo rozsah) |
| vícestránkový doklad | fixture se 70 řádky, 3 strany | záhlaví i zápatí s kontakty a Vystavil na každé straně |
| stornovaný doklad | fixture s vodoznakem | vodoznak beze změny vzhledu |
| zápatí 2 / 1 / 0 řádků | doklad, přepínače přes `ds-setting` | všechny čtyři údaje → 2 řádky; jen web → 1 řádek dole; vše vypnuté → jen pravý sloupec; zápatí se vejde do dolního okraje |

Stránka Tisky v prohlížeči (1280 a 390 px): čtyři zaškrtávátka zapnutá
bez uložené hodnoty, po odškrtnutí dvou a Uložit jde na server explicitní
`true` / `false` pro všechna čtyři, po novém načtení stav drží.
`ds-setting set prints.footer.web false` uloží boolean, `… maybe` skončí
chybou s výčtem, `get` vypíše `false`.

### K posouzení při prokliku

- **Předmět dokladu** (`.doc-subject`) je teď body 9 pt (dřív 10 pt) — na
  stránce působí drobně; alternativa je heading 10,5 pt.
- **Zápis v obchodním rejstříku** je černě v písmu body (dřív šedě 8 pt);
  dlouhý zápis se v bloku Dodavatel zalomí na dva řádky.
- **Nápověda u prvního zaškrtávátka** působí, jako by patřila jen
  k e-mailu; druhá možnost je stejný text u všech čtyř.
- **Sloupec součtů u dokladu v cizí měně** je užší, protože rekapitulace
  se šesti sloupci v písmu body zabere víc místa.

### Nález mimo task

Demo sada v repozitáři zaostává za exportérem: `dataset-dump` po seedu
přidává u všech 295 dokladů klíč `totalRoundingAuto`, který sada nemá.
S tímto taskem to nesouvisí (snapshoty se do sady nedostávají); sada by
chtěla přegenerovat samostatně.

## Formulace k revizi

Překlady `sk` a `de` navrhla implementace; reviduje je kolega jako
v `tasks/prints-languages.md` (D32). Zdroj pravdy je katalog
`modules/docs/core/prints/_layout/messages.jsonc`; opravy přijdou
samostatným commitem.

| Klíč | cs | en | sk | de |
|---|---|---|---|---|
| `footer.dataBox` | Datová schránka | Data box | Dátová schránka | Datenpostfach |

Tiskne se v zápatí jako „<popisek>: <ID datové schránky>“. V němčině
připadá v úvahu i ponechání českého pojmu (datová schránka je český
institut bez přesného protějšku).
