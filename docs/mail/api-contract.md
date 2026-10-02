# `core.mail` — API kontrakt pro mail-router

**Status:** Stabilní od Fáze 2a.
**Implementace:** `src/Api/Controller/MailController.php`, `src/Api/Router.php`,
idempotence přes `core_mail_incoming_idempotency`.

## 1. Přehled

Mail-router je samostatná služba v samostatném repozitáři. Přijímá e-maily přes
SMTP/IMAP, parsuje `.eml`, a každou zprávu odešle do Shipardu přes HTTP endpoint
`POST /_mail/incoming`. Shipard zprávu zvaliduje, uloží spolu s přílohami do
jedné transakce a vrací ID nového záznamu.

### Separace zodpovědností

| Vrstva | Odpovědnost |
|---|---|
| Mail-router | Příjem SMTP/IMAP, parsing MIME, antivir, persistent queue + retry, idempotency key generation |
| Shipard `/_mail/incoming` | Auth, validace, atomické uložení zprávy + příloh, idempotency cache |
| Shipard `IncomingMessageDocument` | Generování `message_id`, normalizace sender_email, cascade delete |

## 2. Endpoint

```
POST /api/v1/_mail/incoming
Host: {ds-host}
Authorization: Bearer shpd_ak_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
X-Idempotency-Key: <hex sha256>  // volitelné
Content-Type: multipart/form-data; boundary=...
```

### 2.1 Autentizace

- **API klíč** typu `shpd_ak_` vydaný přes `bin/shpd-ds mail-router-setup`.
  Na hostovaných DS ho mintuje provisioning agent (`mail-router-setup
  --json`) a distribuci na mail-router řeší hosting — lookup endpoint
  `/_hosting/mail/lookup` + proces `lookup-sync` (D4, `docs/hosting.md`
  §5.3, `docs/operations/mail-router.md`); ruční editace `lookup.json`
  zůstává pro nenapojené routery.
- Kontroluje se existence klíče (hash v `core_system_api_keys`), platnost a
  optional IP whitelist.
- Controller navíc vynucuje, aby uživatel klíče byl systémový `_mail_router`
  (jiné API klíče tento endpoint nesmějí používat → **403 FORBIDDEN**).

### 2.2 Pole požadavku (multipart/form-data)

| Pole | Typ | Pov.? | Popis |
|---|---|---|---|
| `mailbox` | string | | `mailbox_id` (např. `invoices`). Prázdné / chybějící → použije se schránka s `is_default = 1`. |
| `external_message_id` | string | | RFC822 `Message-ID` — ukládá se pro pozdější dedup |
| `received_at` | ISO 8601 | ✓ | Čas doručení s TZ, např. `2026-04-18T14:32:00+02:00` |
| `subject` | string | | Předmět. Prázdné → `(bez předmětu)` |
| `sender_email` | string | ✓ | Musí projít `FILTER_VALIDATE_EMAIL` |
| `sender_name` | string | | Display name z `From:` hlavičky |
| `body_plain` | text | | Tělo v plain textu |
| `body_html` | text | | Tělo v HTML |
| `in_reply_to` | string | | RFC822 `In-Reply-To` header |
| `reply_references` | string | | RFC822 `References` header (whitespace-separated) |
| `source_type` | int | | Default `2` (email). Router nemění. |
| `raw_source` | file | ✓ | Originální `.eml` — ukládá se jako attachment a propojuje přes `raw_source_attachment` FK |
| `attachments[]` | file | | 0..N obsahových příloh |

## 3. Odpověď

### 3.1 Úspěch — 201 Created

```json
{
    "success": true,
    "data": {
        "ndx": 12345,
        "message_id": "MSG-20260418-0001",
        "idempotent_replay": false
    }
}
```

Při retry se stejným `X-Idempotency-Key` během TTL vrátí server uloženou odpověď
s `idempotent_replay: true`. Status code zůstává **201**.

### 3.2 Chybové odpovědi

| Kód | Error code | Situace |
|---|---|---|
| 401 | `UNAUTHORIZED` | Chybí / neplatný / expirovaný API klíč nebo session token |
| 403 | `FORBIDDEN` | API klíč patří jinému uživateli než `_mail_router` |
| 422 | `VALIDATION_ERROR` | Chybí povinné pole, neplatný formát, neznámá/chybějící default schránka |
| 500 | `INTERNAL_ERROR` | Neočekávaná chyba (rollback proběhl, uploaded soubory se smažou) |

Mail-router **retryuje jen 5xx**, při 4xx putuje zpráva do DLQ.

Tělo chyby:

```json
{
    "success": false,
    "error": {
        "code": "VALIDATION_ERROR",
        "message": "Schránka 'foo' neexistuje v tomto DS",
        "details": [{"field": "mailbox"}]
    }
}
```

## 4. Idempotence

### 4.1 Generování klíče

Klient generuje `X-Idempotency-Key` deterministicky:

```
sha256(ds_domain + "/" + local_part + "/" + external_message_id)
```

Pokud `external_message_id` chybí, klient **nesmí** posílat idempotency key
(nebo ho vygeneruje náhodně) — server v tom případě idempotenci nevynucuje.

### 4.2 Serverová logika

1. Při příjmu requestu lookup v `core_mail_incoming_idempotency`.
2. **Match + ≤ 7 dní** → server vrátí uloženou odpověď s `idempotent_replay: true`
   a zprávu **nevytvoří znovu**.
3. **No match / expired** → pokračuje ve zpracování. Po commitu tx zapíše key +
   response body do tabulky.

TTL: **7 dní**. Cleanup: `bin/shpd-ds mail-idempotency-prune --days 7`
(běží denně přes slot `daily` systémového cronu, viz `cli.md` § `cron`).

## 5. Flow na straně Shipardu

```
Router POSTs multipart
  → AuthMiddleware validuje shpd_ak_ token → AuthContext(user=_mail_router)
  → MailController::receiveIncoming
    → verify auth + scope (musí být _mail_router, jinak 403)
    → Idempotency lookup (pokud X-Idempotency-Key)
    → Parse & validate form fields (422 při chybě)
    → Resolve mailbox (explicitní nebo default)
    → BEGIN transaction
      → Insert core_mail_incoming_messages (IncomingMessageDocument::beforeSave
        vygeneruje message_id a normalizuje pole)
      → AttachmentService::upload(raw_source) → UPDATE message.raw_source_attachment
      → Pro každou attachments[]: AttachmentService::upload(file, message_id)
      → IdempotencyStore::store() pokud key existuje
    → COMMIT
  → Response 201
```

Při jakékoli výjimce uvnitř transakce: rollback + unlink orphaned files z disku.

## 6. Auto-provisioning

Výchozí schránku a systémového uživatele `_mail_router` vytváří provisioner
automaticky na konci `bin/shpd-ds ds-upgrade` (idempotentně). Pro existující DS
založené před Fází 2a lze spustit samostatně:

```bash
bin/shpd-ds mail-router-bootstrap
```

Default schránka má:
- `mailbox_id = 'default'`
- `email_address = '{ds-id}@shipard.email'`
- `is_default = 1`
- `docState = 40` (V pořádku)

Per-DS smí existovat nejvýš jedna `is_default = 1` schránka — vynuceno
aplikačně v `MailboxDocument::validate()` (MariaDB neumí filtrovaný unikátní
index).

## 7. Bezpečnost

- **HTTPS povinné** v produkci.
- API klíče se ukládají pouze jako sha256 hash, plaintext se zobrazí jen při
  vytvoření.
- IP whitelist volitelný přes `mail-router-setup --ip=<address>`.
- `body_html` se v UI renderuje sandboxovaně — detail ho posílá jako content
  type `untrusted-html` a frontend renderuje v `<iframe srcdoc>` bez
  `allow-scripts` (`SandboxedHtml.svelte`, viz `docs/frontend.md` — Typy
  obsahu). Raw HTML se v DB ukládá beze změny.
- Antivir scan **neproběhne v Shipardu** — očekává se na straně mail-routeru
  před odesláním.
- Rate limit na `/_mail/incoming` zatím není — mail-router je trusted. Pojistka
  přes `RateLimitMiddleware` může přijít jako follow-up.

## 8. Kompatibilita

Kontrakt je **stable**. Breaking změny vyžadují:

1. Nový versioning přes hlavičku (`X-Mail-Api-Version`), nebo
2. Nový endpoint (`/_mail/v2/incoming`).

Přidávání **volitelných polí** je vždy zpětně kompatibilní.

## 9. Pull-based AI analyzer protokol (Fáze 3a)

Druhá sada endpointů `/_mail/analysis/*` slouží externímu AI analyzeru
(samostatný daemon, viz Fáze 3b). Auth: `Bearer shpd_ak_…` token systémového
uživatele `_ai_analyzer` (vygeneruje `ai-analyzer-setup`). Endpointy modifikující
state pak vyžadují navíc hlavičku `X-Claim-Token: ct_…`.

### 9.1 `GET /_mail/analysis/queue`

Query: `?limit=5&profile_id=czech_general` (oba volitelné, default `limit=5`).

Response 200:
```json
{
  "success": true,
  "data": {
    "messages": [
      {
        "ndx": 12345,
        "received_at": "2026-04-26T10:00:00",
        "subject": "Faktura č. 2026000123",
        "sender_email": "accounts@example.com",
        "attachment_count": 2,
        "recommended_profile_ndx": 17,
        "has_raw_source": true
      }
    ],
    "total_available": 23
  }
}
```

Filtruje `analysis_state=10` (Ve frontě), `docState NOT IN (80, 90)` — Koš
a Archiv zprávu z fronty přirozeně vyřadí — `ai_analysis_enabled NOT FALSE`,
bez aktivní claim. Workflow `docState` se jinak nekontroluje (osy jsou
ortogonální, viz `modules/core/mail/docs/ai-analysis.md` „Stavy zprávy").

**Gate předzpracování:** navíc `preprocess_state NOT IN (10, 20)` — zpráva,
které běží technické předzpracování (stažení dokladu z odkazu, viz
`modules/core/mail/docs/preprocess.md`) **nebo odložený ISDOC import**
(#81 — zpráva s platným ISDOC se po intake / uploadu odloží do runneru
s `trigger: 'isdoc'`, import s obsahovou eskalací běží tam), se do fronty
ani do `total_available` nedostane, dokud runner neskončí (30 hotovo /
40 hotovo s chybami). Selhání předzpracování frontu **nikdy neblokuje** — zpráva
doteče se stavem 40. Stejnou podmínku kontroluje i `/claim` (409
`INVALID_STATE`), kdyby analyzer claimoval ze staršího snapshotu fronty.

### 9.2 `POST /_mail/analysis/{ndx}/claim`

Request body:
```json
{
  "analyzer_id": "uuid-of-this-analyzer-instance",
  "profile_ndx": 17,
  "lease_seconds": 300
}
```

`lease_seconds` se clampuje do rozsahu 60–900 s (default 300). Atomicky:
`SELECT … FOR UPDATE` na zprávu, ověř `analysis_state=10` a žádná aktivní
claim, INSERT claims, UPDATE `analysis_state→20`, decrypt `backend.api_key`.
`docState` se nemění.

Response 200:
```json
{
  "success": true,
  "data": {
    "claim_token": "ct_abc123…",
    "expires_at": "2026-04-26T10:05:00",
    "profile": {
      "profile_ndx": 17,
      "profile_id": "czech_general",
      "prompt_version": "v1.0.0",
      "prompt_template": "…",
      "output_schema": { },
      "supported_doc_types": ["invoiceReceived", "creditNote"],
      "language": "cs",
      "confidence_thresholds": { "ready": 0.9, "review": 0.6 },
      "max_tokens": 0
    },
    "backend": {
      "backend_ndx": 5,
      "provider": "anthropic",
      "model": "claude-sonnet-4-5",
      "api_key": "sk-ant-…",
      "base_url": null,
      "max_tokens": 0,
      "temperature": 0.0
    }
  }
}
```

`max_tokens` je kaskáda **profil → backend → default provideru analyzeru**:
`0` znamená „nenastaveno, spadni níž". Jediný skutečný default (číslo) žije
v provideru analyzeru; nenulová hodnota na profilu přebíjí backend (limit
souvisí s komplexností promptu/výstupu, které žijí na profilu).

Response headers: `Cache-Control: no-store, no-cache, must-revalidate`,
`Pragma: no-cache`. Plaintext API klíč žije v paměti analyzeru jen po dobu
zpracování zprávy.

Chybové kódy: `404 NOT_FOUND`, `409 INVALID_STATE` (analysis_state != 10),
`409 ALREADY_CLAIMED`, `409 NO_PROFILE`, `409 NO_BACKEND`, `409 BACKEND_KEY_MISSING`,
`500 SECRETS_UNAVAILABLE`, `500 BACKEND_KEY_CORRUPTED`, `500 INTERNAL_ERROR`
(generická hláška, detail jen v server-side logu — spec §10 dec.2).

### 9.3 `GET /_mail/analysis/{ndx}/payload`

Headers: `X-Claim-Token: ct_…`.

Response: `subject`, `sender_email`, `sender_name`, `body_plain`, `body_html`,
`received_at` + pole `attachments[]` s metadaty (bez obsahu). `raw_source_attachment`
je z listu vyloučen — analyzer pracuje s rozparsovanými přílohami, ne se .eml.

### 9.4 `GET /_mail/analysis/{ndx}/attachments/{att_ndx}/content`

Headers: `X-Claim-Token`. Streamuje binární obsah jedné přílohy.
Response headers: `Content-Type` z attachment metadat, `Content-Disposition: attachment`,
`Cache-Control: no-store`. `raw_source_attachment` je explicitně blokovaný (404).

### 9.5 `POST /_mail/analysis/{ndx}/result`

Kontrakt v4 (message-centrický, `tasks/mail-message-centric.md` D11):
jednotkou analýzy je celá zpráva, výsledkem **nejvýše jeden** dokumentový
návrh. Headers: `X-Claim-Token`. Request body:
```json
{
  "model_name": "claude-sonnet-4-5",
  "model_version": "20260101",
  "prompt_version": "v4.0.0",
  "profile_ndx": 17,
  "backend_ndx": 5,
  "tokens_input": 4500,
  "tokens_output": 1200,
  "duration_ms": 12340,
  "cost_usd": 0.0234,
  "overall_confidence": 0.92,
  "analysis_json": { },
  "message_classification": {
    "primary_type": "invoiceReceived",
    "confidence": 0.97,
    "title": "Faktura 2026-0042 — Dodavatel s.r.o., 13 105 Kč"
  },
  "document": {
    "doc_type": "invoiceReceived",
    "extracted_json": { },
    "confidence": 0.94
  },
  "secondary_findings": [
    { "type": "contract", "note": "Rámcová smlouva v příloze smlouva.pdf" }
  ]
}
```

- `message_classification` je **povinná** (422 při absenci; prompt v4 ji
  vždy generuje). Fallback čtení z `analysis_json.message_classification`
  zůstává pro robustnost, top-level pole má přednost. `title` (volitelný,
  ≤ 120 znaků, od promptu v4.3.0) je lidský titulek zprávy → sloupec
  `ai_title`; bez něj server složí fallback z canonicalu.
- `document` je volitelný (0..1) — primární dokument zprávy. Pole
  `doc_type` se ukládá do sloupce `proposed_type`.
- `secondary_findings` je volitelný informativní seznam dalších nálezů
  (`{type, note}`) — server ho strukturálně nevaliduje nad rámec tvaru,
  žije jen v `analysis_json`.
- Pole `extracted_documents` server od v4 **nepřijímá** (422) — big-bang,
  bez kompatibilní mezivrstvy. `source_attachment_ndxs` z kontraktu zaniklo
  (přílohy návrhu = všechny obsahové přílohy zprávy).

Server transakčně:

1. Validace `document.extracted_json` proti canonical schématu
   (`shpd.docs.document.v1`, registry typy proti
   `shpd.registry.document.v1`) + enrichment (`RowHistoryEnricher`);
   INSERT `core_mail_message_analyses` (status=2, `canonical_json`,
   `proposed_type`, `confidence` = `document.confidence`, u běhu bez
   dokumentu `overall_confidence`). Nevalidní canonical → forenzní wrapper
   `{_validationError, _validationIssues, _rawOutput}` do `canonical_json`,
   běh se uloží (dashboard emituje chybovou kartu), 201 se vrací.
   Confidence pásma (ready/review/low) se **nepersistují** — počítají se
   za běhu z `confidence` vs. thresholds profilu.
2. UPDATE claims SET `released=1, release_reason='result'`.
3. UPDATE messages SET `analysis_state=30` (Analyzováno), vynuluj
   `needs_reanalysis`.
4. Workflow: `document` přítomen a validní **a** zpráva stále v Nové
   (`docState=10`) → UPDATE `docState=20` (K řešení). Běh bez dokumentu
   docState **nemění** — zpráva zůstává v Nové (dashboard emituje kartu
   „Není faktura"). Ruční workflow stav pipeline nikdy nepřepisuje.
5. `message_classification`: validace `primary_type` proti klíčům
   `core.mail.primaryTypes` (tolerují se i `enabled: false` typy;
   neznámý klíč → server-side warning + pole se ignoruje, **ne** 422).
   UPDATE `primary_type` + `primary_type_source='ai'` — **jen pokud**
   `primary_type_source != 'user'` (volba uživatele má vždy přednost).
6. Partner zprávy z validního canonicalu (`MessagePartnerWriter`, spec
   `tasks/mail-message-title-partner.md`): UPDATE `partner_name` ←
   `supplier.name` / `party.name` (jen dokud `target_row IS NULL`) a
   `partner_person` ← Osoba při shodě IČO / DIČ / VAT ID (jen do NULL a jen
   dokud `target_row IS NULL`; nikdy shoda jménem). Best-effort — selhání
   nevrací chybu.
7. Titulek zprávy: UPDATE `ai_title` ← `message_classification.title`
   (trim, 200 znaků), jinak fallback `MessageTitleComposer` z validního
   canonicalu, jinak NULL — zapisuje se **vždy** (AI-vlastněný sloupec,
   bez guardů; re-analýza bez dokumentu titulek smaže). Best-effort.

Response 201: `{ analysis_ndx }`.

### 9.6 `POST /_mail/analysis/{ndx}/failed`

Headers: `X-Claim-Token`. Request body:
```json
{
  "error_type": "schema_error | ai_error | config_error",
  "error_message": "…",
  "tokens_used": 123,
  "retryable": true,
  "model_name": "claude-sonnet-4-5",
  "prompt_version": "v1.0.0"
}
```

Server: INSERT failed `message_analyses` (status=3; `error_message` se
ukládá jako `"[error_type] error_message"` — z tohoto tvaru odvozuje
`AnalysisErrorPresenter` lidskou hlášku pro UI, viz
`modules/core/mail/docs/ai-analysis.md` → „Chybové hlášky pro uživatele"),
uvolni claim (`release_reason='failed'`), přepni stav analýzy (`docState`
se nemění):

- `retryable=true` → `analysis_state=10` (vrátí se do fronty)
- `retryable=false` → `analysis_state=70` (Analýza selhala, manuální zásah)

### 9.7 `POST /_mail/messages/{ndx}/reanalyze`

UI akce, **jiný auth** — vyžaduje běžný uživatelský token (`shpd_st_…` nebo
admin `shpd_ak_…`), ne `_ai_analyzer`. Request body:
```json
{
  "profile_override_ndx": 42
}
```

`profile_override_ndx` je volitelné. Server validuje:

- Zpráva existuje, `analysis_state ∈ {30, 70}` a `docState NOT IN (80, 90)`
  (jinak 409 INVALID_STATE).
- Zpráva nemá aplikovaný návrh s živým targetem (poslední úspěšná analýza
  `resolution=40` + `target_row` obsazený) — jinak 409 INVALID_STATE,
  nejdřív unapply.
- Profile override (pokud zadán) existuje a `is_active=1`.

Server v transakci: UPDATE `messages` SET `analysis_state=10`,
`needs_reanalysis=1`, `profile_override`. `docState` se nemění. Historie
analýz se nemění — „aktuální návrh" je implicitně poslední úspěšný běh
(koncept superseded zanikl).

Analyzer při dalším GET /queue zprávu uvidí včetně override profilu.

### 9.8 `POST /_mail/messages/{ndx}/apply`

UI akce „Použít" nad dokumentovým návrhem **poslední úspěšné analýzy**
zprávy. Auth: běžný uživatelský token. Body volitelně `{ "_resolve":
{cesta: userAction}, "applyOptions": {autoCreateMode, targetDocState} }` —
prázdné tělo = safe mode (viz `docs/exchange-format.md`).

Guardy: zpráva mimo Archiv/Koš, `analysis_state=30`, otevřený návrh
(`resolution IS NULL`), validní `canonical_json`; `message.target_row`
obsazený → idempotent/recovery cesta. Routing dle `proposed_type` běhu →
`PrimaryTypes::targetFor()` (docs = exchange `DocumentApplier` s injection
`source.kind='aiExtraction'` + `source.message`; registry =
`RegistryApplier` se **všemi** obsahovými přílohami zprávy). Obě větve
zapíšou obě strany lineage atomicky (doklad `source_message` ↔ zpráva
`target_table_id`/`target_row`), pak verdikt: analysis `resolution=40` +
`resolved_at/by`, zpráva `docState → 40` (z 10 i 20).

Vrací `{ savedDocId, messageNdx, analysisNdx }` + jeden z `idempotent: true`
/ `recovered: true` / `canonical`. Chyby: `404 NOT_FOUND`,
`409 INVALID_STATE`, `422 NO_PROPOSAL` / `AI_OUTPUT_INVALID` /
`unresolved_required` (s `canonical._resolve.issues`), `500`.

### 9.9 `POST /_mail/messages/{ndx}/reject`

UI akce „Zamítnout". Request body: `{ "reason": "…" }` — povinné, neprázdné.
Zapíše na poslední úspěšnou analýzu `resolution=50` + `rejected_reason` +
`resolved_at/by`; zpráva `docState → 40` (Hotovo — symetricky s apply,
uživatel může následně Koš/Archiv). Reanalýza po rejectu zůstává možná
(vznikne nový běh s `resolution=NULL`). Vrací
`{ messageNdx, analysisNdx, resolution: 50 }`.

### 9.10 Reaper expirovaných claimů

CLI `bin/shpd-ds mail-analysis-reap` (cron 1×/min) vyčistí stale claimy:

- Najde `released=0 AND expires_at < now()`.
- Označí `released=1, release_reason='expired'`.
- UPDATE messages SET `analysis_state=10` WHERE `id=msg AND analysis_state=20`
  (dokončený result/failed má přednost). `docState` se nemění.

### 9.11 `POST /_mail/messages/{ndx}/unapply`

Bez UI — záchranná brzda pro MCP / ruční volání. Auth: běžný uživatelský
token. Vrátí předchozí apply — viz `MessageProposalApplier::unapply`.
Transakčně:

1. Poslední úspěšná analýza musí mít `resolution=40` (applied) a zpráva
   `target_row > 0`, jinak **409 `INVALID_STATE`**.
2. Docs cíl musí být **stále nedotčený Koncept** (`docState=10`), jinak
   **409 `DOC_ADVANCED`** (uživatel řeší ručně); registry cíl guard
   `modified <= resolved_at`.
3. Cílová entita → **Koš** (`docState=90`, ne hard-delete — vratné) přes
   Document flow. Koncept nespotřeboval číslo dokladu (přiděluje se až 10→20).
4. Analysis `resolution`/`rejected_reason`/`resolved_at/by` → NULL,
   zpráva `target_table_id`/`target_row` → NULL, `docState 40→20`.

Vrací `{ messageNdx, analysisNdx, trashedDocId }`. Chyby: `409 INVALID_STATE`
/ `409 DOC_ADVANCED`, `404 NOT_FOUND`, `500 INTERNAL_ERROR`.

### 9.12 `GET /_mail/messages/{ndx}/preview`

Read-only náhled návrhu poslední úspěšné analýzy pro review modal. Auth:
běžný uživatelský token. Vrací `{ messageNdx, analysisNdx, proposedType,
confidence, resolution, attachments, … }` + `aiFailed`/`wrapper` (nevalidní
výstup), nebo `canonical` (docs: s fresh enrichmentem a `_resolve`
z `applier->preview()`; registry: passthrough + `target: "registry"`).
`attachments` = **všechny** obsahové přílohy zprávy
(`{ndx, filename, mime_type, size_bytes}`). `userActions` = rozhodnutí
uložená z review modalu (§9.13): flat mapa `{cesta: userAction}`, nebo `{}`
— klíč je ve všech větvích odpovědi (i `aiFailed` a registry), na DS bez
sloupce `user_actions_json` vždy `{}`. Chyby: `404 NOT_FOUND` /
`NO_ANALYSIS` / `NO_PROPOSAL`, `500 CORRUPTED_DATA`.

### 9.13 `POST /_mail/messages/{ndx}/decisions`

Průběžné ukládání rozhodnutí z review modalu (resolve badge popovery,
issue #76). Auth: běžný uživatelský token. Body **povinně**
`{ "_resolve": {cesta: userAction} }` — vždy **celá** mapa, ne delta;
last-write-wins bez ETagu. Tvar shodný s `_resolve` v §9.8, např.
`{"supplier": "useExisting:42", "rows[0].item": "skip"}`; prázdný objekt =
smazat všechna rozhodnutí.

Guardy shodné s reject (§9.9): zpráva existuje a je mimo Archiv/Koš,
poslední úspěšná analýza + `analysis_state=30`, `resolution IS NULL`.
Server mapu **sanitizuje** — ponechá jen cesty z whitelistu
`supplier|customer|supplierBank|customerBank` a `rows[N].(item|unit|vatCode)`
s neprázdnou string hodnotou, zbytek tiše zahodí — a uloží ji jako JSON do
`core_mail_message_analyses.user_actions_json` na řádek **poslední
analýzy** (prázdná mapa po sanitizaci = `NULL`). Vazba na analýzu, ne na
zprávu: reanalýza vytvoří nový řádek bez rozhodnutí. Po verdiktu
(apply/reject) se sloupec nemaže; po unapply se modal otevře s původními
rozhodnutími.

Vrací `{ messageNdx, analysisNdx, userActions }` s mapou tak, jak byla
uložena (po sanitizaci; prázdná jako `{}`). Chyby: `422 VALIDATION_ERROR`
(`_resolve` chybí nebo není objekt, `details[0].field = "_resolve"`),
`404 NOT_FOUND`, `409 INVALID_STATE`, `500 INTERNAL_ERROR`; v read-only
stavu DS `403 DS_READ_ONLY`.

## 10. Známé limity

- **Velikost message:** v Shipardu žádný tvrdý limit. Postfix v mail-routeru
  odřízne 25 MB dřív. Pro lokální konfiguraci nginx/PHP zvýšit `client_max_body_size`
  a `upload_max_filesize` dle potřeby.
- **Per-request timeout:** PHP-FPM default (typicky 30 s). Pro 20 MB request
  může být těsný — konfigurovat v deploymentu, ne v kódu.
- **Scope restrictions** na API klíče (omezit klíč jen na `/_mail/incoming`)
  zatím neexistuje — follow-up.
- **Race condition při claim:** invariant "max jedna aktivní claim per zpráva"
  vynucuje aplikační kód v `claim()` přes `SELECT … FOR UPDATE`. MariaDB neumí
  partial unique index `(message) WHERE released=0`. Aktuální implementace
  serializuje souběžné claim() přes řádek zprávy.
