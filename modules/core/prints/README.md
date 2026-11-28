# Modul: Tisky (core.prints)

Nastavení společné všem tiskům zdroje dat. Modul je obecný — nezná doklady
ani jiné agendy; samotný běh tisku (deklarace, builder, šablony, render)
žije v `src/Core/Prints/` a popisuje ho [docs/prints.md](../../../docs/prints.md).

Drží tři věci:

- **Vzhled tisků** (#90 D46, #162 D132): akcentovou barvu hlavičky,
  umístění loga a čtyři přepínače kontaktů vlastní firmy v zápatí.
  Hodnoty čte `PrintRunner` a plní jimi `branding` v obálce `PrintData`;
  barvu a logo vykresluje záhlaví tisku (`header.html.twig` layoutu
  dokladů), kontakty zápatí (`footer.html.twig`). Tělo dokladu se nebarví.
- **Texty na tiscích** (#90 D47–D52): vlastní texty vkládané do slotů tisku
  a do e-mailu — agenda v Nastavení, tabulka `core_prints_texts`.

- **Tiskové úlohy** účtenkových tiskáren (#177 task 4b,
  [docs/prints.md](../../../docs/prints.md) kap. 14): tabulka
  `core_prints_jobs`, směrování úlohy na tiskárnu zařízení podle role,
  služba, úklid a přehled Tiskové úlohy v Nastavení → Zařízení. REST je
  v jádru (`src/Api/Controller/PrintJobsController.php`).

A konfiguraci **tisku na účtenkovou tiskárnu** (#177 task 4a,
[docs/prints.md](../../../docs/prints.md) §13) — jen data, třídy jsou
v `src/Core/Prints/EscPos/`:

- `core.prints.printerDrivers` — bajty příkazů ESC/POS (ovladač
  `escpos-generic`);
- `core.prints.printerProfiles` — katalog profilů tiskáren (šířky řádku,
  kódová stránka, funkce); čte ho i `GET /_devices/printer-profiles`;
- `core.prints.printerTestTexts` — popisky zkušební účtenky.

## Závislosti

- `core.system`
- `core.devices` — tiskové úlohy se směrují na periferii registrovaného
  zařízení; opačným směrem závislost není (katalog profilů čte
  `core.devices` jen z konfigurace)

Na dokladech modul nezávisí. Cílení textu na typ dokladu a číselnou řadu
zná jen `PrintTextTargeting` (mapa tabulka tisku → sloupce) a nabídky se
plní, jen když je číselník typů v konfiguraci zdroje.

## Tabulky

| Tabulka | Popis |
|---|---|
| [core_prints_texts](tables/core_prints_texts.md) | Texty na tiscích — text, slot, cílení, platnost |
| [core_prints_jobs](tables/core_prints_jobs.md) | Tiskové úlohy — co, na které periferii kterého zařízení, data a výsledek |

Texty jsou v `keepOnReset` — jsou konfigurace zdroje, `ds-reset` je
nechává. Tiskové úlohy ne: odkazují na záznamy, reset je zahodí.

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [PrintTextDocument.php](src/PrintTextDocument.php) | Validace (povinná pole, kompilace textu v sandboxu, platnost, cílení), JSON sloupce |
| [PrintTextsForm.php](src/PrintTextsForm.php) | Formulář — nabídky tisků, typů a řad podle umístění a vybraných tisků |
| [PrintTextsViewer.php](src/PrintTextsViewer.php) | Agenda se štítkem „Platí dnes“ |
| [PrintTextResolver.php](src/PrintTextResolver.php) | Výběr textů platných pro tisk záznamu (tisk, typ, řada, jazyk, stav, platnost ke dni tisku) — implementace `PrintTextProvider` z jádra |
| [PrintTextChoices.php](src/PrintTextChoices.php) | Sloty, tisky, typy dokladů a jazyky z kompilované konfigurace |
| [PrintTextTargeting.php](src/PrintTextTargeting.php) | Která tabulka tisku má typ dokladu a číselnou řadu |

| [Jobs/PrintJobRouter.php](src/Jobs/PrintJobRouter.php) | Zařízení + role → periferie a profil; chyby nastavení místo výběru první nalezené |
| [Jobs/PrintJobService.php](src/Jobs/PrintJobService.php) | Jediný zápis do `core_prints_jobs`: založení úlohy se složenými daty, znovu načtení, výsledek, úklid |
| [Jobs/PrintJobException.php](src/Jobs/PrintJobException.php) | Kódy chyb úloh a jejich HTTP stavy |
| [PrintJobsViewer.php](src/PrintJobsViewer.php) | Přehled Tiskové úlohy — jen ke čtení, bez dat úlohy |
| [Command/PrintJobsPruneCommand.php](src/Command/PrintJobsPruneCommand.php) | `print-jobs-prune` — úklid úloh starších než 30 dní |

Vykreslení textu (Twig v sandboxu → Markdown → HTML do slotu obálky) dělá
jádro — `src/Core/Prints/Texts/` (`PrintTextCompiler`, `MarkdownEscaper`,
`PrintTextMarkdown`, `PrintTextRenderer`). Modul texty jen drží a vybírá.

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `core.prints.textSlots` | [config/textSlots.jsonc](config/textSlots.jsonc) | Názvy a popisy slotů. Id a druh slotu drží výčet `PrintTextSlot` (`src/Core/Prints/Texts/`); shodu hlídá `PrintTextSlotTest` |
| `core.prints.jobKinds`, `core.prints.jobStates` | [config/jobKinds.jsonc](config/jobKinds.jsonc), [config/jobStates.jsonc](config/jobStates.jsonc) | Popisky druhů a stavů tiskových úloh, styl stavu ve vieweru. Hodnoty drží výčty `PrintJobKind` a `PrintJobState` |
| `core.prints.declarations` | — (skládá `ConfigCompiler`) | Deklarace tisků aktivních modulů: název, tabulka, filtr, `textSlots`. Formulář a Document z něj berou nabídku tisků — k registru tisků se bez cest modulů nedostanou |

## Nastavení

V sekci Zařízení je přehled **Tiskové úlohy** (`core.prints.jobs`) — jen ke
čtení, jen pro administrátora. Úlohy starší než 30 dní maže příkaz
`print-jobs-prune` (cron `daily`, jen ve stavu `active`).

V sekci Aplikace jsou stránka **Tisky** (`printsAppearance`) a agenda
**Texty na tiscích** (`core.prints.texts`). Klíče stránky:

| Klíč | Typ pole | Popis | Bez hodnoty |
|---|---|---|---|
| `prints.accentColor` | `color` | Akcentová barva hlavičky jako `#rrggbb` — pruh u titulku a linka pod hlavičkou | neutrální šedá `#c8c8c8` (`PrintData::DEFAULT_ACCENT_COLOR`) |
| `prints.logoPlacement` | `select` | Strana hlavičky s logem: `left` / `right` | `left` |
| `prints.footer.email` | `checkbox` | E-mail vlastní firmy v zápatí dokladu | zapnuto |
| `prints.footer.phone` | `checkbox` | Telefon vlastní firmy v zápatí dokladu | zapnuto |
| `prints.footer.web` | `checkbox` | Web vlastní firmy v zápatí dokladu | zapnuto |
| `prints.footer.dataBox` | `checkbox` | ID datové schránky vlastní firmy v zápatí dokladu | zapnuto |

Barvu i umístění ověřuje `PrintRunner` znovu při čtení — do nastavení se dá
zapsat i mimo stránku (`ds-setting set`), kde hodnotu nikdo nekontroluje.
Neplatná hodnota = výchozí vzhled. Kontakt v zápatí vypíná jen uložené
`false` (`ds-setting set prints.footer.web false`); tiskne se jen údaj
vyplněný na kartě vlastní firmy v době, kdy doklad vznikl — zápatí čte
snapshot strany dokladu.

Logo samotné se nahrává v **Nastavení → Aplikace** (branding slot
`companyLogo`, [docs/app-settings.md](../../../docs/app-settings.md) §3).
