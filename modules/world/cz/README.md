# Modul: Česko — číselníky (world.cz)

Statické české státní číselníky, které potřebuje víc domén a které nemají
podobu dat na zdroji dat. Modul nemá žádné tabulky — jen konfigurační
položky, které se kompilují do `compiled.{cs,en}.json` a čtou se přes
`ConfigRuntime`.

První konzument: **profil podatele** na registraci k DPH
(`economy.vat.filingProfileCz`, issue #74, #55 D19) — vybírá z něj finanční
úřad a územní pracoviště pro větu P podání DPHDP3 / DPHKH1 / DPHSHV.

## Závislosti

- `world.base` — číselníky zemí (společný základ vrstvy `world`)

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `world.cz.taxOffices` | [config/taxOffices.jsonc](config/taxOffices.jsonc) | Finanční úřady (15) — atribut `c_ufo` v podání |
| `world.cz.taxOfficeBranches` | [config/taxOfficeBranches.jsonc](config/taxOfficeBranches.jsonc) | Územní pracoviště (201) — atribut `c_pracufo`, `office` = nadřízený úřad |
| `world.cz.epoCountries` | [config/epoCountries.jsonc](config/epoCountries.jsonc) | Země podle daňového portálu — atribut `stat` v podání |
| `world.cz.exchangeRateLists` | [config/exchangeRateLists.jsonc](config/exchangeRateLists.jsonc) | Typy kurzovních lístků ČNB (denní devizový trh, měsíční ostatní měny) — pořadí = priorita dohledání, `maxAgeDays` = nejvyšší stáří denního lístku (#121 D1, U1); čte `economy.exchangeRates` |

## Principy

### Číselník, ne tabulka

Seznam finančních úřadů je legislativní konstanta, ne uživatelská data —
patří do modulu, ne na zdroj dat. Aktualizace = změna souboru
a `vendor/bin/shpd-ds ds-upgrade` (rekompilace cfgItem).

### Kód je hodnota v podání

Klíč položky je přímo kód, který jde do XML (`c_ufo` = `451`,
`c_pracufo` = `2001`). Názvy jsou jen pro nabídku ve formuláři; u pracovišť
nesou i kraj, protože nabídka není provázaná s vybraným úřadem
(filtr podle `office` je připravený bod rozšíření).

### Stav dat

Přeneseno ze starého Shipardu (stav 2021). Kódy i názvy je potřeba ověřit
proti aktuálnímu XSD daňového portálu při implementaci XML podání
(#55 Fáze 3) — do té doby slouží jen k vyplnění profilu.

## Další číselníky

Do modulu patří další statická česká data téhož druhu (kódy NACE/OKEČ pro
`c_okec`, kódy podepisujících osob, pokud přestanou být specifické pro DPH).
Doménové konvence — co se kterým kódem vykazuje — zůstávají v doménovém
modulu, tady jsou jen seznamy (stejná hranice jako `world.vat` vs.
`economy.vat`, viz [docs/accounting.md](../../../docs/accounting.md)).
