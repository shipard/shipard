# Odchozí pošta — fronta, transporty, senders

Obecná služba odchozí pošty modulu `core.mail`: fronta v DB s logem
doručení, per-sender SMTP transporty a relay fallback. Používá ji auth
flow (pozvánky, reset hesla — Fáze 0b) a odesílání dokladů (Odeslaná
pošta, [sent.md](sent.md)), později alerty. Spec: `tasks/mail-outbound.md`
(rozhodnutí D22–D27).

## Architektura

```
volající (auth, alerty, …)
    │  OutboundMessage
    ▼
MailOutboxService ──── enqueue / enqueueAndSend ───▶ core_mail_outbox
    │ attemptSend / processQueue                        │
    ▼                                                   ▼
TransportResolver ──▶ MailComposer ──▶ SMTP      core_mail_outbox_log
    │                                                (řádek per pokus)
    ├─ hit v core_mail_senders → custom SMTP (heslo přes DsSecretCipher)
    └─ miss → relay z konfigurace (DS main.json ?? server.json)
```

Třídy žijí v `src/Core/Mail/` (`Shipard\Core\Mail`), wiring dělá
`MailServiceFactory::create($dsConfig, $db)` — jediné místo, kde se mergí
relay konfigurace (DS override ?? server default).

- **Kdo posílá** rozhoduje volající: explicitní `from` v `OutboundMessage`,
  jinak DS default ze settings klíče **`mail.defaultFrom`** (Nastavení →
  Pošta → Odchozí pošta). Bez obojího enqueue vyhodí validační chybu.
- **Kudy** rozhoduje `TransportResolver` lookupem from adresy
  v `core_mail_senders` (case-insensitive, jen `is_active`). Hit → SMTP
  senderu, label v logu `sender:{id}`. Miss → relay, label `host:port`.
  Žádný relay → pokus selže s jasnou hláškou (zpráva zůstává ve frontě).

### Příjemci, kopie, jméno odesílatele

`OutboundMessage` nese vedle adresy odesílatele i jeho jméno a víc příjemců:

| Pole | Sloupec outboxu | Význam |
|------|-----------------|--------|
| `to` (`string` \| seznam) | `email_to` | „Komu“ — jedna adresa nebo seznam |
| `cc` (seznam) | `email_cc` | kopie; prázdné = NULL |
| `fromName` | `email_from_name` | jméno v hlavičce From; o transportu dál rozhoduje jen adresa |

Seznamy se ukládají jako text s adresami oddělenými čárkou — skládá a rozebírá
ho výhradně `Shipard\Core\Mail\AddressList` (duplicitní adresy bez ohledu
na velikost písmen jen jednou). `enqueue` validuje každou adresu zvlášť
a zprávu s neplatnou adresou odmítne celou (`MailValidationException`);
ze jména odesílatele odstraní konce řádků. Volající s jednou adresou
v `to` fungují beze změny.

### Odesílatel záznamu (`SenderResolver`)

Odeslání záznamu (doklad, #90 D39) nevolí adresu volným textem — smí jen
z **povolených adres**: `mail.defaultFrom` a `email_from` aktivních
záznamů `core_mail_senders` (`AllowedSenders`). Z jiné adresy by pošta
neprošla SPF / DKIM domény.

`SenderResolver::resolve($table, $record, $chosen)`:

1. adresa zvolená při odeslání (dialog, CLI) — mimo povolené adresy
   `SENDER_NOT_ALLOWED`;
2. odesílatel podle záznamu — `RecordSenderProvider` registrovaný pro
   tabulku (`recordSenderProviders` v `module.jsonc`, jeden na tabulku).
   Doklady: `NumberSeriesSenderProvider` čte volbu „Odesílat z“ na číselné
   řadě (`docs_core_number_series.email_from`, NULL = automaticky). Uložená
   adresa, která už není povolená, je `SENDER_NOT_ALLOWED` — žádný tichý
   přechod na výchozí;
3. `mail.defaultFrom`;
4. jinak `NO_SENDER`.

Jméno odesílatele: podle záznamu (číselná řada `email_from_name`), jinak
název vlastní firmy (osoba s `is_own`), jinak žádné. Neúspěch není výjimka
— `SenderResolution` nese `errorCode` a text z cfgItemu
`core.mail.sendLabels`, návrh odeslání ho ukáže uživateli.

`GET /_mail/sender-addresses` → `{addresses: [{email, source: default|sender}],
default}` — nabídka povolených adres; práva jako čtení `core_mail_senders`.

## Konfigurace

### Relay (server default + DS override)

Klíč `mail.relay` — stejná struktura v `/etc/shipard/server.json`
(default pro všechny DS) i v DS `config/main.json` (override, vyhrává
celý objekt, ne per-pole):

```json
{
    "mail": {
        "relay": {
            "host": "relay.example.com",
            "port": 587,
            "security": "starttls",
            "username": "shipard",
            "password": "…"
        }
    }
}
```

- `security`: `starttls` (587, default) | `tls` (implicitní TLS, 465) |
  `none` (bez šifrování — lokální Postfix `localhost:25`).
- `port` default 587, `username`/`password` volitelné.
- Heslo je plaintext — oba soubory mají 0600, spravuje je provozovatel
  a leží vedle DB hesel se stejným threat modelem.
- Lokální Postfix není vyžadován (frontu drží aplikace), ale zůstává
  podporovaný jako `{"host": "localhost", "port": 25, "security": "none"}`.

### Senders (per-from SMTP)

Tabulka `core_mail_senders` — Nastavení → Pošta → Odesílatelé pošty.
Zpráva s from adresou aktivního senderu odchází jeho SMTP serverem —
typicky účet zákazníka v Google Workspace: zpráva projde SPF/DKIM
domény a uvidí ji uživatel v Odeslané poště.

**Google Workspace app password:**

1. Účet musí mít zapnuté 2-Step Verification.
2. <https://myaccount.google.com/apppasswords> → vytvořit App password.
3. Sender: `email_from` = adresa účtu, `smtp_host` = `smtp.gmail.com`,
   port 587, security `starttls`, `smtp_username` = adresa účtu.
4. Heslo nastavit akcí **Nastavit heslo** v detailu senderu (nebo
   `POST /_mail/senders/{id}/password`, admin session).

`password_enc` je `encrypted_text` + `sensitive`: generické API ho nikdy
nevrací ani nepřijme (400 `SENSITIVE_COLUMN`), v DB je šifrované
per-DS klíčem (`DsSecretCipher`, viz `docs/operations/secrets.md`).
Jediná cesta zápisu je dedikovaný endpoint.

## Stavový automat outboxu

```
enqueue → pending ──claim──▶ sending ──ok──▶ sent
             ▲                  │
             │           fail, pokus 1–5 (backoff 60 s, 5 min, 30 min, 2 h, 6 h)
             └──────────────────┘
                                │ fail, 6. pokus
                                ▼
   mail-outbox-retry ◀───────  failed
```

- Claim je atomický (`UPDATE … WHERE state='pending'`) — souběžné běhy
  workeru jsou bezpečné.
- `enqueueAndSend()` = priority high + synchronní první pokus v requestu
  (reset hesla nečeká na cron); selhání nepropaguje, zprávu převezme cron.
- Recovery: `sending` starší 10 minut (pád workeru) vrací `processQueue()`
  na `pending`, bez inkrementu počítadla.
- Každý pokus zapíše řádek do `core_mail_outbox_log` (transport, výsledek,
  SMTP odpověď, trvání).

## Cron

`mail-outbox-run` běží každou minutu na všech DS přes systémový dispatcher
— slot `minute` generovaného `/etc/cron.d/shipard` (`shpd-server cron
--slot=minute`; soubor spravuje `shpd-server upgrade`, živost hlídá
`shpd-server doctor`). Žádná ruční cron konfigurace není potřeba —
detaily viz [`../cli.md`](../cli.md) § `cron`. Slot `daily` navíc denně
pouští `mail-idempotency-prune`.

Exit kód workeru je SUCCESS i při selhaných zprávách (selhání reportuje
alert check a doctor, cron nesmí spamovat MAILTO); FAILURE jen při infra
chybě.

## Viditelnost selhání

- **Alert check `core.mail.outbox_health`** (interval 15 min):
  - `failed_24h` — terminálně selhané zprávy s pokusem za posledních 24 h,
  - `stuck_pending` — zprávy due přes 30 minut (worker neběží nebo leží
    transport; měří se od `next_attempt`, backoff není false positive).
- **`shpd-server doctor`** — sekce Outbound mail per DS: relay/senders
  nakonfigurovány, failed za 24 h, hloubka fronty, overdue pending
  (> 30 min = error).

## Runbook

**Zaseklá fronta (alert `stuck_pending`):**

1. Běží cron? `crontab -l`, log workeru.
2. `shpd-ds mail-outbox-run` ručně z adresáře DS — výstup říká
   sent/retried/failed.
3. Transport: `shpd-ds mail-send-test --to <tvoje adresa>` — synchronní
   pokus, vypíše transport, trvání a SMTP odpověď.
4. Leží relay → oprav `mail.relay` / síť; zprávy se pošlou samy
   (backoff), nic ručně přesouvat nemusíš.

**Selhané zprávy (alert `failed_24h`):**

1. `SELECT id, email_to, subject, last_error FROM core_mail_outbox WHERE state='failed'`.
2. Detail pokusů: `SELECT * FROM core_mail_outbox_log WHERE outbox_id=<id>`.
3. Oprav příčinu (heslo senderu, relay, adresa) a vrať zprávu do fronty:
   `shpd-ds mail-outbox-retry --id <id>` (vynuluje počítadlo pokusů).

**Smoke test při zřizování:**

```bash
cd /opt/shipard/data-sources/<id>
vendor/bin/shpd-ds mail-send-test --to admin@example.com                     # přes relay/default from
vendor/bin/shpd-ds mail-send-test --to admin@example.com --from ucet@firma.cz  # přes custom sender
```

Zpráva se zapíše do outboxu; při selhání ji dál zkouší cron.

**Retence:** outbox a log se nemažou automaticky (a při `ds-reset` se
mažou celé — `keepOnReset` chrání jen senders). Úklid starých `sent`
záznamů je na provozovateli.

## Posluchač výsledku transportu

Kdo do fronty zprávu zařadil a chce znát výsledek, zaregistruje se podle
prefixu `source_ref`: `MailOutboxService::addSourceListener($prefix,
OutboxSourceListener)`. Posluchač dostane `sent`, `failed` (vyčerpané
pokusy) a `requeued` (`mail-outbox-retry`); mezistavy ne. Chyba posluchače
stav fronty nemění. Používá to Odeslaná pošta — viz [sent.md](sent.md).

## Non-goals (zatím)

Bounce/VERP handling, rate limiting. Evidence odeslaných zpráv
a odesílání dokladů: [sent.md](sent.md) a [`../prints.md`](../prints.md) §9.
