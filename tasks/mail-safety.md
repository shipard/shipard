# Odchozí pošta — pojistka přesměrování na dev a testovacích serverech

**Stav:** částečně — implementace, testy a docs hotové 2026-10-05 (3 commity); `drop` ověřen na dev serveru (CLI i proklik UI); zbývá ověřit `redirect` proti skutečnému SMTP a nastavit `mail.safety` na testovacím serveru (člověk), `ds-upgrade` zdrojů dat při nasazení

> PRD pro Claude Code (3 commity). Design: issue #95, komentář
> „Rozhodnutí“. Souvisí: #90 fáze 4 (`tasks/prints-phase4.md` —
> Odeslaná pošta, `print-send`).

## Kontext

Dev servery a testovací server posílají poštu ven přes relay. Na zdrojích
dat, které jsou kopií ostrých dat, jsou skutečné e-maily partnerů —
odeslání faktury, upomínky nebo notifikace při testu tak může dojít
skutečnému odběrateli. Relay navíc může mít override v `main.json` zdroje
dat, takže kopie ostrého zdroje si může přinést produkční SMTP.

Veškerá odchozí pošta odchází jediným místem —
`MailOutboxService::attemptSend()` přes `TransportResolver` (auth e-maily,
notifikace, odeslání záznamu, `mail-send-test`). Pojistka tam má jedno
místo a platí pro relay i pro odesílatele s vlastním SMTP.

## Před implementací přečti

- Issue #95 — tělo a komentář „Rozhodnutí“ (D1–D9)
- `docs/mail/outbound.md` celé
- `src/Core/Mail/MailOutboxService.php` (`attemptSend`, `notifySource`),
  `MailComposer.php`, `TransportResolver.php`, `MailServiceFactory.php`,
  `MailRelayConfig.php`
- `src/Core/Config/ServerConfig.php` (`getMode`, `getMailRelay`)
- `modules/core/mail/tables/core_mail_outbox.jsonc`, `core_mail_outbox_log.jsonc`,
  `core_mail_sent_messages.jsonc`
- `modules/core/mail/src/Sent/SentMessageOutboxListener.php`,
  `SentMessageStore.php`
- `src/Command/Server/DoctorCommand.php`, `src/Core/Server/HealthChecker.php`
- `src/Api/Controller/AppController.php` (`/_app/info`)
- `src/Command/DataSource/PrintSendCommand.php`
- `docs/operations/production.md` (konfigurace `server.json`)

## Scope

**Uvnitř:** konfigurace `mail.safety`, uplatnění v `attemptSend`, stopa
v e-mailu, ve frontě, v logu a v Odeslané poště, `doctor`, `/_app/info`,
upozornění v UI, uvolnění `print-send --to`, testy, dokumentace.

**Mimo:** příchozí pošta; nastavení per zdroj dat; úprava těla e-mailu
(D5 — jen předmět a hlavičky); nastavení testovacího serveru (dělá
člověk při nasazení).

## 1. Konfigurace (D1, D2)

`server.json`, vedle `mail.relay`:

```jsonc
"mail": {
    "relay": { … },
    "safety": {
        "mode": "redirect",                 // redirect | allowlist | drop | off
        "redirectTo": "testy@example.com",  // povinné pro redirect; volitelné pro allowlist
        "allow": ["@example.com", "jan@example.org"]  // jen allowlist
    }
}
```

- `ServerConfig::getMailSafety(): MailSafetyConfig` (nová immutable třída
  v `src/Core/Mail/`): `mode`, `redirectTo`, `allow`, `source`
  (`configured` / `default` / `invalid`), `problem` (text pro `doctor`).
- **Výchozí stav (D2):**
  - sekce chybí, server `production` → `off`,
  - sekce chybí, server `development` → `drop`,
  - neznámý `mode`, `redirect` bez `redirectTo`, `allowlist` bez `allow`,
    neplatná adresa / doména v `allow` nebo `redirectTo` → `drop`
    + `source: invalid` + `problem`,
  - `server.json` nejde načíst → `drop` + `source: invalid`
    (fail-closed; `MailServiceFactory` dnes chybu načtení relay polyká —
    pro pojistku to neplatí).
- Validace adres: syntakticky; položka `allow` je buď celá adresa,
  nebo `@doména`; porovnání bez ohledu na velikost písmen.
- `MailServiceFactory` předá `MailSafetyConfig` do `MailOutboxService`
  (konstruktor; testy dostanou explicitní instanci).

## 2. Uplatnění (D3, D4, D5)

`MailSafetyGuard::apply(Email $email, MailSafetyConfig $cfg): MailSafetyResult`
— čistá funkce nad sestaveným `Symfony\Component\Mime\Email`.

- V `attemptSend()` **po** `compose()` a **před** `resolver->resolve()`.
  Řádek fronty se nemění v adresách (D3).
- Režimy (D4):
  - `off` → e-mail beze změny,
  - `redirect` → Komu = `redirectTo`, Kopie a skrytá kopie prázdné,
  - `allowlist` → povolené adresy zůstanou na svých místech; ostatní
    se nahradí jedním `redirectTo` v Komu (je-li nastaven), jinak
    zahodí; nezbude-li žádná adresa → jako `drop`,
  - `drop` → nic se neodešle.
- Stopa v e-mailu (D5), když se aspoň jedna adresa změnila:
  - předmět s prefixem `[TEST] ` (jen jednou — neopakovat při retry),
  - hlavičky `X-Shipard-Original-To` a `X-Shipard-Original-Cc`
    (původní seznamy; `Cc` jen když byla kopie),
  - tělo beze změny.
- `drop` (i vzniklý z `allowlist`): transport se **nevolá**; pokus
  skončí jako úspěch (D6) — řádek fronty `sent`, log `ok`, posluchači
  dostanou `STATE_SENT`.
- `MailSafetyResult`: `action` (`none` / `redirected` / `dropped`),
  `target` (adresa přesměrování nebo null), upravený e-mail.

## 3. Stopa v aplikaci (D6)

- `core_mail_outbox`: nové sloupce `safety_action` (nullable enum
  `redirected` / `dropped`) a `safety_target` (nullable varchar).
  Plní `attemptSend` při úspěchu. Původní `email_to` / `email_cc` se
  nemění.
- `core_mail_outbox_log`: transport `safety:<režim>` u `dropped`;
  u `redirected` skutečný transport s poznámkou v detailu logu
  („přesměrováno na …“).
- `core_mail_sent_messages`: nové sloupce `safety_action`,
  `safety_target` — propíše `SentMessageOutboxListener` (rozšířit
  rozhraní `OutboxSourceListener` o výsledek pojistky, nebo ho číst
  z řádku fronty — vyber čistší variantu). Při Odeslat znovu se přepíší
  výsledkem nového pokusu.
- UI Odeslaná pošta (agenda, formulář, sekce v detailu záznamu):
  štítek „Přesměrováno na <adresa>“ / „Zachyceno — neodesláno“
  u zprávy se `safety_action`.
- Fronta pošty v administraci (je-li viewer): totéž.

## 4. Viditelnost a CLI (D7, D8)

- `shpd-server doctor`: nová kontrola „Pojistka odchozí pošty“ — režim,
  zdroj (`configured` / `default` / `invalid`), u `invalid` problém jako
  chyba; u `redirect` cílová adresa. Server `development` s `off` =
  varování.
- `/_app/info` (nebo jiný už načítaný endpoint s metadaty aplikace —
  `/_app/info` je veřejný, proto **jen** `{mailSafety: {mode}}`, žádné
  adresy).
- UI upozornění (pruh / štítek) při `mode ≠ off`:
  - dialog Odeslat (odeslání záznamu),
  - agenda Odeslaná pošta,
  - Nastavení → Pošta.
  Text: „Pošta je na tomto serveru přesměrovaná / zachycená — nic
  neodejde skutečným příjemcům.“ (podle režimu). i18n `cs` / `en`.
- `print-send` (D8): `--to` povinné jen když pojistka je `off` **a**
  server není `production`. Jinak volitelné. Nápověda příkazu
  a `docs/cli.md` upravit.
- `mail-send-test`: výstup ukáže výsledek pojistky
  (přesměrováno / zachyceno).

## Testy

- **Unit:** `MailSafetyConfig` z `server.json` — všechny větve D2
  (chybějící sekce × režim serveru, neplatné hodnoty, nečitelný soubor);
  `MailSafetyGuard` — `off`, `redirect` (Komu, Kopie, skrytá kopie),
  `allowlist` (přesná adresa, doména, velikost písmen, smíšení
  povolených a nepovolených, bez `redirectTo`, nezbude nikdo → drop),
  `drop`; prefix předmětu jen jednou; hlavičky původních příjemců;
  tělo i přílohy beze změny.
- `MailOutboxService` s fake transportem: `drop` transport nevolá a řádek
  je `sent` se `safety_action = dropped`; `redirect` volá transport
  s upraveným e-mailem a řádek si nechá původní `email_to`; posluchač
  Odeslané pošty dostane výsledek pojistky.
- `PrintSendCommand`: pravidlo `--to` (D8) pro kombinace režimu pojistky
  a serveru.
- Controller `/_app/info`: jen `mode`, žádné adresy.
- `doctor`: výstup pro `configured`, `default`, `invalid`.

## Task breakdown

### Commit 1 — Konfigurace a pojistka

§1, §2, sloupce fronty a logu z §3, testy.

**Hotovo když:** na dev serveru bez `mail.safety` `mail-send-test` nic
neodešle a řádek fronty má `safety_action = dropped`; s `redirect`
dorazí zpráva s `[TEST]` na adresu přesměrování.

### Commit 2 — Odeslaná pošta, doctor, UI, CLI

Zbytek §3, §4, testy.

**Hotovo když:** `doctor` ukazuje režim; v dialogu Odeslat a v agendě
Odeslaná pošta je upozornění; zachycená zpráva má štítek;
`print-send` bez `--to` projde při aktivní pojistce.

### Commit 3 — Dokumentace

- `docs/mail/outbound.md`: kapitola Pojistka (konfigurace, výchozí
  stavy, režimy, kde se uplatní, stopa).
- `docs/operations/production.md`: `mail.safety` v `server.json`,
  doporučení pro dev a testovací server (`redirect` na týmovou adresu).
- `docs/cli.md` (`print-send`, `mail-send-test`).
- `tasks/prints-phase4.md` §11 Bezpečnost testování — odkaz, že
  technickou pojistku má #95; podmínka „nic neodesílat z reálné kopie“
  platí jen když je pojistka `off`.
- Hlavička tohoto tasku + `python3 scripts/tasks-index.py`.

## Rozhodnutí k designu

Zamčeno v #95: D1–D9. Upřesnění z PRD:

- Sekce je `mail.safety` (vedle `mail.relay`), ne samostatný klíč
  nejvyšší úrovně.
- Nečitelný `server.json` = `drop` (fail-closed), na rozdíl od relay,
  kde se chyba načtení polyká.
- `drop` se tváří jako úspěšné odeslání (řádek `sent`, posluchači
  `STATE_SENT`) se štítkem — aplikace se chová jako v produkci.
- `/_app/info` je veřejný → vystavuje jen režim.

Nasazení: na testovacím serveru nastavit `mail.safety` na `redirect`
na týmovou adresu (adresa jen v `server.json`).

## Implementace

Hotovo ve třech commitech podle task breakdownu. Odchylky a upřesnění proti
zadání:

- **`attemptSend()` otočil pořadí** — dřív `resolve()` před `compose()`,
  teď compose → pojistka → resolve. Zachycená zpráva resolver nevolá, takže
  projde i na zdroji dat bez relay.
- **Stopa se zapisuje zvlášť, až po stavu `sent`** (fronta i Odeslaná
  pošta). Zdroj dat před `ds-upgrade` nové sloupce nemá; chyba zápisu stopy
  v hlavní větvi by z odeslané zprávy udělala selhanou a další pokus by ji
  poslal znovu. Log pokusů nové sloupce nedostal — stačí `transport`
  a `smtp_response`.
- **Posluchač dostává výsledek parametrem** (`?MailSafetyResult` na konci
  `outboxStateChanged()`), nečte ho z řádku fronty — nezávisí na sloupcích
  fronty ani na pořadí zápisů.
- **`MailSafetyConfig` skládá `fromServerData()`** nad dekódovaným
  `server.json` (sdílí ho `ServerConfig::getMailSafety()` i `doctor`);
  `forServer()` řeší nečitelný soubor. Bez sekce je `drop` na každém
  serveru, který není `production` — i při neznámém režimu.
- **Žádná změna adres = žádná stopa:** `redirect` na adresu, která je
  jediným příjemcem, a `allowlist` se samými povolenými adresami vrací
  akci `none`.
- **`allowlist` bez `redirectTo`, část příjemců vypadne:** akce
  `redirected` s prázdným `safety_target`, štítek „Příjemci omezeni
  pojistkou“ (D6 zná jen dvě hodnoty; schváleno v chatu).
- **Text upozornění pro `allowlist`:** „Pošta je na tomto serveru omezená
  na povolené adresy — ostatním příjemcům nic neodejde.“
- **Štítek ukazuje jen zpráva ve stavu Odesláno** — zpráva znovu ve frontě
  nebo selhaná stopu dřívějšího odeslání neukazuje; odeslání bez zásahu ji
  smaže.
- **`safety_action` je `enumString`** s cfgItemem `core.mail.safetyActions`
  (popisky štítků vč. `nameTarget` / `nameRestricted`).
- **Upozornění v agendě a Nastavení kreslí `ContentArea`** podle seznamu
  položek odchozí pošty (`frontend/src/utils/mailSafety.js`) — viewery na
  serveru konfiguraci serveru nevidí. Fronta v administraci je generická
  tabulka, nové sloupce ukazuje sama.
- **Výsledek dialogu Odeslat** ukazuje štítek pojistky místo „Odesláno“
  (`safety` v odpovědi `send`) — nad rámec zadání.
- **`print-send` hlásí režim pojistky serveru**, ne výsledek: z příkazové
  řádky se zpráva jen řadí do fronty, zásah pojistky ukáže až Odeslaná
  pošta.
- **Nápověda:** odstavec o štítcích v `help/posta/odeslana-posta.md`.

Ověřeno na dev serveru (`mode: development`, bez `mail.safety` → `drop`):
`mail-send-test`, `print-send` bez `--to`, `mail-outbox-run`, `doctor`,
`/_app/info` a proklik agendy, formuláře zprávy, sekce u záznamu, dialogu
Odeslat a Nastavení → Pošta v headless prohlížeči. `redirect` pokrývají
unit testy s fake transportem.
