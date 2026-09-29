# AI prompty — default profil + guidelines pro customizaci

## Default profil `czech_general`

Šablona: [profiles/czech_general.jsonc](../profiles/czech_general.jsonc).
Při prvním `ds-upgrade` z ní `AIAnalyzerProvisioner` vytvoří záznam v
`core_mail_ai_profiles`. Pozdější editace probíhá přímo v DB / UI; soubor
v repu není zdroj pravdy pro běžící DS.

### Pole profilu

| Pole | Popis |
|---|---|
| `profile_id` | Lidský identifikátor (`czech_general`) |
| `name` | UI název |
| `language` | ISO 639-1 (`cs`) — řídí jazyk uživatelských textů v promptu |
| `prompt_version` | SemVer (`v1.0.0`) — manuálně bumpuj při netriviální změně promptu |
| `prompt_template` | Vlastní text promptu pro analyzer |
| `output_schema` | JSON Schema, proti kterému analyzer validuje výstup providera |
| `supported_doc_types` | JSON pole klíčů z `core.mail.primaryTypes` |
| `confidence_thresholds` | `{"ready": 0.9, "review": 0.6}` — prahy runtime confidence pásem návrhu (`ready`/`review`/`low`, počítá `AnalysisConfidenceResolver`; pásmo se nikam nepersistuje) |

Audit běhu: každý `core_mail_message_analyses` row si propíše `profile_ndx`,
`backend_ndx` a `prompt_version`, takže historie je auditovatelná i po pozdějších
změnách profilu.

## Default prompt (v4.4.0)

Od `v4.0.0` je analýza **message-centrická**
([tasks/mail-message-centric.md](../../../../tasks/mail-message-centric.md)
D1/D11): analyzer zpracovává zprávu **jako celek** — subject, tělo
i přílohy jsou jeden kontext, tělo zprávy je plnohodnotný zdroj dat
(platební instrukce, faktura přímo v textu, úřední obsah). Výstup:

- **právě jedna `message_classification`** (`{primary_type, confidence,
  title}`) — povinná, server ji vynucuje (422); `title` je volitelný
  krátký titulek zprávy (≤ 120 znaků, od v4.3.0) — bez něj server složí
  fallback z canonicalu (`MessageTitleComposer`),
- **nejvýše jeden `document`** — *primární* dokument zprávy. Kritérium
  primárnosti: business dokument, kvůli kterému zpráva přišla
  (faktura > smlouva > obchodní podmínky a doprovodné přílohy),
- ostatní nálezy jako volitelné **`secondary_findings`**
  (`{type, note}` — typ z enum primary_type + krátká poznámka), nikdy
  druhý `document`.

Data vrací přímo v kanonickém formátu v poli `document.extracted_json`
(viz [`docs/exchange-format.md`](../../../../docs/exchange-format.md)):
**`shpd.docs.document.v1`** pro faktury/dobropisy/účtenky,
**`shpd.registry.document.v1`** pro registry typy (smlouvy, pojistky,
nabídky, revize, úřední písemnosti) — cíl je Spisovna (`base.registry`),
apply jde přes `RegistryApplier`. Data téhož dokumentu z více zdrojů
(PDF + tělo e-mailu) se slučují.

Klíčové pokyny v promptu:

- Pole, která AI nedokáže určit, vynechej (neuhaduj).
- Datumy ISO 8601 `YYYY-MM-DD`, měny ISO 4217 uppercase (`CZK`), země
  ISO 3166-1 alpha-2 lowercase (`cz`).
- `selfParty` vždy `"customer"` (jsme příjemce přijaté faktury).
- `source.kind` vždy `"aiExtraction"`, `source.promptVersion` vždy
  shodná s `prompt_version` profilu (`v4.4.0`).
- VAT kódy v řádcích jsou klíče z `world.vat.{country}.vatCodes`
  cfgItem (`cz-110`, `cz-111`, …) — ne sazby v procentech.
- `totals.totalRounding` = zaokrouhlení celkové částky se znaménkem
  (dolů = záporné); zaokrouhlení nikdy nepatří jako položkový řádek
  do `rows`.
- `rows` musí obsahovat **všechny** položkové řádky dokladu (u
  vícestránkových ze všech stran) + self-check součtu řádků proti
  rekapitulaci před vrácením výsledku.
- `rows[].item.name` = text položky tak, jak je na řádku dokladu;
  `rows[].item.description` **jen** pro doplňující text z dokladu
  (fakturované období, číslo služby, přípojky či smlouvy) — nikdy záhlaví
  sloupců, jednotka, množství, označení pokladny, prodejny nebo skladu;
  u účtenek se vynechá. Text řádku na dokladu skládá server
  (`CanonicalRowText`: název, za ` — ` popis, není-li v názvu obsažený),
  viz [`docs/exchange-format.md`](../../../../docs/exchange-format.md) §7.
- `vatRecap` a `totals` výhradně **opisem** z rekapitulačního bloku
  dokladu, nikdy dopočtem z cen položek; bez rekapitulace na dokladu
  se pole vynechají.
- `document.doc_type` nikdy `other` — když zpráva žádný doklad ani
  dokument nenese, vrať `document: null` a klasifikaci `other`.

Plný prompt v [`profiles/czech_general.jsonc`](../profiles/czech_general.jsonc)
sekce `prompt_template`.

## Output schema

JSON Schema **draft-2020-12** (od `v2.0.0`; dřív draft-07). Wrapper (v4):

```json
{
  "type": "object",
  "required": ["overall_confidence", "message_classification"],
  "additionalProperties": false,
  "properties": {
    "overall_confidence": { "type": "number", "minimum": 0, "maximum": 1 },
    "message_classification": { /* primary_type + confidence, enum enabled typů */ },
    "secondary_findings": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["type", "note"],
        "properties": { "type": { "type": "string" }, "note": { "type": "string" } }
      }
    },
    "document": {
      "oneOf": [
        { "type": "null" },
        {
          "type": "object",
          "required": ["doc_type", "confidence", "extracted_json"],
          "properties": {
            "doc_type": { "enum": ["invoiceReceived", "creditNote", "contract", "insurance", "quotation", "certificate", "official"] },
            "confidence": { "type": "number" },
            "extracted_json": {
              "oneOf": [
                { /* inline shpd.docs.document.v1 schema */ },
                { /* inline shpd.registry.document.v1 schema */ }
              ]
            }
          }
        }
      ]
    }
  }
}
```

Pole `documents[]` a `source_attachment_ndxs` z kontraktu **v4 zanikla**
(přílohy návrhu = všechny obsahové přílohy zprávy; `extracted_documents`
v `POST /result` server odmítá 422). Názvy polí jsou přesně dle kontraktu
— analyzer nic nepřejmenovává.

**`extracted_json` je oneOf dvou inline kopií** — struktura se volí podle
**targetu** typu dokumentu (cfgItem `core.mail.primaryTypes`):

- target `docs` (faktury, dobropisy) →
  `modules/core/exchange/schemas/shpd.docs.document.v1.json`,
- target `registry` (smlouvy, pojistky, nabídky, revize, úřední
  písemnosti — Spisovna) →
  `modules/base/registry/schemas/shpd.registry.document.v1.json`.

Analyzer (`/claim` response) dostává `output_schema` napřímo — neumí
`$ref` resolve napříč souborům, takže obě canonical schémata musí být
doslovně embedded. Drift mezi profilem a canonical soubory hlídá test
[`tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php`](../../../../tests/Unit/Module/Core/Mail/ProfileSchemaDriftTest.php) —
selže s odkazem na regeneraci, pokud někdo updatuje jedno a zapomene
druhé. Shodu `kindFields` registry schématu s `base.registry.docKinds`
hlídá `tests/Unit/Module/Base/Registry/RegistrySchemaDriftTest.php` —
názvy polí se **nikdy nesmí lišit** (analyzer plní kindFields přesně dle
schématu; přejmenované pole = tiché prázdno v metadatech).

Plné schéma viz [`profiles/czech_general.jsonc`](../profiles/czech_general.jsonc).

## Customization guidelines

### Přidání nového typu dokumentu

1. Přidej klíč do `core.mail.primaryTypes`
   ([config/primaryTypes.jsonc](../config/primaryTypes.jsonc))
   včetně `target` (`docs` / `registry`; registry typy navíc `docKind`
   z `base.registry.docKinds`) — jediná klasifikační osa, interpretuje
   helper `PrimaryTypes`.
2. V profilu rozšiř `supported_doc_types` (JSON pole klíčů).
3. V `prompt_template` doplň pravidla pro nový typ — u registry typů
   **vyjmenuj přesné názvy `kindFields`** dle `docKinds.fields`
   (nesoulad = tiché prázdno, hlídá `RegistrySchemaDriftTest` +
   `ProfileSchemaDriftTest::testPromptEnumeratesKindFieldsExactly`).
4. V `output_schema` přidej nový klíč do enum `document.doc_type`
   (i do enum `message_classification.primary_type`) a zvol větev
   `extracted_json.oneOf` podle **targetu** typu: docs typy jedou
   přes `shpd.docs.document.v1` (polymorfní dle `docType`, bez per-typ
   branche), registry typy přes `shpd.registry.document.v1` (nový druh =
   nová if/then větev `kindFields` v registry schématu + kopie embedu).
5. Bumpni `prompt_version` (`v4.4.0` → `v4.5.0`).

### Vlastní profil pro jiný jazyk / účel

1. Vytvoř nový řádek v `core_mail_ai_profiles` se `profile_id` (např.
   `english_invoices`), `language=en`, vlastním promptem a schématem.
2. Pokud má být default DS, ostatní `is_default` ručně shoď — invariant
   "max 1 default profile per DS" vynucuje aplikační validace, nikoli DB.

### Tweak thresholdů

`confidence_thresholds` řídí runtime pásmo návrhu (`ready` / `review` /
`low`) — pásmo se počítá při každém čtení (`AnalysisConfidenceResolver`),
nikam se nepersistuje. Přísnější DS by mohlo mít
`{"ready": 0.95, "review": 0.75}`. Změna je živá — projeví se okamžitě
i u otevřených návrhů.

### Iterativní ladění promptu

Workflow pro ladění promptu z JSONC šablony v repu (zdroj pravdy pro
default profil):

1. Uprav `modules/core/mail/profiles/czech_general.jsonc` —
   `prompt_template`, případně `output_schema`, `confidence_thresholds`,
   `supported_doc_types`, `language`.
2. Bumpni `prompt_version` (semver, např. `v1.1.0` → `v1.2.0`).
3. Commit do gitu, deploy.
4. Z DS adresáře spusť `bin/shpd-ds ds-upgrade` — sync profilu ze šablony
   je součástí provisioning fáze (`[UPDATE] profile 'czech_general':
   v1.1.0 → v1.2.0`). Jen upgrade — se stejnou verzí je no-op, s novější
   verzí v DB vypíše `[WARN]` a nic nepřepíše (ochrana proti náhodnému
   downgrade nebo přepisu admin tweaků se zapomenutým bumpem).

   Pro speciální případy zůstává manuální příkaz:
   ```
   bin/shpd-ds ai-profile-reload [--dry-run] [--force] [--template-path=...]
   ```
   - `--dry-run` ukáže, co se změní, bez zápisu.
   - `--force` přepíše i při stejné/nižší verzi (vědomý downgrade).
   - `--template-path` reload z jiné šablony než výchozí.

   Sync ani reload **nepřepisují** `name`, `is_default`, `is_active`,
   `backend` — admin si je může lokálně upravit.
5. V UI klikni "Znova analyzovat" na vybraných zprávách — vznikne nový
   běh a stane se automaticky aktuálním návrhem (historie se nemění,
   žádný supersede krok). Zprávu s aplikovaným návrhem a živým targetem
   reanalyzovat nejde — nejdřív unapply. Případně re-queue přes SQL.
6. Porovnej kvalitu před / po (`message_analyses.prompt_version` umožňuje
   filtrovat).

Analyzer čte prompt z DB při každém claimu, takže reload neovlivní právě
běžící zpracování — promítne se až do nových claimů po reload.

### Jinak vybraný backend per profil

Pole `backend` v profilu je FK na `core_ai_backends`. Můžeš mít víc
backendů (`default` Anthropic Claude Sonnet pro běžné případy, druhý backend
s Claude Opus pro náročné dokumenty) a přiřadit je různým profilům.

## Changelog promptu

### v4.4.0 (2026-09-29)

Text řádku dokladu
([tasks/exchange-row-text.md](../../../../tasks/exchange-row-text.md)
D3, #84) — u účtenky PHM model do `item.description` opsal záhlaví sloupců
(„DPHM Množství") a vystavený doklad měl v řádku tento šum místo názvu
položky; prompt rozdíl `name` / `description` nedefinoval a ukázka měla
`description` u všech řádků:

- PRAVIDLA: `rows[].item.name` = text položky z řádku dokladu;
  `rows[].item.description` jen pro doplňující text (fakturované období,
  číslo služby, přípojky či smlouvy), nikdy záhlaví sloupců, jednotka,
  množství, označení pokladny, prodejny nebo skladu; bez takového textu
  pole vynechat.
- PRAVIDLA PRO ÚČTENKY: zpravidla stačí `item.name` (druh paliva),
  `item.description` vynechat.
- Ukázka: druhý řádek (Doprava) bez `description`.
- Server (nezávisle na verzi promptu, D1/D2): text řádku skládá
  `CanonicalRowText` — top-level `description`, jinak `item.name` +
  ` — ` + `item.description` (není-li v názvu obsažený); stejný text
  vrací náhled v `_resolve.rows[i].rowText`, zapisuje applier a čtou guard
  dodavatelských kódů, klasifikátor štítků i enricher z historie. Staré
  návrhy s `description` z v4.3.0 tím dostanou název před šumem.

### v4.3.0 (2026-09-10)

Titulek zprávy
([tasks/mail-message-title-partner.md](../../../../tasks/mail-message-title-partner.md)
D2) — zprávy ze skeneru a ruční nahrání mají generický předmět
(`Message from …`, název souboru), v seznamu Došlé pošty nebylo podle čeho
hledat:

- `message_classification` nově nese volitelný `title` (≤ 120 znaků,
  česky): „co to je + od koho + částka / číslo, je-li"; u `other` stručný
  popis obsahu. Nikdy název souboru ani opis generického předmětu. Ukázkové
  JSONy doplněny.
- `output_schema.message_classification.properties.title`
  (`{"type": "string", "maxLength": 120}`), **ne** required — starší
  analyzer bez `title` projde, server doplní deterministický fallback
  z canonicalu (`MessageTitleComposer`).
- Server ukládá titulek do `core_mail_incoming_messages.ai_title`
  (AI-vlastněný sloupec, přepisuje každý běh) a zobrazuje ho místo předmětu
  jen u generických / prázdných předmětů a ručních zpráv
  (`core.mail.genericSubjectPatterns`; viz
  [ai-analysis.md](ai-analysis.md#titulek-zprávy-ai_title)).

### v4.2.0 (2026-08-16)

Integrita řádků a rekapitulace DPH
([tasks/ai-extraction-integrity.md](../../../../tasks/ai-extraction-integrity.md)
D1/D4) — dva reálné případy z dev DS: u třístránkové faktury model
extrahoval 8 z 57 položkových řádků a rekapitulaci opsal z dokladu
(doklad „vypadal OK", chyběly položky za ~14 tis. Kč); u účtenky s cenami
bez DPH model chybně určil `fromTotal` a rekapitulaci dopočítal pozpátku
(podplaceno o DPH):

- Nová pravidla úplnosti: `rows` musí obsahovat VŠECHNY položkové řádky
  (vícestránkové doklady ze všech stran), zákaz zkracování/shrnování;
  self-check součtu `totalPrice` proti rekapitulaci před vrácením
  výsledku. Ukázkový JSON má nově dva položkové řádky (prolomení kotvy
  jednořádkové ukázky).
- Zpřesněné pravidlo `vat.mode`: `fromTotal` POUZE při shodě součtu
  položek s částkou k úhradě; shoda na řádek „Základ" → `fromBase`
  i na účtence (samotný fakt účtenky není důvod pro `fromTotal`).
- Nové pravidlo opisu: `vatRecap` a `totals` výhradně opisem
  z rekapitulačního bloku dokladu, nikdy dopočtem z cen položek.

Prompt je pojistka první linie — nezávisle na něm `DocumentValidator`
oba vzory chytá warningy **`rows_recap_mismatch`** (součet řádků vs.
rekapitulace dle režimu DPH) a **`vat_recap_inconsistent`** (vnitřní
aritmetika řádků rekapitulace). U dlouhých faktur vyžaduje úplná
extrakce zvýšený `max_tokens` na straně ai_analyzeru (samostatný task
v repu ai_analyzer) — nasazovat společně.

### v4.1.0 (2026-08-14)

Režim výpočtu DPH u dokladů s koncovými cenami
([tasks/docs-vat-mode-derivation.md](../../../../tasks/docs-vat-mode-derivation.md)
D2/D3) — u účtenek (PHM, maloobchod) model vracel `vat.mode: "fromBase"`,
ačkoli řádky nesou ceny včetně DPH, a daň se na dokladu počítala podruhé:

- Nové pravidlo pro `vat.mode`: ceny řádků uvedené včetně DPH (součet
  položek odpovídá částce k úhradě, ne základu daně) → `"fromTotal"`;
  čísla z dokladu se vždy opisují, nikdy nepřepočítávají na základ.
- PRAVIDLA PRO ÚČTENKY: `rows[].priceCalcMode: "fromTotal"`, když je
  autoritativní celková cena řádku (PHM — qty × jednotková cena nemusí
  kvůli zaokrouhlení u stojanu sedět na celkovou), `totalPrice` se opisuje
  přesně z dokladu.

Prompt je pojistka první linie — nezávisle na něm `DocumentApplier`
`vat_mode` deterministicky derivuje z poměru součtu řádků a rekapitulace
(`VatModeDerivation`, issue `vat_mode_derived`).

### v4.0.0 (2026-08)

Message-centrický kontrakt v4
([tasks/mail-message-centric.md](../../../../tasks/mail-message-centric.md)
D1/D11) — big-bang, bez kompatibilní mezivrstvy:

- Analyzuj zprávu **jako celek** — subject + body + přílohy jsou jeden
  kontext; tělo zprávy je plnohodnotný zdroj dat.
- Top-level `documents[]` → **`document`** (0..1) — primární dokument
  zprávy (faktura > smlouva > doprovodné přílohy); data téhož dokumentu
  z více zdrojů se slučují.
- `message_classification` je **povinná** (dřív volitelná).
- Nové pole `secondary_findings` — informativní seznam dalších nálezů
  `{type, note}`; nikdy druhý `document`.
- `source_attachment_ndxs` zaniklo (přílohy návrhu = všechny obsahové
  přílohy zprávy).

### v3.2.0 (2026-07-23)

Podpora účtenek / zjednodušených daňových dokladů — účtenky za palivo
(sken z kopírky) model klasifikoval jako `other` s vysokou jistotou, protože
prompt znal jen „přijatou fakturu nebo dobropis“:

- TRIAGE i extrakční krok nově explicitně zahrnují účtenku / zjednodušený
  daňový doklad (paragon) — klasifikuje i extrahuje se jako `invoiceReceived`.
- Nový blok „PRAVIDLA PRO ÚČTENKY“: chybějící odběratel je v pořádku
  (`customer: null`, `selfParty` zůstává `customer`), `docNumber` = číslo
  účtenky, `issueDate` = `taxPointDate` = datum prodeje, `dueDate` se vynechává
  (uhrazeno na místě), `payment.method` = `card`/`cash` dle dokladu.
- Limit 10 000 Kč pro zjednodušený doklad je v promptu jen popisně — model
  ho nevymáhá, extrahuje i účtenky nad limit.

Žádná změna schématu ani PHP kódu: canonical schéma `customer` nevyžaduje
a `DocumentApplier` při `selfParty=customer` resolvuje odběratele jako
`resolveSelfParty()`; `card`/`cash` jsou v `PAYMENT_METHOD_MAP`.

### v3.1.0 (2026-07-23)

Zaokrouhlení celkové částky faktury (spec
[tasks/mail-invoice-rounding.md](../../../../tasks/mail-invoice-rounding.md)):

- Nové pravidlo pro `totals.totalRounding`: rozdíl mezi součtem položek
  s DPH a částkou k úhradě se vrací se znaménkem (zaokrouhleno dolů =
  záporná hodnota); bez zaokrouhlení `0` nebo pole vynechat.
- Explicitní zákaz vracet zaokrouhlení jako položkový řádek v `rows` —
  patří výhradně do `totals.totalRounding` (jinak falešná položka na
  dokladu a rozbitá derivace `total_rounding_mode` v applieru).

### v3.0.0 (2026-07-14)

Spisovna Fáze 2 — extrakce registry typů
([tasks/registry-phase2.md](../../../../tasks/registry-phase2.md),
design [docs/registry-mvp.md](../../../../docs/registry-mvp.md) §7):

- Nové `doc_type` hodnoty `contract`, `insurance`, `quotation`,
  `certificate`, `official` (target `registry` → dokument Spisovny);
  `primary_type` enum rozšířen o tytéž klíče.
- `documents[].extracted_json` je nově **oneOf**
  [`shpd.docs.document.v1`, `shpd.registry.document.v1`] — registry typy
  vrací `{schema, docType, title, summary, party, kindFields,
  binderSuggestion}`.
- Prompt vyjmenovává přesné názvy `kindFields` per druh (dle
  `base.registry.docKinds`), `summary` 2–3 věty v jazyce profilu,
  `binderSuggestion` volitelný obecný název šanonu.

### v2.3.0 (2026-07-14)

Opravy tří opakujících se vzorů `schema_error` z reálného provozu (spec
[tasks/mail-analysis-schema-fixes.md](../../../../tasks/mail-analysis-schema-fixes.md)):

- `attachments[].kind`: enum ve schématu rozšířen o `structured` (strojově
  čitelná příloha — ISDOC, XML, UBL) a prompt povolené hodnoty nově
  vyjmenovává — model si `structured`/`isdoc` dřív vymýšlel sám.
- Nové pravidlo: objekty (`vat`, `payment`, `customer`, …), které nelze
  určit, vynechat nebo vrátit `null` — nikdy prázdné objekty s vymyšleným
  obsahem. Top-level `vat` je ve schématu nově nullable (konzistence se
  zbytkem schématu).
- Nové pravidlo: nepřidávat klíče mimo ukázku (`additionalProperties:
  false`). Ukázka `supplier` doplněna o `courtRegistration` — model si pro
  rejstříkový údaj dřív vymýšlel klíč `registration`.

### v2.2.0

- Triage celé zprávy: top-level `message_classification`
  (`primary_type` + `confidence`), viz
  [ai-analysis.md](ai-analysis.md).

### v2.0.0

- Výstup přímo v kanonickém `shpd.docs.document.v1` (dřív ad-hoc shape),
  output schema draft-2020-12.

## Reference

- [ai-analysis.md](ai-analysis.md) — architektura
- [profiles/czech_general.jsonc](../profiles/czech_general.jsonc)
