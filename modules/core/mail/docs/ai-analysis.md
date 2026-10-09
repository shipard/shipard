# AI analýza došlých zpráv

Tento dokument popisuje architekturu, datový tok a životní cyklus AI analýzy
v modulu `core.mail`. Specs: [tasks/mail-phase3a.md](../../../../tasks/mail-phase3a.md)
(protokol, Fáze 3a) a [tasks/mail-message-centric.md](../../../../tasks/mail-message-centric.md)
(message-centrický model, který extrahované dokumenty nahradil).

## Cíl

Z došlé zprávy v `core_mail_incoming_messages` strojově vytěžit **nejvýše
jeden dokumentový návrh** (přijatá faktura, dobropis, registry dokument…)
a nabídnout ho uživateli k review/použití. Jednotkou analýzy je **celá
zpráva** — subject + body + přílohy jsou jeden kontext (D1). Vlastní
extrakci dělá **runner v procesu** (`shpd-ds mail-analyze`, viz
[Analýza v procesu](#analýza-v-procesu), #85 D9); dočasně vedle něj
funguje i **externí analyzer daemon** (samostatný repozitář `ai_analyzer`)
přes pull protokol níže. Shipard je server-of-record: spravuje frontu,
claimy, ukládá výsledky, řídí review workflow — pro obě cesty stejně.

## Pull-based protokol

> **Stav (říjen 2026):** primární cestou je [analýza v procesu](#analýza-v-procesu);
> protokol zůstává kvůli souběhu s démonem a porovnání obou cest, dokud ho
> nezruší `tasks/ai-analyzer-removal.md`. Stavový automat, claimy a zápis
> výsledku jsou pro obě cesty tytéž — endpointy jsou tenké obálky nad
> službami `Shipard\Module\Core\Mail\Analysis\*` (`AnalysisQueue`,
> `AnalysisClaimService`, `AnalysisResultWriter`), které volá i runner.
> Na serveru, kde má dál pracovat démon, se runner vypíná
> `ai.analysis.maxConcurrent = 0`.

Externí analyzer drží trvale token a periodicky volá:

```
GET  /api/v1/_mail/analysis/queue
POST /api/v1/_mail/analysis/{ndx}/claim
GET  /api/v1/_mail/analysis/{ndx}/payload
GET  /api/v1/_mail/analysis/{ndx}/attachments/{att_ndx}/content
POST /api/v1/_mail/analysis/{ndx}/result
POST /api/v1/_mail/analysis/{ndx}/failed
```

Auth: `Bearer shpd_ak_…` token systémového uživatele `_ai_analyzer`. Vygeneruje
ho `ai-analyzer-setup` CLI; zobrazí se jednou.

### Životní cyklus jednoho běhu

Pipeline status žije v `analysis_state` (cfgItem `core.mail.analysisStates`),
ortogonálně k workflow stavu `docState` — viz sekce "Stavy zprávy".

```
1. analyzer GET /queue                  ← zprávy s analysis_state=10 mimo Archiv/Koš,
                                            z povolených schránek (viz "Stavy zprávy")
2. analyzer POST /{ndx}/claim           ← atomicky: analysis_state 10→20, vznikne claim row,
                                            response obsahuje plaintext API klíč backendu
                                            (jen v paměti; Cache-Control: no-store)
3. analyzer GET /{ndx}/payload          ← subject, body, metadata příloh
4. analyzer GET /{ndx}/attachments/.../content   ← streamuje binární obsah
5. analyzer ➜ provider (Anthropic, ...) ← analyzer.provider extrahuje, vrátí JSON
6. analyzer POST /{ndx}/result          ← uloží běh do message_analyses (canonical_json,
                                            proposed_type, confidence), uvolní claim,
                                            analysis_state →30; zpracuje povinnou
                                            message_classification; nese-li běh validní
                                            dokumentový návrh a zpráva je stále v Nové,
                                            docState 10→20 (K řešení)
```

Při chybě:

```
2'. POST /{ndx}/failed { retryable: true }   ← analysis_state 20→10 (vrátí do fronty)
2'. POST /{ndx}/failed { retryable: false }  ← analysis_state 20→70 ("Analýza selhala")
```

`docState` se při failed nemění. Pokud analyzer mezi `claim` a `result` spadne,
`mail-analysis-reap` (cron 1×/min) najde claim s `expires_at < now()`, označí ho
`released=true` s reason `expired` a vrátí `analysis_state` zpět na 10 (jen
pokud je stále ve 20 — dokončený result/failed má přednost).

### Atomicita a souběh

`POST /claim` běží v transakci s `SELECT … FOR UPDATE` na řádku zprávy →
serializuje souběžné claim() přes stejnou zprávu. MariaDB nepodporuje partial
unique index `(message) WHERE released=0`, invariant "max jedna aktivní claim
per zpráva" tedy vynucuje aplikační kód v claim controlleru.

`POST /result` v jedné transakci: `INSERT message_analyses` (status=2,
`canonical_json` = validovaný + obohacený canonical návrhu, `proposed_type`,
`confidence`; nevalidní canonical → forenzní wrapper `{_validationError, …}`
do `canonical_json`, běh se uloží a 201 se vrací) → `UPDATE claims SET
released=1` → `UPDATE messages SET analysis_state=30` (+ podmíněný
`docState 10→20` a zápis klasifikace) → archivace podle pravidla
odesílatele (`PostAnalysisDisposer::afterResult`, best-effort: běh bez
dokumentu, zpráva `other` v Nové, první úspěšná analýza, ne ruční nahrání,
jistota klasifikace ≥ `review` práh profilu, potvrzené pravidlo libovolné
dispozice → `docState 10→80` + `auto_disposed_*`;
`tasks/mail-sender-rules-after-analysis.md` D4–D6). Při selhání se vše
rollbackuje.
Kontrakt v4 detailně: [docs/mail/api-contract.md §9.5](../../../../docs/mail/api-contract.md).

## Analýza v procesu

Tasks/mail-analysis-inprocess.md (#85 D9–D19). Runner dělá tytéž kroky
jako pull protokol, ale volá je jako služby v procesu — stavy zprávy,
tab Analýzy, hlášky selhání, reanalýza, zámek zprávy při `analysis_state =
20` i závod s ISDOC importem zůstávají beze změny. `analyzer_id` claimu
je `internal:<hostname>:<pid>`, `created_by` běhu je NULL (strojový
kontext; běh přes HTTP nese uživatele `_ai_analyzer`).

```
příjem / nahrání zprávy (commit)
   ├─ plán předzpracování nebo ISDOC → spawn mail-preprocess
   │      └─ konec běhu (stav 30/40), zpráva ve frontě → spawn mail-analyze
   └─ jinak, zpráva ve frontě ──────────────────────────→ spawn mail-analyze

reanalyze (analysis_state → 10) ────────────────────────→ spawn mail-analyze
cron minute: mail-analysis-reap → mail-analyze --sweep ─→ spawn mail-analyze (≤ volné sloty)

mail-analyze --message N  (AnalysisRunner::run)
   1. slot (flock, neblokující)      bez slotu → konec, zpráva čeká ve frontě
   2. AnalysisQueue::isEligible(N)   ne → konec
   3. AnalysisClaimService::claim    10 → 20, lease 900 s; profil + backend + klíč
   4. AttachmentPreparer → PromptRenderer
   5. LLM (stream, opakování, před každým pokusem prodloužení lease)
   6. OutputParser (JSON + output_schema profilu)
   7. AnalysisResultWriter::storeResult / storeFailure   20 → 30 / 10 / 70
```

**Spouštění** (D14): `AnalysisSpawner` spouští `shpd-ds mail-analyze
--message <id>` přes `DetachedProcess` (stdout do `analysis.log` vedle
serverového logu) — po commitu příjmu nebo nahrání u zprávy, která nešla
do předzpracování (až **po** `deferIsdocImport()`, jinak by si AI za peníze
claimla fakturu, kterou umí deterministický import), na konci běhu
`PreprocessRunner`, po `reanalyze`; vždy jen pro zprávu ve frontě
(`AnalysisQueue::isEligible`). Spawner se wiruje jen v `public/index.php`
a `PreprocessRunnerFactory`; testy stavějí controllery bez něj. Záchrana:
`mail-analyze --sweep` v minutovém slotu za reaperem — pro zprávy ve
frontě bez aktivního claimu spustí runnery, nejvýš tolik, kolik je
volných slotů; bez použitelného backendu (aktivní výchozí profil → aktivní
backend s klíčem) nic a jedno varování. Druhý runner na tutéž zprávu skončí
na `ALREADY_CLAIMED`.

**Sloty souběhu** (D15): `ai.analysis.maxConcurrent` v `server.json`
(výchozí 2), slot = neblokující `flock` na `/opt/shipard/run/ai-analysis-<n>.lock`
(`AnalysisSlots`). Runner bez slotu končí bez claimu. `0` = analýza
v procesu vypnutá (spawn i sweep nic nedělají). Do adresáře zapisuje
i PHP-FPM worker (spawn z requestu) — vlastní ho shipard-user, pod kterým
pool běží; nezapisovatelný adresář zapíše chybu do logu při každém běhu.

**Příprava příloh** (D16, `AttachmentPreparer`): přílohy jako `/payload`
(bez `raw_source_attachment`, bez smazaných); ZIP se rozbalí jednu úroveň
(vnořený ZIP se přeskočí, soubory dědí `ndx` rodiče a název
`<zip>/<vnitřní název>`); třídění na PDF / obrázek (`jpeg`, `png`, `gif`,
`webp`) / text (`text/*`), u `application/octet-stream` odhad podle
přípony; text se dekóduje `utf-8` → `utf-16` (jen s BOM) → `windows-1250`
→ `latin-1`. Limity jsou konstanty: 20 příloh, 30 MB v požadavku
(priorita PDF > obrázek > text, při stejné prioritě větší dřív, zachované
přílohy v původním pořadí), nad 10 MB na přílohu jen varování. Příloha
`.eml` / `message/rfc822` se **nerozbaluje** — přeskočí se jako
nepodporovaný typ.

**Prompt** (D17, `PromptRenderer`): `prompt_template` profilu vykreslí Twig
v sandboxu (`AnalysisPromptPolicy`: tagy `if`, `for`, `set`; filtry
`length`, `default`, `join`, `trim`, `lower`, `upper`; žádné funkce,
metody ani vlastnosti objektů), `strict_variables`, bez autoescape.
Kontext jako u démona: `message` (`subject`, `sender_email`, `sender_name`,
`received_at`, `body_plain`, `body_html`), `attachments[]` (`ndx`,
`filename`, `mime_type`, `kind`, `size_human`), `output_schema`. Šablony
vznikly pro Jinja2 s `trim_blocks` + `lstrip_blocks`: první konec řádku za
blokovým tagem stříhá Twig sám, `lstrip_blocks` doplňuje renderer nad
zdrojem — výstup je bajtově shodný s démonem.

**Volání modelu a výstup** (D18): jedna zpráva `user` — text promptu, pak
za každou přílohu blok `document` (PDF, base64) / `image` / `text`
(`--- Attachment <název> (#<ndx>) ---`). `max_tokens` kaskádou profil →
backend → 32768, teplota z backendu, streamovaně, `stallTimeoutSeconds =
180`, `timeoutSeconds = 840` (lease 900 s). `OutputParser`: první textový
blok → JSON (přímo, jinak z markdown bloku ```` ```json ````) → validace
proti `output_schema` přes `opis/json-schema` **bez formátů** (démon je
nekontroloval; `source.extractedAt` server přepíše) → objekt na nejvyšší
úrovni. Z chyb validace se bere ta s nejhlubší cestou (jako `best_match`
v Pythonu — u `oneOf` dokumentu vyhraje konkrétní pole) a zpráva má
textový tvar jsonschema, ze kterého `AnalysisErrorPresenter` skládá hlášku
(`output does not match schema: Additional properties are not allowed
('x' was unexpected) at ['document', 'extracted_json', …]`).

**Chyby a opakování** (D18):

| Situace | Zápis | Opakování |
|---|---|---|
| chyba claimu z konfigurace (`NO_PROFILE`, `NO_BACKEND`, `BACKEND_KEY_*`, `SECRETS_UNAVAILABLE`) | žádný — zpráva zůstává ve frontě, jedno varování | sweep každou minutu |
| šablona promptu nejde vykreslit, nevalidní `output_schema`, nepodporovaný provider | `config_error`, stav 70 | ne |
| výstup není JSON / neodpovídá schématu | `schema_error`, stav 70 | ne |
| `stop_reason = max_tokens` | `ai_error` „output truncated at max_tokens=<n>“, stav 70 | ne |
| vyčerpaný měsíční strop útraty (429 s `error.details.error_code = enforced_spend_limit_reached`, bez `retry-after`) | `config_error` se zprávou poskytovatele, stav 70 | ne — API stojí do dalšího měsíce; u hostovaných DS jde o strop společné organizace |
| přechodná chyba (transport, 408, 429, 5xx, `overloaded_error`) | v rámci běhu další dva pokusy po 10 a 60 s (`LlmRetry`); po vyčerpání `ai_error`, stav 10 | nejvýš třikrát za hodinu (počítají se selhané běhy zprávy), pak stav 70 |
| jiná chyba API (400, 401, 404) | `ai_error`, stav 70 | ne |
| lease nešla prodloužit / claim expiroval před zápisem | žádný — jen varování včetně ceny | reaper už zprávu vrátil do fronty |
| nečekaná výjimka (DB, disk) | žádný — claim se nechá vypršet | reaper + sweep |

**Cena** (D19): `cost_usd` počítá `Shipard\Core\Ai\AnthropicPricing`
z tabulky cen podle nejdelšího shodného prefixu ID modelu (ID z odpovědi
streamu, fallback model backendu); neznámý model = 0 a varování. Dočasné —
ceny převezme katalog modelů (#85 fáze 1).

**Třídy:** `modules/core/mail/src/Analysis/` — `AnalysisStates`,
`AnalysisQueue`, `AnalysisClaimService` (+ `AnalysisClaim`,
`AnalysisClaimException`), `AnalysisResultWriter` (+ `AnalysisResultException`),
`AnalysisServices` (wiring), `AttachmentPreparer` (+ `RawAttachment`,
`PreparedAttachment`), `PromptRenderer` (+ `AnalysisPromptPolicy`,
`PromptRenderException`), `OutputParser` (+ `SchemaValidationException`),
`AnalysisSlots` (+ `AnalysisSlot`), `AnalysisRunner`, `AnalysisSpawner`,
`AnalysisRunnerFactory`; CLI `src/Command/DataSource/MailAnalyzeCommand.php`
([docs/cli.md](../../../../docs/cli.md) § `mail-analyze`).

## Šifrování API klíčů backendů

`core_ai_backends.api_key` je sloupec typu `encrypted_text` (viz
[docs/operations/secrets.md](../../../../docs/operations/secrets.md)).
`AIBackendDocument::beforeSave()` šifruje hodnotu přes `DsSecretCipher` při
dirty change; `AnalysisController::claim()` ji decryptuje a vkládá plaintext do
JSON response s `Cache-Control: no-store, no-cache, must-revalidate`.

**Bezpečnostní invarianty (CLAUDE.md "Citlivá data" + spec §10 dec.2):**

- Plaintext nikdy neleží v DB.
- Plaintext nikdy nejde do logu — `claim()` catch maskuje výjimku fixní zprávou
  a detail loguje pouze server-side přes `error_log()`.
- Empty submit do form fieldu `api_key` → `AIBackendDocument::beforeSave` ho
  unsetne (UPDATE nepřepíše ciphertext).
- Bez injektovaného `DsSecretCipher` Document hodí výjimku → CLI / API nikdy
  silently nezapíše plaintext.

## Stavy zprávy

Zpráva má **dvě ortogonální osy** (spec
[tasks/mail-states-and-classification.md](../../../../tasks/mail-states-and-classification.md)):

**Workflow `docState`** (`core.mail.docStatesIncoming`) — stav pro uživatele,
srovnaný se zbytkem aplikace. Pipeline na něj sahá jediným místem: result
s dokumentovým návrhem posune Novou na K řešení. Do Hotovo zprávu posouvá
verdikt uživatele (apply/reject návrhu).

| Kód | cs | mainState | viewGroup | Poznámka |
|---|---|---|---|---|
| 10 | Nová | 1 | active | výchozí |
| 20 | K řešení | 2 | active | nastavuje result s dokumentovým návrhem, nebo ručně |
| 40 | Hotovo | 3 | active | readOnly; nastavuje verdikt (apply i reject), unapply vrací na 20 |
| 80 | Archiv | 4 | archive | readOnly |
| 90 | Smazáno | 5 | trash | readOnly |

**Pipeline `analysis_state`** (`core.mail.analysisStates`) — status AI
analýzy, přežívá Koš i Archiv; řídí ho výhradně pipeline + reanalyze:

```
0 (Bez analýzy)   koncový — AI vypnutá / netýká se (importy v Hotovo)

10 (Ve frontě) ──claim──▶ 20 (Analyzuje se) ──result──▶ 30 (Analyzováno)
                               │                              │
                               ├─failed retryable / reaper─▶ 10
                               │                              └─reanalyze─▶ 10
                               └─failed permanent─▶ 70 (Analýza selhala) ─reanalyze─▶ 10
```

`analysis_state=20` (aktivní claim) drží read-only zámek formuláře —
`IncomingMessageDocument::validate()` odmítne uložení s form-level chybou
`analysis_in_progress`. Přechody `docState` (Koš/Archiv) fungují i během
analýzy; fronta zprávy v Archivu/Koši přirozeně vynechává.

Nová zpráva dostává `analysis_state=10`, pokud vzniká v docState 10 nebo 20
(Nová/K řešení; chybějící docState = default Nová), analýza není vypnutá
a existuje aktivní AI profil; jinak 0. Zda je analýza vypnutá, se dědí
s precedencí **zpráva > schránka > default povoleno**: explicitní
message-level `ai_analysis_enabled` (true/false) rozhoduje vždy; při `NULL`
rozhoduje flag schránky `mailboxes.ai_analysis_disabled`; obojí neurčené =
povoleno. Stejnou precedenci vynucuje `/queue` (výdej i `total_available`) —
zprávu z vypnuté schránky nevydá, ledaže má `ai_analysis_enabled=1`, takže
vypnutí schránky působí okamžitě i na už nafrontované zprávy.
Zprávy vznikající rovnou v Hotovo/Archivu/Koši (import, zrcadlení archivní
pošty) se nefrontují — `/queue` by je nikdy nevydal (trvale zavádějící
„Ve frontě") a hrozila by hromadná analýza při odarchivování. Explicitní
`analysis_state` v requestu (`POST /_mail/import`) má vždy přednost.
Dříve nafrontované archivní zprávy opravuje idempotentní datový krok
v `ds-upgrade` (`AIAnalyzerProvisioner::fixQueuedArchivedMessages` —
jen docState 80/90; Hotovo do opravy nepatří, tam mohla zpráva dojít
legálně workflow cestou s dokončenou analýzou).

## Dokumentový návrh a verdikt (resolution)

Zpráva má **nejvýše jeden otevřený dokumentový návrh**: canonical poslední
úspěšné analýzy (`canonical_json` na řádku `core_mail_message_analyses`,
konvence `MAX(analyzed_at), status=2` — žádný flag). Typ návrhu nese
`proposed_type` (klíč `core.mail.primaryTypes`) — historický záznam běhu,
na rozdíl od mutable `message.primary_type` se po zápisu nemění.

**Verdikt uživatele** se zapisuje na řádek analýzy — sloupec `resolution`
(cfgItem `core.mail.analysisResolutions`):

| Kód | Význam |
|---|---|
| NULL | otevřený návrh / běh bez návrhu |
| 40 | `applied` — návrh byl použit, cílová entita existuje |
| 50 | `rejected` — uživatel zamítl (`rejected_reason` povinný) |

K verdiktu patří `resolved_at` / `resolved_by`. Kódy jsou záměrně shodné
s někdejšími stavy extrahovaných dokumentů (tabulka
`core_mail_extracted_documents` zanikla — D2).

**Confidence pásma nejsou perzistentní stav.** Pásmo `ready` / `review` /
`low` počítá za běhu `AnalysisConfidenceResolver` z `confidence` běhu vs.
thresholds profilu (`confidence_thresholds`, fallback
`{"ready": 0.9, "review": 0.6}`), se stropem podle pokrytí řádků — viz
„Obohacení řádků z historie" níže. Používá ho dashboard (kind karty),
detail zprávy (badge) a preview; nikam se nezapisuje.

## Obohacení řádků z historie (Row History Enrichment)

Deterministická vrstva 0 „AI párování položek" — `RowHistoryEnricher`
(`modules/core/exchange/src/Enrich/`). Řádkům canonical dokumentu bez
`item.ourCode` doplní trojici `item.ourCode` + `vat.code` + `account`
podle řádků dřívějších dokladů (docState 20/40) téhož partnera a
`doc_type`. Bez šablon, bez LLM — paměť jsou finalizované doklady
v `docs_core_rows`.

Klíčová rozhodnutí (D1–D9, detailně `tasks/row-history-enrichment.md`):

| | |
|---|---|
| Doplňuje se | jen prázdná pole; priorita userAction pin > AI extrakce > historie |
| Historie | řádky partnera + doc_type, docState IN (20, 40), item živý (10/40/80), nejnovější první, limit 200 |
| Match popisu | exact raw → exact normalizovaný (bez číslic/interpunkce) → Jaccard token-set ≥ 0.6; první zásah vyhrává |
| Dominantní položka | úroveň 3, fallback bez textového signálu (`tasks/enrichment-dominant-item.md`): historie ≥ 10 řádků a jedna položka ≥ 80 % z nich → `historyDominantItem` / `low`, trojice z nejnovějšího výskytu. Guard přes částku: total řádku > max historických `total_price` dominantní položky → bez návrhu (chybějící total řádku → guard se neuplatní) |
| Běží | `/result` (persist do `canonical_json`), `GET /_mail/messages/{ndx}/preview` (fresh), `apply` (fresh, před merge userActions) |
| Selhání | nikdy neshodí endpoint — log + neobohacený canonical |

Fresh běh je idempotentní: vlastní dřívější návrhy (poznané podle
`enrichment.suggested` == aktuální hodnota) nejdřív odvolá a matchuje
znovu proti aktuální DB. `DocumentApplier::withResolve()` přenáší
enrichment blok přes fresh resolve per row index.

**Ruční volba položky v review** (`rows[i].item = useExisting:<id>`,
#111 D7b) účet navržený historií nebo obsahovým štítkem odvolá: účet jde
s položkou (účetní položka → její účet, služba / zásoba → maska
kategorie), takže `DocumentApplier::reconcile()` účet řádku, jehož číslo
se shoduje s `enrichment.suggested.account`, na řádek nezapíše. Účet od
uživatele nebo z AI zůstává; kód DPH se při změně položky nemění (D7a).
Náhled ukazuje účet, podle kterého se řádek zaúčtuje
(`_resolve.rows[i].effectiveAccount`, `docs/exchange-format.md` §9).

Audit per řádek v `_resolve.rows[i].enrichment` (žádná změna schématu,
`_resolve` má `additionalProperties: true`); blok se zapisuje vždy,
i pro nenapárované a přeskočené řádky:

```jsonc
{
    "matchedBy":  "historyExactRaw" | "historyExactNorm" | "historyFuzzy" | "historyDominantItem" | null,
    "confidence": "high" | "medium" | "low" | null,
    "matchedText": "…",                            // vyhrávající kandidátní text (null u dominance)
    "itemName":   "Materiál",                      // jméno navržené položky (pro UI tooltip)
    "sourceDocId": 12345,                         // docs_core_heads.id zdroje
    "sourceDocNumber": "FP-2026-0042",            // doc_number zdroje (pro UI badge)
    "suggested":  { "ourCode": "…", "vatCode": "…", "account": "…" },  // co reálně doplnil
    "dominance":  { "share": 0.94, "rows": 179 }, // jen u historyDominantItem (podklad tooltip badge)
    "skipped":    "hasOurCode" | "noItemRow"      // jen u přeskočených řádků
}
```

**Strop pásma (D7):** `AnalysisConfidenceResolver::capBandByRowCoverage`
sníží runtime pásmo `ready` na `review`, pokud po enrichmentu existuje
item řádek bez `item.ourCode`, **nebo** řádek doplněný enrichmentem
s confidence `low` (dominance nemá textový signál — uživatel návrh
potvrzuje, viz `tasks/enrichment-dominant-item.md` D5). Kontační řádky
(`acc.record` / `accSide`) položku nemají validně — stropu se netýkají.
Strop se počítá za běhu při každém čtení (feed, detail, preview) — nic
se nepersistuje.

**Zpětný zápis supplier codes (D8):** `SupplierCodeCaptureHandler`
(registrace `documentEventHandlers` v module.jsonc) při přechodu dokladu
10 → 20 s lineage `aiExtraction` zapíše `INSERT IGNORE` do
`economy_items_supplier_codes` mapování z canonical řádků s extrahovaným
`item.supplierCode`, kterým finální řádek přiřadil položku. Doplňuje
apply-time zápis `DocumentApplier::writeSupplierCodeMappings` (ten pokryje
řádky vyřešené už při apply, handler ruční přiřazení v Konceptu); překryv
řeší unique index `(person, supplier_code)`. Párování canonical → finální
řádky je poziční přes `order_pos` s guardem na shodu popisu. Jedinou
cestou, která naučené mapování **přepíše**, je výslovná volba položky
v review (`useExisting:<id>` u řádku se `supplierCode`, #111 D8 —
`ON DUPLICATE KEY UPDATE` v apply); automatické napárování i handler
z Konceptu dál jen `INSERT IGNORE`.

## Obsahová eskalace (content tags)

Vrstva 2 „AI párování položek" (`tasks/content-tag-enrichment.md`,
D1–D22) — nastupuje, když po Vrstvě 0 zbývají item řádky bez
`item.ourCode`. Orchestruje `RowEnrichmentPipeline`
(`modules/core/exchange/src/Enrich/`), který nahrazuje přímé volání
`RowHistoryEnricher` ve všech cestách — od #81 i v [deterministickém
ISDOC importu](#deterministický-isdoc-import) (ISDOC nese strukturované
položky dodavatele, ne naše; řádek bez historie partnera potřebuje
zařazení stejně jako u AI extrakce).

**Flow:**

1. Vrstva 0 (`RowHistoryEnricher::enrich`) — beze změny.
2. Zbývá nepokrytý item řádek? Ne → konec.
3. **Pravidlo IČO → štítek** (`core_exchange_tag_rules`, tableId 438):
   zásah přeskakuje LLM, persistuje se
   `_resolve.contentTag {tag, tagSource: "rule", ruleId}`.
4. Jinak **LLM klasifikace** (`ContentTagClassifier`, jen při `/result` —
   právě jednou za běh analýzy): doklad se klasifikuje do fixní taxonomie
   `core.exchange.contentTags` (prompt `tag-v1.0.0`, enum generovaný
   z cfgItem, digest = supplier + popisy řádků + totals). Persist
   `{tag, tagSource: "llm", tagConfidence, promptVersion, rowExceptions}`;
   `null` štítek je legitimní výstup (nic se nezapisuje). Selhání LLM
   nikdy neshodí `/result`.
5. **Resolution štítek → položka** (`ContentTagResolver`, fresh při každém
   čtení): tag řádku = `rowExceptions[rowIndex] ?? primaryTag`; právě
   jedna živá položka s tagem v `economy_items.content_tags` → trojice
   {ourCode, account}; více → `ambiguous`; žádná → fallback účet z nabídky
   účetních položek aktivní varianty osnovy (`AccountingItemsOffer`,
   prefix fallback `vehicle.*`) → `accountOnly`; ani ten → `unmapped`.
   `amountGuard` z `economy.items.contentTagDefaults` návrh zadrží
   (`guarded`, možný dlouhodobý majetek). Propisují se jen prázdná pole.

Fresh běh (preview/apply) LLM nevolá — **fresh re-check pravidla přepíše
persistnutý LLM štítek** (deterministika bije odhad, D16); otagování
položky mezi analýzou a preview se projeví bez reanalýzy. Štítek se navíc
denormalizuje do sloupce `core_mail_message_analyses.content_tag`.

Audit per řádek (`_resolve.rows[i].enrichment`):

```jsonc
{
    "matchedBy":  "contentTag",
    "confidence": "medium" | null,        // medium jen když se něco propsalo
    "tag":        "vehicle.fuel",
    "tagSource":  "rule" | "llm",
    "itemName":   "Spotřeba PHM",         // jen resolution item
    "sourceItemId": 123,                  // jen resolution item
    "suggested":  { "ourCode": "…", "account": "…" },  // co reálně doplnil
    "resolution": "item" | "accountOnly" | "ambiguous" | "unmapped" | "guarded",
    "candidates": ["FUEL-A", "FUEL-B"],   // jen ambiguous
    "guard":      "amount",               // jen guarded
    "vatHint":    "nonDeductible",        // informativní (D4), z contentTagDefaults
    "tagLabel":   "Pohonné hmoty"         // jen fresh čtení (jazyk requestu, D23);
                                          // persist z /result label nenese
}
```

**Precedence (D13):** setting `exchange.contentTag.beforeDominance`
(SettingsStore, default `true`) — contentTag má přednost před dominancí
(tier 3 Vrstvy 0 běží až jako úklid po eskalaci); `false` = stávající
pořadí. Backend LLM klasifikace jde přes setting
`exchange.contentTag.backend` (ndx `core_ai_backends`, null = default
backend; doporučení: levný model). Obojí zatím jen přes `ds-setting set`.

**UI (tasks/content-tag-ui.md, D23–D28):**

- **Review modal**: podmíněný sloupec Účet (render, když aspoň jeden řádek
  `account` nese), badge tooltip „Obsahová klasifikace — {tagLabel}
  (pravidlo dodavatele / AI)" + řádek vatHint. Row-level userAction
  **`noItem`** („Jen účet — bez položky", stringová akce jako `skip`):
  klient ji nabídne, jen když řádek nese `account`; `DocumentApplier`
  reconcile pořídí řádek bez item FK s účtem, chybějící účet = 422
  `no_item_requires_account`; pin přebíjí fresh resolve i enrichment
  návrh (D3). Response označí řádek `_resolve.rows[i].item.status =
  "noItem"`.
- **Dashboard karta „Nová kategorie"** (`ContentTagSuggestionsSource`,
  `modules/core/exchange/src/Dashboard/`): jedna karta per štítek
  s otevřenými návrhy bez živé otagované položky; akce
  `materialize_content_tag` volá `POST /_exchange/content-tags/materialize`
  (sdílená služba `AccountingItemMaterializer`). `goods.stock` nabízí
  volbu materiál (501…) / zboží (504…); štítky vědomě bez mapování
  (admin.other…) nekartují. Query-driven, bez dismiss stavu.
- **Nastavení → Položky → Obsahové štítky** (panel `contentTags`,
  `ContentTagsSettings.svelte`): stav mapování taxonomie + Založit per
  štítek (`GET /_exchange/content-tags/overview`), reverzní otagování
  neotagovaných položek s jednoznačným účtem
  (`POST /_exchange/content-tags/tag-items`), odkaz na setup nabídku.
- **Nastavení → Položky → Pravidla obsahových štítků**
  (`core.exchange.tagRules` viewer + `TagRuleDocument`): label štítku,
  IČO + jméno partnera, origin, statistiky; přeštítkování formulářem
  přepne `origin` na `user`, smazání = hard DELETE (detail akce) —
  koš by přes unique(company_id) blokoval re-learning.

**Strop pásma (D14):** řádek doplněný s `matchedBy: "contentTag"` stropuje
pásmo na `review` vždy — obsahový návrh potvrzuje člověk.

**Learning (D22):** `ContentTagRuleCaptureHandler` (registrace
`documentEventHandlers` v `core.exchange/module.jsonc`) při vstupu
dokladu do V pořádku — z Konceptu (10 → 40) i rovnou při „Vystavit
a uzavřít“ (0 → 40); oprava 80 → 40 ne — s lineage `aiExtraction`
a LLM štítkem zapíše pravidlo
IČO → štítek (origin `learned`, platné okamžitě). Shoda s existujícím
pravidlem → jen statistiky; konflikt s `learned` pravidlem → pravidlo se
smaže (dodavatel s pestrým sortimentem); `user`/`seed` pravidla learning
nikdy nemění. Doklad, jehož LLM blok nese neprázdné `rowExceptions`
(nájem + energie + parkovné), pravidlo **neučí** vůbec — ani INSERT, ani
statistiky, jen `info` log; dokument-wide pravidlo by výjimky zahodilo,
takový dodavatel jde vždy přes LLM (tasks/content-tag-row-exceptions.md
D2). Ruční a seedovaná pravidla se uplatňují dokument-wide dál (D2b).

**Seed z účetní historie:** třetí zdroj pravidel `IČO → štítek` (kromě
ruční správy a learningu) je import agregované účetní historie ze
zdrojového systému — `shpd-ds booking-history --apply-seed`, origin
`seed`. Nad tímtéž souborem stojí i report o kvalitě zdroje a o taxonomii
(pokrytí, konzistence LLM × reverz, mrtvé štítky) a reverzní otagování
položek podle účtů. Formát souboru, prahy seedu a chování příkazu:
[`docs/booking-history-format.md`](../../../../docs/booking-history-format.md).

## Zaokrouhlení celkové částky při apply

Faktury se zaokrouhlenou částkou k úhradě (typicky na celé Kč):
`DocumentApplier::transform` odvozuje `total_rounding_mode` dokladu
nezávisle z čísel — porovnáním vypočtené částky (Σ `vatRecap[].total` →
`totalBase + totalVat` → Σ řádků s DPH) s deklarovanou
`totals.totalAmount`. Extrahovaný `totals.totalRounding` je jen
informativní (review modal ho zobrazuje v součtech). Konzervativní
kritérium a detaily: `docs/exchange-format.md` (sekce vatRecap/totals)
a [tasks/mail-invoice-rounding.md](../../../../tasks/mail-invoice-rounding.md).
Platí i pro ISDOC větev (`PayableRoundingAmount`) — apply jde přes týž
applier.

## Režim výpočtu DPH při apply (derivace vat_mode)

U dokladů s řádky v koncových cenách (účtenky, PHM, maloobchod) AI
vrací `vat.mode: "fromBase"`, ačkoli `rows[].totalPrice` už daň
obsahuje — počítat DPH zdola by ji na doklad dalo podruhé.
`DocumentApplier` proto `vat_mode` hlavičky deterministicky derivuje
(`VatModeDerivation`): sedí-li Σ řádků právě na Σ `vatRecap[].total`
(a ne na base), nastaví režim „shora" (fromTotal) bez ohledu na
deklarovaný mode — a zrcadlově. Korekce se v review modalu zobrazí
jako warning **`vat_mode_derived`** v `_resolve.issues` (preview
i apply). Když derivace nemá dost dat (chybí recap i `totalBase`),
validátor místo toho warnuje **`vat_mode_suspect`**. Prompt (od v4.1.0,
zpřesněno ve v4.2.0) navíc učí model mode u koncových cen vracet rovnou
správně — derivace zůstává pojistka. Integritu řádků a rekapitulace
hlídají validátorové warningy **`rows_recap_mismatch`** (neúplné řádky)
a **`vat_recap_inconsistent`** (rekapitulace dopočtená místo opsaná) —
viz `docs/exchange-format.md` a
[tasks/ai-extraction-integrity.md](../../../../tasks/ai-extraction-integrity.md). Detaily: `docs/exchange-format.md` (sekce vat)
a [tasks/docs-vat-mode-derivation.md](../../../../tasks/docs-vat-mode-derivation.md).

**Zdroj dat neplátce DPH:** přijatý doklad, ke kterému k datu dokladu
neplatí žádná registrace DPH, vznikne vždy „Bez DPH“ a daň dodavatele je
součástí cen řádků (z ISDOC `computed.vatTotal`, jinak ze sazby řádku,
s dorovnáním na rekapitulaci dodavatele). Derivace režimu pak říká jen,
v jakých cenách řádky přišly; místo `vat_mode_derived` se hlásí info
**`vat_non_payer`**. Platí pro AI i ISDOC větev — viz
`docs/exchange-format.md` § 8.4 „Přijatý doklad neplátce DPH“
a [tasks/exchange-received-non-vat-payer.md](../../../../tasks/exchange-received-non-vat-payer.md).

## Pohyb řádků při apply (doplnění operation)

AI pohyb řádku (`rows[].operation`) záměrně nevrací — je to interní
účetní koncept, na předloze není a prompt pro něj pravidlo nemá. Aby
koncept z Použít prošel na stav V pořádku bez ručního doplňování,
`DocumentApplier` doplní item řádkům pohyb podle typu resolvované /
založené položky, jinak výchozím pohybem docTypu (cfgItem
`docs.core.applyRowOperations`). Doplnění je **tiché** — probíhá na
každém item řádku každého apply, takže info hláška by svítila vždy
a degradovala pozornost pro vzácné hlášky; transparentnost dává sám
výsledek (sloupec Pohyb konceptu). Do `_resolve.issues` jde jen
warning `row_operation_config_invalid` při rozbité konfiguraci
(neexistující/nepovolený kód). Detaily: `docs/exchange-format.md` §10
„Doplnění pohybu řádků"
a [tasks/mail-apply-row-operation.md](../../../../tasks/mail-apply-row-operation.md).

## Message-centrické akce (apply / reject / unapply / preview)

Akce nad dokumentovým návrhem operují nad **poslední úspěšnou analýzou**
zprávy; jádro je HTTP-agnostická služba `MessageProposalApplier` (sdílí ji
HTTP controller i MCP nástroj `mail_draft_document`), výsledek nese
`ProposalApplyOutcome`. Guardy: zpráva mimo Archiv/Koš, `analysis_state=30`,
otevřený návrh (`resolution IS NULL`). Endpointy (detailně
[docs/mail/api-contract.md §9.8–9.13](../../../../docs/mail/api-contract.md)):

- **`GET /_mail/messages/{ndx}/preview`** — read-only náhled návrhu pro
  review modal: canonical + fresh enrichment + `applier->preview()`
  (registry větev vrací canonical přímo). Přílohy v response = **všechny**
  obsahové přílohy zprávy (D10). `userActions` = uložená rozhodnutí
  z review (flat mapa, nebo `{}`) — ve všech větvích odpovědi; modal jimi
  předvyplní stav.
- **`POST /_mail/messages/{ndx}/apply`** — routing dle `proposed_type`
  běhu přes `PrimaryTypes::targetFor()`: docs → exchange `DocumentApplier`
  se server-side injection `source.kind='aiExtraction'` +
  `source.message={ndx}`; registry → `RegistryApplier` (záznam dostává
  všechny obsahové přílohy zprávy, D5). Obě větve zapíšou obě strany
  lineage atomicky (doklad `source_message` ↔ zpráva `target_table_id` /
  `target_row`); pak verdikt `resolution=40` + zpráva → Hotovo (z 10 i 20).
  Recovery/idempotence: obsazený `message.target_row` = opakovaný apply
  jen dokončí zbytek (nevzniká duplicitní entita).
- **`POST /_mail/messages/{ndx}/reject`** — povinný `reason`;
  `resolution=50` + `rejected_reason` + `resolved_at/by`; zpráva → Hotovo
  (symetricky s apply, uživatel může následně Koš/Archiv).
- **`POST /_mail/messages/{ndx}/unapply`** — reverz apply (bez UI, MCP/API
  záchranná brzda): cílová entita → Koš (docs guard: stále nedotčený
  Koncept, jinak 409 `DOC_ADVANCED`), `message.target_*` → NULL,
  `resolution/resolved_*` → NULL, zpráva 40 → 20.
- **`POST /_mail/messages/{ndx}/decisions`** — průběžné ukládání
  rozhodnutí z review modalu (badge popovery, #76). Body `{"_resolve":
  {cesta: userAction}}` — vždy **celá** mapa (last-write-wins), tvar shodný
  s `_resolve` v apply. Guardy jako reject; sanitizace přes whitelist cest
  sdílený s `expandUserActions` (neznámé cesty a ne-string hodnoty tiše
  zmizí). Ukládá se do `user_actions_json` na řádek **analýzy** (prázdná
  mapa = NULL); po verdiktu se nemaže (záznam, co uživatel rozhodl),
  reanalýza = nový řádek bez rozhodnutí. Odpověď vrací uloženou mapu.
  One-click apply z feedu a MCP `mail_draft_document` uložená rozhodnutí
  **nečtou**. Viz
  [tasks/mail-review-decisions-persist.md](../../../../tasks/mail-review-decisions-persist.md).

## Klasifikace typu zprávy (message_classification)

Analyzer v prvním kroku klasifikuje zprávu jako celek a vrací top-level
pole `message_classification: {primary_type, confidence, title}` v `POST
/result` — od kontraktu v4 **povinné** (422 při absenci; prompt v4 ho vždy
generuje). `title` je volitelný lidský titulek zprávy (od promptu v4.3.0,
viz [Titulek zprávy](#titulek-zprávy-ai_title)). Server v transakci resultu zapíše `primary_type` +
`primary_type_source='ai'` — **jen pokud** `primary_type_source != 'user'`
(ruční volba uživatele má vždy přednost; nastavuje ji dirty-change detekce
v `IncomingMessageDocument::beforeSave`). Neznámý typ = warning + ignore,
uložení výsledku se nikdy nerozbije.

Dokument s `doc_type='other'` neexistuje — zpráva bez dokladu i dokumentu
Spisovny vrací `document: null` + klasifikaci `other`; dashboard pak emituje
info kartu ostatní pošty s titulkem z `ai_title` a akcemi Koš/Archiv (viz
docs/dashboard.md). Má-li odesílatel potvrzené pravidlo a klasifikace má
`confidence` aspoň na `review` prahu profilu, zprávu místo karty rovnou
archivuje `PostAnalysisDisposer` (viz výše); bez `confidence` zůstává
v Ostatních.

**Pozornost u zprávy bez dokladu** (od promptu v4.7.0, volitelná pole nullable od v4.7.1,
[tasks/mail-other-attention.md](../../../../tasks/mail-other-attention.md)
D1–D3, D7): u `other` klasifikace navíc nese `attention` — `action`
(vyžaduje akci nebo rozhodnutí: expirace domény, výzva k platbě, upomínka,
žádost), `info` (potvrzení, stav objednávky, notifikace, doručenka, sken
obálky), `promo` (newsletter, leták, nabídka) —, u `action` větu
`action_note` („co udělat", ≤ 200 znaků) a `due_date` (jen lhůta výslovně
ve zprávě, model ji neodhaduje), a protistranu `party {name, companyId,
email}` — od koho zpráva skutečně je, ne kdo ji přeposlal (přes 70 %
pošty chodí přes skupinu). Server je zapíše tímtéž UPDATE jako typ do
sloupců `attention` / `action_note` / `action_due` (cfgItem
`core.mail.attentionKinds`; neznámá hodnota = warning + NULL; mimo `action`
poznámka i lhůta NULL; u dokladu a dokumentu Spisovny vše NULL — reanalýza
`other` → faktura starou poznámku smaže; ruční volba typu `user` se
nedotkne). Dashboard z nich staví sekci **K vyřízení** (`action`:
poznámka, badge lhůty, akce Vyřízeno = archiv) a Ostatní (`info` /
`promo` / NULL) s hromadným Archivovat vše; pravidlo `archiveIfOther`
akční zprávy vynechá (D6). `promo` se zatím v UI od `info` neliší — sbírá
se pro pozdější rozhodnutí o auto-koši newsletterů. Bez backfillu —
čekající řádky Ostatní si uživatel reanalyzuje nebo odklidí po staru.
Ostatní nálezy
vedle primárního dokumentu (smlouva v příloze faktury apod.) vrací analyzer
jako informativní `secondary_findings` (`{type, note}`, D7) — žijí jen
v `analysis_json`, žádné entity, žádný stav; UI je ukazuje jako hint na
kartě a v detailu zprávy.

Enum typů v promptu i output_schema je zatím natvrdo; generování
z `primaryTypes.jsonc` je future work.

## Partner zprávy (partner_person / partner_name)

Spec [tasks/mail-message-title-partner.md](../../../../tasks/mail-message-title-partner.md)
(D4, D5, D7, D8). Zprávy ze skeneru nebo ruční nahrání nesou technického
odesílatele (`sender_person` = kolega / zařízení), nikdy dodavatele. Partner
zprávy je proto samostatný pojem — **protistrana dokumentu**, ne odesílatel:

- `partner_person` — FK na `base_persons_persons`, ručně editovatelný ve
  formuláři (lookup Osoby);
- `partner_name` — jméno protistrany z canonicalu (`supplier.name` u docs,
  `party.name` u registry), denormalizace pro fulltext a pro partnery, kteří
  v Osobách nejsou. Po Použít zůstává snapshotem toho, co bylo na dokladu
  (může znít jinak než `full_name` Osoby — zobrazení preferuje Osobu).

Plní se ve dvou vrstvách, obě řeší `MessagePartnerWriter`
(`modules/core/mail/src/`) resp. `MessageProposalApplier`:

1. **Vrstva 1 — návrh** (`POST /result` v transakci resultu, hned za
   klasifikací; `IsdocImportService` ve své transakci — ISDOC obchází AI,
   proto si partnera plní sám). Jen z **validního** canonicalu (forenzní
   wrapper se ignoruje). Strana: docs → `supplier` (při `selfParty =
   supplier` `customer`), registry → `party`. **Druhý zdroj — klasifikace**
   (`writeFromClassification`, tasks/mail-other-attention.md D7): u běhu
   bez `document` s typem `other` je protistranou
   `message_classification.party {name, companyId, email}` — provozovatel
   služby, dodavatel, úřad, ne kdo zprávu přeposlal; stejná pravidla
   (jméno dokud `target_row IS NULL`, Osoba jen shodou IČO; e-mail se na
   Osobu nepáruje).
   - `partner_name` se přepíše vždy, dokud `target_row IS NULL`
     (bez jména v canonicalu se ponechá předchozí hodnota, nenuluje se);
   - `partner_person` se zapíše **jen do NULL** a jen dokud `target_row IS
     NULL`, a výhradně při deterministické shodě identifikátorem
     (`PartyResolver::resolve(…, identifiersOnly: true)` nad IČO / DIČ /
     VAT ID). Shoda jménem se nikdy nepoužívá (u tisíců skenů by ukazovala
     špatné partnery), Osoba se nikdy nezakládá.
   - Selhání resolveru nebo UPDATE je jen warning — výsledek analýzy se
     kvůli partnerovi nikdy neztratí.
2. **Vrstva 2 — Použít** (`MessageProposalApplier`): po úspěšném apply se
   `partner_person` přepíše **autoritativně** partnerem založeného záznamu.
   Hodnota se čte z cílového záznamu přes `target_table_id` / `target_row`
   zprávy (`docs_core_heads.partner`, `base_registry_documents.partner`) —
   jednotně pro docs, registry i recovery cestu `completeApplied`; zápis
   jde ve stejné transakci jako verdikt, ale bez guardu na `docState`.
   Cíl bez partnera (účtenka bez dodavatele) partnera zprávy nemění.
   Vrátit (unapply) `partner_person` nesahá.

3. **Třetí cesta — cílový doklad** (`MessageTargetWriter`,
   [tasks/mail-import-partner-title.md](../../../../tasks/mail-import-partner-title.md)):
   zpráva **navázaná na doklad**, která neprošla ani AI, ani ISDOC. Typicky
   import ze starého Shipardu — `MailRunner` posílá `analysis_state = 0`,
   takže zpráva do AI fronty nikdy nevstoupí a `/result` ji nikdy nepotká.
   Fakta si server dopočítá z `target_table_id` / `target_row`, které už
   v payloadu má: `partner_person` = `docs_core_heads.partner`,
   `partner_name` = `full_name` Osoby (u importu je snapshot ze stejného
   jména odvozený, Osoba má lepší pokrytí).
   - `POST /_mail/import` je aplikuje **před** `validate()`, takže zpráva
     vzniká rovnou v cílovém stavu. Volitelná payload pole `partner_person`
     / `partner_name` jsou jen **návrh** (vrstva 1) — u navázané zprávy
     vyhrává doklad bez ohledu na pořadí, u nenavázané se použijí (partnera
     z doclinku dodává runner, ten server odjinud nezjistí).
   - `shpd-ds mail-target-backfill` (`docs/cli.md`) doplní historii:
     import, zprávy aplikované přes Použít před zavedením partnera zprávy
     a zprávy, jejichž doklad se doimportoval později. Zapisuje jen do NULL
     sloupců, takže ruční volbu ani titulek od AI nepřepíše. Tím je **D6
     rodičovského tasku uzavřené** — backfill historických zpráv už není
     odložený.

Pořadí priorit tedy je: ruční volba ve formuláři > Použít / cílový doklad
> návrh. Re-analýza po Použít je blokovaná už v `reanalyze`; guard
`target_row IS NULL` v UPDATE je druhá pojistka (P2).

**Zobrazení** (`IncomingMessagesViewer`, D7): t1 předmět, t2 partner
(`full_name` Osoby přes LEFT JOIN, jinak `partner_name`) s fallbackem na
odesílatele; když t2 nese partnera, odesílatel se přesune do t3 jako
`[Schránka] od: …` (popisek z `core.mail.viewerDetailLabels.labels.from`).
Hlavička detailu: partner · od: odesílatel · schránka · doručeno. Fulltext
prohledává navíc `partner_name` i `full_name` Osoby. Smazaná Osoba řádek
nefiltruje (LEFT JOIN), t2 padá na `partner_name`.

**Dashboard a MCP:** návrhové karty (`MailSuggestionsSource`) berou
`headline.partnerName` přednostně z Osoby zprávy (`partner_person`), pak
z canonicalu, pak ze snapshotu `partner_name`; chybové karty a karty
ostatní pošty mají v subtitle „partner · od: odesílatel". MCP `mail_list_pending`
vrací u položky `partner {name, person}` odděleně od `sender`.

**Dataset** (`MailExporter` / `MailSeeder`): `partnerPerson` je odkaz
stejného tvaru jako `senderPerson` (jméno + identifikátory, na cílovém DS se
páruje jen identifikátorem), `partnerName` řetězec. Backfill historických
zpráv řeší `mail-target-backfill` (třetí cesta výš).

## Titulek zprávy (ai_title)

Spec [tasks/mail-message-title-partner.md](../../../../tasks/mail-message-title-partner.md)
(D1, D2, D3). `subject` je RFC822 hlavička a **nepřepisuje se** — u ručních
zpráv je jedinou stopou vstupu uživatele. Vedle něj žije AI-vlastněný
sloupec `core_mail_incoming_messages.ai_title` (varchar 200):

- **Zdroj:** `message_classification.title` (prompt v4.3.0, ≤ 120 znaků,
  jazyk profilu — „co to je + od koho + částka / číslo", u `other` stručný
  popis obsahu; nikdy název souboru ani opis generického předmětu). Když
  `title` chybí (starší analyzer, P8 — pole není required), server složí
  fallback deterministicky z validního canonicalu:
  `MessageTitleComposer` — docs `{label typu} {docNumber} — {supplier.name},
  {totalAmount} {currency}` (chybějící části vypadnou), registry `title`,
  bez dokumentu `null`. Label typu je z compiled configu **v jazyce AI
  profilu** (profil běhu, jinak výchozí aktivní profil DS; fallback
  výchozí jazyk DS) — `MessageTitleComposer::forDataSource()`. Ne v jazyce
  requestu: intake od mail-routeru `Accept-Language` nenese a bez
  `defaultLanguage` v main.json by titulek vyšel anglicky, zatímco AI píše
  v jazyce profilu. Bez compiled configu se label vynechá.
- **Třetí zdroj — cílový doklad:** u zprávy navázané na doklad, která
  neprošla ani AI, ani ISDOC (import ze starého Shipardu), skládá titulek
  `MessageTitleComposer::fromDocument()` z hlavičky cíle:
  `{label typu} {doc_number} — {partner}, {total_amount} {doc_currency}`.
  Label je z **`docs.core.docTypes`**, ne z `core.mail.primaryTypes` —
  navázané vydané faktury a účetní doklady v mail primaryTypes klíč nemají
  a skončily by jako „Ostatní". Jazyk stejný jako výš (profil DS, ne
  request), zdroj `MessageTargetWriter` při `POST /_mail/import` a při
  `shpd-ds mail-target-backfill`. U zprávy **bez** navázaného dokladu
  titulek nevzniká — není z čeho. Detail:
  [tasks/mail-import-partner-title.md](../../../../tasks/mail-import-partner-title.md).
- **Zápis:** `AnalysisController::applyMessageTitle` v transakci resultu,
  **vždy** (i `null` — re-analýza bez dokumentu titulek smaže), bez guardů
  na `primary_type_source` (uživatel titulek needituje, ve formuláři je
  read-only) i `target_row` (záměr). `IsdocImportService` píše titulek
  z composeru jako primární zdroj (ISDOC nemá AI). Oba zápisy best-effort.
- **Zobrazení (D3):** `IncomingMessageTitle::display(subject, ai_title,
  source_type, patterns)` vrátí `ai_title` místo předmětu jen když je
  předmět **prázdný**, zpráva je **ruční** (`source_type = 1` — pořízení
  v UI i nahrání z dashboardu; předmět je název souboru), nebo předmět
  odpovídá vzoru z cfgItem **`core.mail.genericSubjectPatterns`**
  (`config/genericSubjectPatterns.jsonc`, pole PCRE bez delimiterů,
  case-insensitive/unicode: `^Message from `, `^Scan(ned)?…`, `^Skenov`,
  `^(image|img|doc|dsc)[_-]?\d+`, …; vadný vzor se přeskočí s warningem).
  U běžného e-mailu zůstává předmět — uživatel ho zná ze svého klienta.
  Detekce skenu jde **jen** přes vzory, nikdy přes `source_type` e-mailu
  (skener posílá běžný e-mail, P1). Fulltext prohledává `subject`
  i `ai_title` vždy.
- **Kde se pravidlo uplatňuje:** `IncomingMessagesViewer` (t1 řádku, titulek
  hlavičky detailu; když titulek nahradil předmět, původní předmět je
  v Technických údajích detailu — popisek
  `core.mail.viewerDetailLabels.labels.subject`), `IncomingMessagesForm`
  (`header_info`), `FileFromMessageService` (název registry dokumentu ze
  zprávy), `RegistryDocumentsViewer` (Zdrojová zpráva). Technická místa
  (pravidla předzpracování, název souboru renderu těla, payload analyzeru)
  zůstávají na `subject`. Dashboardové karty (`MailSuggestionsSource`,
  pole `emailSubject`) a MCP `mail_list_pending` (`full_name` položky,
  navíc holé `ai_title`) pravidlo používají také. Karta ostatní pošty
  (`mail_notinvoice:*`) navíc používá `ai_title` **přímo jako titulek**
  (popis obsahu zprávy, nezávisle na pravidle D3) a `emailSubject` vynechá,
  když by titulek jen opakoval — tasks/dashboard-other-row-title.md.
- **Dataset:** `aiTitle` (řetězec) v exporteru / seederu / schématu.

## Deterministický ISDOC import

Když došlá zpráva nese ISDOC, extrahuje se doklad **deterministicky
parserem** místo AI analýzy — ISDOC nese autoritativní strukturovaná data,
LLM extrakce je u něj zbytečná. Specs:
[tasks/mail-isdoc-import.md](../../../../tasks/mail-isdoc-import.md),
[tasks/mail-message-centric.md](../../../../tasks/mail-message-centric.md)
Fáze D (embedded + dedup).

**Detekce kandidátů** (`IsdocImportService`): samostatné přílohy `.isdoc` /
`.isdocx` (ZIP obal) / XML s root elementem `{http://isdoc.cz/*}Invoice`,
**plus ISDOC embedded v PDF** (PDF/A-3 `/EmbeddedFiles`) — extrakce
server-side při intake přes `pdfdetach` (poppler-utils). Chybí-li binárka,
embedded detekce se vypne s jednorázovým warningem do logu, intake nikdy
neselže; přítomnost `pdfdetach` kontroluje `shpd-server doctor`. Embedded
ISDOC je **transientní** — nevytváří se z něj příloha zprávy (nosné PDF na
zprávě je); do canonicalu jde `attachments[]` odkaz na nosné PDF
(kind `original`, ne `structured` — strojová forma je uvnitř).

Datový tok (#81, `tasks/mail-isdoc-content-tags.md`): import běží **vždy
v detached runneru předzpracování** (`mail-preprocess --message`, viz
[preprocess.md](preprocess.md)), ne v HTTP requestu — obsahová eskalace
může volat LLM a nesmí zpomalit intake ani ruční nahrání až 20 souborů.

1. `MailController` (intake `receiveIncoming` i upload `uploadMessages`)
   po commitu transakce zavolá jen `IsdocImportService::detect()` (lazy
   wiring — bez kandidáta `.isdoc`/`.isdocx`/XML/PDF se service nestaví;
   detekce = parse, validace schématu, dedup identitou, **bez zápisu**).
2. `detect() === true` → podmíněný `UPDATE … SET preprocess_state = 10,
   preprocess_log = {plan: [], trigger: 'isdoc'} WHERE preprocess_state = 0
   AND analysis_state IN (0, 10)` + spawn runneru. 0 řádků = analyzer si
   zprávu mezitím claimnul (závod v okně mezi commitem a detekcí, kterou
   může protáhnout `pdfdetach`) — odložení se vzdá, zpráva zůstává jeho.
   `detect() === false` (PDF bez ISDOC, vadný ISDOC, více identit) →
   zpráva jde do AI fronty jako dřív, `preprocess_state` zůstává 0.
3. Zpráva s plánem technického předzpracování jde do runneru rovnou
   (D10) — ISDOC import nad **všemi** obsahovými přílohami (původními
   i vygenerovanými, stažené PDF může nést embedded ISDOC) udělá runner
   po dokončení akcí.
4. Runner (`PreprocessRunner::runIsdocImport`) zavolá `tryImport`, který
   detekci zopakuje — dvojí detekce je záměr (D4), výsledek se přes DB
   nepředává, runner čte přílohy znovu přes `listAttachments`.

Service (`tryImport`):

1. rozparsuje všechny kandidáty (`IsdocReader`, modul `core.exchange`) na
   canonical `shpd.docs.document.v1` se `source.kind='isdoc'`,
2. **deduplikuje identitou** (D8): klíč = element `UUID`, fallback kompozit
   (`ID` dokladu + DIČ/IČ výstavce + datum vystavení). Shodná identita →
   jeden doklad, preference zdroje samostatná `.isdoc` příloha > embedded
   z PDF (deterministické pořadí); přílohy zprávy nedotčeny. Po dedupu
   **≥ 2 odlišné identity** → větev se celá vzdá → AI fronta (zpráva má
   nejvýše jeden návrh, D1 — AI vybere primární + `secondary_findings`),
3. zvaliduje proti schema a obohatí řádky přes
   `RowEnrichmentPipeline::enrichAtResult()` — stejné jako `/result`:
   Vrstva 0 (historie partnera), a zbývá-li item řádek bez položky,
   [obsahová eskalace](#obsahová-eskalace-content-tags) (pravidlo IČO →
   štítek, jinak LLM klasifikace). Běží **před** zápisovou transakcí —
   LLM latence nikdy nedrží zámky. Selhání obohacení není fatální,
   import pokračuje neobohaceně,
4. ve vlastní transakci s `FOR UPDATE` guardem (`analysis_state IN (0, 10)`
   — závod s analyzerem prohrává import) zapíše:
   - záznam do `core_mail_message_analyses` (`status=2`,
     `model_name='isdoc'`, `prompt_version='isdoc'`, `model_version`
     z `@version` XML, cost/tokens NULL, `confidence=1.0`,
     `canonical_json` = canonical návrhu, `proposed_type` dle
     `DocumentType`: 1 → `invoiceReceived`, 2 → `creditNote`,
     `content_tag` = `_resolve.contentTag.tag` přes
     `RowEnrichmentPipeline::contentTagOf()` — týž helper jako `/result`;
     z něj staví karta „Nová kategorie" i učení pravidel) —
     žádná jiná entita nevzniká, návrh čeká na verdikt jako u AI,
   - message: `analysis_state=30`, `primary_type='invoiceReceived'`
     + `primary_type_source='isdoc'` (jen pokud source není `user`),
     docState 10→20 jen pokud je stále 10,
   - partner zprávy (`partner_name` z dodavatele ISDOC, `partner_person`
     při shodě IČO / DIČ) přes `MessagePartnerWriter` — stejná pravidla
     jako u `/result`, viz [Partner zprávy](#partner-zprávy-partner_person--partner_name);
     best-effort, selhání import neshodí,
   - titulek `ai_title` z canonicalu přes `MessageTitleComposer` (u ISDOC
     primární zdroj — žádná AI), viz [Titulek zprávy](#titulek-zprávy-ai_title).

Vztah k frontě: odložená zpráva (`preprocess_state` 10/20) je za gate AI
fronty ([api-contract.md §9.1](../../../../docs/mail/api-contract.md));
úspěšně naimportovaná zpráva se v ní pak **vůbec neobjeví**
(analysis_state přeskočí 10 → 30), analyzer daemon nevyžaduje změny. Když
se import v runneru vzdá (prohraný závod, vadný soubor), runner skončí ve
stavu 30 s `isdoc: none`, gate se otevře a zpráva jde k analyzeru
s `analysis_state` 10 — nikdy se nezasekne. Vadná **samostatná** ISDOC
příloha / nepodporovaný `DocumentType` (zálohové faktury apod.) = celá
větev se vzdá už při `detect()` a zpráva jde normálně do AI fronty (warning
v logu, příjem pošty nikdy neselže); vadný **embedded** ISDOC se naopak jen
ignoruje a pokračuje se zbytkem kandidátů. Selhání spawnu runneru = zprávu
zvedne sweep po 5 minutách (týká se jen ISDOC zpráv, běžná PDF pošta
odložení neprochází).

Degradace bez AI: DS bez AI backendu / API klíče importuje stejně —
classifier v pipeline je null-safe, uplatní se jen Vrstva 0 a pravidlo IČO,
`content_tag` zůstane NULL a návrh vznikne jako dřív (thresholds fallback
`{ready: 0.9, review: 0.6}`). Učení pravidel (`ContentTagRuleCaptureHandler`)
bere i doklady s `source_kind = 'isdoc'` (D2). Bez backfillu — změna platí
pro poštu přijatou po nasazení (D3).

„Znovu analyzovat" (30 → 10) zůstává únikovou cestou k AI, kdyby ISDOC
výsledek nestačil.

## Reanalýza

`POST /api/v1/_mail/messages/{ndx}/reanalyze` — UI akce viditelná v toolbaru
detail panelu, jen když `analysis_state ∈ {30, 70}` a zpráva není v Archivu/Koši.
Volitelný `profile_override_ndx` v body. Logika:

1. Validuj `analysis_state` (30 nebo 70) a `docState NOT IN (80, 90)`.
2. Guard aplikovaného návrhu: zprávu, jejíž poslední úspěšná analýza má
   `resolution=40` a živý target (`target_row` obsazený), reanalyzovat
   nelze — 409, nejdřív unapply.
3. Validuj profile_override (pokud zadán) — musí existovat a být `is_active=1`.
4. UPDATE message: `needs_reanalysis=1`, `profile_override`, `analysis_state→10`.
   `docState` se nemění. Zpráva ze schránky s `ai_analysis_disabled=1` bez
   message-level `ai_analysis_enabled=1` dostane navíc `ai_analysis_enabled=1` —
   explicitní záměr uživatele přebíjí default schránky, jinak by `/queue`
   zprávu tiše nikdy nevydal.
5. Po commitu spawn runneru `mail-analyze --message` (jen je-li zpráva ve
   frontě, viz [Analýza v procesu](#analýza-v-procesu)); bez něj ji do
   minuty dohledá sweep.

Historie analýz se nemění — „aktuální návrh" je implicitně poslední
úspěšný běh, žádný supersede krok neexistuje (koncept `superseded` zanikl).
Reanalýza po rejectu je možná — vznikne nový běh s `resolution=NULL`.
Runner převezme `profile_override` v claimu; analyzer při dalším GET /queue
zprávu uvidí včetně override profilu.

## Chybové hlášky pro uživatele

Selhání analýzy vidí uživatel jako lidskou hlášku — co se stalo, čí je
to chyba a co dělat — místo odznaku „Analýza selhala" a technického
textu (tasks/mail-analysis-error-messages.md, D1–D6). Jeden katalog,
jeden helper, stejná hláška na Dashboardu i v detailu zprávy.

**Katalog** `core.mail.analysisErrorKinds`
(`modules/core/mail/config/analysisErrorKinds.jsonc`): klíč = kategorie,
pole `name` (titulek), `description` (vysvětlení), volitelně `detail`
se zástupnými `{key}` / `{path}`. Rezervovaný klíč `_common` nese popisky
„Co se stalo" / „Co dělat" a dvě varianty hintu. Compiled config je per
jazyk, texty přijdou lokalizované; bez configu anglický fallback v PHP.

| Kategorie | Kdy |
|---|---|
| `schemaAdditionalProperty` | `[schema_error] … Additional properties are not allowed ('<klíč>' was/were unexpected) at [<cesta>]` |
| `schemaTooLong` | `[schema_error] … '<hodnota>' is too long at [<cesta>]` |
| `schemaEnum` | `[schema_error] … '<hodnota>' is not one of […] at [<cesta>]` |
| `schemaInvalidJson` | `[schema_error] fenced JSON is invalid: …` / `output is not valid JSON` / `output JSON must be an object at the top level` |
| `schemaOther` | jiný, neznámý nebo vícenásobný tvar `schema_error` |
| `aiTruncated` | `[ai_error] anthropic: output truncated at max_tokens=<n>` |
| `aiError` | ostatní `[ai_error] …` |
| `configError` | `[config_error] …` |
| `invalidOutput` | úspěšný běh s wrapperem `_validationError` v `canonical_json` (bez error_message) |
| `unknown` | bez prefixu `[typ]`, prázdná hláška, neznámý typ |

**Helper** `AnalysisErrorPresenter` (`modules/core/mail/src/`):

- `fromErrorMessage(?string $errorMessage, ?string $failedPromptVersion)`
  rozebere prefix `[typ]` a u `schema_error` tvar textu z
  `ai_analyzer/schema.py`; `{path}` z Python listu bez obalu
  `document` / `extracted_json`, tečkovaně (`rows.0.vat.code`), `{key}`
  z `('<klíč>' was unexpected)`. Rozbor je záměrně tolerantní — neznámý
  tvar padá do `schemaOther` / `unknown`, nikdy výjimkou (ai_analyzer#1
  plánuje hlásit všechny chyby najednou).
- `forInvalidOutput(?string $failedPromptVersion)` — kategorie
  `invalidOutput`.
- `isReanalysisRecommended(?string $failedPromptVersion)` (**D4**):
  `true`, jen když výchozí aktivní profil (`is_active=1 ORDER BY
  is_default DESC`) nese novější `prompt_version` než selhaný běh
  (`version_compare` po `ltrim('v')`; neparsovatelné `unknown`, `isdoc`,
  null → `false`). Selhané běhy mají `profile` NULL, proto výchozí profil,
  ne profil běhu. Verze profilu se čte jednou per instance.
- Výstup `AnalysisErrorInfo` (readonly): `kind`, `title`, `description`,
  `detail`, `hint` (podle D4), `reanalysisRecommended`, `technical`
  (původní `error_message` — může nést hodnoty z dokladu, zobrazuje se
  jen sbalené v detailu zprávy, na Dashboard nejde).
- `cardDetails(AnalysisErrorInfo)` — řádky „Co se stalo" / „Co dělat"
  pro `details` karty Dashboardu.

**Kde se používá:** tab **Návrh** (`failure` obsahu při
`analysis_state=70`, `proposal.failure` u wrapperu — viz níže), tab
**Analýzy** (sloupec **Chyba**), Dashboard (`MailSuggestionsSource`,
`docs/dashboard.md` §5.1). Nastavení → Analýzy zpráv zůstává technické.
Chyby předzpracování (`preprocess_state=40`) mají vlastní katalog
`core.mail.preprocessErrorKinds` a `PreprocessErrorPresenter`, karta je
sdílená (`FailureCard`) — viz [preprocess.md](preprocess.md) → „Hlášky
pro uživatele" (D6).

## UI detail panelu

`IncomingMessagesViewer` generuje 4 taby (labely z cfgItem
`core.mail.viewerDetailLabels`):

1. **Obsah** — subject, sender, body + sekce obsahových příloh (bez raw .eml)
2. **Analýzy** — historie běhů z `core_mail_message_analyses` (čas, model,
   prompt, confidence, sloupec **Návrh** ano/ne z `canonical_json IS NOT
   NULL`, sloupec **Verdikt** z `resolution`, cost, duration). Sloupec
   **Chyba** nese u selhaných běhů a běhů s wrapperem `_validationError`
   titulek (+ detail) z katalogu hlášek, jinak „—".
3. **Návrh** — dokumentový návrh poslední úspěšné analýzy (nejvýše jeden,
   D1): jedna karta s typem, confidence pásmem z runtime resolveru (resp.
   resolution badge u rozhodnutých), summary z canonicalu, hintem
   `secondary_findings` a akcemi **Použít** (POST
   `/_mail/messages/{ndx}/apply`), **Zamítnout** (modal s povinným
   důvodem, POST `/_mail/messages/{ndx}/reject`) a **Zobrazit detail**
   (review modal nad `GET /_mail/messages/{ndx}/preview`). Bez návrhu
   prázdný stav s klasifikací zprávy. Při `analysis_state=70` nese obsah
   `failure` (hláška posledního selhaného běhu + `analyzedAt`,
   `promptVersion`) — frontend kreslí kartu selhání
   (`FailureCard`, varianta `error`; komponenta sdílená s hláškami
   předzpracování) místo prázdného stavu, klasifikace se
   neposílá; starší úspěšný návrh pod ní zůstává (akce jen ve stavu 30).
   Návrh s wrapperem `_validationError` nese `proposal.failure`
   kategorie `invalidOutput`, stejná komponenta. Předzpracování ve stavu
   40 přidává nad to `preprocessWarning` (viz preprocess.md).
4. **Originál** — raw `.eml` pokud existuje

Řádek vieweru i hlavička detailu zobrazují badge stavu analýzy (label +
stateStyle z `core.mail.analysisStates`; hodnota 0 se nezobrazuje). Toolbar
v detailu obsahuje "Otevřít" (form edit) a "Znovu analyzovat" (podmíněně dle
`analysis_state`, viz Reanalýza).

## Auto-provisioning

Při každém `ds-upgrade` se zavolá `AIAnalyzerProvisioner::provision()`:

1. Systémový uživatel `_ai_analyzer` (idempotentně).
2. Default backend (`backend_id=default`, `provider=anthropic`,
   `model=claude-sonnet-4-5`, `api_key=NULL`, `is_active=0`) — admin doplní
   klíč přes `ai-analyzer-set-key`, čímž `is_active=1`.
3. Default profil (`profile_id=czech_general`) ze šablony
   `profiles/czech_general.jsonc`. Před lookupem běží jednorázový rename
   legacy id `czech_invoices` → `czech_general` (včetně `name`; ds-upgrade
   vypíše `[RENAME]`, po prvním běhu no-op).

Bootstrap je idempotentní — když existuje jiný profil/backend s `is_default=1`,
default *se nepřepíše*; admin zachová svůj override.

## Reference

- [tasks/mail-phase3a.md](../../../../tasks/mail-phase3a.md) — spec protokolu (Fáze 3a)
- [tasks/mail-message-centric.md](../../../../tasks/mail-message-centric.md)
  — message-centrický model (D1–D12): zánik extrahovaných dokumentů,
  resolution, secondary_findings, lineage
- [tasks/mail-states-and-classification.md](../../../../tasks/mail-states-and-classification.md)
  — oddělení `analysis_state` od `docState` + klasifikace `primary_type`
- [tasks/mail-isdoc-import.md](../../../../tasks/mail-isdoc-import.md)
  — deterministický ISDOC import (mapovací tabulka ISDOC → canonical)
- [tasks/mail-analysis-error-messages.md](../../../../tasks/mail-analysis-error-messages.md)
  — lidské hlášky selhané analýzy (katalog, presenter, pravidlo D4)
- [tasks/mail-analysis-inprocess.md](../../../../tasks/mail-analysis-inprocess.md)
  — analýza v procesu (#85 D9–D19): služby, runner, sloty, spouštění, opakování
- [docs/operations/secrets.md](../../../../docs/operations/secrets.md) — DsSecretCipher
- [docs/mail/api-contract.md](../../../../docs/mail/api-contract.md) — API kontrakty
- [ai-prompts.md](ai-prompts.md) — default prompt + customization guidelines
