# Tabulka: Tiskové úlohy (core_prints_jobs)

Úlohy pro účtenkové tiskárny (#177 D4, D25, U36–U39): co se má vytisknout,
na které periferii kterého zařízení a jak to dopadlo. Úloha vzniká na serveru
(`POST /_print-jobs`), tiskárně ji ve verzi 1 předává webová aplikace přes
most nativní aplikace a výsledek hlásí zpět (`docs/prints.md` kap. 14,
`docs/native-bridge.md` §5).

Tabulka nemá Document třídu ani formulář — řádky zakládá a mění jen
`PrintJobService`. Úlohy starší než 30 dní maže `print-jobs-prune` (cron
`daily`); `ds-reset` tabulku zahazuje.

## Struktura

| Sloupec | Typ | Popis |
|---|---|---|
| `job_id` | varchar(36), `utf8mb4_bin`, unikátní | UUID úlohy — pod ním ji zná most i REST |
| `kind` | varchar(10) | `print` (tisk záznamu) / `test` (zkušební účtenka profilu) / `drawer` (otevření šuplíku) |
| `print_id` | varchar(100), NULL | Id tisku z deklarace `prints`; jen `print` |
| `record_table`, `record_id` | varchar(64), int, NULL | Tištěný záznam; jen `print` |
| `device` | int → `core_devices_devices` | Zařízení, které úlohu tiskne |
| `peripheral` | int → `core_devices_peripherals` | Periferie s rolí, na kterou úloha jde |
| `role` | varchar(16) | Role periferie — ve verzi 1 jen `receipt` |
| `format` | varchar(10) | Formát dat — ve verzi 1 jen `escpos` |
| `profile_id` | varchar(64) | Profil tiskárny, podle kterého se data složila (i záložní) |
| `data` | mediumblob, `sensitive` | Bajty úlohy, jak půjdou na tiskárnu (ne base64) |
| `data_size` | int | Velikost dat v bajtech |
| `warnings` | json, NULL | Varování skládání a profilu `[{severity, code, text}]` |
| `state` | varchar(10) | `created` (bez výsledku — „neověřeno“) / `sent` / `failed` |
| `error_code` | varchar(64), NULL | Kód chyby z `print.submit` u posledního výsledku `failed` |
| `attempts` | smallint | Počet nahlášených výsledků |
| `created_by` | int → `core_system_users` | Kdo úlohu založil; jen on a administrátor ji smí znovu načíst a zapsat výsledek |
| `created_at`, `result_at` | datetime | Vznik úlohy a čas posledního výsledku |

## Poznámky

- `data` je binární sloupec: generické cesty (CRUD, meta, viewer) ho
  nevracejí. Ven jde jen přes `GET /_print-jobs/{jobId}` v base64.
- Stav se řídí **posledním** nahlášeným výsledkem; opakování (Zkusit znovu)
  používá tutéž úlohu a jen zvyšuje `attempts`.
- Přehled pro administrátora: Nastavení → Zařízení → Tiskové úlohy.
