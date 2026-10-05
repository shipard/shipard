# Odeslaná pošta — evidence zpráv, transport, Odeslat znovu

Evidence zpráv, které z aplikace odešly, nad libovolnou tabulkou — oddělená
od transportu. Rozhodnutí D40–D45 v issue #90, zadání
`tasks/prints-phase4.md`. Fronta a SMTP transporty: [outbound.md](outbound.md);
odeslání záznamu (kdo, komu, s čím): [`../prints.md`](../prints.md) §9.

## Model

Obsah a transport jsou dvě věci:

- **Zpráva** (`core_mail_sent_messages`) drží **obsah** — předmět, tělo,
  odesílatele, příjemce, přílohy — a vazbu na záznam, ke kterému patří.
- **Fronta** (`core_mail_outbox`) zprávu **doručuje** a nic si nepamatuje
  navždy: řádky a log pokusů uklízí provozovatel.

Záznam (faktura) ví, co odešlo, přes zprávy, které na něj ukazují
(`target_table_id` = název tabulky, `target_row` = id) — žádná kopie na
záznamu, žádná tabulka historie odeslání. Tabulka: `modules/core/mail/tables/
core_mail_sent_messages.md`. Třídy v `modules/core/mail/src/Sent/`.

Sdílení tabulky s došlou poštou ne — ta je specifická AI analýzou
a předzpracováním; drží se jen stejná konvence sloupců (D40).

## Pevný obsah (D41)

Zpráva vzniká **až odesláním** (`RecordSendService` → `SentMessageStore::
create()`), rovnou ve stavu Odeslaná — Koncept nemá. Po vytvoření se nemění
předmět, tělo, odesílatel, příjemci, vazba ani přílohy; fyzicky se nemaže
nikdy. Uživatel mění jen stav:

| Stav | | Přechody |
|---|---|---|
| 40 | Odeslaná | → 70, 90 |
| 70 | V archivu | → 40 |
| 90 | Smazaná | → 40 |

Vlastní sada `core.mail.docStatesSent`, všechny stavy `readOnly`.
Archivovaná a smazaná zpráva se neukazuje u záznamu, v agendě zůstává.

Zápisových cest je víc, proto pevný obsah hlídá víc vrstev:

| Cesta | Co ji zastaví |
|---|---|
| formulář, generické `PUT` / `PATCH` | read-only stav → 422 `DOCUMENT_READONLY` |
| generické `POST`, `DELETE` | `systemManaged` v definici tabulky → 405 `TABLE_SYSTEM_MANAGED` (`docs/table-definitions.md`) |
| ostatní volající gateway | `SentMessageDocument` — založení (`system_managed`), změna obsahu (`immutable`), smazání (výjimka) |
| přílohy | `SentMessageAttachmentGuard` — smazání, přejmenování, pořadí, příznak i nahrání další → 409 `ATTACHMENT_LOCKED` |

Služba odeslání přílohy zakládá přes `AttachmentService` **bez guardů**.

## Transport (D43)

`SentMessageTransport::dispatch($id, $sendNow)` sestaví ze zprávy
`OutboundMessage` — odesílatel se jménem, všichni v „Komu“, kopie, tělo,
**přílohy zprávy** (v pořadí vzniku: PDF tisku první), `sourceModule:
core.mail`, `sourceRef: sentMessage:<id>` — a zařadí ji do fronty. Zpráva
dostane `transport_state = queued` a `last_outbox_id`.

`enqueue()` + `attempt()` jsou zvlášť pro volajícího s vlastní transakcí:
řádek fronty vzniká uvnitř ní, okamžitý pokus o odeslání až po commitu.
Kanál jiný než `email` = `LogicException` (datová schránka je zatím jen
hodnota sloupce).

**Zpětné propsání výsledku.** Fronta zůstává čistě transportní.
`MailOutboxService::addSourceListener($prefix, OutboxSourceListener)` volá
posluchače pro řádky, jejichž `source_ref` začíná prefixem, při:

| Událost | Kdy | Zpráva |
|---|---|---|
| `sent` | pokus prošel | `transport_state = sent`, `sent_at`, `send_count + 1`; `safety_action` / `safety_target` podle pojistky |
| `failed` | vyčerpané pokusy | `transport_state = failed`, `last_error` |
| `requeued` | `mail-outbox-retry` vrátil selhaný řádek | `transport_state = queued` |

Mezistavy (další pokus po chybě) posluchač nedostává — zpráva je vidí jako
`queued`. Stav fronty je v tu chvíli už zapsaný; výjimka posluchače se jen
zaloguje a workera neshodí. `SentMessageOutboxListener` registruje
`MailServiceFactory` pro prefix `sentMessage:`. Stav transportu přepisuje
jen výsledek **posledního** řádku fronty (`last_outbox_id`) — opožděný
výsledek staršího průchodu novější nepřebije; počet odeslání roste vždy.

**Pojistka odchozí pošty** ([outbound.md](outbound.md) § Pojistka, #95).
Na dev a testovacím serveru „odesláno“ nemusí znamenat, že zpráva došla
příjemcům ze zprávy. Posluchač s událostí `sent` dostává výsledek pojistky
a `SentMessageStore::markSafety()` ho zapíše na zprávu: `safety_action`
(`redirected` / `dropped`, cfgItem `core.mail.safetyActions`)
a `safety_target` (adresa přesměrování). Příjemci na zprávě (`email_to`,
`email_cc`) zůstávají původní. Odeslání bez zásahu stopu dřívějšího
odeslání smaže; zápis je oddělený od `markSent()`, takže zdroj dat před
`ds-upgrade` o stav transportu nepřijde.

`SentMessageTransportInfo::state()` vrací vedle stavu i `safety`
(`{action, target, label, style}` nebo `null`) — štítek „Přesměrováno na
<adresa>“, „Příjemci omezeni pojistkou“ (allowlist bez `redirectTo`, část
příjemců vypadla) nebo „Zachyceno — neodesláno“. Jen u zprávy ve stavu
`sent`: zpráva, která čeká ve frontě nebo selhala, stopu dřívějšího
odeslání neukazuje.

Úklid fronty tak historii nevezme: co, komu a kdy odešlo, zůstává na
zprávě. Historie jednotlivých pokusů (formulář zprávy) se čte z
`core_mail_outbox_log`, dokud existuje.

## Odeslat znovu (D44)

Dvě akce, dva významy:

- **Odeslat** na záznamu → vždy **nová zpráva**: příjemci dohledaní živě,
  nově vyrobené PDF. Oprava adresy = upravit osobu a odeslat; historie
  zůstává.
- **Odeslat znovu** na zprávě → `SentMessageTransport::resend()`: další
  průchod **téže** zprávy — stejní příjemci, stejné přílohy, nic nového
  nevzniká.

Odeslat znovu jde jen zprávu ve stavu Odeslaná (archivovanou nejdřív
obnovit), která právě nečeká ve frontě:

`POST /_sent-messages/{id}/resend` → `{transportState, transport}`

| Kód | HTTP | Kdy |
|---|---|---|
| `NOT_FOUND` | 404 | zpráva neexistuje |
| `INVALID_STATE` | 409 | zpráva je v archivu nebo smazaná |
| `ALREADY_QUEUED` | 409 | zpráva už ve frontě čeká |

Práva: `guardTable()` na tabulku Odeslané pošty a zápis přes
`ReadOnlyPolicy` (routa nemá výjimku → na read-only zdroji 403).

## UI (D45)

- **Agenda Odeslaná pošta** (`core.mail.sent`, `SentMessagesViewer`) —
  root-level položka hned za Došlou poštou (sekce „Pošta“ v navigaci
  není): datum, osoba a adresy příjemce, předmět, popisek záznamu
  (`target_label`, snapshot z doby odeslání), stav transportu a štítek
  pojistky. Bez akce Přidat. Detail má akci **Otevřít záznam**
  (`open_form` na cílový záznam).
- **Formulář zprávy** (`SentMessagesForm`) — všechna pole jen pro čtení;
  stavová lišta nabízí Archivovat / Smazat / Obnovit. Komponenta
  `sentMessageTransport` (`SentMessageTransport.svelte`) ukazuje stav
  transportu, čas a počet odeslání, poslední chybu, historii pokusů
  a tlačítko **Odeslat znovu**; odpověď endpointu nese tentýž tvar jako
  parametry komponenty (`SentMessageTransportInfo::describe()`), takže se
  blok po odeslání jen přepíše. Vedle jsou náhledy příloh
  (`attachmentsView`).
- **Sekce Odeslaná pošta v detailu záznamu** — generický háček
  `ViewerController::detail()` pro libovolnou tabulku, viz
  [`../prints.md`](../prints.md) §7.
- **Štítek pojistky** je všude, kde je stav transportu: řádek a detail
  agendy, formulář zprávy, sekce u záznamu i výsledek dialogu Odeslat.
  Nad agendou je při zapnuté pojistce upozornění (`MailSafetyNotice`).

## Mimo rozsah

Hromadné a automatické odesílání (dávka, plánovač, idempotence), ruční
zpráva bez tisku (model ji umožní — `print_id` i `target_*` jsou
nepovinné), kanál datová schránka, společný pohled došlá + odeslaná pošta,
sledování doručení a přečtení, HTML tělo.

## Bezpečnost testování

Skutečné příjemce chrání pojistka odchozí pošty na úrovni serveru
([outbound.md](outbound.md) § Pojistka, #95): dev server bez `mail.safety`
neposílá nic, testovací server přesměrovává na týmovou adresu. Zprávy
z kopie ostrých dat tak jde odesílat a logiku příjemců zkoušet — na
zprávě zůstávají původní adresy a štítek říká, kam (ne)odešla.

Co platí dál:

- integrační testy jen `prepare` / zařazení do fronty (`trigger: cli`),
  nikdy `attemptSend` ani zpracování fronty; `RecordSendTest` běží celý
  v transakci s rollbackem, takže řádek fronty worker nikdy neuvidí;
- před ručním odesláním z kopie ostrých dat ověř režim pojistky
  (`shpd-server doctor`, upozornění v dialogu Odeslat). Když je `off`,
  platí původní pravidlo: odesílat jen z volného zdroje dat s vlastními
  adresami a `print-send` bez `--dry-run` vyžaduje `--to`.
