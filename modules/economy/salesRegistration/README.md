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
- `Certificate/Pkcs12Importer` — `.p12` + heslo → klíč a certifikát v PEM
  s ověřenou identitou; `Certificate/LegacyPkcs12Reader` — záložní
  `openssl pkcs12 -legacy` pro zastaralou šifru, heslo jen na stdin
- `Certificate/KeyFiles` — dočasné soubory klíče v privátním adresáři
  zdroje dat `tmp/`, smazané ve `finally`
- `Actions/UploadCertificateAction` — akce záznamu „Nahrát certifikát“
  (jen správce)
- `Actions/VerifyConnectionAction` — akce záznamu „Ověřit spojení“:
  zkušební zpráva v ověřovacím módu (jen správce)
- `Engine/SalesRegistrationEngine` — rozhraní engine typu evidence
  a hodnotové objekty `SalesMessage`, `SenderIdentity`, `EngineEnvironment`,
  `SendResult`, `SendStatus`
- `Country/Cz/Eet2Engine` — engine `cz.eet2` (EET 2.0) nad knihovnou
  `ondrejnov/eet`
- `Sender/SalesRegistrationSender` — odeslání jedné zprávy: typ → engine,
  trasa, identita, dočasný klíč, log
- `Texts` — texty akcí a hlášek z katalogu `economy.salesRegistration.texts`
- `Safety/SalesRegistrationServerConfig` — pojistka serveru, sekce
  `salesRegistration` v `server.json` (níže)
- `Safety/MessageRouteResolver` → `MessageRoute` — jediné místo, které
  rozhoduje, kam zpráva jde; `Safety/MessageRouteText` totéž slovy
- `Safety/PlaygroundIdentity` — sdílená testovací identita playgroundu
  z [resources/playground/](resources/playground/README.md)

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `economy.salesRegistration.engines` | [config/engines.jsonc](config/engines.jsonc) | Typ evidence → třída engine; země je první část id typu |
| `economy.salesRegistration.modes` | [config/modes.jsonc](config/modes.jsonc) | Režim registrace: ostrý / ověřovací / playground |
| `economy.salesRegistration.viewerDetailLabels` | [config/viewerDetailLabels.jsonc](config/viewerDetailLabels.jsonc) | Texty vieweru |
| `economy.salesRegistration.formTexts` | [config/formTexts.jsonc](config/formTexts.jsonc) | Texty prvků formuláře |
| `economy.salesRegistration.texts` | [config/texts.jsonc](config/texts.jsonc) | Texty akcí záznamu a jejich hlášek |

Doplněk deklaruje vlastní sekci Nastavení **Evidence tržeb**
(`settingsSections`, #170 D13).

## Knihovna

`ondrejnov/eet` — klient EET 2.0 (sestavení a podpis zprávy, HTTPS, ověření
podpisu potvrzení). Bez release tagu, proto pinovaný commit
`cd26c32b573b0560ab8fe35ad2f3b8ff8fe096f8` v `composer.json`. Balík je
v `repositories` jako `vcs` z GitHubu: Packagist má pod `dev-master` stále
starou verzi pro EET 1 (závislost na `ext-soap`), a Composer bere u pinu
commitu metadata z hlavy větve v repozitáři balíků. `minimum-stability` se
kvůli tomu nemění. Doplněk používá knihovnu jen v `Eet2Engine`.

## Konfigurace serveru

Doplněk deklaruje sekci `salesRegistration` v `/etc/shipard/server.json`
(`serverConfig` v `module.jsonc`, [docs/modules.md](../../../docs/modules.md) kap. 5).
Čte ji [SalesRegistrationServerConfig.php](src/Safety/SalesRegistrationServerConfig.php):

| Klíč | Výchozí | Popis |
|---|---|---|
| `salesRegistration.safety.mode` | produkční server `off`, jinak `drop` | Pojistka serveru: `off` = rozhoduje režim registrace, `playground` = všechno na neprodukční prostředí se sdílenou testovací identitou (nikdy s certifikátem zdroje dat), `drop` = nic neodejde. Chybná hodnota = `drop`. Zdroj dat ji nepřepíše ([docs/sales-registration.md](docs/sales-registration.md) §5) |

```json
"salesRegistration": { "safety": { "mode": "playground" } }
```
