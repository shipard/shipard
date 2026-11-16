# Obsahové štítky — UI a mikrointerakce (Content Tag UI)

**Stav:** hotovo

> **D24 zrušeno #36 D1** (`tasks/exchange-no-item-removal.md`): volba
> „Jen účet — bez položky“ (userAction `noItem`) zanikla; text rozhodnutí
> níž je historický.

> Implementováno 2026-08-19. Odchylky od zadání:
> - **`noItem` je stringová userAction** (jako `skip`/`create`), ne objekt
>   `{kind}` — flat mapa userActions připouští jen stringy
>   (`MessageProposalApplier::expandUserActions`).
> - **Settings stránka = panel** `contentTags` (`ContentTagsSettings.svelte`,
>   vzor dsSetup) — server-driven settings page tabulku s akcemi neunese.
> - **Mazání pravidel = hard DELETE** (detail akce → generický CRUD DELETE),
>   ne koš: `unique(company_id)` by přes koš blokoval re-learning a learning
>   handler sám pravidla hard-DELETEuje. Tabulka zůstává bez docStates;
>   z JSONC odstraněno `hideFromNavigation` (vyřadilo by viewer i ze
>   settings navigace), z hlavního stromu tabulku skrývá settingsItems.
> - **Karta kind=info**; štítky vědomě bez mapování v nabídce (admin.other,
>   people.benefits) nekartují — karta by neměla co založit; goods.stock
>   bere čísla 501/504 z prvních aktivních analytik osnovy. Labely akcí
>   posílá server (passthrough vzor alertů). Sekundární akce „Upravit…"
>   (předvyplněný form) odložena do follow-up (open_form neumí defaultData).
> - **Endpointy**: vedle materialize přibyly `GET /_exchange/content-tags/overview`
>   a `POST /_exchange/content-tags/tag-items` pro settings panel; vlastní
>   dispatcher `contentTags` → `ContentTagsController` (dispatchExchange
>   nenese auth/tables). Materializace = `AccountingItemMaterializer`
>   (economy.items) se save-closure seamem — `SetupController` deleguje,
>   response `/_setup/accounting-items` beze změny (regresní testy prošly
>   beze změny).
>
> Ověřeno na dev DS 2026-08-19 (po `ds-upgrade` — nový viewer/panel/
> settingsItems v compiled configu).

**Cíl:** Zviditelnit a zprovoznit obsahové návrhy z tasku
[content-tag-enrichment.md](content-tag-enrichment.md) v UI: review modal
umí zobrazit a **použít** návrh „jen účet", dashboard nabízí mikrointerakci
„Nová kategorie — založit položku?", settings stránka dává přehled mapování
štítků, reverzní otagování migrovaných položek (D15) a správu pravidel
IČO→štítek.

---

## Návaznost

- **Task 1 hotový a ověřený** na dev DS: 12 dokladů, 11 oštítkováno,
  learning pravidla vznikají, fresh resolution po otagování položek
  povyšuje návrhy bez reanalýzy (D16 funguje).
- Poznatky z testu, které tento task řeší:
  1. Review modal (`DocumentExchangePreview.svelte`) nemá sloupec Účet —
     `accountOnly` návrh je neviditelný (jen ⟲ badge).
  2. Badge tooltip zná jen historii: `matchKindKey` mapuje `contentTag` na
     „přesná shoda" a `sourceDocId` chybí → „doklad #undefined".
  3. Apply gate (`DocumentExchangePreviewModal.canApply` / `allDecided`)
     vyžaduje rozhodnutí u každého nenapárovaného itemu — `accountOnly`
     řádek nejde použít bez založení položky, ačkoli řádek jen s účtem je
     validní.
- Staví na: `RowEnrichmentPipeline` + `ContentTagResolver` (core.exchange),
  nabídka účetních položek se `contentTags`
  (`accountingItemsDefault/Npo.jsonc` + `SetupController` generátor),
  dashboard FeedSources (docs/dashboard.md; registrace napevno, vzor
  `MailSuggestionsSource`), settings pages (docs/app-settings.md),
  decision UI review modalu (userActions / clientResolveFlat).

## Nová rozhodnutí

| D | Rozhodnutí |
|---|---|
| D23 | Review modal: podmíněný sloupec „Účet" v tabulce řádků (render, když aspoň jeden řádek `account` nese). Badge tooltip pro `matchedBy='contentTag'`: „Obsahová klasifikace — {label štítku} ({pravidlo dodavatele \| AI})" + stávající řádek „Doplněno: …" + řádek vatHint, je-li přítomen. Lokalizovaný `tagLabel` doplňuje server do enrichment bloku při čtení (fresh cesty — preview); persist z `/result` label nenese (běží bez uživatelského jazyka), frontend fallbackuje na klíč štítku. |
| D24 | Apply gate: nová row-level userAction **`noItem`** („Jen účet — bez položky"). Klient ji nabídne v decision dialogu položky, jen když řádek nese `account` (z enrichmentu nebo extrakce); `allDecided` ji počítá jako rozhodnutí. Server (`DocumentApplier` reconcile) při `noItem` pořídí řádek bez item FK, s účtem; chybějící account při `noItem` = validační chyba apply. |
| D25 | Dashboard karta „Nová kategorie": zdroj `ContentTagSuggestionsSource` v `core.exchange` (závislosti na mail i economy sedí). Jedna karta per štítek (dedupe přes otevřené návrhy všech zpráv). Query-driven bez dismiss stavu — karta zmizí sama, jakmile pro štítek existuje živá otagovaná položka nebo žádný otevřený návrh štítek nepotřebuje. U `goods.stock` karta nabízí volbu účtu (501/504 z aktivní osnovy) přímo v akci. |
| D26 | Materializační endpoint `POST /api/v1/_exchange/content-tags/materialize` `{tag, account?}`: založí položku z nabídky aktivní varianty osnovy včetně `content_tags` — sdílená služba extrahovaná ze `SetupController` generátoru (žádná duplikace logiky). `goods.stock` (a obecně štítek bez položky v nabídce) vyžaduje `account`; štítek s existující živou otagovanou položkou → 409. |
| D27 | Settings stránka „Obsahové štítky" (sekce items): (a) přehled taxonomie se stavem mapování — otagované položky / default účet z nabídky / bez mapování — s akcí Založit per štítek (endpoint D26); (b) sekce „Neotagované položky" s reverzními návrhy účet→štítek dle nabídky a hromadným potvrzením (D15). Hromadné založení výchozích položek se **neduplikuje** — existující setup panel nabídky už zakládá otagovaně (D11b splněno), stránka na něj jen odkáže. |
| D28 | Správa pravidel: viewer `core_exchange_tag_rules` v settings (label štítku, IČO + jméno partnera je-li dohledatelné, origin, statistiky zásahů) + form: změna štítku (přepne `origin` na `user`), smazání. Žádné ruční zakládání pravidel v v1 (vznikají učením; `seed` importem později). |

## Scope

**In:**
1. Review modal: sloupec Účet, contentTag badge wording, vatHint (D23).
2. userAction `noItem` — frontend decision dialog + `allDecided` +
   server-side reconcile v `DocumentApplier` (D24).
3. `tagLabel` v enrichment bloku fresh cest (D23).
4. `ContentTagSuggestionsSource` + karta na dashboardu (D25).
5. Materializační služba + endpoint (D26) + refactor `SetupController`
   na sdílenou službu.
6. Settings stránka „Obsahové štítky" vč. reverzního otagování (D27).
7. Viewer + form pravidel (D28).
8. Testy, dokumentace, i18n (cs/en, `npm run check:i18n`).

**Out:**
- Auto režim pravidel, sender osa, dispozice (fáze šumu).
- Import seedu pravidel (formát řeší task validačního batche).
- Mikrointerakce „catering — reprezentace vs. cestovné" (až po praxi).
- Per-DS rozšíření taxonomie.
- Sloupec `content_tag` na `docs_core_heads`.

---

## Změny soubor po souboru

### 1. Review modal (frontend, D23 + D24 klient)

`frontend/src/components/exchange/DocumentExchangePreview.svelte`:

- Tabulka řádků: podmíněný sloupec „Účet" (`row.account`), render když
  `canonical.rows.some(r => r.account)`. i18n klíč
  `exchange.preview.row.account`.
- `enrichTitle(e)`: větev `e.matchedBy === 'contentTag'` — header
  `exchange.preview.enrich.contentTag` =
  „Obsahová klasifikace — {tag} ({source})", `{tag}` = `e.tagLabel ?? e.tag`,
  `{source}` z `e.tagSource` (`rule` → „pravidlo dodavatele", `llm` → „AI");
  dále stávající řádky item/filled; nový řádek při `e.vatHint`
  (`exchange.preview.enrich.vatHint.nonDeductible` = „DPH: pozor,
  typicky bez nároku na odpočet"). `matchKindKey` se pro contentTag
  nevolá (guard), ať nevzniká „přesná shoda".
- Decision dialog položky (komponenta rozhodnutí `rows[i].item`): nová
  volba **„Jen účet — bez položky"** viditelná jen když řádek má
  `account`; zapíše userAction `{ kind: 'noItem' }` na `rows[i].item`.

`frontend/src/components/exchange/DocumentExchangePreviewModal.svelte`:

- `allDecided`: userAction `noItem` je platné rozhodnutí (stávající
  kontrola `ua[p] !== undefined` už vyhoví — ověřit, jinak upravit).

### 2. `DocumentApplier` — reconcile `noItem` (D24 server)

`modules/core/exchange/src/Document/DocumentApplier.php`:

- Zpracování userActions: `rows[i].item` s `kind='noItem'` → řádek se
  pořídí bez item FK; `account` řádku povinný (jinak issue error
  `no_item_requires_account`, apply selže srozumitelně). Pohyb řádku
  (`applyRowOperations`) u řádku bez itemu → default docTypu (stávající
  fallback). `_resolve.rows[i].item.status` v response po pinu →
  `skipped`/`noItem` (nový status jen v `_resolve`, ne ve schématu —
  `additionalProperties`).
- Guard: `noItem` na řádku, kde AI/enrichment `ourCode` existuje, je
  legální (uživatel návrh přebíjí) — pin má absolutní přednost (D3).

### 3. `tagLabel` ve fresh enrichment bloku (D23)

`RowEnrichmentPipeline` (fresh cesty volané z HTTP kontextu): do
řádkového `enrichment` bloku a do `_resolve.contentTag` doplnit
`tagLabel` z lokalizovaného cfgItem `core.exchange.contentTags`
(jazyk z requestu — pipeline dostane label resolver / localized cfg;
zvolit nejmenší zásah dle toho, jak fresh cesty dnes dostávají
localized config). `/result` běh label nezapisuje.

### 4. `ContentTagSuggestionsSource` (D25)

`modules/core/exchange/src/Dashboard/ContentTagSuggestionsSource.php`
(nový; vzor `MailSuggestionsSource`, registrace napevno vedle ní):

- Query: otevřené návrhy (poslední úspěšná analýza per zpráva,
  `resolution IS NULL`, zpráva mimo Archiv/Koš) s `content_tag NOT NULL`,
  jejichž štítek **nemá** živou otagovanou položku (SQL přes
  `economy_items.content_tags`; u `goods.stock` navíc bez default
  mapování → karta vždy, dokud položka nevznikne).
- Karta per štítek: titulek „Nová kategorie: {label}", podtitulek
  „{n} dokladů čeká · návrh: {starterItem name} ({účet z nabídky})",
  akce **Založit položku** (volá D26 endpoint; u `goods.stock` akce
  s volbou účtu — dvě tlačítka „Jako materiál (501…)" / „Jako zboží
  (504…)" s čísly z aktivní osnovy), sekundární akce „Upravit…"
  (otevře form nové položky předvyplněný — nice-to-have, smí spadnout
  do follow-up).
- Po založení se feed přirozeně přepočítá (query-driven); žádný dismiss
  stav.

### 5. Materializační služba + endpoint (D26)

- `modules/economy/items/src/AccountingItemsOffer.php` (nový, pokud
  nevznikl už v tasku 1 jako helper fallback účtů — pak rozšířit):
  čtení nabídky aktivní varianty + **generátor jedné položky**
  extrahovaný ze `SetupController::accountingItems` (SetupController
  službu použije, chování `/_setup/accounting-items` beze změny).
- `POST /api/v1/_exchange/content-tags/materialize` (routing vedle
  ostatních `_exchange` rout): body `{tag, account?}`; validace: tag
  existuje v cfgItem; živá otagovaná položka → 409 `ALREADY_MAPPED`;
  položka v nabídce pro tag → založit (s `account` override, je-li dán);
  tag bez položky v nabídce → `account` povinný, položka se založí
  s kódem = číslo účtu (sufix při kolizi, konvence nabídky) a
  `content_tags=[tag]`. Response `{itemId, code}`.
- Zápis přes `TableGateway::saveDocument` (validace + hooks) —
  žádný přímý INSERT.

### 6. Settings stránka „Obsahové štítky" (D27)

- Registrace v `modules/economy/items/module.jsonc` (settings sekce
  `items`). Mechanismus dle možností settings pages
  (docs/app-settings.md): pokud server-driven page neunese
  tabulku s akcemi, vlastní settings route + Svelte komponenta
  (vzor existující custom settings, ověřit ve frontend.md).
- Obsah: (a) tabulka taxonomie — štítek (label), stav (otagované
  položky výčtem / default účet z nabídky / bez mapování), akce
  Založit (D26); (b) sekce „Neotagované položky": živé položky
  s `accounting_account`, jejichž účet má v nabídce aktivní varianty
  jednoznačný štítek a `content_tags` je prázdné → návrh; checkboxy +
  „Otagovat vybrané" (bulk PATCH přes `TableGateway`, nový endpoint
  `POST /_exchange/content-tags/tag-items` `{items: [{id, tags}]}` —
  nebo reuse generického form save, rozhodne implementace dle
  jednoduchosti); kolizní účty (víc štítků) bez návrhu, poctivě.
  (c) odkaz na setup panel nabídky („Hromadné založení výchozích
  položek…").

### 7. Viewer + form pravidel (D28)

- `modules/core/exchange/module.jsonc`: viewer
  `core.exchange.tagRules` (settings sekce, ne hlavní navigace) +
  form + documentClass `TagRuleDocument` (validace: tag klíč existuje;
  editace štítku → `origin='user'` v beforeSave při dirty change).
- Viewer sloupce: štítek (label), IČO (+ jméno partnera lookupem přes
  `base_persons_persons.company_id`, best-effort), origin badge,
  hit_count, last_hit_at. Smazání standardním flow.

### 8. i18n + dokumentace

- `frontend/src/i18n/cs.js` + `en.js`: nové klíče (enrich.contentTag,
  row.account, noItem volba, karta, settings stránka). `npm run
  check:i18n` z `frontend/`.
- `modules/core/mail/docs/ai-analysis.md` — sekce obsahové eskalace:
  doplnit UI část (karta, noItem, settings).
- `docs/dashboard.md` — nový zdroj karet (řádek do tabulky zdrojů).

---

## Testy

1. **`DocumentApplierNoItemTest`**: pin `noItem` + account → apply projde,
   řádek bez itemu s účtem, pohyb z default docTypu; `noItem` bez
   accountu → issue error; `noItem` přebíjí enrichment návrh.
2. **`ContentTagSuggestionsSourceTest`**: štítek bez položky + otevřený
   návrh → karta; otagovaná položka existuje → bez karty; applied/rejected
   návrhy nekartují; dedupe víc zpráv → jedna karta; goods.stock varianta.
3. **`AccountingItemsOffer` / materialize endpoint**: založení z nabídky
   vč. tagů; 409 při existující položce; goods.stock bez accountu → 422;
   sufix při kolizi kódu; SetupController regrese (offer generátor
   beze změny chování).
4. **Reverzní návrhy**: jednoznačný účet → návrh; kolizní účet → bez
   návrhu; bulk otagování přes gateway.
5. **`TagRuleDocumentTest`**: změna štítku → origin user; validace klíče.
6. Frontend: `check:i18n`; manuální scénář na dev DS (viz Hotovo když).

---

## Commit strategie

1. **Commit 1:** `noItem` end-to-end (applier + modal + decision dialog
   + testy) — samostatná hodnota, odblokuje apply accountOnly dokladů.
2. **Commit 2:** review modal prezentace (sloupec Účet, badge wording,
   tagLabel fresh cest, vatHint) + i18n.
3. **Commit 3:** materializační služba + endpoint + dashboard karta
   + testy.
4. **Commit 4:** settings stránka (přehled + reverz) + viewer/form
   pravidel + dokumentace.

---

## Hotovo když

- [x] `accountOnly` doklad jde použít bez zakládání položky: volba
      „Jen účet — bez položky" → Použít aktivní → Koncept má řádek
      s účtem bez položky.
- [x] Badge u contentTag řádku říká „Obsahová klasifikace — {štítek}
      (pravidlo dodavatele / AI)", žádné „doklad #undefined"; účet je
      vidět ve sloupci.
- [x] Nepokrytý štítek s otevřeným návrhem ukáže dashboard kartu;
      Založit položku → karta zmizí a návrhy se při dalším otevření
      povýší na plnou trojici (bez reanalýzy).
- [x] `goods.stock` karta nabízí volbu materiál/zboží a založí
      odpovídající položku.
- [x] Settings stránka ukazuje stav mapování všech štítků; reverzní
      otagování otaguje jednoznačné položky bulkem; kolizní účty bez
      návrhu.
- [x] Pravidla jsou vidět, jdou smazat a přeštítkovat (origin → user).
- [x] `check:i18n` zelené; všechny testy zelené s úzkými filtry.
- [x] Manuální scénář na dev DS: zpráva od dodavatele s learned
      pravidlem → nová analýza jde přes pravidlo (tagSource rule,
      hit_count roste), karta/apply flow bez zádrhelu.
