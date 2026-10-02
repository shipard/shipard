# Modul: Pošta (core.mail)

Modul spravuje e-mailovou komunikaci.

- **Fáze 1** — evidence došlé pošty (schránky, zprávy, struktura pro analýzy).
- **Fáze 2a** — HTTP endpoint `POST /_mail/incoming` pro externí mail-router.
- **Fáze 3a** — AI analýza došlých zpráv (extrakce dokumentů, pull-based protokol
  pro externí analyzer, UI pro review extrahovaných dokumentů, akce "Znova
  analyzovat").
- **Fáze 3 Spisovny (šum)** — deterministická pravidla odesílatelů s učením ze
  zpětné vazby (auto-archiv při ingestu, návrhové karty), signál `is_bulk`
  z hlaviček `.eml`, denní digest karta s „Vrátit vše". Viz `tasks/registry-phase3.md`.

Pozdější fáze: aplikace extrahovaných dokumentů na cílové entity (Fáze 3c),
další providers (Ollama, ...), odeslaná pošta.

## Závislosti

- `core.system` — docState set (archivační), uživatelé
- `core.attachments` — přílohy zpráv a uložený originál (`.eml`)
- `base.persons` — budoucí matching odesílatele na osobu

## Tabulky

| Tabulka | Popis |
|---|---|
| [core_mail_mailboxes](tables/core_mail_mailboxes.md) | Konfigurované e-mailové schránky DS |
| [core_mail_incoming_messages](tables/core_mail_incoming_messages.md) | Došlé zprávy |
| [core_mail_message_analyses](tables/core_mail_message_analyses.md) | Historie AI analýz zpráv |
| [core_mail_incoming_idempotency](tables/core_mail_incoming_idempotency.md) | Idempotency klíče pro `POST /_mail/incoming` (TTL 7 dní) |
| [core_mail_extracted_documents](tables/core_mail_extracted_documents.md) | Kandidáti na business entity z AI analýzy (Fáze 3a) |
| [core_mail_ai_profiles](tables/core_mail_ai_profiles.md) | Prompty + JSON schémata + thresholdy per use-case (Fáze 3a); FK `backend` → `core_ai_backends` (modul core/ai) |
| [core_mail_analysis_claims](tables/core_mail_analysis_claims.md) | Lease mechanismus pro pull protocol (Fáze 3a) |
| [core_mail_sender_rules](tables/core_mail_sender_rules.md) | Pravidla odesílatelů — auto-archiv šumu při ingestu (Fáze 3 Spisovny) |
| [core_mail_preprocess_rules](tables/core_mail_preprocess_rules.md) | Pravidla technického předzpracování (stažení dokladu z odkazu, render HTML těla do PDF) — [docs/preprocess.md](docs/preprocess.md) |

## Zdrojové soubory

| Soubor | Popis |
|---|---|
| [IncomingMessageDocument.php](src/IncomingMessageDocument.php) | Document třída pro došlé zprávy — validace, generování ID, cascade delete |
| [MailboxDocument.php](src/MailboxDocument.php) | Schránky — invariant "max 1 `is_default` per DS" |
| [IncomingMessagesForm.php](src/IncomingMessagesForm.php) | Formulář pro ruční pořízení a úpravu zprávy |
| [IncomingMessagesViewer.php](src/IncomingMessagesViewer.php) | Viewer pro seznam došlých zpráv; detail s hlavičkou (předmět · odesílatel · badges) a taby Obsah / Analýzy / Extrahované dokumenty / Originál |
| [MailRouterProvisioner.php](src/MailRouterProvisioner.php) | Bootstrap `_mail_router` + default schránky |
| [IdempotencyStore.php](src/IdempotencyStore.php) | Lookup/store idempotency klíčů |
| [AIProfileDocument.php](src/AIProfileDocument.php) | AI profil — JSON validace, `is_default` invariant |
| [ExtractedDocumentDocument.php](src/ExtractedDocumentDocument.php) | Extrahovaný dokument — atomický auto-transition zprávy 30→40 v `afterPersist` |
| [AIAnalyzerProvisioner.php](src/AIAnalyzerProvisioner.php) | Bootstrap `_ai_analyzer` + default backend + default profil |
| [AnalysisClaimReaper.php](src/AnalysisClaimReaper.php) | Reaper expirovaných claimů |
| [SenderRuleDocument.php](src/SenderRuleDocument.php) | Pravidla odesílatelů — formát dle druhu, lowercase, unikátnost mezi živými |
| [SenderRulesViewer.php](src/SenderRulesViewer.php) | Settings viewer pravidel odesílatelů |
| [SenderRuleMatcher.php](src/SenderRuleMatcher.php) | Match odesílatele proti potvrzeným pravidlům (e-mail > doména) |
| [BulkHeadersDetector.php](src/BulkHeadersDetector.php) | Signál `is_bulk` z hlaviček raw `.eml` (List-Unsubscribe, Precedence, Auto-Submitted, List-Id) |
| [SenderRuleSuggestionHandler.php](src/SenderRuleSuggestionHandler.php) | Učení: 3+ ručních odklizení téhož odesílatele → návrh pravidla (Koncept) |
| [Feed/MailDigestSource.php](src/Feed/MailDigestSource.php) | Digest karta auto-archivu + karty návrhů pravidel na dashboardu |
| [Preprocess/PreprocessRuleMatcher.php](src/Preprocess/PreprocessRuleMatcher.php) | Match zprávy proti pravidlům předzpracování (AND, CI regexy) → plán |
| [Preprocess/PreprocessRunner.php](src/Preprocess/PreprocessRunner.php) | Runner: claim, vykonání plánu, ISDOC, stavy 30/40, `--force`, sweep |
| [Preprocess/PreprocessSpawner.php](src/Preprocess/PreprocessSpawner.php) | Detached spawn `mail-preprocess --message` po commitu intake |
| [Preprocess/Action/FetchLinkedDocumentAction.php](src/Preprocess/Action/FetchLinkedDocumentAction.php) | Akce `fetchLinkedDocument` — redirect chain s kontrolou per hop, allowlist, provenance, `renderIfHtml` |
| [Preprocess/Action/RenderBodyToPdfAction.php](src/Preprocess/Action/RenderBodyToPdfAction.php) | Akce `renderBodyToPdf` — HTML tělo zprávy → PDF příloha přes render službu (#34), profil Untrusted |
| [Preprocess/Action/GeneratedAttachments.php](src/Preprocess/Action/GeneratedAttachments.php) | Společné pro akce: provenance lookup (idempotence), uložení přílohy, sanitizace názvu |
| [Preprocess/PreprocessRulesProvisioner.php](src/Preprocess/PreprocessRulesProvisioner.php) | Upsert systémového katalogu `config/systemPreprocessRules.jsonc` při `ds-upgrade` |
| [Preprocess/PreprocessRuleDocument.php](src/Preprocess/PreprocessRuleDocument.php) / [PreprocessRulesViewer.php](src/Preprocess/PreprocessRulesViewer.php) | Validace pravidel (podmínky, regexy, akce) a settings viewer |

## Konfigurace

| Klíč | Soubor | Popis |
|---|---|---|
| `core.mail.primaryTypes` | [config/primaryTypes.jsonc](config/primaryTypes.jsonc) | Primární typy došlých zpráv |
| `core.mail.docStatesIncoming` | [config/docStatesIncoming.jsonc](config/docStatesIncoming.jsonc) | Stavy zprávy (10/20/30/40/70/80/90) |
| `core.mail.extractedDocStates` | [config/extractedDocStates.jsonc](config/extractedDocStates.jsonc) | Stavy review extrahovaných dokumentů |
| `core.mail.extractedDocTypes` | [config/extractedDocTypes.jsonc](config/extractedDocTypes.jsonc) | Typy extrahovaných dokumentů |
| `core.mail.senderRulePatternKinds` | [config/senderRulePatternKinds.jsonc](config/senderRulePatternKinds.jsonc) | Druhy vzorů pravidel (`email` \| `domain`) |
| `core.mail.senderRuleDispositions` | [config/senderRuleDispositions.jsonc](config/senderRuleDispositions.jsonc) | Akce pravidla (zatím jen `archive`) |
| `core.mail.senderRuleOrigins` | [config/senderRuleOrigins.jsonc](config/senderRuleOrigins.jsonc) | Původ pravidla (`user` \| `suggested`) |

## Default AI profil

[profiles/czech_general.jsonc](profiles/czech_general.jsonc) — šablona, ze které
`AIAnalyzerProvisioner` při `ds-upgrade` vytvoří první profil `czech_general`.

## API endpointy

| Endpoint | Popis |
|---|---|
| `POST /api/v1/_mail/incoming` | Příjem došlé pošty z mail-routeru. Auth: `_mail_router`. |
| `GET /api/v1/_mail/analysis/queue` | Fronta zpráv k analýze. Auth: `_ai_analyzer`. |
| `POST /api/v1/_mail/analysis/{ndx}/claim` | Atomic claim. Vrací plaintext API klíč backendu (Cache-Control no-store). |
| `GET /api/v1/_mail/analysis/{ndx}/payload` | Subject/body/sender + metadata příloh. Auth: claim token. |
| `GET /api/v1/_mail/analysis/{ndx}/attachments/{att_ndx}/content` | Streamuje obsah přílohy. |
| `POST /api/v1/_mail/analysis/{ndx}/result` | Uloží výsledek + extracted documents. |
| `POST /api/v1/_mail/analysis/{ndx}/failed` | Failed analysis. retryable=true → 10, false → 70. |
| `POST /api/v1/_mail/messages/{ndx}/reanalyze` | UI akce "Znovu analyzovat". Auth: běžný uživatel. |
| `POST /api/v1/_mail/extracted-documents/{ndx}/apply` | UI akce "Použít" — prochází přes `ExtractedDocumentDocument` hooky (auto-transition zprávy 30→40). |
| `POST /api/v1/_mail/extracted-documents/{ndx}/reject` | UI akce "Zamítnout" — povinný `reason` v body. |
| `POST /api/v1/_mail/sender-rules/{id}/confirm` | Potvrzení návrhu pravidla (Koncept 10 → 40). Auth: běžný uživatel. |
| `POST /api/v1/_mail/sender-rules/{id}/reject` | Zamítnutí návrhu pravidla (10 → 90). |
| `POST /api/v1/_mail/auto-archive/undo` | „Vrátit vše" — obnova zpráv auto-archivovaných v daném dni (body `{date?}`, jen dnešek/včerejšek). |

Kontrakty: [docs/mail/api-contract.md](../../../docs/mail/api-contract.md).

## CLI příkazy

| Příkaz | Popis |
|---|---|
| `bin/shpd-ds mail-router-bootstrap` | Bootstrap `_mail_router` + default schránky. |
| `bin/shpd-ds mail-router-setup [--force] [--ip=X]` | API klíč pro mail-router. |
| `bin/shpd-ds mail-idempotency-prune [--days N]` | Prune idempotency klíčů (cron 1×/den). |
| `bin/shpd-ds ai-analyzer-bootstrap` | Bootstrap `_ai_analyzer` + default backend + default profile. |
| `bin/shpd-ds ai-analyzer-setup [--force] [--ip=X]` | API klíč pro AI analyzer. |
| `bin/shpd-ds ai-analyzer-set-key --backend default --api-key sk-ant-...` | Nastaví/zrotuje API klíč backendu (šifruje přes `DsSecretCipher`). |
| `bin/shpd-ds mail-analysis-reap` | Reaper expirovaných claimů (cron 1×/min). |
| `bin/shpd-ds mail-preprocess --message <id> [--force]` / `--sweep` | Runner technického předzpracování zprávy (plán z intake) / záchrana zaseknutých (cron 1×/min). Viz [docs/preprocess.md](docs/preprocess.md). |

`ai-analyzer-bootstrap` a `mail-router-bootstrap` se volají automaticky z `ds-upgrade`.

## Dokumentace

- [docs/documentation.md](docs/documentation.md) — Fáze 1+2a architektura
- [docs/ai-analysis.md](docs/ai-analysis.md) — Fáze 3a: AI analýza, pull protokol, životní cyklus
- [docs/ai-prompts.md](docs/ai-prompts.md) — Default prompt + guidelines pro customizaci
- [docs/preprocess.md](docs/preprocess.md) — Technické předzpracování zpráv před AI analýzou (pravidla, runner, `fetchLinkedDocument`, `renderBodyToPdf`, sweep)
