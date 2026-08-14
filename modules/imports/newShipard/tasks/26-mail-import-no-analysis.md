# 26 — Import pošty: žádná importovaná zpráva do AI fronty

**Stav:** implementováno (2026-08-14); zbývá ověření po přeimportu
**Repo:** `old_shipard` (`modules/imports/newShipard`)
**Souvisí:** `nov_shipard: tasks/mail-mailbox-analysis-disable.md`
(schránkový zákaz analýzy — nezávislá změna, žádná vzájemná závislost;
tento task funguje sám o sobě).

## Cíl

Zprávy importované ze starého Shipardu se nesmí dostávat do fronty AI
analýzy. Dnes tam padají všechny zprávy mapované do docState 10 (Nová) —
tj. staré stavy 1000/1001/**1200 K řešení**/8000 — protože `MailRunner`
neposílá `analysis_state` a server (`IncomingMessageDocument::beforeSave`)
pak zprávu v docState 10/20 nafrontuje, existuje-li aktivní AI profil.
Na DS s velkým množstvím nevyřízených „K řešení" (obsahově mrtvých) to
znamená hromadnou nesmyslnou analýzu hned po importu.

## Rozhodnutí (potvrzeno, D1 + O1)

- **D1:** `MailRunner` posílá `analysis_state: 0` explicitně pro **všechny**
  importované zprávy bez ohledu na docState. Endpoint `POST /_mail/import`
  explicitní hodnotu respektuje (zdokumentovaná přednost před defaultem
  z `beforeSave`) — žádná změna na straně serveru.
- **O1 (uzavřeno):** importované zprávy s `analysis_state = 0` nejdou
  následně ručně poslat do analýzy (guard reanalyze vyžaduje stav 30/70).
  Vědomé omezení, ne bug; případné otevření = samostatný budoucí task
  v `nov_shipard`.

## Změna

`libs/runners/MailRunner.php`, `processIssue()` — do `$payload` pro
`POST /_mail/import` přidat (k `docState`):

```php
'analysis_state'  => 0,
```

Nic dalšího: `ensureMailboxes()` beze změny (O2 — schránky vznikají
s defaultem, vypnutí analýzy per schránka je ruční operace v novém
Shipardu), mapování stavů beze změny, přílohy beze změny.

## Ověření

1. Dry-run: `--dry-run` projde beze změny chování (payload se neposílá).
2. Po ostrém importu na čistý DS (read-only, `claude_ro`):

```sql
-- musí vrátit 0 řádků
SELECT COUNT(*) FROM core_mail_incoming_messages
 WHERE source_type IN (1, 2, 3)
   AND analysis_state != 0
   AND created_by = <id uživatele _legacy_importer>;
```

Jednodušší varianta bezprostředně po importu (než doteče živá pošta):
`SELECT analysis_state, COUNT(*) FROM core_mail_incoming_messages
GROUP BY analysis_state;` — vše v 0.

3. Kontrola, že fronta je prázdná: `core_mail_analysis_claims` bez nových
   claimů na importované zprávy.

Zapadá do celkové verifikace přeimportu —
viz `24-mail-message-centric-verify.md`; tam při příštím běhu doplnit
kontrolu `analysis_state = 0` na importovaných zprávách.

## Hotovo když

- [x] `processIssue()` posílá `analysis_state: 0`.
- [ ] Po přeimportu DS nemá žádná importovaná zpráva `analysis_state != 0`.
- [ ] Žádný claim v `core_mail_analysis_claims` na importované zprávy.
