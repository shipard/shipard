# Pravidla odesílatelů — archivace po analýze a pojistka učení

**Stav:** hotovo — implementováno 2026-10-06 (D1–D10); ruční proklik na dev DS dle sekce „Hotovo když“; odchylky od zadání v sekci „Poznámky k implementaci“

## Cíl

Pravidlo odesílatele (`core_mail_sender_rules`, `tasks/registry-phase3.md`)
dnes umí jedinou akci `archive`: zpráva od adresy (nebo domény) vznikne
při příjmu rovnou v Archivu, **bez analýzy**. To je správné pro čistý šum,
ale nebezpečné u **smíšených odesílatelů** — adres, které posílají šum
i doklady. Typicky skenovací služba (sken obálky i sken obsahu) nebo
dodavatel služby (upozornění, potvrzení i faktury). Pravidlo by od nich
spolklo i faktury.

A učící handler `SenderRuleSuggestionHandler` takové pravidlo navrhne sám:
po třech ručně odklizených zprávách od adresy nabídne „Vždy archivovat
poštu od …?“, aniž by zjišťoval, jestli od ní chodí i doklady.

Na testovacím serveru jsou dva největší zdroje řádků v sekci **Ostatní**
právě takoví odesílatelé — vedle desítek čekajících řádků od nich přišly
i desítky faktur a dokumentů Spisovny. Zároveň přes 80 % čekajících řádků
Ostatní pochází od adres se třemi a více zprávami, takže pravidla mají
velký potenciál — jen je potvrzení pravidla dnes neuklidí, platí až pro
novou poštu.

Po tomto tasku:

- pravidlo má druhou akci **`archiveIfOther`** — „Archivovat, když
  neobsahuje doklad ani dokument“: zpráva projde analýzou, a když v ní AI
  nenajde doklad ani dokument Spisovny, odklidí se do Archivu; faktury od
  téhož odesílatele chodí dál normálně;
- učící handler ji navrhne odesílateli, od kterého už přišel doklad nebo
  dokument;
- potvrzení pravidla odklidí i řádky od té adresy, které už čekají
  v Ostatních.

Vztah k zásadě D7 z `docs/registry-mvp.md` (AI auto-archiv jen jako
opt-in): `archiveIfOther` není plošný AI auto-archiv. Uživatel ho potvrzuje
pro konkrétní adresu (deterministický match), AI jen určí, že ve zprávě nic
není. Cena: analýza se zaplatí, úspora je v klikání, ne v AI.

## Před implementací přečti

- `tasks/registry-phase3.md` — pravidla odesílatelů, pre-triage, digest,
  učení
- `modules/core/mail/tables/core_mail_sender_rules.md`
- `docs/registry-mvp.md` §8 (zásady D6, D7)
- `modules/core/mail/docs/ai-analysis.md` → *Klasifikace typu zprávy*
  a zpracování výsledku; `docs/mail/api-contract.md` (kroky `/result`)
- `docs/dashboard.md` — digest auto-archivu, karta návrhu pravidla, karta
  ostatní pošty; `tasks/dashboard-other-row-title.md`
- `docs/document-system.md` — `documentEventHandlers` (`stateChanged`
  běží po commitu)

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — Odesílatel s doklady.** Od adresy existuje v DS — v libovolném
  stavu, i v Koši a Archivu — aspoň jedna zpráva navázaná na cílovou
  entitu (`target_row IS NOT NULL`), nebo zpráva s `primary_type` ≠
  `other`, kterou určila AI, ISDOC nebo uživatel (`primary_type_source`
  ∈ `ai`, `isdoc`, `user`). Výchozí typ schránky (`mailbox`) bez navázané
  entity se nepočítá — nic nedokazuje. Historie z importu se počítá přes
  navázanou entitu.
- ✓ **D2 — Učící handler u odesílatele s doklady navrhne `archiveIfOther`**,
  jinak `archive` jako dnes. Poznámka návrhu (`notice`) to řekne:
  „Navrženo po 3 ručních odklizeních; od adresy chodí i doklady“.
- ✓ **D3 — Nová dispozice `archiveIfOther`** v
  `core.mail.senderRuleDispositions`: cs „Archivovat, když neobsahuje doklad
  ani dokument“. Stávající `archive` (kód beze změny) dostane popisek
  „Archivovat hned, bez analýzy“. Karta návrhu pro novou dispozici:
  „Archivovat poštu od {pattern}, když neobsahuje doklad ani dokument?“
- ✓ **D4 — Kdy se pravidlo po analýze uplatní.** V `AnalysisController::result`
  ve stejné transakci, když platí všechno najednou:
  běh nevrátil dokument (`document` null); výsledný `primary_type` zprávy
  je `other` (ruční volba uživatele má přednost — čte se po zápisu
  klasifikace); zpráva je stále v Nové (10); je to **první úspěšná**
  analýza zprávy; zpráva nevznikla ručním nahráním (`source_type` ≠ 1);
  jistota klasifikace prošla D5; odesílatel má potvrzené pravidlo (D6).
  Pak: `docState` 80 + `auto_disposed_by/at`, `analysis_state` zůstává 30,
  pravidlu `hit_count + 1` a `last_hit_at`. Selhaná analýza se neodklízí
  nikdy (zůstává chybová karta). Podmínka „první úspěšná analýza“ znamená,
  že zprávu vrácenou z Archivu nebo ručně reanalyzovanou pravidlo znovu
  neodklidí.
- ✓ **D5 — Práh jistoty = `review` práh AI profilu běhu**
  (`AnalysisConfidenceResolver::thresholdsForProfile()`, výchozí 0,6).
  Jistota = `message_classification.confidence`. Pod prahem, nebo když
  jistota chybí, zpráva zůstane v Ostatních. Práh `ready` (0,9) by vyřadil
  skeny obálek, které typicky mají jistotu 0,8–0,9.
- ✓ **D6 — Přednost má konkrétnější pravidlo bez ohledu na dispozici**
  (e-mail > doména), jeho dispozice určí fázi. Při příjmu archivuje jen
  pravidlo `archive`; po analýze a při D8 archivuje zprávu `other`
  **jakékoli** potvrzené pravidlo — `archive` znamená „všechno“, tedy
  i ostatní poštu. Funguje tak kombinace „doména `archive`, konkrétní
  adresa `archiveIfOther`“.
- ✓ **D7 — Vrácení bez nové analýzy.** „Vrátit vše“ z digestu u zprávy
  archivované po analýze ponechá `analysis_state` 30 — zpráva se vrátí
  jako řádek Ostatní a AI se znovu neplatí. Rozlišení podle
  `analysis_state` (0 = archivováno před analýzou → do fronty jako dnes,
  30 = po analýze → beze změny), bez nového sloupce.
- ✓ **D8 — Potvrzení pravidla odklidí čekající řádky Ostatní** od adresy
  (domény), pro které je pravidlo nejkonkrétnějším zásahem (D6). Podmínky
  jako D4 a D5 kromě „první analýzy“; jistota a práh z poslední úspěšné
  analýzy zprávy. Stejný audit `auto_disposed_*` — zprávy se ukážou
  v digestu a „Vrátit vše“ funguje. Uplatní se při každém přechodu
  pravidla do stavu 40 (karta návrhu i formulář).
- ✓ **D9 — Ručně zakládané pravidlo má výchozí dispozici `archiveIfOther`**
  (výchozí hodnota sloupce i default v `SenderRuleDocument::beforeSave`).
  „Archivovat hned“ je vědomá volba pro odesílatele, který doklady nikdy
  neposílá.
- ✓ **D10 — Rozsah:** viz Scope.

## Scope

**V rozsahu:** katalog dispozic, výchozí hodnota sloupce, matcher, služba
`PostAnalysisDisposer` (D4–D6, D8), hook v `result`, handler potvrzení
pravidla (D8), učící handler (D1, D2), undo (D7), titulek karty návrhu,
testy, dokumentace a nápověda.

**Mimo rozsah:**

- varování ve formuláři pravidla, když někdo nastaví „Archivovat hned“
  odesílateli s doklady;
- podklasifikace ostatní pošty a nové primární typy (výzva k úhradě,
  upomínka, výpis);
- změny frontendu — formulář pravidla už má select dispozice (jen dostane
  druhou volbu), dashboard po potvrzení pravidla feed znovu načte a digest
  karta se objeví sama;
- nové endpointy, změny promptu.

## 1. Katalogy a schéma

`modules/core/mail/config/senderRuleDispositions.jsonc` — upravit hlavičkový
komentář (dispozice už nejsou jen budoucí rozšíření) a:

```jsonc
    // Po analýze: archivuje, jen když AI ve zprávě nenašla doklad ani
    // dokument Spisovny (primary_type `other`). Bezpečný výchozí stav (D9).
    "archiveIfOther": {
        "name": "Archive when it contains no document",
        "name:cs": "Archivovat, když neobsahuje doklad ani dokument",
        "name:en": "Archive when it contains no document",
        "order": 10
    },
    // Před analýzou: zpráva vznikne rovnou v Archivu, AI ji nečte.
    "archive": {
        "name": "Archive immediately, without analysis",
        "name:cs": "Archivovat hned, bez analýzy",
        "name:en": "Archive immediately, without analysis",
        "order": 20
    }
```

`modules/core/mail/tables/core_mail_sender_rules.jsonc`: `disposition` →
`"default": "archiveIfOther"` (enumString(20), 14 znaků se vejde;
`ds-upgrade` udělá bezpečný MODIFY). `SenderRuleDocument::beforeSave`:
default `archiveIfOther`.

`modules/core/mail/config/feedTexts.jsonc` — nový klíč:

```jsonc
    // Titulek karty návrhu pravidla s dispozicí archiveIfOther (D3).
    "senderRule.titleIfOther": {
        "text": "Archive mail from {pattern} when it contains no document?",
        "text:cs": "Archivovat poštu od {pattern}, když neobsahuje doklad ani dokument?",
        "text:en": "Archive mail from {pattern} when it contains no document?"
    },
```

## 2. `SenderRuleMatcher` a příjem pošty (D6)

`SenderRuleMatcher::match()` přestane filtrovat `disposition = 'archive'` —
vrací nejkonkrétnější potvrzené pravidlo (stav 40, e-mail > doména)
s jeho `disposition`. Docblock přepsat (dispozici vyhodnocuje volající).

`MailController::receiveIncoming`: pre-triage jen pro dispozici `archive`:

```php
        $matchedRule = new SenderRuleMatcher($dibi)->match($fields['sender_email']);
        // D6: při příjmu archivuje jen „Archivovat hned“; archiveIfOther
        // nechá zprávu projít normální cestou (předzpracování, ISDOC, AI).
        $preTriageRule = ($matchedRule['disposition'] ?? null) === SenderRuleDispositions::ARCHIVE
            ? $matchedRule
            : null;
```

… a dál všude místo `$matchedRule` použít `$preTriageRule` (plán
předzpracování, `insertIncomingMessage`, `hit_count`, ISDOC / runner).

Konstanty dispozic na jednom místě — malá třída
`modules/core/mail/src/SenderRuleDispositions.php` s `ARCHIVE = 'archive'`
a `ARCHIVE_IF_OTHER = 'archiveIfOther'` (vzor `PrimaryTypes`), ne literály
v každém souboru.

## 3. Služba `modules/core/mail/src/PostAnalysisDisposer.php` (D4–D6, D8)

Jediné místo s logikou „archivovat ostatní poštu podle pravidla“; volá ji
hook v `result` (krok 4) i handler potvrzení pravidla (krok 5).

```php
final class PostAnalysisDisposer
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * D4–D6: po zápisu výsledku analýzy, uvnitř transakce resultu.
     * Vrací id pravidla, které zprávu archivovalo, jinak null.
     */
    public function afterResult(
        int $messageNdx,
        bool $hasDocument,
        ?float $classificationConfidence,
        ?int $profileNdx,
    ): ?int;

    /**
     * D8: odklidí čekající řádky Ostatní, pro které je pravidlo
     * nejkonkrétnějším zásahem. Vrací počet archivovaných zpráv.
     */
    public function applyToWaiting(int $ruleId): int;
}
```

`afterResult()` — pořadí kontrol (levné první):

1. `$hasDocument` → null; `$classificationConfidence === null` → null.
2. Řádek zprávy (`sender_email`, `primary_type`, `docState`, `source_type`)
   — čte se **po** `applyMessageClassification()`, aby platila ruční volba
   uživatele. `primary_type !== 'other'`, `docState !== 10` nebo
   `source_type === 1` → null.
3. Počet úspěšných analýz zprávy (`status = 2`) **včetně právě vloženého
   řádku** musí být přesně 1.
4. `confidence < thresholdsForProfile($profileNdx)['review']` → null.
5. `SenderRuleMatcher::match()` — žádné pravidlo → null. Jakákoli
   dispozice archivuje (D6).
6. Archivace (společná privátní metoda):

```php
        // UPDATE … SET docState = 80, docStateMain = <z cfgItem
        //   core.mail.docStatesIncoming, fallback 4 — vzor
        //   MailController::resolveIncomingMainState>, auto_disposed_by,
        //   auto_disposed_at, modified
        // WHERE id IN (…) AND docState = 10   ← pojistka proti souběhu
        // UPDATE core_mail_sender_rules SET hit_count = hit_count + N,
        //   last_hit_at = …
```

`applyToWaiting()` — kandidáti jedním dotazem, stejná množina jako řádky
Ostatní (`MailSuggestionsSource::fetchNotInvoiceRows()`), navíc profil
a jistota poslední úspěšné analýzy:

```sql
SELECT m.id, m.sender_email, a.profile,
       JSON_VALUE(a.analysis_json, '$.message_classification.confidence') AS cls_confidence
FROM core_mail_incoming_messages m
JOIN core_mail_message_analyses a ON a.id = (
    SELECT a2.id FROM core_mail_message_analyses a2
    WHERE a2.message = m.id AND a2.status = 2
    ORDER BY a2.analyzed_at DESC, a2.id DESC LIMIT 1)
WHERE m.docState = 10 AND m.analysis_state = 30
  AND m.primary_type = 'other' AND m.source_type <> 1
  AND NOT (a.canonical_json IS NOT NULL AND a.resolution IS NULL)
  AND <vzor pravidla>
```

`<vzor pravidla>`: e-mail `LOWER(m.sender_email) = %s`, doména
`SUBSTRING_INDEX(LOWER(m.sender_email), '@', -1) = %s` (přesná doména —
jako matcher, **ne** subdomény jako pravidla předzpracování). V PHP pak
filtr: `match(sender_email)['id'] === $ruleId` (D6) a jistota ≥ `review`
práh profilu analýzy (prahy cachovat per profil). Pravidlo, které není ve
stavu 40, → 0.

## 4. Hook v `AnalysisController::result` (D4)

Nový krok za zápisem titulku (krok 8), před commitem, **best-effort**
stejně jako partner a titulek — selhání zaloguje warning a výsledek analýzy
se uloží:

```php
            // 9) Pravidlo odesílatele po analýze (tasks/mail-sender-rules-after-analysis.md D4–D6).
            try {
                $this->postAnalysisDisposer()->afterResult(
                    $messageNdx,
                    $document !== null,
                    $this->classificationConfidence($body),
                    $profileNdx,
                );
            } catch (\Throwable $e) {
                ErrorLogger::warn('AnalysisController::result post-analysis disposal failed', [...]);
            }
```

`classificationConfidence()` čte `message_classification.confidence`
s fallbackem na `analysis_json` (vzor `applyMessageClassification()`);
nečíselná nebo chybějící hodnota → null. Disposer dostane
`new DataSourceConnection($dibi)` nad stejným spojením — běží v transakci
resultu.

## 5. Handler potvrzení pravidla (D8)

Nová třída `modules/core/mail/src/SenderRuleConfirmedHandler.php`
(`AbstractDocumentEventHandler`), registrace v `module.jsonc` →
`documentEventHandlers` pro tabulku `core_mail_sender_rules`, událost
`stateChanged`. Při `$newState === 40` (z jakéhokoli stavu) zavolá
`PostAnalysisDisposer::applyToWaiting($data['id'])` nad
`new DataSourceConnection($this->db)` a `$this->config`. Běží po commitu,
výjimky loguje a polyká dispatcher — nikdy neblokuje potvrzení.

## 6. Učící handler (D1, D2)

`SenderRuleSuggestionHandler::insertSuggestion()`: před vložením zjistí
odesílatele s doklady (D1) jedním dotazem:

```sql
SELECT 1 FROM core_mail_incoming_messages
WHERE LOWER(sender_email) = %s
  AND (target_row IS NOT NULL
       OR (primary_type <> 'other' AND primary_type_source IN ('ai', 'isdoc', 'user')))
LIMIT 1
```

Podle výsledku `disposition` = `archiveIfOther` a `notice`
„Navrženo po 3 ručních odklizeních; od adresy chodí i doklady“, jinak
`archive` a dnešní text. Docblock třídy doplnit.

`MailDigestSource::fetchSuggestedRules()` vybere i `disposition`;
`buildRuleSuggestionCard()` volí titulek `senderRule.titleIfOther` pro
`archiveIfOther`, jinak `senderRule.title`. Akce karty beze změny.

## 7. Vrácení (D7)

`SenderRulesController::undoAutoArchive`:

```php
            // D7: zpráva archivovaná po analýze se vrací bez nové analýzy.
            $doc['analysis_state'] = (int) ($doc['analysis_state'] ?? 0) === self::ANALYSIS_ANALYZED
                ? self::ANALYSIS_ANALYZED
                : $analysisState;
```

Docblock kontroleru a komentář `undoAutoArchiveFlow` v
`frontend/src/components/dashboard/Dashboard.svelte` („vč. re-queue
analýzy“) upravit — jen text komentáře.

## 8. Testy

Unit:

- `SenderRuleMatcherTest` — `testMatchFiltersConfirmedStateAndArchiveDisposition`
  přepsat: SQL filtruje jen stav 40, dispozice se vrací; nový test, že
  e-mailové `archiveIfOther` vyhraje nad doménovým `archive`.
- `SenderRuleSuggestionHandlerTest` — odesílatel s doklady → návrh
  `archiveIfOther` + poznámka; bez dokladů → `archive` jako dnes.
- `PostAnalysisDisposerTest` (nový) — `afterResult`: archivuje při splnění
  všech podmínek; null pro dokument, `primary_type` ≠ `other`, stav ≠ 10,
  ruční nahrání, druhou úspěšnou analýzu, jistotu pod prahem i chybějící
  jistotu, žádné pravidlo; `archive` pravidlo archivuje také (D6);
  `applyToWaiting`: jen zprávy, kde je pravidlo nejkonkrétnější, jistota
  z poslední analýzy, pravidlo mimo stav 40 → 0.
- `MailDigestSourceTest` — titulek karty návrhu podle dispozice.

Integrační (`tests/Integration/Mail/`, zdroj dat s režimem *volný*
z `CLAUDE.local.md`, viz `tests/Integration/README.md`):

- `IngestPreTriageTest` — pravidlo `archiveIfOther` zprávu při příjmu
  **nearchivuje** (Nová, ve frontě); `archive` jako dnes.
- `AnalysisResultEndpointTest` — `/result` s `other` od adresy
  s `archiveIfOther` → Archiv, `analysis_state` 30, `auto_disposed_*`,
  `hit_count`; tentýž běh s dokumentem → beze změny.
- `SenderRulesEndpointTest` — potvrzení pravidla odklidí čekající řádek
  Ostatní (D8); undo vrátí zprávu archivovanou po analýze s
  `analysis_state` 30 a zprávu z pre-triage do fronty (D7).

```bash
vendor/bin/phpunit --filter 'SenderRuleMatcherTest|SenderRuleSuggestionHandlerTest|PostAnalysisDisposerTest|MailDigestSourceTest|FeedTextsCatalogTest'
SHIPARD_INTEGRATION_DS_PATH=<volný DS> vendor/bin/phpunit --filter 'IngestPreTriageTest|AnalysisResultEndpointTest|SenderRulesEndpointTest'
```

## 9. Dokumentace a nápověda

- `modules/core/mail/tables/core_mail_sender_rules.md` — úvod (dvě
  dispozice, kdy která platí), sloupec `disposition` (výchozí
  `archiveIfOther`), D6 přednost, D8.
- `modules/core/mail/tables/core_mail_incoming_messages.md` —
  `auto_disposed_by/at`: „při příjmu nebo po analýze“.
- `modules/core/mail/README.md` — popis tabulky pravidel a endpointu undo.
- `modules/core/mail/docs/ai-analysis.md` a `docs/mail/api-contract.md` —
  krok `/result`: archivace podle pravidla odesílatele (D4).
- `docs/dashboard.md` — digest (zprávy archivované před i po analýze,
  Vrátit vše podle D7), karta návrhu pravidla (dva titulky).
- `docs/registry-mvp.md` §8 — poznámka: druhá dispozice
  `archiveIfOther` (odkaz na tento task), vztah k D7.
- `help/posta/prijem-posty.md` — odstavec „Pravidla odesílatelů se učí
  z toho, co děláš.“ přepsat zhruba takto (cestu v Nastavení a názvy ověř
  v `module.jsonc` / `settingsSections.jsonc`):

  > **Pravidla odesílatelů se učí z toho, co děláš.** Když **třikrát**
  > ručně odklidíš poštu od stejného odesílatele do Archivu nebo Koše,
  > Shipard navrhne pravidlo a na Dashboardu ti ho nabídne k **Potvrzení**.
  > Pravidlo má jednu ze dvou akcí:
  >
  > - **Archivovat, když neobsahuje doklad ani dokument** — zpráva projde
  >   analýzou jako každá jiná, a když v ní AI nenajde fakturu ani dokument
  >   pro Spisovnu, odklidí ji do Archivu. Faktury od téhož odesílatele
  >   chodí dál normálně. Shipard tuhle akci navrhne, když od adresy už
  >   někdy přišel doklad nebo dokument, a je výchozí i u pravidla, které
  >   zakládáš sám.
  > - **Archivovat hned, bez analýzy** — zpráva jde rovnou do Archivu
  >   a AI ji vůbec nečte. Šetří to analýzu, ale spolkne i fakturu, kdyby
  >   od té adresy nějaká přišla. Hodí se jen pro odesílatele, kteří
  >   doklady nikdy neposílají.
  >
  > Když pravidlo potvrdíš, Shipard rovnou odklidí i zprávy od té adresy,
  > které už čekají v sekci **Ostatní**.

  Do odrážek pod ním doplnit: když si AI není jistá, že ve zprávě nic
  není, pravidlo ji nechá v Ostatních; zprávu, kterou jsi vrátil nebo
  nechal znovu analyzovat, pravidlo už neodklidí; „Vrátit vše“ vrací
  zprávy, které AI už přečetla, bez nové analýzy — objeví se znovu
  v Ostatních. Front matter `keywords`: doplnit `archivovat bez analýzy`,
  `neobsahuje doklad ani dokument`.

Pak `python3 scripts/help-index.py`.

## Pasti

- **Matcher má jediného volajícího** (`MailController`), ale po změně vrací
  i `archiveIfOther` — každé místo, které dnes s `$matchedRule` počítá jako
  s „archivovat při příjmu“, musí přejít na `$preTriageRule`, jinak by
  `archiveIfOther` zprávu přeskočila předzpracování i ISDOC.
- **Archivace jde přímým UPDATE** (jako pre-triage při příjmu i zápis
  `docState` 20 v `result`), ne přes `TableGateway` — `stateChanged`
  handlery zpráv se nespouštějí. Záměr: učící handler auto-archiv stejně
  ignoruje (`auto_disposed_by`).
- **„První úspěšná analýza“ se počítá včetně právě vloženého řádku**
  `core_mail_message_analyses` — podmínka je `=== 1`, ne `=== 0`.
- **Jistota klasifikace není sloupec** — v `result` je v těle
  (`message_classification.confidence`), u D8 v `analysis_json` poslední
  úspěšné analýzy. Chybí-li, zpráva se neodklízí.
- **Disposer v `result` musí běžet nad stejným spojením** jako transakce
  (`new DataSourceConnection($dibi)`), jinak čte stav před zápisem
  klasifikace.
- **Doménový vzor = přesná doména za posledním `@`** (jako
  `SenderRuleMatcher`), ne subdomény.
- **`LOWER(sender_email)` neumí index** — dotaz D1 a kandidáti D8 jedou
  přes velkou tabulku zpráv. Učící handler běží jednou na ruční odklizení
  a dotaz má `LIMIT 1`, kandidáty D8 zužuje `docState = 10` — únosné;
  ověřit `EXPLAIN` na zdroji s plnými daty.
- **Handler D8 musí běžet pro obě cesty potvrzení** — endpoint
  `/_mail/sender-rules/{id}/confirm` i uložení formuláře se změnou stavu.
  Ověřit, že obě předávají `DocumentEventDispatcher` (integrační test přes
  endpoint, ruční proklik přes formulář).
- **Digest počítá podle `auto_disposed_at` dneška** — potvrzení pravidla
  s velkou zásobou čekajících řádků vyrobí velké číslo v digestu. Záměr,
  „Vrátit vše“ je vrátí.
- **`ds-upgrade`** — nová dispozice v cfgItem, výchozí hodnota sloupce
  a klíč katalogu `feedTexts`. Bez něj formulář nenabídne novou volbu
  a karta návrhu `archiveIfOther` dostane anglický fallback. Při nasazení
  na alfu `ds-upgrade` všech zdrojů dat.

## Commity

1. `feat(mail): pravidlo odesílatele „když neobsahuje doklad ani dokument“`
   — katalogy, schéma, matcher + příjem, `PostAnalysisDisposer`, hook
   v `result`, handler potvrzení, undo, testy.
2. `feat(mail): učení pravidel odesílatelů — pojistka pro odesílatele s doklady`
   — učící handler, titulek karty návrhu, testy.
3. `docs, help: pravidla odesílatelů po analýze` — dokumentace, nápověda,
   `**Stav:**` + `python3 scripts/tasks-index.py`.

## Poznámky k implementaci (2026-10-06)

- **Výchozí hodnota sloupce v DB se na existujících DS nemění.**
  `SchemaComparator` porovnává jen nové sloupce, rozšíření typu/délky
  a zmírnění NULL — defaulty ne. `ds-upgrade` tedy žádný MODIFY neudělá
  a ve starších DS zůstává `DEFAULT 'archive'`. Bez vlivu na chování:
  formulář bere default nového záznamu z definice v JSONC,
  `SenderRuleDocument::beforeSave` doplní `archiveIfOther`, přímé INSERTy
  v kódu dispozici vždy posílají. Rozhodnuto nerozšiřovat porovnávač;
  zapsáno v `core_mail_sender_rules.md`.
- **`SenderRuleDocument` nově eviduje přechod `docState`** (vzor
  `DocDocument::trackStateChange`). Bez toho `TableGateway` pro pravidla
  `stateChanged` vůbec nevysílal — žádný dřívější handler pravidla
  neposlouchal — a `SenderRuleConfirmedHandler` (D8) by neběžel ani z karty,
  ani z formuláře. Odhalil to integrační test potvrzení.
- `PostAnalysisDisposer` má navíc volitelné konstruktorové parametry
  `SenderRuleMatcher` a `AnalysisConfidenceResolver` (unit testy); není
  `final` kvůli mocku v testu handleru. V `result` dostává `$this->db`
  controlleru — je to tentýž `DataSourceConnection` nad spojením transakce,
  `new DataSourceConnection($dibi)` není potřeba.
- `PrimaryTypes::OTHER` jako konstanta pro `'other'` (jen v novém kódu;
  stávající literály ponechány).
- Na DS `9gk5-1` nese jistotu klasifikace v `analysis_json` 21 z 30
  úspěšných analýz (starší prompt ji neuváděl) — takové zprávy potvrzení
  pravidla podle D5 nechá v Ostatních. Zmíněno v nápovědě.
- `EXPLAIN` na `9gk5-1`: dotaz D1 jde přes celou tabulku zpráv
  (`LOWER(sender_email)`, ~2 tis. řádků, `LIMIT 1`), kandidáty D8 zužuje
  `idx_analysis_state` na jednotky řádků. Únosné dle zadání.
- Frontend beze změny kódu (jen komentář v `Dashboard.svelte`), build
  nebyl potřeba.

## Hotovo když

- [x] Formulář pravidla nabízí obě dispozice, nové pravidlo má výchozí
      „Archivovat, když neobsahuje doklad ani dokument“ (katalog +
      default; proklik viz níže).
- [x] Zpráva od adresy s `archiveIfOther` projde příjmem normálně;
      po analýze bez dokladu skončí v Archivu s auditem a v digestu,
      s dokladem jde do K řešení (`IngestPreTriageTest`,
      `AnalysisResultEndpointTest`).
- [x] Jistota pod `review` prahem, druhá analýza, ruční nahrání a selhaná
      analýza zprávu neodklidí (`AnalysisResultEndpointTest`,
      `PostAnalysisDisposerTest`).
- [x] Doménové `archive` + e-mailové `archiveIfOther` na téže doméně:
      zpráva od té adresy projde analýzou (`IngestPreTriageTest`).
- [x] Učící handler navrhne `archiveIfOther` odesílateli s doklady,
      `archive` ostatním; karta návrhu má odpovídající titulek
      (`SenderRuleSuggestionHandlerTest`, `MailDigestSourceTest`).
- [x] Potvrzení pravidla (karta i formulář) odklidí čekající řádky
      Ostatní od adresy; digest je ukáže (`SenderRulesEndpointTest`;
      formulář sdílí `TableGateway` + `stateChanged`, proklik viz níže).
- [x] „Vrátit vše“ vrátí zprávu archivovanou po analýze bez nové analýzy,
      zprávu z pre-triage do fronty (`SenderRulesEndpointTest`).
- [x] Cílené unit i integrační testy zelené (integrační na volném DS
      `lh6x-l`).
- [x] Dokumentace a `help/posta/prijem-posty.md` aktualizované,
      `help-index.py`.
- [ ] `ds-upgrade` na dev DS a ruční proklik: potvrzení návrhu, formulář
      pravidla, digest a Vrátit vše (`ds-upgrade` proveden na `lh6x-l`,
      proklik čeká na ověření).
- [x] `**Stav:**` aktualizován, `tasks-index.py`.
