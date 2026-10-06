# Feed — texty karet ze serverového katalogu (cfgItem + ICU)

**Stav:** hotovo — implementováno 2026-10-06 (#101 D11, D12–D17); čeká na ruční proklik dashboardu cs/en na dev DS. **Při nasazení na alfu `ds-upgrade` všech zdrojů dat** — bez něj čeští uživatelé uvidí anglický fallback (tiše, bez logu)

## Cíl

Zdroje feedu dashboardu skládají texty karet v PHP přes
`$ctx->language === 'cs' ? '…' : '…'` (zhruba 30 textů ve čtyřech
zdrojích) a plurály řeší ručním `match`, v každém zdroji trochu jinak.
Pravidlo server-driven textů přes cfgItem (`CLAUDE.md` → *Backend —
Vícejazyčnost*) tady neplatí.

Po tomto tasku čtou zdroje texty z katalogu modulu (cfgItem), plurály
a parametry formátuje ICU MessageFormat se stejnou syntaxí jako frontend
(`frontend/src/i18n/cs.js`). **Uživatelsky se nic nemění** — výsledné
texty jsou znak po znaku stejné jako dnes.

## Před implementací přečti

- `CLAUDE.md` → *Backend — Vícejazyčnost server-driven labels*
- `docs/dashboard.md` §4–§5 (kartový kontrakt, zdroje)
- `docs/modules.md` — cfgItemy, vícejazyčnost (`:cs` / `:en`), kompilace
  konfigurace
- Vzor katalogu: `modules/core/mail/config/analysisErrorKinds.jsonc`
  + `AnalysisErrorPresenter` (čtení cfgItem, fallback v PHP)
- Vzor testu úplnosti: `PrintDeclarationsTest` (katalogy tisků)

## Rozhodnutí k designu (potvrzená)

- ✓ **D12 — Texty lokalizuje server, ne frontend.** Titulky a podtitulky
  karet čte i AI shrnutí a MCP `feed_cards`.
- ✓ **D13 — Katalog jako cfgItem per modul:** `core.mail.feedTexts`,
  `core.alerts.feedTexts`, `core.exchange.feedTexts`. Texty patří modulu,
  který kartu vyrábí. Formát jako `analysisErrorKinds`: klíč → objekt
  s holým polem + variantami `:cs` / `:en`.
- ✓ **D14 — Plurály a parametry přes ICU MessageFormat** (`ext-intl`,
  `MessageFormatter`) — stejná syntaxe jako frontend. Sdílený helper
  `FeedTexts` v `src/Core/Feed/`.
- ✓ **D15 — Fallback bez compiled configu = anglický text v PHP**
  (konvence `CLAUDE.md`), ne vrácený klíč.
- ✓ **D16 — Test úplnosti katalogu:** každý klíč má `cs` i `en`, každý
  vzor jde zparsovat. Stávající testy s přesnými texty projdou beze změny.
- ✓ **D17 — Mimo rozsah:** labely akcí mail karet (lokalizuje frontend
  podle `action.id`), formát data na kartách (`j. n. Y` / `Y-m-d`), prompt
  AI shrnutí, titulky a zprávy samotných alertů (#102).

## Scope

**V rozsahu:** helper `FeedTexts`, tři cfgItemy, převod textů ve zdrojích
`MailSuggestionsSource`, `MailDigestSource`, `AlertsSource`,
`ContentTagSuggestionsSource`, test úplnosti, dokumentace.

**Mimo rozsah:** viz D17. Kontrakt karet ani frontend se nemění.

## 1. Helper `src/Core/Feed/FeedTexts.php`

```php
/**
 * Texty karet feedu z katalogu modulu (cfgItem, #101 D12–D15).
 * Vzor je ICU MessageFormat (plurály, {param}); jazyk formátování =
 * jazyk feedu. Bez compiled configu / bez klíče → anglický fallback
 * z volajícího + warning do logu.
 */
final class FeedTexts
{
    public function __construct(
        private readonly ?ConfigRuntime $config,
        private readonly string $cfgItemId,
        private readonly string $language,
    ) {}

    public static function forContext(FeedContext $ctx, string $cfgItemId): self;

    /** @param array<string, string|int|float> $params */
    public function t(string $key, string $fallback, array $params = []): string;
}
```

- Vzor = lokalizované pole `text` položky katalogu (compiled config už
  nese jazyk, `:cs` / `:en` sloučí kompilace; `ConfigLocalizer` lokalizuje
  libovolné pole s variantami, ne jen `name`). Chybí cfgItem, klíč nebo
  pole → `$fallback`. Zalogovat (`ErrorLogger::warn`, jednou per klíč
  a instanci = jeden sběr zdroje) **jen chybějící klíč v existujícím
  cfgItemu** — skutečná chyba katalogu. Chybějící compiled config **i
  chybějící cfgItem** (DS před `ds-upgrade`) nelogovat: dashboard a badge
  sekcí se pollují po 60 s, log by se plnil na všech DS až do upgradu.
- Formátování `MessageFormatter::formatMessage($locale, $pattern, $params)`;
  `false` (nevalidní vzor) → warning + zkusit `$fallback`; selže-li
  i ten, vrátit vzor beze změny. Nikdy výjimka — feed nesmí spadnout
  kvůli textu (per-source izolace by kartu zahodila celou). Pozor:
  `new MessageFormatter()` s nevalidním vzorem v PHP 8 **vyhazuje
  `IntlException`** (statické `formatMessage()` vrací `false`) — obalit
  `try/catch \Throwable`.
- Chybějící parametr není chyba: ICU nechá zástupný symbol v textu
  (`jistota {pct} %`), přebytečné parametry ignoruje, neznámé locale
  tiše padá na výchozí. Helper parametry předem nekontroluje.
- Texty bez parametrů jdou přes ICU také — jednotná pravidla pro apostrof
  (`''`) pro katalog i anglické fallbacky v PHP.
- Locale z `ctx->language` (`cs`, `en`); jiné hodnoty předat ICU tak, jak
  jsou.

## 2. Katalogy (D13)

Tři nové cfgItemy, registrace v `module.jsonc` daného modulu v poli
**`config[]`** (`{id, file}` → `config/feedTexts.jsonc`; tak registrují
cfgItemy všechny moduly, `ConfigCompiler` čte `$module->config` — klíč
`cfgItems` neexistuje). Formát položky:

```jsonc
"digest.title": {
    "text": "{n, plural, one {# message auto-archived} other {# messages auto-archived}}",
    "text:cs": "{n, plural, one {# zpráva automaticky archivována} few {# zprávy automaticky archivovány} many {# zpráv automaticky archivováno} other {# zpráv automaticky archivováno}}",
    "text:en": "{n, plural, one {# message auto-archived} other {# messages auto-archived}}"
}
```

Klíče a texty — **přesně dnešní znění** (ověř v kódu, tabulka je mapa,
ne zdroj pravdy):

| cfgItem | Klíč | Dnešní text cs (en) |
|---|---|---|
| `core.mail.feedTexts` | `notInvoice.title` | Není faktura — {type} (Not an invoice — {type}) |
| | `primaryType.other` | Ostatní (Other) |
| | `confidence` | jistota {pct} % (confidence {pct} %) |
| | `emailSubject` | e-mail „{subject}" (email „{subject}") — viz Pasti |
| | `validUntilInline` | platí do {date} (valid until {date}) |
| | `detail.docNumber` / `detail.dueDate` / `detail.paymentReference` / `detail.validUntil` | Číslo dokladu / Splatnost / Variabilní symbol / Platí do |
| | `docTypeFallback` | Doklad (Document) |
| | `partnerFrom` | {partner} · od: {sender} ({partner} · from: {sender}) |
| | `digest.title` | plurál „zpráva automaticky archivována“ |
| | `senderRule.title` | Vždy archivovat poštu od {pattern}? (Always archive mail from {pattern}?) |
| `core.alerts.feedTexts` | `group.subtitle` | {n} upozornění ({n} alerts) |
| | `group.action` | Otevřít upozornění (Open alerts) |
| | `setup.title` | Dokončit nastavení (Finish setup) |
| | `setup.items` | plurál „položka / položky / položek“ |
| | `setup.action` | Otevřít nastavení (Open setup) |
| `core.exchange.feedTexts` | `contentTag.waiting` | plurál „doklad čeká / doklady čekají / dokladů čeká“ |
| | `contentTag.suggestion` | {waiting} · návrh: {starter} ({waiting} · suggestion: {starter}) |
| | `contentTag.suggestionAccount` | … · návrh: {starter} ({account}) |
| | `contentTag.chooseStock` | {waiting} · zvolte účtování: materiál, nebo zboží |
| | `contentTag.create` | Založit položku (Create item) |
| | `contentTag.asMaterial` / `contentTag.asGoods` | Jako materiál ({account}) / Jako zboží ({account}) |

Granularitu klíčů (celá věta s parametry vs. skládání po kouscích) zvol
tak, aby překladatel viděl celou větu — skládání ` · ` mezi nezávislými
částmi (jistota, platí do) může zůstat v PHP.

Upřesnění po ověření v kódu (2026-10-06):

- `group.subtitle` zůstává doslovné `{n} upozornění` / `{n} alerts` —
  skupinová karta vzniká až nad prahem agregace (> 3), plurál by nic
  nezměnil a doslovné `{n}` navíc neformátuje tisíce (výstup 1:1).
- Plurály (`digest.title`, `setup.items`, `contentTag.waiting`) **musí**
  uvnitř větví používat `#`, viz Pasti. České hranice ICU (one = 1,
  few = 2–4, other = 5+, včetně 21, 22, 25, 101) odpovídají dnešnímu
  `=== 1` / `< 5` / `2..4` přesně; `many` platí jen pro desetinná čísla,
  uvádí se kvůli paritě s frontendem.
- Klíče `validUntil` (inline „platí do {date}“) a `docType.fallback`
  („Doklad“) místo `validUntilInline` / `docTypeFallback` z tabulky.

Po přidání cfgItemů `vendor/bin/shpd-ds ds-upgrade` v dev DS.

## 3. Převod zdrojů

- `MailSuggestionsSource`, `MailDigestSource`, `AlertsSource`,
  `ContentTagSuggestionsSource`: každý `$cs ? … : …` a každý text
  z tabulky výše přes `FeedTexts::t(klíč, anglický fallback, params)`.
- Zrušit ruční plurály: `MailDigestSource` (titulek digestu),
  `AlertsSource::pluralizeItems()`, `ContentTagSuggestionsSource::waitingText()`.
- Formát data (`j. n. Y` / `Y-m-d`) zůstává beze změny (D17).
- Nic dalšího ve zdrojích neměnit — kontrakt karet, `kind`, `feedSection`
  i akce zůstávají.

## 4. Dokumentace

- `CLAUDE.md` → *Backend — Vícejazyčnost server-driven labels*: odrážka
  „Texty karet feedu: `*.feedTexts` per modul, `FeedTexts` (ICU
  MessageFormat, plurály), fallback anglicky v PHP“.
- `docs/dashboard.md` §5: odkud zdroje berou texty, jak přidat text
  (klíč do katalogu cs + en, volání `FeedTexts::t`).
- `docs/modules.md` (vícejazyčnost) — zmínka o ICU vzorech v cfgItemech
  jako vzoru pro další katalogy.

## Testy

- `FeedTextsTest` (nový): vzor z cfgItemu, parametry, plurály cs (1 / 3 /
  5 / 0) a en, chybějící config → fallback bez warningu, chybějící klíč →
  fallback + warning, nevalidní vzor → fallback, nevalidní i fallback →
  vzor beze změny, nikdy výjimka.
- **Test úplnosti katalogů** (D16, vzor `PrintDeclarationsTest`): pro
  všechny tři `feedTexts.jsonc` — každý klíč má holé pole, `:cs` i `:en`;
  každý vzor projde `new \MessageFormatter($locale, $pattern)` bez chyby.
- Stávající testy zdrojů (`MailSuggestionsSourceTest`,
  `MailDigestSourceTest`, `AlertsSourceTest`,
  `ContentTagSuggestionsSourceTest`) **projdou beze změny očekávaných
  textů** — to je důkaz, že se texty nemění. **Pozor:** dnes běží
  s `config = null` a `lang = 'cs'` a assertují české texty; s anglickým
  fallbackem (D15) by spadly. Helpery `context()` proto dostanou dodávané
  katalogy lokalizované do jazyka testu přes sdílenou fixture
  `tests/Fixtures/Core/Feed/ShippedFeedTexts` (vzor `shippedConfig()`
  v `AnalysisErrorPresenterTest`); existující mock configu se zabalí
  (ostatní cfgItemy deleguje). Mění se konstrukce kontextu, ne očekávané
  texty. Testy, které kontext staví ručně s `null` configem a texty
  neassertují, zůstávají. Doplnit per zdroj případ „en katalog × en
  fallback dávají totéž“.
- Plurální hranice, které dnes kód řeší ručně, pokrýt explicitně
  (1, 2, 4, 5, 21 položek / zpráv / dokladů).

## Task breakdown

### Commit 1 — Helper a katalogy
`FeedTexts`, tři `feedTexts.jsonc` + registrace, `FeedTextsTest`, test
úplnosti. `feat(feed): katalog textů karet feedu (#101 D11)`

### Commit 2 — Převod zdrojů
Krok 3 + testy zdrojů. `refactor(feed): texty zdrojů feedu z katalogu (#101 D11)`

### Commit 3 — Dokumentace a stav
Krok 4, `**Stav:**`, `python3 scripts/tasks-index.py`.
`docs(feed): katalog textů karet (#101 D11)`

## Pasti

- **ICU apostrof.** V MessageFormat je `'` escape znak — anglické texty
  s apostrofem musí mít `''`. České typografické uvozovky `„“` jsou
  v pořádku. Test úplnosti nevalidní vzor odhalí, chybějící apostrof ve
  výstupu ne — u anglických textů s apostrofem zkontrolovat výstup.
- **Dnešní `e-mail „…"` končí rovnou ASCII uvozovkou** (`'"'`), ne
  typografickou. Text má zůstat stejný — v katalogu zachovat
  stávající znak, sjednocení na `“` je samostatná změna.
- **Vnořené `{n}` v plurálové větvi v PHP nefunguje.** PHP intl bere
  netypované `{n}` jako řetězec a plurálový selektor jako číslo — tentýž
  argument v obou rolích skončí `U_ARGUMENT_TYPE_MISMATCH` a `false`.
  Uvnitř plurálu proto vždy `#`, stejně jako frontend
  (`{count, plural, one {# záznam} …}`). Varianta
  `{n, number, ::group-off}` funguje, ale rozchází se s konvencí
  frontendu — zamítnuto.
- **`#` v plurálu** = formátované číslo v locale (v `cs` s nezlomitelnou
  mezerou u tisíců, `1 234 zpráv`). Dnešní kód vkládá číslo bez
  formátování — u počtů nad 999 se výstup změní. U počtů karet
  nepravděpodobné, přijatelné; zmínit v commitu. Doslovné `{n}` mimo
  plurál tisíce neformátuje (PHP ho předá jako řetězec).
- **Pole s `:cs` / `:en`** — ověřeno: `ConfigLocalizer` lokalizuje
  libovolné pole s variantami (`text`), ne jen `name`.
- **Bez `ds-upgrade` uvidí čeští uživatelé anglický fallback.** Nový
  cfgItem se do `compiled.cs.json` dostane až kompilací. Na alfě po
  nasazení `ds-upgrade` všech zdrojů dat — zapsat do stavu tasku.
- **Fallback nesmí vyhodit výjimku** — `FeedCollector` by izoloval celý
  zdroj a feed by přišel o všechny jeho karty.
- Testovací data syntetická — žádné reálné názvy ani částky.

## Hotovo když

- [x] Ve čtyřech zdrojích feedu nezůstalo `$ctx->language === 'cs'` ani
      `$cs ?` pro texty (výjimka: formát data, D17).
- [x] Ruční plurály zrušené, plurály jdou přes ICU.
- [x] Tři `feedTexts.jsonc` s `cs` i `en`, test úplnosti prochází
      (`FeedTextsCatalogTest` navíc hlídá shodu fallbacků ve zdrojích
      s katalogem a nepoužité klíče).
- [x] Stávající testy zdrojů prošly bez změny očekávaných textů
      (katalog jim dodává fixture `ShippedFeedTexts`); `FeedTextsTest`
      pokrývá fallbacky a plurální hranice.
- [ ] `ds-upgrade` v dev DS (`lh6x` hotovo, ostatní podle potřeby),
      dashboard v cs i en vypadá stejně jako před změnou (ruční proklik).
- [x] `CLAUDE.md`, `docs/dashboard.md`, `docs/modules.md` aktualizované,
      `tasks-index.py` prošel, `**Stav:**` aktualizovaný (včetně
      poznámky o `ds-upgrade` při nasazení).
