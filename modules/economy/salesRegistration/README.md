# Modul: Evidence tržeb (economy.salesRegistration)

**Doplněk** (`"kind": "addon"`, #170) — evidence tržeb u správce daně, zatím
česká EET 2.0 (zákon účinný od 1. 1. 2027, datové rozhraní v4.1). Zapíná se
ve zdroji dat:

```bash
shpd-ds ds-module enable economy.salesRegistration
```

Design: issue #171. Specifikace: [docs/sales-registration.md](docs/sales-registration.md).
Fáze 1 (`tasks/sales-registration-phase1.md`) staví základ — typ evidence,
registrace, certifikát, pojistku serveru, odeslání zprávy a Ověřit spojení;
vazba na doklady přijde ve fázi 2–3.

Data typu evidence nedeklaruje tento modul, ale modul země
(`world.cz.salesRegistrationTypes`, typ `cz.eet2`: platnost, měna, adresy
prostředí, masky polí). Doplněk k typu přiřazuje třídu, která protokol umí
(`economy.salesRegistration.engines`) — modul jádra tak nejmenuje třídu
doplňku (#171 D17). Typ bez enginu se nenabízí; zdroj dat bez aktivního
modulu země nemá žádný typ a nic dalšího se nerozbije.

## Závislosti

- `core.system`

Na modulu země záměrně nezávisí. Jádro o doplňku neví — žádný modul jádra
ani `install.base` na něm nezávisí (`ModuleKindRules`).

## Tabulky

| Tabulka | Popis |
|---|---|
| `economy_sales_registration_registrations` | Registrace: typ evidence, název, identifikátor poplatníka, režim, platnost, výchozí jednotka, certifikát (klíč šifrovaně), stavy `core.system.docStatesArchive` |
| `economy_sales_registration_units` | Evidenční jednotky registrace: číslo jednotky u správce daně a název; spravují se jen z formuláře registrace |

## Třídy

- `SalesRegistrationTypes` / `SalesRegistrationType` — registr typů: mapa
  enginů × data typu z `world.<země>.salesRegistrationTypes`
- `RegistrationDocument` — pravidla registrace: typ z registru, maska
  identifikátoru, výchozí jednotka jen vlastní, unikátní (typ, poplatník)
  mezi živými registracemi
- `UnitDocument` — maska čísla jednotky podle typu registrace, číslo
  u registrace jen jednou
- `RegistrationsForm` — formulář registrace se sub-tabulkou jednotek
- `RegistrationsViewer` — Nastavení → Evidence tržeb → Registrace

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `economy.salesRegistration.engines` | [config/engines.jsonc](config/engines.jsonc) | Typ evidence → třída engine; země je první část id typu |
| `economy.salesRegistration.modes` | [config/modes.jsonc](config/modes.jsonc) | Režim registrace: ostrý / ověřovací / playground |
| `economy.salesRegistration.viewerDetailLabels` | [config/viewerDetailLabels.jsonc](config/viewerDetailLabels.jsonc) | Texty vieweru |
| `economy.salesRegistration.formTexts` | [config/formTexts.jsonc](config/formTexts.jsonc) | Texty prvků formuláře |

Doplněk deklaruje vlastní sekci Nastavení **Evidence tržeb**
(`settingsSections`, #170 D13).
