# Zřízení AI gateway na hostingu (D5)

Runbook pro zprovoznění AI gateway: hosting DS proxuje
`POST /_hosting/ai-gw/v1/messages` na `api.anthropic.com` pod klíčem
organizace, klientské DS se autentizují gateway tokeny `shpd_gw_…`
a spotřeba se loguje per DS do `hosting_core_ai_usage`.
Design a kontrakt: [`docs/hosting.md`](../hosting.md) §5.5.

## Předpoklady

- Hosting DS s aktivním modulem `hosting.core`, po `ds-upgrade`
  (tabulky `hosting_core_ai_tokens` + `hosting_core_ai_usage`).
- Anthropic API klíč organizace.
- Nginx/PHP limity requestů: gateway přijímá těla do 32 MiB — front-line
  ochrana je `client_max_body_size` (nginx) a `post_max_size` (PHP);
  na hosting DS je nastavit aspoň na 32 MB, jinak velké požadavky
  zarazí proxy vrstva dřív, než odpoví gateway.

## 1. Klíč organizace

Z adresáře hosting DS:

```bash
sudo shpd-ds hosting-ai-gw-init --set-key     # klíč z promptu (skrytý vstup)
sudo shpd-ds hosting-ai-gw-init --status      # kontrola: present + 0600
```

Klíč žije v `secrets/ai-gw-anthropic.key` (0600) — nikdy v DB. Rotace =
opakovaný `--set-key` (gateway čte soubor per-request, projeví se hned).
Dokud soubor neexistuje, endpoint gateway vrací 404 a queue payload
sekci `ai` nenese.

## 2. Gateway tokeny

**Nové DS z portálu**: nic — token mintuje queue payload při provisioningu
a agent (krok g.) na DS s aktivním `core.ai` rovnou zapíše backend řádek.

**Backfill existujícího DS**:

```bash
# na hosting DS — ndx z vieweru Zdroje dat
sudo shpd-ds hosting-ai-token --ds <ndx> --generate --note "backfill"
# vytiskne token JEDNOU

# na klientském DS
sudo shpd-ds ai-backend-set-key --backend default \
    --api-key shpd_gw_… \
    --base-url https://portal.example.com/api/v1/_hosting/ai-gw
```

`base_url` = issuer (`hosting.oidc.issuer`) s `/_hosting/oidc` nahrazeným
za `/_hosting/ai-gw`; klienti si `/v1/messages` připojují sami.

## 3. Ověření

- Chat na klientském DS (SSE streaming end-to-end) a analýza pošty
  (non-streaming) fungují.
- Hosting: Nastavení → Hosting → AI gateway — spotřeba: řádky s modelem,
  tokeny (vč. cache), `http_status`, footer sumy.

## Revokace a odpojení

```bash
sudo shpd-ds hosting-ai-token --revoke <ndx tokenu>   # active = 0 → 401
```

Přechod DS na vlastní klíč (D6):

```bash
sudo shpd-ds ai-backend-set-key --backend default --base-url ''   # klíč zadáš skrytě na výzvu
```

## Troubleshooting

| Symptom | Příčina | Řešení |
|---|---|---|
| 404 na `/_hosting/ai-gw/v1/messages` | chybí org klíč nebo hosting tabulky | `hosting-ai-gw-init --status`; `ds-upgrade` na hosting DS |
| 401 `authentication_error` | token chybný/revokovaný, nebo DS není `lifecycle=active` | viewer AI gateway — tokeny; příp. `--generate` nový |
| 500 `AI gateway is misconfigured` | práva klíče ≠ 0600 | `chmod 0600 secrets/ai-gw-anthropic.key` |
| 429 `RATE_LIMITED` (Shipard formát) | bucket `ai_gw` 300/min per token | zvážit limit, příp. rozdělit provoz |
| řádky v usage s `http_status` 401/403 | org klíč neplatný u Anthropicu | rotace `hosting-ai-gw-init --set-key` |
| usage řádky chybí | metering selhal (warn v error logu) | error log hosting DS `ai-gw:` záznamy |
