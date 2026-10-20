# Modul: Kurzy cizích měn (economy.exchangeRates)

Kurzovní lístky země zdroje dat a dohledání kurzu k datu (#121, task A —
`docs/exchange-rates.md`). Lístky jsou převzatá data: v UI jen ke čtení
(Nastavení → Účetnictví → Kurzovní lístky), zapisuje je výhradně
`ExchangeRateListImporter` — z CLI `shpd-ds exchange-rates-import`
(soubor nebo složka ve formátu `shpd.exchangeRates.list.v1`), ze seederu
datové sady (sekce `exchangeRates/`) a ze služby (task B,
`tasks/exchange-rates-download.md`: `shpd-ds exchange-rates-download`
ve slotu `five-minutes`, `docs/exchange-rates.md` §9).

Typy lístků nedeklaruje tento modul, ale modul země
(`world.cz.exchangeRateLists`: ČNB denní devizový trh, ČNB měsíční ostatní
měny). Zdroj dat jiné země bez typů lístky nepřijme a kurz nenajde — nic
dalšího se nerozbije.

## Závislosti

- `core.system`, `world.base` (měny)

Závisí na něm `docs.core` (formuláře dokladů a applier volají resolver);
banka ho dostává přes `docs.core`.

## Tabulky

| Tabulka | Popis |
|---|---|
| `economy_exchange_rates_lists` | Hlavička lístku: typ, domácí měna, platnost od / do, číslo, zdroj |
| `economy_exchange_rates_values` | Hodnoty lístku: měna × množství × kurz, jak ho vydavatel vyhlásil |

## Třídy

- `ExchangeRateListTypes` / `ExchangeRateListType` — typy z `world.<země>.exchangeRateLists`
- `ExchangeRateListImporter` — jediná cesta zápisu (`created` / `skipped` / `replaced` / `conflict`)
- `ExchangeRateResolver` — kurz k datu (D4, D5, U1, U2), memoizace per instance
- `ExchangeRate`, `ExchangeRateLookup` — výsledek dohledání (kurz za jednotku + důvody)
- `ExchangeRateListsViewer` — viewer v Nastavení
- `Dataset/ExchangeRatesSeeder`, `Dataset/ExchangeRatesExporter` — datové sady (D10)
- `Download/ListDownloadConfig` — klíč `download` typu (typ služby, pracovní dny / měsíčně, vyhlášení, zóna)
- `Download/ExchangeRateServiceClient` + `ExchangeRateServiceTransport` (`Curl…`) — klient služby, odpověď `lists` / `none` / `error`
- `Download/ExchangeRateDownloader` — okno (`DownloadWindow`, `FiscalYears`), očekávaná data (`DownloadSchedule`, `WorkingDayCalendars`), přítomná data (`ExchangeRateListPresence`), stav pokusů (`DownloadState`), plán a výsledek (`DownloadPlan`, `DownloadReport`)
- `Checks/ListMissingCheck` — upozornění `economy.exchangerates.list_missing`
