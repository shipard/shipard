# Vzhled tisku dokladů — úpravy po prokliku (D135–D140)

**Stav:** částečně — implementace hotová 2026-11-20 (3 commity, #162 D135–D140); zbývá ruční proklik a komentář do #162

## Kontext / Cíl

Ruční proklik `tasks/prints-look.md` (D125–D134) prošel; připomínky:
čáry tisku bijí do očí (černé i šedé v tabulce), datum splatnosti
a variabilní symbol mají být vidět první, nadpisy Dodavatel / Odběratel
jsou malé, zaškrtávátka na stránce Tisky jsou na konci bez uvedení,
ID datové schránky ve formuláři Osoby má stát vedle Webu a zápis
v rejstříku je natěsnaný na údaje nad ním.

**Mimo rozsah:** revize `sk` / `de` popisku datové schránky (kolega,
`tasks/prints-look.md`), demo sada (#163).

## Návaznost

- **#162** — D125–D134 (`tasks/prints-look.md`), D135–D140 (komentář
  s ručním ověřením)
- `docs/design-system.md` §13 Tisk dokladu, §11 Typografie
- `docs/app-settings.md` — typy polí stránek nastavení (vzor: `checkbox`
  z `tasks/prints-look.md` krok 1)

## Před implementací přečti

- `tasks/prints-look.md` — Odchylky (role v `doc-base.css`,
  `DocPrintStylesTest`, literály záhlaví a zápatí)
- `modules/docs/core/prints/_layout/doc-base.css` (role na začátku souboru)
- `frontend/src/components/settings/DsSetup.svelte` — mezinadpis
  „Volitelné“ (`SectionHeading` úrovně 3 + úvodní odstavec) jako vzor
  vzhledu pro D138

## Rozhodnutí (potvrzená v #162)

- ✓ **D135** Dvě role čar: strukturní `#c8c8c8` 0,5 pt (nad a pod blokem
  dat, pod hlavičkou tabulky, kolem K úhradě, součtový řádek Kontace, `hr`
  a citace v textech na tiscích); řádky tabulek (doklad i Kontace)
  `#e6e6e6` 0,5 pt. Podpisové linky zůstávají černé.
- ✓ **D136** Datum splatnosti a variabilní symbol (platební reference):
  **hodnota** tučně, popisek normálně — pojmenovaná výjimka tisku z §11.
  Všude, kde se tisknou.
- ✓ **D137** (upravuje D127) Nadpis Dodavatel / Odběratel heading 10,5 pt,
  normální tloušťka, černě; název strany heading tučně.
- ✓ **D138** Položka stránky nastavení `section` bez hodnoty — mezinadpis
  a úvodní text. Stránka Tisky: „Kontakty v zápatí“, text „Údaje z karty
  vlastní firmy. Prázdný údaj se netiskne.“, zaškrtávátka E-mail, Telefon,
  Web, Datová schránka bez nápovědy u položek.
- ✓ **D139** Formulář Osoby, sekce Kontakt: Web a ID datové schránky na
  jednom řádku ve dvou sloupcích jako E-mail a Telefon.
- ✓ **D140** Zápis v obchodním rejstříku s malým odsazením shora.

## 1. Tisk (D135–D137, D140)

`doc-base.css` — nové role vedle stávajících (stejný blok proměnných):
`--print-color-rule` `#c8c8c8`, `--print-color-rule-light` `#e6e6e6`,
tloušťka obou 0,5 pt (případně `--print-rule-width`). Všechny čáry jen
přes role:

| Místo | Dnes | Nově |
|---|---|---|
| `.doc-info` nahoře a dole | 0,5pt `#666` | rule |
| `thead th` dole | 0,5pt `#000` | rule |
| `.doc-rows tbody tr` | 0,25pt `#ccc` | rule-light |
| `.doc-totals-due` nahoře a dole | 0,5pt `#000` | rule |
| `.print-text hr`, `.print-text blockquote` | `#666`, 1,5pt `#ccc` | rule (blockquote dál 1,5 pt) |
| `.doc-signature` | 0,5pt `#000` | beze změny — linka k podpisu |
| Kontace `tbody tr` / `.row-total` | 0,25pt `#ccc` / 0,5pt `#000` | rule-light / rule |

Ostatní výskyty `border` v `doc-base.css`, `doc-journal.css` a šablonách
tisku dokladů najdi grepem a zařaď do jedné z rolí (záhlaví s akcentovou
barvou je mimo — barvu určuje nastavení, výchozí `#c8c8c8` už roli
odpovídá).

**D136:** hodnota data splatnosti (`doc-base.html.twig`, blok `dates`;
prodejka `cashRegister/prints/receipt/page.html.twig`) a platební
reference (`_partials/payment.html.twig`) dostane třídu, např.
`doc-key-value`, s `font-weight: var(--print-weight-strong)`; popisek
`dt` beze změny. Grep `label.dueDate`, `payment.reference` — i přepsané
bloky jednotlivých tisků.

**D137:** `h2` v bloku stran: `--print-font-heading`, normální tloušťka.

**D140:** `.party-detail` `margin-top` malé (cca 0,1 cm — stejné jako
mezery mezi skupinami v bloku stran, pokud nějaké jsou; jinak navrhni
a zapiš).

`DocPrintStylesTest` rozšířit: čáry jen z rolí (žádný literál barvy
v `border*` mimo podpisovou linku), třída klíčové hodnoty má strong.

## 2. Mezinadpis na stránkách nastavení (D138)

Položka `{"type": "section", "id": "…", "name…", "hint…"}` v `fields`
stránky — bez hodnoty, žádný klíč nastavení:

- `ModuleDefinition::fromArray()` — `section` do whitelistu; `default`
  a `options` se ignorují.
- `SettingsController::savePage()` — položku přeskočit (hodnota
  v požadavku = ignorovat, ne chyba); `localizePageDefinition()` —
  propustit `name` a `hint` jako u ostatních.
- `DsSettingCommand::collectDeclaredKeys()` — `section` není klíč (ani
  v `list`, ani v nápovědě povolených klíčů).
- `SettingsPage.svelte` — vykreslí sdílený `SectionHeading` (úroveň 3)
  a pod ním `hint` jako úvodní odstavec, přes celou šířku stránky, se
  stejnými odstupy jako „Volitelné“ v `DsSetup`; `isSavedViaPage` false.
- Stránka Tisky (`modules/core/prints/module.jsonc`): před čtyři
  zaškrtávátka položka `section` „Kontakty v zápatí“ / „Footer contacts“
  s textem „Údaje z karty vlastní firmy. Prázdný údaj se netiskne.“ /
  „Taken from your own company's card. An empty value is not printed.“;
  popisky zaškrtávátek E-mail, Telefon, Web, Datová schránka (en: E-mail,
  Phone, Website, Data box); `hint` u zaškrtávátek odstranit.
- Testy: whitelist, `savePage` s položkou `section`, `ds-setting list`
  bez ní, lokalizace.
- `docs/app-settings.md` — typ `section`.

## 3. Formulář Osoby (D139)

`PersonsForm`: `web` a `gov_e_box_id` do `inline()` jako `email`
a `phone`. Ověřit na šířce telefonu (sloupce pod sebe jako u e-mailu).

## 4. Dokumentace a nápověda

- `docs/design-system.md` §13 Tisk dokladu: role čar (D135), výjimka
  klíčových platebních údajů (D136) včetně důvodu, nadpis stran (D137,
  odkaz na D127), odsazení zápisu v rejstříku (D140).
- `docs/prints.md` — jen pokud popisuje čáry nebo nadpisy stran.
- Nápověda: `help/faktury-vydane/vzhled-tisku.md` — názvy zaškrtávátek
  a mezinadpis (stejný commit jako krok 2), `help/osoby/` — pokud
  popisuje rozložení sekce Kontakt; `python3 scripts/help-index.py`.
- `tasks/prints-look.md` — do Stavu odkaz na tento task.

## Commity

1. `feat(prints): světlejší čáry, tučná splatnost a variabilní symbol, větší nadpisy stran (#162, D135–D137, D140)` — krok 1 + §13
2. `feat(settings): mezinadpis na stránkách nastavení, kontakty v zápatí pod ním (#162, D138)` — krok 2 + nápověda
3. `feat(persons): web a datová schránka vedle sebe ve formuláři (#162, D139)` — krok 3
4. `docs: …` jen pokud něco zbude; jinak `**Stav:**` + `python3 scripts/tasks-index.py` v posledním commitu

## Ověření

Volný zdroj dat s demo sadou, PDF přes render službu, `pdftoppm -r 100`,
před a po: faktura `cs` a `en`, zálohová faktura, prodejka se
splatností, pokladní doklad (podpisové linky), Kontace, doklad s textem
s `hr` a citací. Kontrola: všechny čáry ve dvou odstínech, splatnost
a VS tučně jen hodnota, nadpisy stran stejně velké jako název, ale
netučné, zápis v rejstříku odsazený.

Stránka Tisky (1280 a 390 px): mezinadpis a text před zaškrtávátky,
uložení beze změny chování. Formulář Osoby (1280 a 390 px).

PHPUnit cíleně (`DocPrintStylesTest|DocPrintTemplatesTest|SettingsController|ModuleDefinition|DsSetting|PersonsForm`), pak celá sada; `npm run build`, `npm test`.

## Hotovo když

- [x] Čáry jen přes dvě role, podpisy černé (D135)
- [x] Hodnota splatnosti a VS tučně všude, kde se tisknou (D136)
- [x] Nadpis stran heading normální (D137), zápis v rejstříku odsazený (D140)
- [x] Položka `section` (server, CLI, `SettingsPage`), stránka Tisky podle D138
- [x] Web a datová schránka na jednom řádku (D139)
- [x] §13, nápověda, `help-index.py`, `**Stav:**`, `tasks-index.py`
- [ ] Komentář do #162 (po ručním prokliku)

## Pasti

- `#e6e6e6` 0,5 pt je na hranici viditelnosti na laserové tiskárně —
  posoudit v PNG při 100 dpi i v náhledu; nezesvětlovat dál.
- Platební reference se v jiných jazycích jmenuje jinak než „Variabilní
  symbol“ — tučná je vždy hodnota `payment.reference`, ne podle popisku.
- `section` nesmí projít do `ds-setting set` ani do uložených hodnot —
  hlídá test.

## Odchylky od zadání a výsledek ověření

Implementace 2026-11-20, tři commity podle seznamu výše (čtvrtý `docs:`
nezbyl — dokumentace jde s kódem svého kroku).

### Odchylky

- **Tloušťka čar je třetí proměnná** `--print-rule-width` (0,5 pt) vedle
  dvou barev. Bere ji i podpisová linka (`var(--print-rule-width) solid
  #000`) — černá zůstává jen barva.
- **Nadpis strany je pravidlo `h2`**, takže heading v normální tloušťce
  mají i nadpisy Účetní jednotka / Partner v Kontaci, ne jen Dodavatel /
  Odběratel.
- **Odsazení zápisu v rejstříku je 0,15 cm**, ne 0,1 cm: blok strany už
  jednu mezeru mezi skupinami má (adresa → IČO / DIČ, 0,15 cm) a zápis
  dostal tutéž. Shodu obou hodnot hlídá `DocPrintStylesTest`.
- **Klíčový údaj jen tam, kde se tiskne:** společný blok dat
  (`doc-base.html.twig`), prodejka převodem a platební blok. Pokladní
  doklad ani Kontace splatnost a platební referenci netisknou. Specifický
  a konstantní symbol tučné nejsou.
- **Mezinadpis má id `prints.footerContacts`** — id je povinné jako
  u pole, klíč nastavení to není. Mezinadpis na prvním místě stránky nemá
  linku ani horní odstup (není od čeho oddělovat).
- **`SettingsController::buildPageValues()`** mezinadpis přeskakuje také
  (zadání jmenovalo jen `savePage` a `localizePageDefinition`) — bez toho
  by `page` posílala pod jeho id `null`.
- **Nápověda `help/osoby/`** se nemění: rozložení sekce Kontakt
  nepopisuje, jen vyjmenovává pole.
- `docs/cli.md`, `docs/modules.md` a řádek `docs/app-settings.md`
  v `CLAUDE.md` doplněné o `section` nad rámec výčtu zadání.

### Ověření

PDF přes render službu nad volným zdrojem dat `rpud-r` (demo sada
it-servis) a nad fixture `tests/Fixtures/Prints/` (`print-run --data`),
`pdftoppm -r 100`, 19 tisků před a po:

| Tisk | Zdroj | Výsledek |
|---|---|---|
| faktura `cs`, `en`, cizí měna | doklady sady | čáry ve dvou odstínech, splatnost a VS tučně jen hodnota, nadpisy stran ve velikosti názvu a netučné |
| zálohová faktura | doklad sady, fixture | totéž |
| prodejka převodem | fixture | splatnost i VS tučně |
| prodejka hotově | doklad sady, fixture | bez splatnosti a VS — nic tučného v bloku dat |
| pokladní doklad příjem / výdej | fixture, doklad sady | podpisové linky černé, ostatní čáry z rolí |
| Kontace | doklad sady, fixture | řádky světle, součtový řádek strukturní čárou, linka Zaúčtoval černá |
| doklad s textem (`hr`, citace) | fixture s `texts.beforeRows` | `hr` a pruh citace strukturní šedou |
| neplátce, opravný doklad, tři strany, storno | fixture | beze změny proti kroku 4 `prints-look` kromě čar a nadpisů |

Zápis v rejstříku je odsazený a dvouřádkový zápis (demo sada) se čte jako
samostatná skupina.

Stránka Tisky (Playwright, 1280 a 390 px): mezinadpis „Kontakty v zápatí“
a text přes celou šířku, pod nimi čtyři zaškrtávátka bez nápovědy; Uložit
posílá jen šest klíčů polí (žádný pro mezinadpis), hodnoty po novém
načtení drží, bez vodorovného posuvu. `ds-setting set
prints.footerContacts x` končí chybou neznámého klíče.

Formulář Osoby (1280 a 390 px): Webová stránka a ID datové schránky na
jednom řádku pod E-mailem a Telefonem, na šířce telefonu pod sebou.

Celá sada PHPUnit (11 720 testů) prochází; `npm run build`, `npm test`,
`check:i18n` a tři CSS skripty v pořádku. `ds-upgrade` není potřeba
(stránky nastavení se čtou z `module.jsonc` za běhu).

### K posouzení při prokliku

- **Světlá čára řádků (`#e6e6e6`, 0,5 pt)** je v PNG při 100 dpi
  i v náhledu PDF zřetelná; na laserové tiskárně ověřená není.
- **Zaškrtávátka stojí ve sloupci polí** (odsazená pod popisky ostatních
  polí), mezinadpis a text začínají u levého okraje karty — stejná mřížka
  jako zbytek stránky. Alternativa je zaškrtávátka od levého okraje.

