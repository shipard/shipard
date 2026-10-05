# Modul: Tisky (core.prints)

Nastavení společné všem tiskům zdroje dat. Modul je obecný — nezná doklady
ani jiné agendy; samotný běh tisku (deklarace, builder, šablony, render)
žije v `src/Core/Prints/` a popisuje ho [docs/prints.md](../../../docs/prints.md).

Drží dvě věci:

- **Vzhled tisků** (#90 D46): akcentovou barvu hlavičky a umístění loga.
  Hodnoty čte `PrintRunner` a plní jimi `branding` v obálce `PrintData`;
  vykresluje je záhlaví tisku (`header.html.twig` layoutu dokladů). Tělo
  dokladu se nebarví.
- **Texty na tiscích** (#90 D47–D52): vlastní texty vkládané do slotů tisku
  a do e-mailu — agenda v Nastavení, tabulka `core_prints_texts`.

## Závislosti

- `core.system`

Na dokladech modul nezávisí. Cílení textu na typ dokladu a číselnou řadu
zná jen `PrintTextTargeting` (mapa tabulka tisku → sloupce) a nabídky se
plní, jen když je číselník typů v konfiguraci zdroje.

## Tabulky

| Tabulka | Popis |
|---|---|
| [core_prints_texts](tables/core_prints_texts.md) | Texty na tiscích — text, slot, cílení, platnost |

Tabulka je v `keepOnReset` — texty jsou konfigurace zdroje, `ds-reset` je
nechává.

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [PrintTextDocument.php](src/PrintTextDocument.php) | Validace (povinná pole, kompilace textu v sandboxu, platnost, cílení), JSON sloupce |
| [PrintTextsForm.php](src/PrintTextsForm.php) | Formulář — nabídky tisků, typů a řad podle umístění a vybraných tisků |
| [PrintTextsViewer.php](src/PrintTextsViewer.php) | Agenda se štítkem „Platí dnes“ |
| [PrintTextResolver.php](src/PrintTextResolver.php) | Výběr textů platných pro tisk záznamu (tisk, typ, řada, jazyk, stav, platnost ke dni tisku) — implementace `PrintTextProvider` z jádra |
| [PrintTextChoices.php](src/PrintTextChoices.php) | Sloty, tisky, typy dokladů a jazyky z kompilované konfigurace |
| [PrintTextTargeting.php](src/PrintTextTargeting.php) | Která tabulka tisku má typ dokladu a číselnou řadu |

Vykreslení textu (Twig v sandboxu → Markdown → HTML do slotu obálky) dělá
jádro — `src/Core/Prints/Texts/` (`PrintTextCompiler`, `MarkdownEscaper`,
`PrintTextMarkdown`, `PrintTextRenderer`). Modul texty jen drží a vybírá.

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `core.prints.textSlots` | [config/textSlots.jsonc](config/textSlots.jsonc) | Názvy a popisy slotů. Id a druh slotu drží výčet `PrintTextSlot` (`src/Core/Prints/Texts/`); shodu hlídá `PrintTextSlotTest` |
| `core.prints.declarations` | — (skládá `ConfigCompiler`) | Deklarace tisků aktivních modulů: název, tabulka, filtr, `textSlots`. Formulář a Document z něj berou nabídku tisků — k registru tisků se bez cest modulů nedostanou |

## Nastavení

V sekci Aplikace jsou stránka **Tisky** (`printsAppearance`) a agenda
**Texty na tiscích** (`core.prints.texts`). Klíče stránky:

| Klíč | Typ pole | Popis | Bez hodnoty |
|---|---|---|---|
| `prints.accentColor` | `color` | Akcentová barva hlavičky jako `#rrggbb` — pruh u titulku, linka pod hlavičkou, podklad loga | neutrální šedá `#c8c8c8` (`PrintData::DEFAULT_ACCENT_COLOR`) |
| `prints.logoPlacement` | `select` | Strana hlavičky s logem: `left` / `right` | `left` |

Barvu i umístění ověřuje `PrintRunner` znovu při čtení — do nastavení se dá
zapsat i mimo stránku (`ds-setting set`), kde hodnotu nikdo nekontroluje.
Neplatná hodnota = výchozí vzhled.

Logo samotné se nahrává v **Nastavení → Aplikace** (branding slot
`companyLogo`, [docs/app-settings.md](../../../docs/app-settings.md) §3).
