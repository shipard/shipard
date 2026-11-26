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

## Třídy

- `SalesRegistrationTypes` / `SalesRegistrationType` — registr typů: mapa
  enginů × data typu z `world.<země>.salesRegistrationTypes`

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `economy.salesRegistration.engines` | [config/engines.jsonc](config/engines.jsonc) | Typ evidence → třída engine; země je první část id typu |
