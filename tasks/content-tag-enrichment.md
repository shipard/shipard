# Obsahová eskalace párování položek (Content Tag Enrichment) — Vrstva 2

**Stav:** hotovo

> Implementováno 2026-08-19. Odchylky od zadání: form editace `content_tags`
> přes nový generický element `multiselect` (form systém ho neměl);
> `vehicle.toll` + `admin.fees` → nová analytika **538200** v default osnově
> (538100 = zrušená daň z převodu nemovitosti) + nová položka nabídky;
> `people.gifts` → **543900** (ne 513900) + nová položka nabídky; settings
> klíče (`exchange.contentTag.beforeDominance`, `exchange.contentTag.backend`)
> zatím jen přes `ds-setting set`, UI stránka až v `content-tag-ui.md`
> (settingsPages neumí bool/select pole). Zbývá: `ds-upgrade` + ruční smoke
> na dev DS, live LLM ověření na DS s aktivním backendem.

**Cíl:** Když Vrstva 0 (historie partnera) nenajde pro řádky AI-extrahovaného
dokladu položku, nastoupí obsahová eskalace: doklad se klasifikuje do fixní
sémantické taxonomie (`vehicle.fuel`, `office.supplies`, …) — deterministicky
pravidlem IČO→štítek, jinak levným LLM voláním — a štítek se deterministicky
resolvuje na účetní položku (`economy_items.content_tags`) s trojicí
item/vat/account. První účtenka za PHM v životě DS se tak předvyplní správně
i bez jakékoli historie. Follow-up UI (dashboard karta „Nová kategorie",
obrazovka správy štítků) je samostatný task `content-tag-ui.md` (vznikne).

---

## Návaznost

- **Vrstva 2** z brainstormu „AI párování položek" — navazuje na Vrstvu 0+3
  ([tasks/row-history-enrichment.md](row-history-enrichment.md)) a dominanci
  ([tasks/enrichment-dominant-item.md](enrichment-dominant-item.md)).
  Vrstva 1 (kontext dodavatele do extrakčního promptu) zůstává mimo scope.
- Staví na: `RowHistoryEnricher` (`modules/core/exchange/src/Enrich/`),
  `AnalysisConfidenceResolver` (strop pásma), `_resolve` audit konvence
  (`additionalProperties: true`), `AnthropicLlmClient` + `AiBackendResolver`
  (`src/Core/Ai/`), nabídka účetních položek
  (`modules/economy/items/config/accountingItemsDefault.jsonc` +
  `SetupController` `/_setup/accounting-items*`), settings pages
  ([docs/app-settings.md](../docs/app-settings.md)),
  `SupplierCodeCaptureHandler` (vzor learning handleru).
- **ai_analyzer se nemění** — vše žije na PHP straně.
- Klíčové poznatky z průzkumu:
  - Nabídka účetních položek s generátorem už existuje (kódy = čísla účtů,
    sufixová konvence při sdílení účtu, per varianta osnovy default/NPO) —
    starter položky se **nevymýšlí**, taguje se nabídka.
  - PHM je v default osnově/nabídce na **503100** (ne 501).
  - `RowHistoryEnricher::create()` factory se volá ze 3 míst
    v `public/index.php` (ř. ~377/780/894) + wiring v `AnalysisController`.
  - První volné tableId: **438**.

## Potvrzená rozhodnutí

| D | Rozhodnutí |
|---|---|
| D1 | Eskalace = sémantická mezivrstva: AI klasifikuje do fixní obsahové taxonomie; mapování štítek → položka/účet/DPH je deterministické a per-DS. |
| D2 | Břitva granularity: dva štítky existují, jen když se účtují jinak. Taxonomie o chlup jemnější než default rozvrh, nikdy hrubší. |
| D3 | Plochý uzavřený enum s prefixovou konvencí (`vehicle.fuel`); mapování smí fallbackovat na prefix (`vehicle.*`); „žádný štítek" je legitimní výstup. |
| D4 | Ortogonální osy mimo taxonomii: hranice drobného majetku = amountGuard v mapování; DPH režim = vatHint mapování; nepoznatelný záměr (catering) = default + pozdější mikrointerakce. |
| D5 | Granularita v1: document-level `primaryTag` + `rowExceptions` pro odchylné řádky; moc výjimek → nízká confidence / nic. |
| D6 | Taxonomie v1 fixní systémová; per-DS rozšíření až po ověření. |
| D7 | Jeden štítek `goods.stock`; 501 vs. 504 rozhodne per-DS volba (mikrointerakce v UI tasku; do té doby bez mapování → review). |
| D8 | Validace taxonomie offline batchem nad starým Shipardem — samostatný task v `old_shipard`. |
| D9 | Mapování = `content_tags` na `economy_items`; žádná mapovací tabulka. Trojice návrhu se čte z položky. |
| D10 | Shipnutý default: štítky přímo na položkách nabídky (`accountingItemsDefault/Npo.jsonc`); fallback štítek → účet se derivuje z nabídky aktivní varianty; `contentTagDefaults.jsonc` nese jen vatHint + amountGuard. Žádná materializace při provisioningu. |
| D11 | Lazy materializace položek mikrointerakcí (UI task); D11b: obrazovka správy štítků nabídne i hromadné založení výchozích položek (UI task). |
| D12 | Pravidla operují ve štítkovém prostoru (trigger → tag), nikdy přímo na položku. Potvrzené pravidlo přeskakuje LLM. D12b: v1 osa IČO (post-extraction); sender osa → fáze šumu. |
| D13 | Precedence štítek vs. dominantní položka = per-DS setting, default „štítek má přednost". |
| D14 | ContentTag návrhy mají vždy strop pásma review; auto režim až per-pravidlo žebříkem (mimo v1). |
| D15 | Otagování migrovaných položek reverzem (účet → štítek z nabídky) — obrazovka v UI tasku; backend helper může vzniknout tady. |
| D16 | LLM právě jednou za běh (při `/result`); štítek se persistuje, resolution štítek→položka běží fresh při každém čtení. Fresh re-check pravidel má přednost před persistnutým LLM štítkem. |
| D17 | Eskalace synchronně v `/result`, jen když po Vrstvě 0 zbývají nepokryté item řádky; selhání LLM nikdy neshodí `/result`; reanalýza = retry. |
| D18 | Kód v `core.exchange/src/Enrich/`; prompt verzovaný konstantou v repu (vzor DashboardSummaryService), bez DB profilu; backend přes settings override, default = default backend. |
| D19 | Taxonomie = cfgItem `core.exchange.contentTags`; defaults v `economy.items`. |
| D20 | Persistence: `_resolve.contentTag` (dokument-level) + `matchedBy: 'contentTag'` v řádkovém enrichment bloku + sloupec `content_tag` na `core_mail_message_analyses`. |
| D21 | Tabulka `core_exchange_tag_rules` (jen osa IČO), tableId 438. |
| D22 | Apply dokladu s LLM štítkem zapisuje pravidlo IČO→štítek (origin `learned`), platné okamžitě. Zúženo `tasks/content-tag-row-exceptions.md` D2: doklad s neprázdnými `rowExceptions` pravidlo neučí. |

## Taxonomie v1 (aplikace D2 na reálnou nabídku položek)

Břitva vůči shipnuté nabídce vedla ke splitům: energie → elektřina/plyn/voda,
telco → telefon/internet, professional → účetní/právní, pojištění →
vozidla/majetek. Mapování „→ kód nabídky" = položka, která štítek ponese
(sufixové kódy = **nové položky nabídky**, viz Scope bod 3; čísla účtů
ověřit proti `accountChartDefault.jsonc` při implementaci):

| Štítek | name:cs | → kód nabídky (default osnova) |
|---|---|---|
| `vehicle.fuel` | Pohonné hmoty | 503100 |
| `vehicle.consumables` | Provozní kapaliny a drobný materiál k vozidlům | 501100 |
| `vehicle.service` | Servis a opravy vozidel | 511202 |
| `vehicle.parts` | Náhradní díly | 501100 |
| `vehicle.toll` | Dálniční známky a mýto | 538100 (nová položka, ověřit účet) |
| `vehicle.parking` | Parkovné | 518100 |
| `vehicle.washing` | Mytí vozidel | 518100 |
| `vehicle.insurance` | Pojištění vozidel | 548202 |
| `office.supplies` | Kancelářské potřeby | 501100S (nová, účet 501100) |
| `office.equipment` | Drobné vybavení | 501201 + amountGuard |
| `office.cleaning` | Úklidové a hygienické potřeby | 501100 |
| `it.hardware` | IT hardware | 501201 + amountGuard |
| `it.software` | Software a SaaS | 518206 |
| `it.hosting` | Hosting, domény, cloud | 518206 |
| `it.phone` | Telefonní služby | 518201 |
| `it.internet` | Internetové připojení | 518202 |
| `premises.rent` | Nájemné | 518205 |
| `premises.electricity` | Elektřina | 502100 |
| `premises.gas` | Plyn | 502300 |
| `premises.water` | Vodné a stočné | 502200 |
| `premises.maintenance` | Opravy a údržba prostor | 511201 |
| `premises.security` | Ostraha a zabezpečení | 518100 |
| `services.accounting` | Účetní a daňové služby | 518211 |
| `services.legal` | Právní služby | 518212 |
| `services.marketing` | Reklama a marketing | 518204 |
| `services.training` | Školení a kurzy | 518100 |
| `services.shipping` | Přeprava a kurýři | 518100 |
| `services.postage` | Poštovné | 518203 |
| `services.banking` | Bankovní poplatky | 568201 |
| `services.waste` | Odvoz odpadu | 518100 |
| `goods.stock` | Zboží / materiál na sklad | — (D7: bez mapování do volby DS) |
| `travel.accommodation` | Ubytování | 512100 |
| `travel.fares` | Jízdné a letenky | 512100 |
| `people.catering` | Občerstvení a pohoštění | 513900 + vatHint nonDeductible |
| `people.gifts` | Dary a pozornosti | 513900 + vatHint nonDeductible (ověřit vs. 543) |
| `people.benefits` | Zaměstnanecké benefity | — (osnova/nabídka nemá jasný cíl — bez mapování, review) |
| `people.workwear` | Pracovní oděvy a OOPP | 501100 |
| `admin.insurance` | Pojištění majetku a odpovědnosti | 548201 |
| `admin.fees` | Správní a licenční poplatky | 538100 (sdílí s toll, ověřit) |
| `admin.memberships` | Členské příspěvky a předplatná | 518100 |
| `admin.other` | Ostatní (bez zařazení) | — (vědomě bez mapování → review) |

Víc štítků → jeden kód nabídky je v pořádku (M:1). NPO varianta
(`accountingItemsNpo.jsonc`): otagovat jen položky s jednoznačným
protějškem, zbytek štítků zůstane bez default mapování (review) —
nevymýšlet NPO účty, kde nabídka nemá.

## Scope

**In:**
1. cfgItem `core.exchange.contentTags` (taxonomie).
2. Sloupec `economy_items.content_tags` + editace ve formuláři položky.
3. Otagování + rozšíření nabídek účetních položek (obě varianty).
4. `contentTagDefaults.jsonc` (vatHint, amountGuard) + derivace fallback
   účtů z nabídky.
5. Tabulka `core_exchange_tag_rules` (438).
6. `ContentTagResolver`, `ContentTagClassifier`, `RowEnrichmentPipeline`
   + wiring (index.php, AnalysisController).
7. Sloupec `content_tag` na `core_mail_message_analyses`.
8. Strop pásma pro contentTag řádky (D14).
9. Settings: precedence (D13) + backend override (D18).
10. Learning handler (D22).
11. Unit testy, dokumentace.

**Out:**
- Dashboard karta „Nová kategorie", obrazovka správy štítků, hromadné
  založení, reverzní otagování UI → `content-tag-ui.md`.
- Import seedu pravidel ze starého Shipardu (origin `seed` je v tabulce
  rezervovaný; formát a endpoint definuje task validačního batche).
- Sender osa pravidel, dispozice, auto režim, per-DS rozšíření taxonomie,
  Vrstva 1.
- Změny ai_analyzeru, změny extrakčního promptu/profilu.
- Sloupec `content_tag` na `docs_core_heads` (časem, ne teď).

---

## Změny soubor po souboru

### 1. `modules/core/exchange/config/contentTags.jsonc` (nový) + `module.jsonc`

cfgItem `core.exchange.contentTags` — plochý slovník dle tabulky výše:

```jsonc
{
    // core.exchange.contentTags — sémantická taxonomie obsahu dokladů.
    // FIXNÍ systémová sada (D6). Klíče s prefixovou konvencí `group.tag`;
    // mapování smí fallbackovat na prefix. Břitva D2: nový štítek jen
    // při odlišném zaúčtování.
    "vehicle.fuel": { "name": "Fuel", "name:cs": "Pohonné hmoty", "name:en": "Fuel", "order": 10 },
    ...
}
```

`module.jsonc`: přidat blok `config` (modul dosud žádný nemá) + upravit
komentář „No tables of its own" (bod 5 tabulku přidává).

### 2. `modules/economy/items/tables/economy_items.jsonc` + Document/Form

- Nový sloupec `content_tags` (`type: json`, nullable, group
  `classification`, name:cs „Obsahové štítky") + zmínka v
  `economy_items.md`.
- `ItemDocument::validate`: hodnoty musí být klíče
  `core.exchange.contentTags` (pozor: economy.items na core.exchange
  nezávisí — validaci provést přes cfgItem lookup bez tvrdé modulové
  závislosti; cfgItemy jsou globální, dependency hrana se nepřidává).
- `ItemsForm`: editace štítků (multi-select nad cfgItem; použít existující
  form primitivum pro výběr z cfgItem, pokud multi-select chybí, postačí
  v1 chips/JSON editace — rozhodnout dle možností edit-forms, viz
  `docs/edit-forms.md`).
- Schema změna jde první (ds-upgrade).

### 3. Nabídky účetních položek (`accountingItemsDefault.jsonc`, `accountingItemsNpo.jsonc`)

- Ke stávajícím položkám doplnit pole `contentTags: ["…"]` dle tabulky.
- Nové položky: `538100` Dálniční poplatky a mýto (ověřit účet v osnově;
  jinak zvolit dle osnovy), `501100S` Kancelářské potřeby (sufixová
  konvence, účet 501100). Zvážit, zda `vehicle.consumables` dát vlastní
  sufixovou položku, nebo nechat na 501100 — implementace rozhodne dle
  konzistence nabídky, priorita je nerozbít stávající kódy.
- `SetupController` generátor: propsat `contentTags` z nabídky do
  zakládané položky (`content_tags`). Tím jsou položky založené ze setup
  panelu rovnou otagované.
- POZOR na varování v hlavičce souborů: kódy/účty se mezi variantami
  nesmí míchat.

### 4. `modules/economy/items/config/contentTagDefaults.jsonc` (nový)

Per-tag účetní pravidla nezávislá na variantě osnovy:

```jsonc
{
    "people.catering": { "vatHint": "nonDeductible" },
    "people.gifts":    { "vatHint": "nonDeductible" },
    "office.equipment": { "amountGuard": { "over": 80000, "action": "review",
                          "note:cs": "možný dlouhodobý majetek" } },
    "it.hardware":      { "amountGuard": { "over": 80000, "action": "review" } }
}
```

Registrace jako cfgItem `economy.items.contentTagDefaults`. Fallback
štítek → účet se NEdefinuje tady — derivuje se z nabídky aktivní varianty
(helper, viz bod 6). `vatHint` je v1 **informativní** (propíše se do audit
bloku / issue warning `content_tag_vat_hint`); tvrdé vynucení DPH režimu
mimo scope.

### 5. `modules/core/exchange/tables/core_exchange_tag_rules.jsonc` (nový, tableId 438)

```jsonc
columns:
  id            int PK autoIncrement
  company_id    varchar(20)  nullable:false   // IČO dodavatele (normalizované, bez mezer)
  tag           enumString(40) cfgItem core.exchange.contentTags, nullable:false
  origin        enumString(10)  // 'user' | 'learned' | 'seed'
  confirmed     tinyint default 1   // v1 vše=1 (D22 platí hned); pole pro budoucí žebřík
  hit_count     int default 0
  last_hit_at   datetime nullable
  created       datetime, created_by int nullable, modified datetime nullable
indexes:
  unique (company_id)      // jedno pravidlo per IČO; změna štítku = update
  index (tag)
```

+ `core_exchange_tag_rules.md`, registrace v `module.jsonc` (`tables`).
Bez vieweru/formu v tomto tasku (správa v UI tasku); bez `keepOnReset`
(learned pravidla se obnoví provozem, seed re-importem).
Pozn.: jedno pravidlo per IČO je vědomé v1 zjednodušení — dodavatel
s pestrým sortimentem (hobbymarket) pravidlo nedostane vůbec (learning
handler ho nezaloží, pokud by přepisoval odlišný štítek → místo toho
pravidlo smaže/neučí, viz bod 10).

### 6. `modules/core/exchange/src/Enrich/ContentTagResolver.php` (nový)

Deterministická část — čistá služba:

- `resolveTagByRule(string $companyId): ?TagRuleHit` — lookup
  `core_exchange_tag_rules` (normalizace IČO), inkrement statistik
  (hit_count/last_hit_at) až při skutečném použití v pipeline.
- `resolveItemForTag(string $tag): TagResolution` — živé položky
  (`docStateMain` aktivní, item stavy 10/40/80) s `content_tags`
  obsahujícím `$tag`:
  - právě 1 → trojice `{ourCode, vatCode?, account}` z položky
    (účet přes `accounting_account` FK → číslo; vat default položky,
    má-li — jinak nevyplňovat),
  - více → `status: ambiguous` (bez návrhu, audit vyjmenuje kandidáty),
  - žádná → `status: unmapped` + **fallback účet z nabídky**: helper
    `defaultAccountForTag(string $tag): ?string` čte nabídku aktivní
    varianty osnovy (reuse logiky výběru souboru ze `SetupController` —
    extrahovat do sdíleného helperu, např.
    `Shipard\Module\Economy\Items\AccountingItemsOffer`), najde položky
    nabídky nesoucí tag, vezme účet (víc položek se stejným tagem
    v nabídce → první dle pořadí; nabídka je kurátorovaná). Prefix
    fallback (D3): `vehicle.consumables` bez zásahu → zkusit `vehicle.*`.
    Fallback účet se navrhne jen do `account` (bez ourCode) — řádek bez
    položky, jen s účtem, je validní návrh.
- `applyAmountGuard(...)`: řádek s `totalPrice` nad `amountGuard.over` →
  návrh se nepodá (audit `guard: 'amount'`), pásmo zůstane review.

### 7. `modules/core/exchange/src/Enrich/ContentTagClassifier.php` (nový)

LLM část:

- Konstanta `TAG_PROMPT_VERSION = 'tag-v1.0.0'`; prompt v kódu (privátní
  konstanta / malá šablona), enum štítků generovaný z cfgItem za běhu.
- Vstup: kompaktní digest canonicalu — supplier name + companyId,
  docNumber?, popisy řádků (jen nepokryté? ne — všechny, model potřebuje
  celek pro dominantní štítek; limit délky ~50 řádků / ořez), totals.
  Žádné přílohy, žádný originál.
- Výstup (JSON, instrukce „pouze JSON"): `{ primaryTag: string|null,
  confidence: number, rowExceptions: [{rowIndex, tag}] }`. Neznámý klíč
  štítku ve výstupu → zahodit (lekce enum), null = legitimní.
- Volání přes `AnthropicLlmClient` — přidat non-streaming convenience
  (sesbírat stream do stringu), `maxTokens` konstanta ~500, backend
  z `AiBackendResolver` s override ze settings (bod 9); model default
  backendu (doporučení do docs: levný model, např. Haiku, jde nastavit
  override backendem).
- Timeout + try/catch: výjimka → null výsledek, log přes ErrorLogger,
  nikdy nepropadne výš (D17).

### 8. `modules/core/exchange/src/Enrich/RowEnrichmentPipeline.php` (nový) + wiring

Orchestrátor obou vrstev; nahrazuje přímé volání `RowHistoryEnricher`:

- `enrichAtResult(array $canonical): array` — plný běh (D16/D17):
  1. `RowHistoryEnricher::enrich()` (beze změny),
  2. zbývá nepokrytý item řádek? ne → konec,
  3. IČO z canonical supplier → `resolveTagByRule` → hit? persist
     `_resolve.contentTag {tag, tagSource:'rule', ruleId}`,
  4. jinak `ContentTagClassifier` → persist
     `{tag, tagSource:'llm', tagConfidence, promptVersion, rowExceptions}`
     (null výstup → blok s `tag: null` se nezapisuje, jen log),
  5. `applyTagToRows(...)` (společné s fresh cestou, viz níž).
- `enrichFresh(array $canonical): array` — preview/apply (bez LLM):
  1. `RowHistoryEnricher::enrich()` (fresh, jako dnes),
  2. fresh re-check pravidla dle IČO — zásah **přepíše** persistnutý
     `contentTag` blok (deterministika bije LLM odhad, D16),
  3. `applyTagToRows(...)` nad persistnutým/čerstvým štítkem.
- `applyTagToRows`: pro každý nepokrytý item řádek (bez `item.ourCode`
  po Vrstvě 0, `skipped` řádky vynechat) vezmi tag řádku
  (`rowExceptions[rowIndex]` ?? `primaryTag`), `resolveItemForTag`,
  amountGuard, propiš trojici (jen prázdná pole, D3 vzor Vrstvy 0)
  a zapiš řádkový audit blok:
  ```jsonc
  "enrichment": {
    "matchedBy": "contentTag", "confidence": "medium",
    "tag": "vehicle.fuel", "tagSource": "rule"|"llm",
    "itemName": "…", "sourceItemId": 123,
    "suggested": { "ourCode": "…", "vatCode": "…", "account": "…" },
    "resolution": "item" | "accountOnly" | "ambiguous" | "unmapped" | "guarded"
  }
  ```
- **Precedence dominance (D13):** setting `contentTagBeforeDominance`
  (default true). True → dominantní položka (stupeň 3 Vrstvy 0) se
  uplatní až na řádky, které contentTag nepokryl → implementačně:
  `RowHistoryEnricher` dostane flag „bez dominance" a dominance se
  spustí jako krok 6 pipeline; false → stávající pořadí (dominance
  uvnitř Vrstvy 0), contentTag jen na zbytek. Zvolit nejmenší zásah do
  `RowHistoryEnricher` (např. veřejná metoda pro dominance krok), bez
  změny jeho chování mimo pipeline.
- Wiring: `public/index.php` (3 místa) + `AnalysisController` — místo
  `RowHistoryEnricher::create(...)` konstruovat
  `RowEnrichmentPipeline::create(...)` (factory staví enricher, resolver,
  classifier; classifier null-safe, když chybí backend → jen
  deterministická část). `/result` volá `enrichAtResult`, preview/apply
  `enrichFresh`. ISDOC větev (`IsdocImportService`) zůstává na čistém
  `RowHistoryEnricher` (ISDOC nese strukturované položky, obsahová
  eskalace tam nepatří — případně zvážit `enrichFresh` bez LLM;
  rozhodnout při implementaci, default: beze změny).

### 9. `core_mail_message_analyses.content_tag` + strop pásma + settings

- `modules/core/mail/tables/core_mail_message_analyses.jsonc`: sloupec
  `content_tag` (`enumString(40)`, nullable, cfgItem
  `core.exchange.contentTags`) + index. Zápis v `/result` transakci
  z `_resolve.contentTag.tag`.
- `AnalysisConfidenceResolver::capBandByRowCoverage`: řádek doplněný
  s `matchedBy: 'contentTag'` → strop `review` (D14) — rozšíření
  stávající podmínky (dnes low-confidence enrichment), contentTag je
  vždy „potvrzuje člověk".
- Settings (dle `docs/app-settings.md`; umístění: stávající stránka
  AI/analýzy, pokud existuje, jinak nová sekce): klíče
  `exchange.contentTag.beforeDominance` (bool, default true, D13) a
  `exchange.contentTag.backend` (FK/ndx na `core_ai_backends`, nullable
  = default backend, D18).

### 10. `modules/core/exchange/src/Enrich/ContentTagRuleCaptureHandler.php` (nový, D22)

Vzor `SupplierCodeCaptureHandler`: `stateChanged` na `docs_core_heads`,
`10→20` s lineage `aiExtraction`:

- Načti poslední úspěšnou analýzu zdrojové zprávy; pokud má
  `content_tag` a `tagSource='llm'` (z `_resolve.contentTag`), zapiš
  pravidlo `company_id → tag` (IČO z canonical supplier), origin
  `learned`, upsert:
  - žádné pravidlo → INSERT,
  - existující se **stejným** tagem → jen statistiky,
  - existující s **jiným** tagem → pravidlo SMAZAT (dodavatel s pestrým
    sortimentem — pravidlo by škodilo; audit log). `origin='user'`/`seed`
    se maže také? Ne — user/seed pravidla learning nikdy nemění
    (jen statistiky při shodě, při neshodě no-op + log).
- Registrace v `modules/core/exchange/module.jsonc`
  (`documentEventHandlers`) + rebuild cfg poznámka.
- Best-effort (výjimky dispatcher polyká), nikdy neblokuje vystavení.

### 11. Dokumentace

- `docs/ai.md` §4 — jedna věta + odkaz (vedle zmínky RowHistoryEnricher).
- `modules/core/mail/docs/ai-analysis.md` — nová sekce „Obsahová
  eskalace (content tags)": flow, D-tabulka odkazem, tvar audit bloků,
  precedence řetěz.
- `docs/exchange-format.md` — zmínka `_resolve.contentTag` (audit,
  additionalProperties).
- Nový `modules/core/exchange/docs/` netvoř — enrichment dokumentace
  žije v ai-analysis.md (konzistence s Vrstvou 0).
- `docs/README.md` / `tasks/README.md` — **neaktualizovat** (Davidova
  režie).

---

## Testy

PHPUnit, úzké filtry (`--filter ContentTag`, `--filter RowEnrichmentPipeline`):

1. **`ContentTagResolverTest`**: pravidlo hit/miss + normalizace IČO;
   resolution 1 položka → trojice; 2 položky → ambiguous; 0 položek →
   fallback účet z nabídky (mock offer helperu); prefix fallback;
   amountGuard nad/pod limitem; položka v Koši se nepočítá.
2. **`ContentTagClassifierTest`**: parsování validního JSON; neznámý
   štítek → zahozen; ne-JSON / výjimka klienta → null; enum v promptu
   obsahuje všechny klíče cfgItemu.
3. **`RowEnrichmentPipelineTest`**: eskalace se nespustí při plném
   pokrytí Vrstvou 0; rule přeskočí LLM; fresh re-check pravidla přepíše
   LLM štítek; rowException přebije primaryTag na svém řádku; propsání
   jen prázdných polí; D13 setting obě větve (dominance před/po);
   LLM selhání → canonical bez contentTag, žádná výjimka; determinismus
   fresh běhu.
4. **`AnalysisControllerResultTest`** (rozšíření): persist
   `_resolve.contentTag` + sloupec `content_tag`; strop pásma review
   u contentTag řádků; `/result` projde při výjimce classifieru.
5. **`ContentTagRuleCaptureHandlerTest`**: LLM štítek + 10→20 → INSERT
   learned; shoda → statistiky; neshoda learned → DELETE; neshoda user →
   no-op; rule-sourced štítek → no-op (učí se jen z LLM); bez IČO → no-op.
6. **`ItemDocumentTest`** (rozšíření): validace klíčů `content_tags`.
7. Drift guard: test, že všechny `contentTags` klíče v nabídkách
   (default + NPO) a v `contentTagDefaults` existují v cfgItemu
   (vzor ProfileSchemaDriftTest).

---

## Commit strategie

1. **Commit 1 — data a konfigurace:** cfgItem taxonomie, sloupec
   `economy_items.content_tags` + Document/Form, otagování a rozšíření
   nabídek + generátor, `contentTagDefaults`, tabulka
   `core_exchange_tag_rules` (438), drift test. (Schema první.)
2. **Commit 2 — deterministické jádro:** `ContentTagResolver` + offer
   helper + testy.
3. **Commit 3 — LLM + pipeline:** `ContentTagClassifier`,
   `RowEnrichmentPipeline`, wiring (index.php, AnalysisController,
   fresh cesty), sloupec `content_tag` na analýzách, strop pásma,
   settings, testy.
4. **Commit 4 — learning + dokumentace:** `ContentTagRuleCaptureHandler`
   + registrace + testy + docs.

---

## Hotovo když

- [ ] `ds-upgrade` projde: nové sloupce, tabulka 438, cfgItemy.
- [ ] Položka založená ze setup nabídky nese `content_tags`.
- [ ] Doklad plně pokrytý Vrstvou 0 → eskalace se nespustí (žádné LLM
      volání, ověřitelné testem).
- [ ] Doklad bez historie s pravidlem IČO → štítek deterministicky,
      trojice z otagované položky, bez LLM.
- [ ] Doklad bez historie a pravidla → LLM štítek při `/result`,
      persistnutý v `_resolve.contentTag` + sloupci `content_tag`;
      preview/apply resolvují fresh (otagování položky mezi analýzou
      a preview se projeví bez reanalýzy).
- [ ] Bez otagované položky → návrh aspoň fallback účtu z nabídky
      (`resolution: accountOnly`); `admin.other` a `goods.stock` bez
      návrhu → review.
- [ ] rowExceptions fungují (PHM účtenka s náplní do ostřikovačů:
      fuel řádky + consumables výjimka).
- [ ] ContentTag řádky vždy stropují pásmo na review.
- [ ] Apply dokladu s LLM štítkem založí learned pravidlo; další doklad
      téhož IČO jde bez LLM. Konfliktní štítek learned pravidlo maže.
- [ ] Selhání LLM nikdy neshodí `/result` ani preview/apply.
- [ ] Všechny testy zelené s úzkými filtry; drift test hlídá klíče.
- [ ] Dokumentace (ai.md, ai-analysis.md, exchange-format.md) aktuální.
