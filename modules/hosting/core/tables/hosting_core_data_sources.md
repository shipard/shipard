# Tabulka: Hosting — zdroje dat (hosting_core_data_sources)

Evidence zdrojů dat (DS) spravovaných hostingem. Fáze 0 = ručně plněná
evidence + zdroj portálového seznamu „moje DS". Fáze 1 přidala OIDC
klientské sloupce (řádek = klient OIDC OP, `client_id` = `ds_id`).
Fáze 2 přidala provisioning sloupce (`owner`, `provision_error`,
`claimed_at`) — řádek s `lifecycle = request` je požadavek ve frontě
pro agenta `hosting-sync`. Fáze 3 (D4) přidala `mail_token` — token
DS pro `/_mail/incoming`, který hosting brokeruje mail-routerům.
Sloupec `analyzer_token` (task hosting-10, token pro externí AI analyzer)
z definice zmizel s registrem analyzerů (#85 D22); v databázích starších
hostingů zůstává osiřelý, `ds-upgrade` sloupce nemaže.

`tableId = 431`. Stavový model: `core.system.docStatesArchive`.
**`adminOnly = true`** (D9) — portáloví uživatelé se k datům dostanou
výhradně přes `/_hosting/portal/*` (D10).

## Struktura

### Identifikace (identity)

| Sloupec | Typ | Popis |
|---|---|---|
| `ds_id` | varchar(19), NOT NULL, UNIQUE | ID zdroje dat ve formátu `xxxx-xxxx-xxxx-xxxx` |
| `name` | varchar(200), NOT NULL | Lidský název DS (zobrazuje se na portálu) |
| `web_id` | varchar(50), UNIQUE | Slug pro mail adresy a hezké URL. NULL = nepřidělen. Fáze 3: vyplněný `web_id` = alias klíče v lookup odpovědi pro mail-routery. |

### Umístění (placement)

| Sloupec | Typ | Popis |
|---|---|---|
| `server` | int → [hosting_core_servers](hosting_core_servers.md) | Server, na kterém DS běží. NULL = zatím nepřiřazen. |
| `url_app` | varchar(200), NOT NULL | URL aplikace — cíl vstupního tlačítka na portálu |
| `install_module` | varchar(50) | Install modul DS (např. `install.base`) — evidence pro provisioning |
| `lifecycle` | enumString(10), NOT NULL, default `active` | Klíč v [`hosting.core.dsLifecycle`](../config/dsLifecycle.jsonc) — request, creating, active, suspended, failed. Frontu Fáze 2 řídí request → creating → active/failed; retry po `failed` = admin přepne zpět na `request`. |
| `owner` | int → core_system_users | Vlastník DS (U1) — vybírá se v admin formuláři požadavku; při confirmu `ok` dostane vazbu v `hosting_core_ds_users` (role `admin`) a předpropojenou identitu na novém DS (U2). |

### OIDC (oidc)

Klient OIDC OP (D2) je aktivní, jen když jsou vyplněné **oba** sloupce —
`authorize` jinak klienta odmítne (400, bez redirectu).

| Sloupec | Typ | Popis |
|---|---|---|
| `oidc_client_secret` | encrypted_text, **sensitive** | Client secret pro token endpoint (`client_secret_post`). Šifruje `HostingDataSourceDocument::beforeSave`; plní CLI `hosting-oidc-client`. Nikdy se nevrací v API/form odpovědích. |
| `oidc_redirect_uri` | varchar(250) | Registrovaná redirect URI klienta — **exact match** proti `authorize` požadavku. Fáze 1 plní admin ručně (CLI), Fáze 2 provisioning agent. |

### Pošta (mail)

| Sloupec | Typ | Popis |
|---|---|---|
| `mail_token` | encrypted_text, **sensitive** | `shpd_ak_` token DS pro `/_mail/incoming`. Mintuje agent (`mail-router-setup --json`) a hlásí ho confirmem; šifruje `HostingDataSourceDocument::beforeSave`. Dešifrovaný odchází jen endpointem `GET /_hosting/mail/lookup` (klíč mail-routeru, https). Ruční backfill: admin form (opt-in sensitive pole). |

### Stav (status)

| Sloupec | Typ | Popis |
|---|---|---|
| `provision_error` | text | Chybová zpráva posledního neúspěšného provisioningu (confirm `failed`); confirm `ok` ji nuluje |
| `claimed_at` | datetime | Čas, kdy si agent požadavek převzal z fronty (request → creating) |
| `created` | datetime, NOT NULL | Čas vytvoření záznamu |
| `modified` | datetime, NOT NULL | Čas poslední změny |

## Indexy

| Index | Typ | Sloupce |
|---|---|---|
| `unq_ds_id` | unique | `ds_id` |
| `unq_web_id` | unique | `web_id` (MariaDB unikátní index povoluje víc NULL — nullable unique funguje) |
| `idx_lifecycle` | index | `lifecycle` |

## Návaznosti

| Tabulka | Vazba | Popis |
|---|---|---|
| [hosting_core_servers](hosting_core_servers.md) | `data_sources.server → servers.id` | Umístění na serveru |
| [hosting_core_ds_users](hosting_core_ds_users.md) | `ds_users.data_source → data_sources.id` | Vazby uživatelů na DS |
