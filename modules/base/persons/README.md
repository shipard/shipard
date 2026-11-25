# Modul: Osoby (base.persons)

Modul spravuje fyzické osoby i firmy — dodavatele, odběratele, zaměstnance
a další subjekty, se kterými systém pracuje. Ke každé osobě lze evidovat
kontaktní osoby/místa a bankovní účty.

## Závislosti

- `core.system`

## Tabulky

| Tabulka | Popis |
|---|---|
| [base_persons_persons](tables/base_persons_persons.md) | Hlavní evidence osob a firem |
| [base_persons_contacts](tables/base_persons_contacts.md) | Kontaktní osoby a kontaktní místa |
| [base_persons_bank_accounts](tables/base_persons_bank_accounts.md) | Bankovní účty |
| [base_persons_addresses](tables/base_persons_addresses.md) | Adresy — sídla, doručovací adresy, provozovny, zařízení |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [PersonDocument.php](src/PersonDocument.php) | Dokumentová třída — validace a before-save logika |
| [PersonType.php](src/PersonType.php) | PHP enum `PersonType` |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `base.persons.personTypes` | [config/personTypes.jsonc](config/personTypes.jsonc) | Číselník typů osob (neurčeno / FO / firma) |
| `base.persons.bankAccountSources` | [config/bankAccountSources.jsonc](config/bankAccountSources.jsonc) | Zdroje bankovních účtů (ruční / transakce / registr DPH) |
| `base.persons.addressTypes` | [config/addressTypes.jsonc](config/addressTypes.jsonc) | Typy adres (sídlo / doručovací / provozovna / zařízení) |
| `base.persons.placeRegTypes` | [config/placeRegTypes.jsonc](config/placeRegTypes.jsonc) | Typy registrů míst (IČP / IČZ) |
| `base.persons.sourceKinds` | [config/sourceKinds.jsonc](config/sourceKinds.jsonc) | Klíče pro sloupec `source_kind` v `base_persons_persons` — `manual`, `aiExtraction`, `import.ares`, `import.rpo`, `import.handelsregister`, `import.shipardRegistry`, `import.csv` |

### Konfigurace serveru

Modul deklaruje sekci `registry.persons` v `/etc/shipard/server.json`
(`serverConfig` v `module.jsonc`, [docs/modules.md](../../../docs/modules.md) kap. 5).
Čte ji [Registry/PersonsRegistryServerConfig.php](src/Registry/PersonsRegistryServerConfig.php):

| Klíč | Výchozí | Popis |
|---|---|---|
| `registry.persons.baseUrl` | `https://data.shipard.org/persons` | Adresa registru osob (ARES + RPO + registr plátců DPH). Musí být http(s); prázdná hodnota je chyba, koncové lomítko se odřízne |
