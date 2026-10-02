# Technické předzpracování došlé pošty (preprocess)

Asynchronní stage mezi intake došlé zprávy a AI frontou (issue #33,
zadání `tasks/mail-preprocess.md`). Řídí ji pravidla
`core_mail_preprocess_rules`; výsledkem jsou **nové obsahové přílohy
zprávy** — vidí je uživatel, AI analyzer i Spisovna stejně jako přílohy
z e-mailu. Motivace: část pošty nenese doklad jako PDF přílohu (faktura za
odkazem v těle — Bolt; faktura přímo HTML tělem — Apple, Google Play).

Fáze 1 (#33) přinesla pravidla, runner a `fetchLinkedDocument`; Fáze 2
(`tasks/mail-preprocess-phase2.md`, po render službě #34) akci
`renderBodyToPdf`, parametr `renderIfHtml` a aktivaci pravidel Apple/Google.
Od #81 (`tasks/mail-isdoc-content-tags.md`) je runner i **jediné místo
deterministického ISDOC importu** — zpráva s platným ISDOC se do něj odloží
i bez pravidla (`trigger: 'isdoc'`), aby obsahová eskalace (LLM) neběžela
v HTTP requestu.

Odlišení od sousedů:

- **Sender rules** (`core_mail_sender_rules`) jsou terminální triage —
  auto-archiv šumu. Preprocess je obohacení: matchnout může víc pravidel
  a všechna se vykonají. Zpráva archivovaná sender rule žádné
  předzpracování nedostává.
- **Renditions** (#34, render HTML *příloh* do PDF) sem nepatří —
  preprocess vyrábí jen nové přílohy dle pravidel, nic plošně (D4).

## Osa `preprocess_state`

Třetí ortogonální osa zprávy vedle `docState` (workflow) a
`analysis_state` (AI pipeline). Hodnoty `core.mail.preprocessStates`:

| Stav | Význam | Kdo nastavuje |
|---|---|---|
| 0 Netýká se | Žádné pravidlo při intake nematchlo a zpráva nenese platný ISDOC — koncový stav, chování jako dřív | intake |
| 10 Čeká | Plán uložen v `preprocess_log`, runner ještě neběžel; nebo zpráva odložená jen kvůli ISDOC importu (`trigger: 'isdoc'`, plán prázdný) | intake (plán pravidel), ISDOC detekce po commitu intake / uploadu (#81), sweep (reset) |
| 20 Běží | Runner si zprávu claimnul a vykonává plán | runner |
| 30 Hotovo | Všechny akce proběhly | runner |
| 40 Hotovo s chybami | Některá akce selhala, nebo sweep vzdal po 3 pokusech | runner, sweep |

**Gate AI fronty:** `GET /_mail/analysis/queue` (i `total_available`)
a `/claim` vyřazují `preprocess_state IN (10, 20)`. Zpráva má normálně
`analysis_state = 10` už od intake, do fronty se ale dostane až po
doběhnutí runneru. **Selhání nikdy neblokuje tok** — zpráva doteče
se stavem 40 a analyzer ji dostane s tím, co k ní je (D9).

`preprocess_log` (JSON):

```json
{
  "plan": [{"ruleId": "bolt-invoice-link", "ruleNdx": 3, "actions": [{"action": "fetchLinkedDocument", "...": "..."}]}],
  "results": [
    {"ruleId": "bolt-invoice-link", "action": "fetchLinkedDocument", "ok": true, "note": "fetched https://… → attachment 812", "attachmentId": 812},
    {"ruleId": "other-rule", "action": "fetchLinkedDocument", "ok": false, "note": "https://…: HTTP 404 at https://…", "code": "linkExpired"}
  ],
  "attempts": 1,
  "isdoc": "none",
  "createdAt": "…", "startedAt": "…", "finishedAt": "…"
}
```

`trigger` (volitelný): `isdoc` = běh spuštěný jen kvůli ISDOC importu
(#81, `PreprocessRunner::isdocOnlyLog()`) — `plan` je prázdný záměrně
a runner to nebere jako chybu. Bez triggeru je prázdný plán chyba
(`stored plan is empty` → stav 40); chybějící `trigger` = běh podle
pravidel (starší zprávy, zpětná kompatibilita).

`results[].code` (jen u `ok: false`): strukturovaný kód selhání
`PreprocessFailureCode` — z něj se skládá hláška pro uživatele, viz
[Hlášky pro uživatele](#hlášky-pro-uživatele). Záznam bez kódu (log
z doby před zavedením) se zobrazí jako neznámý druh chyby.

## Pravidla

Tabulka [core_mail_preprocess_rules](../tables/core_mail_preprocess_rules.md);
životní cyklus `core.system.docStatesArchive`, **matchují jen pravidla
ve stavu 40**. UI: Nastavení → Pošta → Pravidla předzpracování (viewer
`PreprocessRulesViewer`, JSONC form; akce se editují jako JSON, D13).

**Podmínky shody** (D11) — AND přes vyplněné, aspoň jedna povinná:
`sender_email` (přesná adresa), `sender_domain` (i subdomény),
`subject_regex`, `body_regex` (nad `body_html` i `body_plain`). Regexy jsou
PCRE bez oddělovačů, case-insensitive. Reálné vzorky jsou `Fwd:` forwardy
z interních adres → systémová pravidla kotvíme přes `body_regex`, ne přes
odesílatele. Nevalidní regex pravidlo tiše vyřadí (warning do logu), intake
nikdy nespadne.

**Akce** (`actions`, uspořádaný JSON seznam, klíče `core.mail.preprocessActions`):

| Akce | Parametry |
|---|---|
| `fetchLinkedDocument` | `linkHrefRegex` (povinný), `allowedDomains` (povinný seznam), `renderIfHtml` (bool, default false) |
| `renderBodyToPdf` | — |
| `convertOfficeToPdf` | rezervováno (konverze příloh = renditions, #34/D7) — validace odmítne |

`PreprocessRuleDocument` odmítne akce, které runner neumí
(`PreprocessRuleDocument::IMPLEMENTED_ACTIONS`, zrcadlo
`PreprocessRunnerFactory::defaultActions`), a hlídá povinné parametry
a typ `renderIfHtml`.

### Systémový katalog

`config/systemPreprocessRules.jsonc` → `PreprocessRulesProvisioner` při
`ds-upgrade` (bezpodmínečně, i pod `skipProvisioning` — import-mode DS
přijímá poštu stejně). Per `rule_id`: chybí → INSERT (`origin = system`,
stav 40, `system_phase` = `phase` z katalogu; pravidla s `phase > 1`
vznikají rovnou archivovaná — 70); existuje ve 40 → UPDATE obsahových
polí (+ dorovnání `system_phase`); archivované/smazané/koncept → beze
změny (**nekřísí se**). `hit_count`, `last_hit_at`, `created` se nikdy
nepřepisují.

**Aktivace pravidel z pozdější fáze** (`system_phase`, D14): archivované
systémové pravidlo se `system_phase > 1` archivoval systém, ne uživatel.
Když katalog `phase` odebere, `ds-upgrade` ho **aktivuje** — `[ACTIVATE]`,
70 → 40, obsah z katalogu, `system_phase = 1`. Uživatelem archivované
živé pravidlo má `system_phase = 1` a zůstává archivované. Výpis
`ds-upgrade -v`: `[CREATE]`, `[ACTIVATE]`, `[UPDATE]`, `[SKIP]`, `[OK]`.

Důsledek: úpravu systémového pravidla ve stavu 40 další `ds-upgrade`
přepíše. Přizpůsobení = systémové pravidlo archivovat a založit
uživatelskou kopii.

Katalog: `bolt-invoice-link` (fetch z odkazu), `apple-invoice-body`
(kotva `Apple Distribution International` — fakturační právnická osoba;
adresa odesílatele v těle přímé pošty není), `google-play-order`
(kotva na adresy odesílatele v těle — forwardy). Všechna živá.

> **Migrace DS z Fáze 1:** DS, kde `ds-upgrade` založil Apple/Google
> pravidla ještě před sloupcem `system_phase`, je má archivovaná se
> `system_phase = 1` (default sloupce) — od uživatelem archivovaných
> nerozlišitelná, provisioner je správně nechá být. Jednorázově před
> `ds-upgrade`:
> `UPDATE core_mail_preprocess_rules SET system_phase = 2 WHERE origin = 'system' AND docState = 70 AND system_phase = 1 AND hit_count = 0 AND rule_id IN ('apple-invoice-body', 'google-play-order');`
> Týká se jen DS upgradovaných mezi 29. 8. 2026 (Fáze 1) a nasazením Fáze 2.

## Tok

```
POST /_mail/incoming
  ├─ SenderRuleMatcher → archiv? → konec (žádné předzpracování)
  ├─ PreprocessRuleMatcher (pravidla 40, AND, regexy) → plán | null
  ├─ tx: INSERT zprávy (+ preprocess_state=10, preprocess_log.plan), hit_count++
  ├─ commit
  ├─ plán → PreprocessSpawner: setsid -f php bin/shpd-ds mail-preprocess --message <id>
  └─ plán null → kandidát .isdoc/.isdocx/XML/PDF? → IsdocImportService::detect()  (bez zápisu)
        ├─ false → konec, AI fronta (preprocess_state 0, dnešní chování)
        └─ true  → UPDATE … SET preprocess_state = 10,
                     preprocess_log = {plan: [], trigger: 'isdoc', …}
                   WHERE id = ? AND preprocess_state = 0 AND analysis_state IN (0, 10)
                   ├─ 1 řádek → PreprocessSpawner (jako u plánu)
                   └─ 0 řádků → analyzer si zprávu claimnul, nic

POST /_mail/messages/upload (ruční nahrání, #81 D4)
  └─ po commitu dávky totéž per zpráva: detect() → odložení → spawn
     (až 20 runnerů naráz, každý s jedním malým LLM voláním — neserializuje se)

shpd-ds mail-preprocess --message <id>          (PreprocessRunner)
  ├─ claim: UPDATE … SET preprocess_state=20 WHERE id=? AND preprocess_state=10
  │        (0 řádků = prohraný závod / už hotovo → tichý konec)
  ├─ vykoná uložený plán (ne re-match, D12) — výsledek per akce do results;
  │  u trigger 'isdoc' je plán prázdný záměrně (žádný řádek `plan`, ne chyba)
  ├─ IsdocImportService::tryImport nad všemi obsahovými přílohami
  │  (původní i vygenerované, D10): detekce znovu → RowEnrichmentPipeline::
  │  enrichAtResult (Vrstva 0 + pravidlo IČO | LLM štítek, mimo tx) → tx:
  │  analýza model_name 'isdoc' vč. content_tag, analysis_state → 30
  └─ preprocess_state = 30 | 40, finishedAt → gate AI fronty otevřena
     (import se vzdal → analysis_state zůstal 10 → zpráva jde k analyzeru)
```

Plán je **snapshot** z intake: změna pravidla po přijetí zprávy plán
nemění. Ruční přepočet = `--force`.

## Akce `fetchLinkedDocument`

`Preprocess/Action/FetchLinkedDocumentAction`:

1. Kandidátní URL z `href` atributů `body_html` (HTML entity dekódované)
   a z holých URL v textu; kandidát musí matchnout `linkHrefRegex` přímo
   **nebo po URL-decode** (tracking wrappery typu awstrack nesou cíl
   zakódovaný v cestě). Max 5 kandidátů, první úspěšný vyhrává.
2. Redirecty se procházejí **ručně** (max 5 hopů), každý hop: jen
   `http`/`https`, host se přeloží a **každá** jeho adresa musí být
   veřejná (privátní, loopback, link-local, rezervované = blok celého
   hostu); ověřená IP se pinuje do requestu (`CURLOPT_RESOLVE`) — žádný
   druhý DNS lookup, žádný DNS rebinding.
3. Tracking wrapper smí být průchozí 3xx z cizí domény; **obsah** se
   přijme jen z finální URL, jejíž host je v `allowedDomains` (i
   subdomény) a která matchne `linkHrefRegex`.
4. Anonymní GET, timeout 20 s, strop 20 MB (přenos se přeruší), content
   type `application/pdf` nebo magic `%PDF`. Finální `text/html`:
   s `renderIfHtml: true` se vyrenderuje do PDF (render služba, profil
   Untrusted, strop 2 MB HTML — kontroly allowlist/regex/size cap jdou
   před renderem; selhání renderu = poznámka kandidáta, zkouší se další);
   bez flagu je HTML selhání s poznámkou.
5. Uložení přes `AttachmentService::upload` jako obsahová příloha zprávy,
   provenance do `core_attachments_files.metadata`:
   `{generatedBy: "preprocess", ruleId, action, sourceUrl, finalUrl, fetchedAt}`
   (D5), u renderovaného výsledku navíc `rendered: true`.

**Idempotence:** nesmazaná příloha se shodným `(ruleId, action, sourceUrl)`
→ akce se přeskočí jako úspěšná. Opakované `mail-preprocess --message`
tedy nevyrábí duplikáty; `--force` generované přílohy nejdřív smaže
(soft delete) a přegeneruje.

Selhání (expirovaný odkaz, 404, timeout, cizí doména, size cap) je
**provozní stav**: zapíše se do `results`, zpráva skončí ve 40, žádná
výjimka ven (D6).

## Akce `renderBodyToPdf`

`Preprocess/Action/RenderBodyToPdfAction` — pro zprávy, kde je doklad
přímo HTML tělem e-mailu (Apple, Google Play):

1. Vstup je jen `body_html` (prázdné = selhání akce; strop 2 MB).
2. Render přes `RenderClient::renderHtml(..., RenderProfile::Untrusted)`
   (`docs/render.md`), **bez assetů**. Odchozí síť Chromia je vypnutá,
   takže **vzdálené obrázky a tracking pixely se záměrně nenačtou** —
   deterministický výstup, žádný egress; `cid:` obrázky v1 neřešíme
   (v PDF chybí). Tělo bez `<meta charset>` dostane UTF-8 hlavičku
   (Chromium by jinak hádal kódování a rozbil diakritiku).
3. Uložení jako obsahová příloha; název z předmětu zprávy (sanitizovaný,
   `.pdf`, prázdný předmět → `message-body.pdf`). Provenance
   `{generatedBy: "preprocess", ruleId, action, bodySha256, renderedAt}`.

**Idempotence** dle `(ruleId, action)` — tělo je po intake neměnné.
Nenakonfigurovaná služba (`render` chybí v server config) nebo její
výpadek = selhání akce s `render failed: <errorKind>: <note>`, zpráva
skončí ve 40 a doteče do AI fronty; nic nepadá. ISDOC krok po renderu
proběhne nad PDF a skončí `none` (renderované PDF ISDOC nenese).

Společné kusy obou akcí (provenance lookup, uložení s úklidem temp
souboru, sanitizace názvu) žijí v `Action/GeneratedAttachments`.

## Provoz

- **Spawn** (`PreprocessSpawner` → `Core\Process\DetachedProcess`):
  `setsid -f` odpojí runner od php-fpm workera, argv pole = žádný shell.
  stdout/stderr potomka jdou do `preprocess.log` vedle serverového logu
  (když je adresář zapisovatelný), runner loguje přes `ErrorLogger` do
  `shipard.log`. Selhání spawnu se jen zaloguje — zprávu zvedne sweep.
  Runner běží pod uživatelem web workeru; potřebuje zápis do `att/` DS
  (stejně jako intake příloh).
- **Sweep** (`mail-preprocess --sweep`, cron slot `minute` vedle
  `mail-analysis-reap`): stav 10 starší než 5 min (spawn selhal) a stav 20
  starší než 15 min (proces umřel) → `attempts++`, zpět na 10, spawn; po
  3 pokusech stav 40 s poznámkou. Sweep je jen záchrana, ne primární
  spouštěč (D8). Selhaný spawn u zprávy odložené jen kvůli ISDOC znamená,
  že import proběhne až po sweepu (5 min) — týká se jen ISDOC pošty,
  běžné PDF odložením neprochází (#81 D4).
- **`--force`**: re-match dle aktuálních pravidel, smazání generovaných
  příloh, přegenerování. Funguje i na stavech 0/30/40 (ladění nového
  pravidla nad starou zprávou). Odmítne zprávu s aktivním AI claimem
  (`analysis_state = 20`) a zprávu ve stavu 20 (použij `--sweep`).
  U zprávy odložené jen kvůli ISDOC (`trigger: 'isdoc'`) vrátí `no_match`
  — matcher hledá pravidla odesílatele, ne ISDOC; ruční re-import ISDOC
  je mimo rozsah #81, únikovou cestou je „Znovu analyzovat".
- **Render služba**: runner ji bere ze `render` sekce
  `/etc/shipard/server.json` (`RenderClient::fromServerConfig`,
  `PreprocessRunnerFactory`); server config nenačitatelný = klient
  nenakonfigurovaný, render akce selhávají provozně.
- **Detail zprávy** (viewer Došlá pošta): badge stavu předzpracování
  v hlavičce, blok „Předzpracování" v tabu Obsah (pravidla, pokusy,
  výsledek per akce, ISDOC, čas; u ISDOC-only běhu řádek „Spuštěno:
  ISDOC import" místo pravidel), generované přílohy nesou badge
  „Vygenerováno". Selhání má nad technickým blokem lidskou kartu a tab
  Návrh upozornění — viz [Hlášky pro uživatele](#hlášky-pro-uživatele).

CLI reference: [docs/cli.md](../../../../docs/cli.md) § `mail-preprocess`.
API gate: [docs/mail/api-contract.md](../../../../docs/mail/api-contract.md) § 9.1.

## Hlášky pro uživatele

Selhané předzpracování vidí uživatel jako lidskou hlášku — co se
nepovedlo, proč návrh nebo klasifikace nemusí sedět a co udělat — místo
odznaku „Hotovo s chybami" a anglické poznámky v technickém bloku
(tasks/mail-preprocess-error-messages.md, D1–D3, D6). Stejný princip jako
u selhané analýzy ([ai-analysis.md](ai-analysis.md) → „Chybové hlášky pro
uživatele"): katalog → helper → sdílená karta.

**Kódy selhání** (`Preprocess/PreprocessFailureCode`, D1): každé
`ActionResult::failure()` nese povinný kód, runner ho ukládá do
`results[].code`; záznamy mimo akce (prázdný plán, vzdaný sweep, neznámá
akce, výjimka akce) ho dostávají v runneru. Žádný rozbor textu poznámek.

| Kód | Kdy |
|---|---|
| `linkNotFound` | v těle zprávy žádný odkaz odpovídající `linkHrefRegex` |
| `linkExpired` | finální server vrátil 4xx mimo 408/429 (vypršelý odkaz, přihlášení, 404) |
| `remoteUnavailable` | transportní chyba vč. timeoutu, 408, 429, 5xx |
| `unexpectedContent` | nepoužitelný obsah: content-type mimo PDF, prázdné tělo, size cap, vadné přesměrování (bez Location, příliš mnoho hopů, cizí schéma, neveřejná adresa) |
| `bodyRender` | `renderBodyToPdf`: chybí HTML tělo, size cap, render selhal (vč. nenakonfigurované služby) |
| `ruleConfig` | chyba pravidla: chybí / nevalidní `linkHrefRegex`, chybí `allowedDomains`, finální host mimo allowlist, finální URL mimo regex, HTML bez `renderIfHtml`, neznámá akce v plánu |
| `internal` | chyba Shipardu: uložení přílohy, chybějící render klient u `renderIfHtml`, render selhal u stažené HTML, výjimka akce, prázdný uložený plán, sweep vzdal po `MAX_ATTEMPTS` |

Sejde-li se víc kódů — kandidátní odkazy jedné akce
(`FetchLinkedDocumentAction`, až `MAX_CANDIDATES`) i víc selhaných akcí
jedné zprávy — vyhrává nejkonkrétnější podle
`PreprocessFailureCode::PRIORITY`: `linkExpired` > `ruleConfig` >
`unexpectedContent` > `bodyRender` > `remoteUnavailable` > `internal` >
`linkNotFound`. Poznámka zůstává spojená ze všech pokusů.

**Katalog** `core.mail.preprocessErrorKinds`
(`config/preprocessErrorKinds.jsonc`, D2): klíč = kód, pole `name`
(titulek), `description` (vysvětlení), `hint` (co dělat; `{ruleId}` =
pravidlo vybraného záznamu, bez pravidla token zmizí). Navíc `isdocFailed`
(selhaný import ISDOC, jen informace — stav 40 nenastavuje) a `unknown`
(záznam bez kódu). `_common`: titulek a text upozornění pro tab Návrh,
prefix řádku na kartě Dashboardu. Compiled config je per jazyk; bez
configu anglický fallback v PHP. Hinty nesmí slibovat opakování z UI —
akce „Znovu předzpracovat" zatím není (D4 odloženo, jen CLI
`mail-preprocess --message --force`).

**Helper** `Preprocess/PreprocessErrorPresenter`:
`fromLog(state, log): ?PreprocessFailureInfo` — ve stavu 40 kategorie
podle priority z kódů neúspěšných záznamů (varianta `warning`), mimo
stav 40 jen `log.isdoc = failed` → `isdocFailed` (varianta `info`), jinak
null. `PreprocessFailureInfo` nese `kind`, `variant`, `title`,
`description`, `hint`, `failedCount`, `technical` (spojené poznámky
neúspěšných akcí — **nesou URL s tokeny**, patří jen do sbaleného detailu
zprávy) a `ruleId`; `toArray()` je tvarově kompatibilní
s `AnalysisErrorInfo` (sdílená komponenta `FailureCard`).
`proposalWarning()` a `cardWarning()` dávají texty pro tab Návrh a kartu.

**Kde se zobrazuje** (D3):

- tab **Obsah** — blok `{type: 'failure', failure}` nad technickým blokem
  „Předzpracování" (`IncomingMessagesViewer::buildPreprocessFailure`;
  `failure` = `toArray()` + `finishedAt`); stav 40 i selhaný ISDOC;
- tab **Návrh** — pole `preprocessWarning {kind, title, text}` v obsahu
  `proposal` při stavu 40, ve všech větvích (s návrhem, bez návrhu, vedle
  karty selhání analýzy — ta stojí pod ním);
- **Dashboard** — žádná nová karta; všechny tři druhy mail karet při stavu
  40 nesou `warning` „Předzpracování: {titulek}"
  (`MailSuggestionsSource::withPreprocessWarning`,
  [docs/dashboard.md](../../../../docs/dashboard.md) §4, §5.1).

Mimo rozsah: akce „Znovu předzpracovat" v UI (D4), automatické opakování
dočasných chyb (D5), statistiky selhání per pravidlo.

## Mimo scope

Render HTML *příloh* do PDF a konverze Office příloh = renditions (#34/D7),
ne preprocess; `convertOfficeToPdf` zůstává v katalogu akcí rezervované
(D18). `cid:` obrázky v renderovaném těle (v1 se nenačtou).
