# 24 — Verifikace kompatibility: message-centrická AI analýza v Novém Shipardu

**Stav:** ověřeno staticky (2026-08-12) — bez nálezu vyžadujícího změnu;
zbývá smoke test na alfě (bod 4)

**Kontext:** Nový Shipard opouští model extrahovaných dokumentů
(`core_mail_extracted_documents` zaniká) a přechází na message-centrickou
AI analýzu — jedna zpráva → nejvýše jeden dokumentový návrh. Design a
serverové změny: `nov_shipard: tasks/mail-message-centric.md` (D1–D12).
Očekávaný dopad na migrační pipeline je **nulový až minimální** — tento
task je primárně verifikace, ne implementace.

## Co se na straně Nového Shipardu mění (relevantní výřez)

- Tabulka `core_mail_extracted_documents` zaniká.
- `POST /_mail/import` (importní endpoint) zůstává beze změny — pole
  `primary_type`, `target_table_id`, `target_row`, `docState`,
  explicitní `analysis_state` fungují dál.
- cfgItem `core.mail.extractedDocTypes` zaniká, klíče splynuly
  s `core.mail.primaryTypes` (klíče byly záměrně shodné — hodnoty
  posílané v `primary_type` zůstávají platné).
- `docs_core_heads.source_extracted_doc` → `source_message` (import
  dokladů tento sloupec neplní, lineage importu je `source_kind`).

## Verifikační checklist

1. **`MailRunner`** (`modules/imports/newShipard/libs/runners/MailRunner.php`):
   - [x] payload zpráv neposílá nic z rušeného modelu (extracted docs,
     `source_attachments`) — dle inspekce neposílá; potvrdit.
   - [x] hodnoty `primaryTypeFor()` jsou platné klíče
     `core.mail.primaryTypes` po sloučení (žádný klíč se nemazal,
     jen přibyly atributy `target`/`docKind`).
   - [x] `target_table_id`/`target_row` mapování na navázané doklady
     beze změny — singulární vazba je nyní kanonická, import ji plnil
     správně už dřív.
2. **`DocsRunner` / exchange import** — neplní
   `source_extracted_doc`; potvrdit, že se sloupec nikde nereferencuje
   (grep `source_extracted_doc` přes `modules/imports/newShipard/`).
3. **Duplicitní ISDOC v zrcadlené poště:** starý Shipard extrahuje ISDOC
   z PDF a ukládá jako přílohu → zrcadlené zprávy nesou `.isdoc` dvakrát
   (příloha + extrakt). Nový Shipard to nyní řeší sám (dedup identitou,
   Fáze D v PRD) — **žádná změna na straně starého Shipardu**; jen
   ověřit, že mirror posílá obě přílohy beze změny formátu.
4. **Smoke test:** po nasazení Fáze A+B na alfě spustit importní
   orchestrátor proti jednomu DS (např. lefreal) a ověřit, že mail
   import projde bez chyb a zprávy z Archivu se nefrontují
   (`analysis_state=0`).

## Výstup

Krátký zápis do tohoto souboru: co bylo ověřeno, případné nálezy.
Implementační změny jen pokud checklist odhalí reálnou nekompatibilitu.

## Zápis ověření (2026-08-12)

Statická verifikace proti kódu nového Shipardu (`sw/shpd`, stav po
sloučení primaryTypes / message-centric refaktoru). **Žádná
nekompatibilita, implementační změny nejsou potřeba.**

1. **Payload `MailRunner` ✓** — `processIssue()` posílá jen `mailbox`,
   `subject`, `sender_email/name/person`, `received_at`,
   `body_plain/html`, `source_type`, `primary_type`, `target_table_id`,
   `target_row`, `docState`. Nic z rušeného modelu (extracted docs,
   `source_attachments`).
2. **Klíče `primaryTypeFor()` ✓** — runner vrací jen `invoiceReceived`
   a `other`; oba v `core.mail.primaryTypes` po sloučení existují,
   `enabled: true`, `target: docs`.
3. **`target_table_id`/`target_row` ✓** — `MailController::import`
   je dál čte beze změny; explicitní `docState` i `analysis_state`
   z requestu mají dál přednost.
4. **`source_extracted_doc` ✓** — grep přes
   `modules/imports/newShipard/`: žádný výskyt mimo tento task
   (`extractedDocTypes` a `source_attachments` rovněž).
5. **Duplicitní ISDOC ✓** — `AttachmentReader` čte všechny nesmazané
   přílohy z `e10_attachments_files` bez filtrování podle přípony či
   formátu → obě `.isdoc` kopie projdou beze změny; dedup identitou
   řeší nová strana (Fáze D).

**Poznámka k `analysis_state`:** import ho neposílá → platí default
z `beforeSave` (`IncomingMessageDocument`): fronta jen pro docState
10/20, a jen když je analýza povolená a existuje aktivní AI profil.
Mapování importu: Archiv (9000→80) a Vyřešeno (4000→40) → 0 (bez
analýzy). Zprávy mapované na 10 (Nová) se u DS s aktivním AI profilem
frontovat **budou** — dle designu záměr; při smoke testu zkontrolovat
`SELECT analysis_state, COUNT(*) … GROUP BY analysis_state`.

**Zbývá:** smoke test na alfě po nasazení Fáze A+B (bod 4 checklistu) —
orchestrátor proti lefreal, mail import bez chyb, archivní zprávy
s `analysis_state=0`.
