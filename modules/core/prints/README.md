# Modul: Tisky (core.prints)

Nastavení společné všem tiskům zdroje dat. Modul je obecný — nezná doklady
ani jiné agendy; samotný běh tisku (deklarace, builder, šablony, render)
žije v `src/Core/Prints/` a popisuje ho [docs/prints.md](../../../docs/prints.md).

Dnes drží **vzhled tisků** (#90 D46): akcentovou barvu hlavičky a umístění
loga. Hodnoty čte `PrintRunner` a plní jimi `branding` v obálce `PrintData`;
vykresluje je záhlaví tisku (`header.html.twig` layoutu dokladů). Tělo
dokladu se nebarví.

## Závislosti

- `core.system`

## Nastavení

Stránka **Tisky** (`printsAppearance`) v sekci Aplikace:

| Klíč | Typ pole | Popis | Bez hodnoty |
|---|---|---|---|
| `prints.accentColor` | `color` | Akcentová barva hlavičky jako `#rrggbb` — pruh u titulku, linka pod hlavičkou, podklad loga | neutrální šedá `#c8c8c8` (`PrintData::DEFAULT_ACCENT_COLOR`) |
| `prints.logoPlacement` | `select` | Strana hlavičky s logem: `left` / `right` | `left` |

Barvu i umístění ověřuje `PrintRunner` znovu při čtení — do nastavení se dá
zapsat i mimo stránku (`ds-setting set`), kde hodnotu nikdo nekontroluje.
Neplatná hodnota = výchozí vzhled.

Logo samotné se nahrává v **Nastavení → Aplikace** (branding slot
`companyLogo`, [docs/app-settings.md](../../../docs/app-settings.md) §3).
