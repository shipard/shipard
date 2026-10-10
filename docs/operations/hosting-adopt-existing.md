# Adopce existujícího serveru a jeho DS do hostingu

Postup pro **zpětné napojení** už běžícího serveru s živými zdroji dat
na (nový) hosting. Doplněk k runbookům, které pokrývají čistou
instalaci (`production.md`) a nový DS z portálu (`cli.md` §Workflow,
`mail-router.md` §4): tady je opačný směr — hosting vzniká vedle
existujícího provozu a DS se do něj adoptují postupně.

Pořadí fází je podle rizika; každá fáze je samostatně ověřitelná
a vratná. Mezi fázemi lze kdykoli zastavit — částečně adoptovaný stav
je plně funkční.

> **Související:** `docs/hosting.md` (design D1–D12),
> `docs/operations/mail-router.md`, `docs/operations/ai-gateway.md`,
> `docs/cli.md`.

## Fáze 0 — deploy kódu

Hosting kód (jádro `adminOnly`, modul `hosting.core`, agent, gateway)
musí být na serveru nasazen: git pull větve, `composer install`,
build frontendu (dle `production.md` §3–4), a **`shpd-server
cron-install`** (šablona cronu se s hostingem změnila — doctor
neaktuálnost hlásí).

Kontrola: `shpd-server doctor` bez nálezů; existující DS běží beze
změny (hosting je plně opt-in).

## Fáze 1 — hosting DS

```bash
# 1) nový DS s install modulem hostingu
shpd-server ds-create --name "Hosting" --module install.hosting
# → vypíše <HOSTING_DS_ID>
cd /var/lib/…/data-sources/<HOSTING_DS_ID>   # dle layoutu serveru
shpd-ds ds-upgrade

# 2) doména portálu — POZOR: doména = OIDC issuer NAVŽDY (D12),
#    pozdější změna zneplatní propojené identity na všech DS
shpd-server domain-add --host <PORTAL_HOST> --ds <HOSTING_DS_ID>

# 3) admin hostingu
shpd-ds user-create --login <email> --email <email> --name "…" --admin
# (heslo dle politiky — invite/reset, viz auth)

# 4) OIDC OP klíč
shpd-ds hosting-oidc-init
```

V Nastavení hostingu (přihlášený admin) vyplnit:
- `hosting.oidc.issuer` = `https://<PORTAL_HOST>/api/v1/_hosting/oidc`
- `hosting.baseDomain` = `<BASE_DOMAIN>` (pro budoucí DS z portálu)

Kontrola: discovery odpovídá —
`curl https://<PORTAL_HOST>/api/v1/_hosting/oidc/.well-known/openid-configuration`
(issuer doslovně, 4 endpointy); JWKS vrací klíč.

## Fáze 2 — server → hosting

Na hosting DS: v Nastavení → Hosting založit řádek serveru
(`can_provision` dle záměru; pro čistou adopci může být zprvu false)
a vydat klíč:

```bash
shpd-ds hosting-server-key --server <ndx řádku> --generate   # tiskne JEDNOU
```

Na serveru do `/etc/shipard/server.json`:

```jsonc
"hosting": {
    "url": "https://<PORTAL_HOST>",
    "serverId": <ndx>,
    "apiKey": "shpd_hk_…"
}
```

První běh ručně: `shpd-server hosting-sync` — rekonciliace nahlásí
inventuru; **warningy „unknown ds_id" na hostingu jsou v této fázi
očekávané** (evidence ještě neexistuje). Cron pak běží sám
(2min slot).

Rollback fáze: smazat sekci `hosting` ze server.json.

## Fáze 3 — evidence DS + portálové účty

Na hostingu (admin, Nastavení → Hosting → Zdroje dat) založit řádek
pro **každý existující DS**: `ds_id` (skutečné ID), název,
`url_app` (`https://<host DS>`), `lifecycle = active`, `web_id`.

> **`web_id` nevymýšlet** — pokud DS přijímá poštu, musí se shodovat
> s aliasem ve stávajícím `lookup.json` na mail-router stroji (jinak
> se po fázi 5 změní adresy). Zkontrolovat tam.

Dále: portálové účty (`user-create` / pozvánka na hosting DS) a vazby
uživatel↔DS (Nastavení → Hosting → Uživatelé DS, role `admin`/`member`).

Kontrola: po dalším `hosting-sync` zmizí reconcile warningy;
`stats_wanted` cyklus začne plnit badge na portálových kartách
(read-only COUNTy na DS — první viditelný výsledek adopce).

## Fáze 4 — centrální login (per DS, vratné)

Pro každý DS jednotlivě:

```bash
# na hosting DS — registrace klienta + secret (tiskne JEDNOU):
shpd-ds hosting-oidc-client --ds <DS_ID> --generate \
  --redirect-uri "https://<host DS>/api/v1/_auth/oidc/callback"
```

Do `config/main.json` daného DS přidat (read-modify-write, 0600):

```jsonc
"auth": { "providers": [ {
    "id": "shipard-id", "label": "Shipard ID",
    "issuer": "https://<PORTAL_HOST>/api/v1/_hosting/oidc",
    "clientId": "<DS_ID>", "clientSecret": "<secret>",
    "autoLinkEmail": true
} ] }
```

`autoLinkEmail: true` napáruje **existující** uživatele DS podle
e-mailu (OP posílá `email_verified: true`); e-maily na hostingu
a na DS se musí shodovat. Ověřit na jednom DS (login tlačítkem,
SSO druhý průchod, `oidc_no_account` pro nespárovaný účet), pak
zbytek.

Rollback per DS: odebrat položku z `auth.providers` (lokální login
není adopcí dotčen).

## Fáze 5 — mail-router backfill (nejcitlivější)

Předpoklad: router připojen dle `mail-router.md` §1–3 (řádek routeru,
klíč, `lookup_sync` config + timer).

> **První přepnutí (cutover) dělej dávkově, ne po jednom DS:** dokud
> lookup spravuje ruční soubor, musí první běh `lookup-sync` už
> obsahovat tokeny **všech** DS, které mají dál přijímat poštu — co
> v evidenci nemá `mail_token`, z lookupu zmizí a policy server poštu
> **odmítne** (bounce, ne odklad). Správně: (1) router + klíč +
> `lookup_sync` config **bez** timeru, (2) rotace + backfill tokenů
> všech DS v jednom zátahu (okno mezi rotací a syncem = jen odložené
> doručení — alias ve starém souboru zůstává, worker retry-uje 401),
> (3) kontrolní diff endpointu proti stávajícímu `lookup.json`
> (hosts, všechny klíče, api_url), (4) první sync, (5) timer.
> Postup „po jednom DS" platí až pro následné změny na routeru,
> který už hosting spravuje.
>
> **Preferovaná varianta bez rotace:** starý `lookup.json` obsahuje
> platné tokeny v plaintextu — stačí je **opsat do evidence**
> (`mail_token`) místo rotace. Cutover je pak beze změny obsahu
> (jen přibudou web_id aliasy) a bez jakéhokoli okna. Rotace
> `mail-router-setup --force` je potřeba jen tam, kde plaintext
> tokenu není k dispozici; provést ji lze i dodatečně per DS.
> DS mimo spravované servery (čistě „poštovní" záznamy) se evidují
> s prázdným `server` — rekonciliace je ignoruje, lookup je servíruje.

Následné změny pak **po jednom DS** dle
`mail-router.md` §5 (ruční backfill): rotace tokenu
(`mail-router-setup --force --json`) → vložit do evidence na hostingu
→ **ihned** ruční běh `shipard-mail-router-lookup-sync` → ověřit
doručení testovací zprávy → další DS.

> Mezi rotací a syncem je starý token neplatný — doručení v tom okně
> skončí dočasnou chybou a MTA ho zopakuje (retry fronta). Proto po
> jednom DS a sync hned; neplánovat doprostřed špičky pošty.

Od této chvíle `lookup.json` spravuje hosting; ruční editace na
routeru už jen přes evidenci.

## Fáze 6 — AI gateway (volitelně, postupně)

Dle `ai-gateway.md`: `hosting-ai-gw-init --set-key` (klíč organizace),
pak per DS token + přepnutí backendu (`ai-backend-set-key
--backend default --api-key <token> --base-url
https://<PORTAL_HOST>/api/v1/_hosting/ai-gw`). Začít jedním DS,
zkontrolovat usage řádky vs. reálný provoz, pak dle záměru.
Doporučení: **nechat část DS trvale na přímých klíčích** jako
kontrolní skupinu cesty D6 (vlastní klíč musí zůstat rovnocenný).

Rollback per DS: `ai-backend-set-key` zpět s přímým klíčem bez
`--base-url`.

## Fáze 7 — ověření plného cyklu

Založit z portálu jeden **testovací DS** (`can_provision = true` na
řádku serveru): projde se celý agent — ds-create, doména,
`auth.providers`, owner s předpropojenou identitou, mail token,
AI backend, confirm, vazba na portálu. Tím je adopce kompletní
a server se chová stejně pro staré i nové DS. Testovací DS pak
smazat/ponechat dle záměru.

> **domains.json na agent-managed serveru:** agent běží z cronu jako
> shipard user a krok `domain-add` zapisuje mapu domén atomicky
> (tmp + rename) — potřebuje tedy **zápis na adresář** souboru.
> Výchozí `/etc/shipard` je root-managed (root:shipard 750), takže
> před fází 7 přesměruj `domainsFile` v `server.json` na app-writable
> cestu (např. `/opt/shipard/domains.json`) a stávající soubor tam
> přesuň (`chown shipard:`). `shpd-server doctor` nezapisovatelný
> adresář na hosting-managed serveru hlásí.

## Souhrn kontrol po adopci

- [ ] Discovery/JWKS na `<PORTAL_HOST>`; login přes Shipard ID na
      všech adoptovaných DS vč. SSO
- [ ] Reconcile bez warningů; badge na portálu živé
- [ ] Pošta doručuje přes hosting-spravovaný lookup (a `lookup.json`
      už nikdo needituje ručně)
- [ ] AI usage na hostingu odpovídá provozu gateway DS; kontrolní DS
      s vlastním klíčem beze změny
- [ ] Nový DS z portálu projde end-to-end (fáze 7)
