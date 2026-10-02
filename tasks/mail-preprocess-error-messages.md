# Task: Srozumitelné hlášky selhaného předzpracování došlé pošty

**Stav:** hotovo — implementováno 2026-10-02 (tři commity, rozhodnutí D1–D3, D6 + upřesnění U1–U7; D4, D5 odložené), ověření na dev zdroji podle sekce „Ověření (člověk)“ zbývá; po nasazení nutný `ds-upgrade` kvůli nové cfgItem `core.mail.preprocessErrorKinds`

**Cíl:** Když předzpracování zprávy (stažení dokumentu z odkazu, převod
těla do PDF, import ISDOC) selže, uživatel uvidí lidskou hlášku — co se
nepovedlo, proč návrh / klasifikace nemusí sedět a co má udělat — místo
odznaku „Hotovo s chybami“ a anglické technické poznámky v bloku
„Předzpracování“.

Navazuje na `tasks/mail-analysis-error-messages.md` (druhá část rozhodnutí
D6 téhož návrhu): stejný princip katalog → helper → karta, sdílená
komponenta karty.

## Před implementací přečti

- `modules/core/mail/docs/preprocess.md` — stavy, runner, sweep, log
- `tasks/mail-analysis-error-messages.md` — vzor katalogu, helperu
  a karty (`AnalysisErrorPresenter`, `AnalysisFailureCard`)
- `docs/dashboard.md` — kontrakt mail karet
- `help/posta/prijem-posty.md`, `docs/help-authoring.md`

## Kontext — jak to dnes vypadá

Runner (`Preprocess/PreprocessRunner.php`) vykoná plán akcí; každá akce
vrací `ActionResult` (`ok`, volný text `note`). Selže-li kterákoli akce,
zpráva skončí ve stavu 40 „Hotovo s chybami“ a **AI analýza pokračuje
bez vygenerované přílohy** (stav 40 frontu nikdy neblokuje). Import
ISDOC běží vždy a výsledek zapisuje do `preprocess_log.isdoc`
(`imported` / `none` / `failed` / `skipped`) — stav 40 nenastavuje.

Co uživatel vidí:

- hlavička zprávy — odznak „Hotovo s chybami“
- tab **Obsah** — blok „Předzpracování“ (`IncomingMessagesViewer::buildPreprocessItems`)
  s řádky „Chyba — <anglická poznámka včetně URL>“
- tab **Návrh** a **Dashboard** — nic; návrh ani karta „Není faktura“
  neprozradí, že vznikly bez dokumentu z odkazu

Data: na testovacím serveru ani na dev zdrojích není žádná zpráva ve stavu
40 (2026-10-02) — návrh vychází z kódu.

## Rozhodnutí k designu (potvrzená)

- ✓ **D1 — strukturovaný kód:** `ActionResult` nese kód selhání, runner
  ho ukládá do záznamu v `preprocess_log.results` vedle `note`. Žádný
  rozbor textu (poznámky jsou naše). Starší záznamy bez kódu → `unknown`.
- ✓ **D2 — katalog** `preprocessErrorKinds.jsonc` ve tvaru
  `analysisErrorKinds` (titulek, vysvětlení, co dělat; cs/en). U stažení
  z více kandidátních odkazů se pro hlášku vybírá kód podle priority.
- ✓ **D3 — kde:** (a) tab Obsah — karta nad blokem „Předzpracování“
  (blok zůstává jako technický detail); (b) tab Návrh — upozornění, že
  návrh vznikl bez výsledku předzpracování (stav 40); (c) Dashboard —
  žádná nová karta, řádek s upozorněním na stávající kartě zprávy.
  *Upřesnění:* (c) jako nové volitelné pole karty `warning`; selhaný
  ISDOC (stav 40 nenastavuje) jen v (a) jako informace.
- ✓ **D6 — sdílená komponenta:** `AnalysisFailureCard` zobecnit na
  `FailureCard` s variantami `error` / `warning` / `info`.
- **D4 odloženo** — akce „Znovu předzpracovat“ v UI (dnes jen CLI
  `mail-preprocess --message --force`). Hinty proto nesmí slibovat
  opakování z UI.
- **D5 odloženo** — automatické opakování u dočasných chyb.

### Upřesnění z plánu implementace (potvrzená 2026-10-02)

- **U1 — řádek ~205 `fetch()`** (nevalidní / ne-http URL) → `unexpectedContent`,
  ne `linkNotFound`: kandidáti už prošli filtrem `https?://`, sem spadne
  hlavně přesměrování na cizí schéma. Odkaz v e-mailu existoval.
- **U2 — kompaktní řádky Dashboardu** (`FeedRowCompact`: „Není faktura“
  i návrhy v pásmu ready) hint řádky nemají; `warning` tam ukáže **ikona
  varování s tooltipem** z textu `warning`. Plná karta (`FeedCard`) má
  řádek. Server posílá `warning` na všech třech druzích karet.
- **U3 — `{ruleId}` v hintech**: záznamy `plan` a `sweep` `ruleId` nemají;
  presenter při prázdné hodnotě token s předcházející mezerou odstraní.
  Cesta v hintu je „Nastavení → Pošta → Pravidla předzpracování“ (popisky
  ověřené v `module.jsonc` a `settingsSections.jsonc`).
- **U4 — sdílené texty**: popisky „Co se stalo“ / „Co dělat“ preprocess
  katalog nepotřebuje — Dashboard dostává jen řetězec `warning`, karta
  v Obsahu kreslí titulek, vysvětlení a hint přímo. `_common` nese jen
  `proposalWarningTitle`, `proposalWarningText`, `cardWarning`.
- **U5 — `bodyRender` v prioritě**: zadání ho v žebříčku neuvádí; na úrovni
  zprávy (víc selhaných akcí) stojí za `unexpectedContent`, před
  `remoteUnavailable`.
- **U6 — varianty `FailureCard`**: `warning` přes `--shpd-color-warning`
  + `--shpd-color-warning-soft`, `info` přes tokeny stavu `confirmed`
  (samostatný info token není). Technický detail dostane klíč „Dokončeno“.
- **U7 — nápověda**: navíc odrážka v `help/co-dnes-nejde.md`, že „Znovu
  předzpracovat“ v UI není (D4). Po nasazení i na dev zdrojích `ds-upgrade`
  kvůli nové cfgItem.

## Co je potřeba udělat

### Commit 1 — kódy, katalog, helper (D1, D2)

**Kódy** — konstanty v nové třídě `Preprocess/PreprocessFailureCode.php`:
`linkNotFound`, `linkExpired`, `remoteUnavailable`, `unexpectedContent`,
`bodyRender`, `ruleConfig`, `internal`. `ActionResult` dostane
`public string $code = ''`; `failure(string $note, string $code)` — kód
povinný. Interní `fetch()` / `store()` pole (`['ok' => false, 'note' => …]`)
nesou i `code`.

Mapování míst (ověř každé, čísla řádků orientační):

| Místo | Poznámka | Kód |
|---|---|---|
| `FetchLinkedDocumentAction` ~72, ~76, ~80 | chybí / nevalidní `linkHrefRegex`, chybí `allowedDomains` | `ruleConfig` |
| ~90 | v těle žádný odpovídající odkaz | `linkNotFound` |
| ~205 | nevalidní nebo ne-http URL | `unexpectedContent` (U1) |
| ~210 | host nevede na veřejnou adresu | `unexpectedContent` |
| ~216 | transportní chyba (vč. timeoutu) | `remoteUnavailable` |
| ~227 | `HTTP {status}` — 4xx kromě 408/429 | `linkExpired` |
| ~227 | `HTTP {status}` — 5xx, 408, 429 | `remoteUnavailable` |
| ~221, ~263 | přesměrování bez Location, příliš mnoho přesměrování | `unexpectedContent` |
| ~232, ~235 | finální host mimo `allowedDomains`, finální URL mimo regex | `ruleConfig` |
| ~238, ~248, ~251, ~282, ~285 | velikost, content-type, prázdné tělo / HTML, limit renderu | `unexpectedContent` |
| ~276 | HTML bez `renderIfHtml` | `ruleConfig` |
| ~279, ~291 | chybí render klient, render selhal | `internal` |
| `GeneratedAttachments` ~67, ~76 | dočasný soubor, upload přílohy | `internal` |
| `RenderBodyToPdfAction` ~56, ~59, ~65 | chybí HTML tělo, velikost, render selhal | `bodyRender` |
| `RenderBodyToPdfAction` ~79 | uložení přílohy | `internal` |
| `PreprocessRunner` ~364 | chybějící / neznámá akce | `ruleConfig` |
| `PreprocessRunner` ~371 | výjimka akce | `internal` |
| `PreprocessRunner` ~195 | prázdný uložený plán | `internal` |
| `PreprocessRunner::sweep` ~266 | vzdáno po N pokusech | `internal` |

`FetchLinkedDocumentAction` — kandidáti (až `MAX_CANDIDATES`) sbírají
kódy; výsledný kód podle priority `linkExpired` > `ruleConfig` >
`unexpectedContent` > `bodyRender` > `remoteUnavailable` > `internal` >
`linkNotFound` (nejvíc konkrétní pro uživatele; `bodyRender` jen na úrovni
zprávy, U5). Poznámka zůstává spojená jako dnes.

`PreprocessRunner` — záznam v `results` (~ř. 177) a záznamy prázdného
plánu a sweepu dostanou `code` u neúspěchu.

**Katalog** `modules/core/mail/config/preprocessErrorKinds.jsonc` →
cfgItem `core.mail.preprocessErrorKinds` (vzor `analysisErrorKinds.jsonc`).
Návrh znění cs (en obdobně):

| Kategorie | Titulek | Vysvětlení | Co dělat |
|---|---|---|---|
| `linkNotFound` | V e-mailu chybí odkaz na dokument | Pravidlo předzpracování čekalo v e-mailu odkaz na doklad, ale žádný odpovídající nenašlo. Odesílatel nejspíš změnil podobu e-mailu. | Dokument stáhni z e-mailu ručně a nahraj ho na Dashboard. Zkontroluj pravidlo {ruleId} v Nastavení → Pošta → Pravidla předzpracování, nebo nám dej vědět. |
| `linkExpired` | Odkaz na dokument nefunguje | Server odesílatele dokument nevydal — odkaz mohl vypršet nebo vyžaduje přihlášení. | Otevři odkaz v e-mailu, dokument stáhni a nahraj ho na Dashboard. |
| `remoteUnavailable` | Server s dokumentem nebyl dostupný | Stažení se nepovedlo kvůli výpadku nebo pomalé odpovědi na straně odesílatele. | Dokument stáhni z odkazu v e-mailu ručně a nahraj ho na Dashboard. |
| `unexpectedContent` | Na odkazu není použitelný dokument | Odkaz vedl na stránku nebo soubor, který nejde použít jako doklad (jiný formát, prázdný nebo příliš velký soubor). | Otevři odkaz v e-mailu ručně; pokud tam doklad je, stáhni ho a nahraj na Dashboard. |
| `bodyRender` | Text e-mailu se nepodařilo převést do PDF | Pravidlo mělo z těla e-mailu vytvořit PDF dokladu, ale převod neproběhl. | Doklad zadej ručně a dej nám vědět, o jakou zprávu šlo. |
| `ruleConfig` | Pravidlo předzpracování je nastavené chybně | Chyba je v nastavení pravidla, ne ve zprávě. | Zkontroluj pravidlo {ruleId} v Nastavení → Pošta → Pravidla předzpracování, nebo nám dej vědět. |
| `internal` | Předzpracování selhalo na straně Shipardu | Interní chyba při zpracování, ne chyba ve zprávě. | Dej nám vědět, o jakou zprávu šlo. |
| `isdocFailed` (info) | Přílohu ISDOC se nepodařilo načíst | Doklad proto vyčte AI z ostatních příloh. | Návrh zkontroluj obvyklým způsobem. |
| `unknown` | Předzpracování skončilo s chybou | Neznámý druh chyby. | Dej nám vědět, o jakou zprávu šlo. |

Společné texty (`_common`, U4 — popisky „Co se stalo“ / „Co dělat“
nejsou potřeba): upozornění pro tab
Návrh — titulek „Návrh vznikl bez výsledku předzpracování“, text „AI
pracovala bez dokumentu, který mělo předzpracování vytvořit, takže návrh
nebo klasifikace nemusí sedět. Podrobnosti jsou v záložce Obsah.“ —
a prefix řádku na Dashboardu „Předzpracování: {title}“.

**Helper** `Preprocess/PreprocessErrorPresenter.php`:

- `fromLog(int $state, array $log): ?PreprocessFailureInfo` — `null`,
  když nic neselhalo; ve stavu 40 kategorie podle priority z kódů
  neúspěšných záznamů (záznam bez kódu → `unknown`), `{ruleId}` z
  vybraného záznamu, varianta `warning`. Není-li stav 40, ale
  `log.isdoc === 'failed'` → `isdocFailed`, varianta `info`.
- `PreprocessFailureInfo` (readonly): `kind`, `variant`, `title`,
  `description`, `hint`, `failedCount`, `technical` (poznámky
  neúspěšných akcí spojené, pro sbalený detail); `toArray()` ve tvaru
  kompatibilním s `AnalysisErrorInfo::toArray()` + `variant`.
- `proposalWarning(PreprocessFailureInfo)` a `cardWarning(…)` — texty
  pro D3b a D3c.

Testy: `ActionResult` / akce vrací správné kódy (rozšířit stávající testy
akcí a runneru — najdi je pod `tests/Unit/Module/Core/Mail/Preprocess/`),
`PreprocessErrorPresenterTest` (každá kategorie, priorita více
neúspěchů, záznam bez kódu, ISDOC, nic neselhalo → null).

Ověření: `php -l`, `vendor/bin/phpunit --filter 'Preprocess'`.

### Commit 2 — serverová data (D3)

`IncomingMessagesViewer`:

- tab Obsah — je-li `fromLog()` neprázdné, před blok „Předzpracování“
  vložit blok `{type: 'failure', failure: {...}}`.
- tab Návrh — při `preprocess_state = 40` pole `preprocessWarning`
  (`title`, `text`, `kind`) v obsahu `proposal` — ve všech větvích
  (s návrhem, bez návrhu, s `failure` z analýzy).

`MailSuggestionsSource` — všechny tři druhy mail karet (návrh, „Není
faktura“, chybové) při `preprocess_state = 40` dostanou `warning`
(string, lokalizovaný server). Dotazy doplní `preprocess_state`
a `preprocess_log` (log jen při stavu 40, např. `IF(...)`); žádné N+1.

Testy: `IncomingMessagesViewerTest` (blok v Obsahu, ISDOC info,
`preprocessWarning` v Návrhu), `MailSuggestionsSourceTest` (`warning`
na všech druzích karet, bez stavu 40 chybí).

Docs: `docs/dashboard.md` — pole `warning` v kontraktu karet a u mail
karet; `modules/core/mail/docs/preprocess.md` — kódy selhání, katalog,
kde se hláška zobrazuje.

Ověření: `vendor/bin/phpunit --filter 'IncomingMessagesViewerTest|MailSuggestionsSourceTest|Preprocess'`.

### Commit 3 — frontend, nápověda, uzavření (D3, D6)

- `AnalysisFailureCard.svelte` → `FailureCard.svelte` s prop
  `variant` (`error` výchozí, `warning`, `info`); barvy přes CSS
  proměnné stavů, BEM `shpd-failure-card` (přejmenování tříd
  konzistentně). Stávající použití v tabu Návrh beze změny chování.
- `ViewerDetail.svelte` `renderContent` — nový typ bloku `failure`;
  v obsahu `proposal` vykreslit `content.preprocessWarning` (varianta
  `warning`) nad návrhem / kartou selhání.
- `FeedCard.svelte` — řádek `card.warning` (vzor hint řádku
  `secondaryFindings`, varianta upozornění); `FeedRowCompact.svelte` —
  ikona varování s tooltipem `card.warning` (oba módy, U2).
- i18n jen statické popisky; texty hlášek ze serveru.
- Nápověda `help/posta/prijem-posty.md` — nový odstavec (např.
  „Faktura z odkazu nedorazila“ / „Předzpracování skončilo s chybami“):
  co je předzpracování, co uživatel uvidí (upozornění na kartě,
  v Návrhu, karta v Obsahu), co dělat (stáhnout a nahrát na Dashboard,
  pravidlo v Nastavení); keywords. Dnes nápověda předzpracování vůbec
  nezmiňuje. `help/co-dnes-nejde.md` — odrážka, že „Znovu předzpracovat“
  v UI není (U7).
- `**Stav:**` → `hotovo` + `python3 scripts/tasks-index.py`.

Ověření: `cd frontend && npm run check:i18n && timeout 90 npm run build`,
`python3 scripts/help-index.py --check`, na konci celá PHPUnit sada lokálně.

### Ověření (člověk)

Na dev zdroji v režimu *volný*: zpráva s pravidlem stažení z odkazu na
nedostupnou / neexistující adresu (nebo upravené pravidlo s chybnou
doménou) a `mail-preprocess --message <id> --force` → tab Obsah karta,
tab Návrh upozornění po doběhnutí analýzy, Dashboard řádek na kartě.

## Mimo rozsah

- D4 — akce „Znovu předzpracovat“ v UI a navazující automatická
  reanalýza.
- D5 — automatické opakování dočasných chyb.
- Statistiky selhání per pravidlo v Nastavení.
- Změna logiky stavů (ISDOC dál stav 40 nenastavuje).

## Pasti

- **Poznámky obsahují URL** (často s tokeny v query). Do `technical`
  patří jen do sbaleného detailu zprávy; na Dashboard, do `warning`,
  testů, docs ani nápovědy ne. Fixtury jen s fiktivními doménami
  (`example.com`).
- **Starší logy bez `code`** — helper nesmí spadnout, `unknown`.
- **`ActionResult::failure` bez kódu** — po změně povinný; projdi všechna
  volání (`grep -rn "ActionResult::failure(" --include=*.php modules/core/mail`)
  i testy, které ho konstruují.
- **Sweep zapisuje do logu mimo akce** — kód i tam, jinak vzdané zprávy
  skončí jako `unknown`.
- **Hinty nesmí slibovat opakování z UI** (D4 odloženo).
- **Stav 40 + selhaná analýza** — v tabu Návrh se ukáže upozornění
  předzpracování i karta selhání analýzy; pořadí: upozornění nahoře.
- **Přejmenování komponenty** — najdi všechny importy
  `AnalysisFailureCard` a třídy `shpd-analysis-failure`.
- **JS:** jen ASCII uvozovky, české texty do `i18n/*.js` nebo ze serveru.

## Hotovo když

- [x] Všechna `ActionResult::failure` a záznamy runneru / sweepu nesou kód;
      testy akcí a runneru.
- [x] Katalog `preprocessErrorKinds` a `PreprocessErrorPresenter` s testy.
- [x] Tab Obsah ukazuje kartu (stav 40 i selhaný ISDOC), tab Návrh
      upozornění, Dashboard karty řádek `warning` (kompaktní řádky ikona
      s tooltipem).
- [x] `FailureCard` s variantami nahradila `AnalysisFailureCard`.
- [x] `docs/dashboard.md`, `preprocess.md` a nápověda aktualizované;
      `help-index --check` prošel.
- [ ] Ověření člověkem na dev zdroji v režimu volný (sekce výše).
