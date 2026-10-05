# Autor dokladu („Vystavil“), uživatel ↔ Osoba a import uživatelů

**Stav:** naplánováno — rozhodnutí D1–D13 zamčená v #93, k implementaci

> PRD pro Claude Code (6 commitů). Design: issue #93, komentář
> „Rozhodnutí (zamčeno 2026-10-05)“ (D1–D11) a „Rozhodnutí D12–D13“.
> Vyčleněno: podpis a razítko #99, kontakty vlastní firmy v patičce #100.

## Kontext

Hlavička dokladu (`docs_core_heads`) nemá autora a tisk proto neumí
„Vystavil“. Starý systém měl na hlavičce `author` (odkaz na Osobu; uživatel
byl ve starém Osobou s loginem), výchozí přihlášený uživatel, a globální
nastavení „Autor automaticky generovaných dokladů“ pro doklady vzniklé bez
uživatele.

V novém Shipardu jsou uživatelé (`core_system_users`) a Osoby
(`base_persons_persons`) oddělené a nejsou propojené. Ostatní tabulky mají
konvenci `created_by` → `core_system_users` (`system: true`), kterou ale
plní jen `FormController` při insertu — záznam vzniklý jinou cestou
(applier, služba, CLI) ji má NULL, pokud ji služba nenastaví sama.

Import ze starého systému (mimo tento repozitář) potřebuje: založit
uživatele pro Osoby, které „něco udělaly“ (autor dokladu, autor záznamu
spisovny), a předat autora explicitně. Rozbor dvou zdrojů starého systému:
bez autora je 6–21 % dokladů, autorů je jednotky, většina z nich je
zároveň partnerem na dokladech (Osoba tedy zůstává Osobou) a osob
s loginem je řádově víc než autorů — login proto není kritérium.

## Před implementací přečti

- Issue #93 — komentář „Rozhodnutí (zamčeno 2026-10-05)“
- `docs/document-system.md` (lifecycle `TableGateway::saveDocument`, hooky)
- `docs/table-definitions.md` §8 Extensions
- `docs/edit-forms.md` — select, taby, `applyNewRecordDefaults`
- `docs/app-settings.md` §6 (settings page, `select` + `optionsProvider`)
- `docs/exchange-format.md` (`applyOptions`, import mód) a
  `docs/exchange-format-persons.md` (vzor exchange flow mimo doklady)
- `docs/auth.md` — mapování identit, auto-link podle e-mailu, JIT, pozvánka
- `docs/prints.md` — `PrintData`, blok `document` (`DocDocumentBlock`),
  `_layout/footer.html.twig`, katalogy překladů
- `src/Core/Document/TableGateway.php`, `src/Api/Controller/FormController.php`
  (blok „Auto-manage created_by“), `src/Core/Auth/CurrentUser.php`,
  `src/Api/Middleware/AuthMiddleware.php` (API klíč → `user_id`)
- `modules/docs/core/src/DocDocument.php` (`beforeSave`),
  `DocsHeadsFormBase.php` (`applyNewRecordDefaults`, `buildExtraTabs`),
  `IssuedInvoiceFormBase.php` (`buildSettingsTab`),
  `modules/docs/invoicesIn/src/ReceivedInvoiceForm.php`,
  `modules/docs/cashDocs/src/CashDocForm.php`,
  `modules/docs/cashRegister/src/CashRegisterForm.php`,
  `modules/docs/accountingDocs/src/AccountingDocsForm.php`,
  `modules/docs/core/src/DocsHeadsForm.php`, `NumberSeriesForm.php`
- `modules/core/exchange/src/Document/DocumentApplier.php` (sestavení dat
  hlavičky + `array_filter` nullů), `src/Api/Controller/ExchangeController.php`,
  `src/Api/Router.php` (`/_exchange/...`)
- `modules/base/registry/src/RegistryImportService.php` (`legacy.author`,
  `created_by => null`)
- `modules/core/system/src/UsersViewer.php`, `modules/core/system/module.jsonc`
- `modules/economy/assets/src/Posting/AssetPostingSeries.php` (vzor
  `SettingsOptionsProvider`)

## Scope

**Uvnitř:** vyplňování `created_by` v `TableGateway`; sloupec Osoba
u uživatele; `author` na hlavičce dokladu s pořadím výchozích hodnot;
autor automaticky vystavených dokladů (číselná řada + nastavení); pole
na formulářích dokladů; autor v exchange (doklady, spisovna); import
uživatelů přes exchange; „Vystavil“ v patičce tisku; testy, dokumentace,
nápověda.

**Mimo:** podpis a razítko (#99); kontakty vlastní firmy v patičce
(#100); hromadná fakturace (D11 ji jen připravuje); odesílání e-mailu
z adresy autora; obecná historie změn záznamu; kopie dokladu (v novém
zatím není — až vznikne, autor = kdo kopíruje, podle D1); runner ve
starém systému.

## 1. `created_by` v `TableGateway` (D10)

- `TableGateway::saveDocument`: při **insertu**, pokud tabulka
  (`$this->tableDef`) má sloupec `created_by` a klíč **v datech chybí**
  (`!array_key_exists`), nastav `created_by = CurrentUser::id()`.
  Přítomný klíč (i `null`) se nepřepisuje — applier / import / služba
  rozhodla. Ve strojovém kontextu (`CurrentUser::id() === null`) zůstane
  NULL. Fallback D11 se na `created_by` **nepoužije**.
- Místo: před `validate()` (hooky už vidí efektivní hodnotu), jen pro
  insert (`$originalData === null`).
- `FormController`: blok „Auto-manage created_by“ odstranit — `created_by`
  je `system: true`, z klienta nepřijde, gateway ho doplní. `created` /
  `modified` zůstávají ve `FormController` (mimo rozsah).
- Ověř, že všechny továrny gateway předávají `tableDef` (bez něj se
  vyplnění tiše přeskočí) — `grep -l` na `new TableGateway(` s
  `--include=*.php`; kde chybí a jde to, doplň.
- Služby, které zapisují mimo gateway (raw `insert`) a `created_by` si
  nastavují samy (`AttachmentService`, …), se nemění.

## 2. Uživatel ↔ Osoba (D6)

- `modules/base/persons/extensions/core_system_users.jsonc`: sloupec
  `person` (int, nullable, `reference: base_persons_persons`,
  `name:cs` „Osoba“). Index `idx_person`. `base.persons` už na
  `core.system` závisí — žádná nová závislost.
- Zdroj dat bez `base.persons` (portál hostingu) sloupec nemá a nic
  z následujícího ho nesmí předpokládat (kontrola přes `TableDefinition`).
- Uživatelé nemají registrovaný formulář (AutoFormBuilder). Ověř, že
  `person` se ve formuláři uživatele ukáže jako lookup na Osoby
  (`PersonsLookup`); pokud ne, zaregistruj v `base.persons` úzký
  `UsersForm`, nebo doplň podporu — rozhodni podle toho, co je menší
  zásah, a zapiš do „Implementace“.
- `UsersViewer::renderDetail`: řádek Osoba (jméno), jen když sloupec existuje.
- Aktivace importovaného uživatele (D8) = admin ve formuláři uživatele
  zaškrtne Aktivní; pozvánka v detailu už existuje. Nic dalšího.

## 3. Autor na dokladu (D1, D2, D3, D11, D12)

### 3.1 Schéma

- `docs_core_heads.author` — int, nullable, `reference: core_system_users`,
  `name:cs` „Vystavil“, `name:en` „Issued by“. **Není** `system` (edituje
  se ve formuláři). Bez `created_by` na dokladech (D2).
- `docs_core_number_series.auto_author` — int, nullable,
  `reference: core_system_users`, `name:cs` „Autor automaticky
  vystavených dokladů“; skupina vedle „Odesílání e-mailem“.
- Globální nastavení: nová settings page `docsDocuments` v `docs.core`
  („Doklady“, sekce `accounting` vedle Číselných řad), pole
  `docs.autoAuthor` typu `select` s `optionsProvider`.

### 3.2 Výběr uživatelů

- `Shipard\Module\Core\System\ActiveUsersOptions`
  (`SettingsOptionsProvider`): aktivní, ne-systémoví uživatelé,
  `label = full_name`, řazení podle jména. Stejnou nabídku použije
  formulář dokladu a číselné řady (statická metoda / sdílená služba —
  jedna implementace).
- Ve formuláři: nabídka = aktivní uživatelé + **aktuální hodnota**, i když
  je neaktivní (jinak by uložení formuláře autora tiše smazalo nebo
  selhalo). Prázdná volba = bez autora.

### 3.3 Výchozí hodnota (D1, D11)

Nová služba `DocAuthorResolver` (`docs.core`) — jediné místo pravdy:

1. klíč `author` **v datech je** (i `null`) → beze změny;
2. `CurrentUser::id()` není null → ten;
3. strojový kontext: `auto_author` číselné řady dokladu → `docs.autoAuthor`
   (`SettingsStore`) → NULL. Neaktivního uživatele z nastavení nepoužít
   (zalogovat warning, NULL).

Volá ji `DocDocument::beforeSave` jen při insertu (`$originalData === null`)
a před `denormalizeFromSeries` (potřebuje řadu z dat). Při updatu se
`author` nemění, pokud ho nepošle formulář.

Formulář nového dokladu: `DocsHeadsFormBase::applyNewRecordDefaults`
předvyplní `author = CurrentUser::id()` (pokud v datech chybí), aby pole
ukázalo výchozího autora. Uživatel ho může vymazat — pak se uloží NULL
(bod 1).

### 3.4 Formuláře (D3, D12)

Pole `author` (select podle 3.2, popisek „Vystavil“) v tabu **Nastavení**
všech formulářů dokladů:

- `IssuedInvoiceFormBase::buildSettingsTab` (faktura vydaná, zálohová),
  `ReceivedInvoiceForm`, `CashDocForm`, `CashRegisterForm` — tab existuje;
- `AccountingDocsForm` a `DocsHeadsForm` (výchozí) tab Nastavení nemají —
  přidat přes `buildExtraTabs` tab s tímto jediným polem. Společná metoda
  v `DocsHeadsFormBase` (např. `addAuthorElement(TabBuilder)`), ať se
  pole nestaví pětkrát.

Read-only stav dokladu: pole je jen ke čtení jako ostatní (nepřidávat do
`getReadOnlyEditableColumns`).

`NumberSeriesForm`: `auto_author` (select podle 3.2) v sekci vedle
„Odesílání e-mailem“, nápověda „Použije se u dokladů vystavených bez
přihlášeného uživatele (např. automaticky)“.

## 4. Exchange a import (D7, D9, D13)

### 4.1 Doklady — `applyOptions.author`

- Schéma `shpd.docs.document.v1`: `applyOptions.author` —
  `integer | null`. Přítomný klíč = explicitní autor (null = bez autora);
  chybějící = výchozí podle 3.3.
- `DocumentApplier`: přítomný klíč propsat do dat hlavičky **i jako null** —
  současný `array_filter` nully zahazuje, takže `author` musí projít
  výjimkou (stejně jako `rows`). Hodnota musí být id existujícího uživatele
  (aktivního i neaktivního), jinak validační chyba `applyOptions.author`.
- Platí mimo import mód taky (API klient smí autora určit); AI extrakce
  ho neposílá.

### 4.2 Spisovna — `createdBy`

- `RegistryImportService::import`: volitelné `createdBy` (int | null) →
  `created_by`; ověřit existenci uživatele jako v 4.1. Chybějící = dnešní
  chování (NULL). `legacy.author` (jméno) zůstává v metadatech.

### 4.3 Import uživatelů — `/_exchange/users/user/{validate,apply}`

Nový malý flow ve sdíleném dispatcheru `exchange` (vzor osob), schéma
`shpd.system.user.v1`:

```jsonc
{
  "format": "shpd.system.user.v1",
  "login": "jana@example.test",      // povinné; importér ho určí (D7)
  "email": "jana@example.test",      // nullable
  "fullName": "Jana Příkladová",     // povinné
  "person": 123                      // nullable — id Osoby v tomto zdroji
}
```

Odpověď `apply`: `{ "userId": 7, "created": true|false }`.

Párování (idempotentní, `ds-reset` uživatele nemaže — `keepOnReset`):

1. uživatel se stejným `login` → vrátit ho;
2. jinak **aktivní** uživatel se stejným `email` (bez ohledu na velikost
   písmen; právě jeden) → vrátit ho (skutečný účet založený adminem);
3. jinak založit: `is_active = 0`, `password_hash = NULL`, `is_admin = 0`,
   `is_system = 0`, `login`, `email`, `full_name`, `person`.

U nalezeného uživatele se **nic nemění** kromě `person`: nastaví se, když
je prázdná nebo ukazuje na neexistující Osobu (po `ds-reset` se Osoby
založí s novými id, uživatelé zůstanou). Jinak se ponechá.

Login = e-mail, při chybějícím nebo duplicitním e-mailu syntetický
(`import-<ref>`) — rozhoduje **importér** (zná všechny Osoby), applier
jen kontroluje `unq_login` přes párování.

Oprávnění (D13): admin nebo API klíč. Applier nikdy nezaloží aktivního
uživatele, admina ani heslo a existujícího uživatele nemění (kromě
`person`), takže API klíč tím nezíská přihlášení.

Kde žije kód: `core.exchange` (`src/User/UserApplier.php`, schéma
v `schemas/`), sloupec `person` jen když existuje.

## 5. Tisk — „Vystavil“ (D4)

- `DocDocumentBlock::describe`: `author` → `{ "name": full_name }` nebo
  `null` (bez autora / neexistující uživatel). Platí i pro Kontace
  (stejný `describe`). Doplnit do tabulky bloku `document` v
  `docs/prints.md`.
- `_layout/footer.html.twig`: vpravo nad číslem strany řádek
  „{{ t('footer.issuedBy') }}: jméno“, jen když `data.document.author`.
  Patička musí dál fungovat u tisků bez bloku `document`.
- Katalogy `cs` / `en` / `sk` / `de`: `footer.issuedBy` = „Vystavil“ /
  „Issued by“ / „Vystavil“ / „Ausgestellt von“ (sk/de do revize jako
  ostatní, `tasks/prints-languages.md`).

## Testy

- `TableGatewayTest`: insert bez klíče → `CurrentUser`; klíč `null` →
  NULL; update se nemění; tabulka bez `created_by` beze změny;
  `CurrentUser` null → NULL.
- `DocAuthorResolverTest`: všechny větve 3.3 včetně neaktivního
  uživatele v nastavení a řady bez `auto_author`.
- `DocDocument` insert/update — autor se při updatu nepřepisuje.
- Formuláře: pole v tabu Nastavení u všech typů; nabídka obsahuje
  aktuální neaktivní hodnotu.
- `DocumentApplier`: `applyOptions.author` přítomen / `null` / chybí /
  neexistující uživatel.
- `RegistryImportService`: `createdBy`.
- `UserApplier`: párování 1–3, oprava visící `person`, nic jiného se
  u existujícího nemění, `unq_login`, zdroj bez sloupce `person`.
- Tisk: `DocDocumentBlock` s autorem a bez, render patičky (`--format=html`).

PHPUnit cíleně `--filter`, celou sadu na konci.

## Task breakdown

### Commit 1 — `created_by` v `TableGateway` (§1)
Kód, testy, `docs/document-system.md` (kdo plní `created_by`).

### Commit 2 — Uživatel ↔ Osoba (§2)
Extension, formulář / detail uživatele, testy.

### Commit 3 — Autor dokladu (§3)
Sloupce, `DocAuthorResolver`, `ActiveUsersOptions`, settings page,
formuláře dokladů a číselné řady, testy. `ds-upgrade` na ukázkovém zdroji.

### Commit 4 — Exchange (§4)
`applyOptions.author`, `createdBy` spisovny, flow uživatelů, schémata,
testy, `docs/exchange-format.md` + nový oddíl o uživatelích.

### Commit 5 — Tisk (§5)
Blok, patička, katalogy, testy, `docs/prints.md`.

### Commit 6 — Dokumentace a nápověda
- `CLAUDE.md` → Dokumentový systém: jedna odrážka o `created_by`
  (gateway, `CurrentUser`, přítomný klíč se nepřepisuje) a o `author`
  dokladu (`DocAuthorResolver`).
- `docs/auth.md`: importovaný neaktivní uživatel a jeho aktivace (D8),
  sloupec Osoba.
- `help/`: „Vystavil“ v tabu Nastavení dokladu
  (`faktury-vydane/vystaveni-faktury.md` a obdobně), autor automaticky
  vystavených dokladů (nastavení Doklady a číselná řada);
  `help/co-dnes-nejde.md`: podpis a razítko, kontakty v patičce.
  Popisky ověř ve zdroji (`module.jsonc`, `cs.js`). `python3
  scripts/help-index.py`.
- Hlavička `**Stav:**` tohoto tasku + `python3 scripts/tasks-index.py`.

## Hotovo když

- [ ] Nový doklad z formuláře, z návrhu došlé pošty i přes API má
      autora = přihlášený uživatel; ve strojovém kontextu autora z řady,
      jinak z nastavení, jinak NULL.
- [ ] Explicitní `applyOptions.author: null` uloží doklad bez autora.
- [ ] `created_by` vyplňuje gateway na všech cestách s uživatelem;
      `FormController` ho už nenastavuje.
- [ ] Uživatel má pole Osoba (jen na zdrojích s Osobami).
- [ ] `/_exchange/users/user/apply` je idempotentní a nikdy nezaloží
      aktivní účet.
- [ ] Tisk faktury ukazuje „Vystavil: jméno“ v patičce; doklad bez autora
      řádek nemá.
- [ ] Testy prošly, frontend se nemění (`npm run build` jen pokud se
      ukáže potřeba), dokumentace a nápověda aktualizované.

## Rozhodnutí k designu (potvrzená)

D1–D11: issue #93, komentář „Rozhodnutí (zamčeno 2026-10-05)“;
D12–D13: komentář „Rozhodnutí D12–D13“. Potvrzené:

- ✓ **D12** (nahrazuje D3 v části „lookup“) — `author` a `auto_author` jsou
  `select`, ne `lookup`. Uživatelů je v jednom zdroji jednotky až desítky
  a lookup endpoint by u `core_system_users` narazil na `TableAccessGuard`
  (systémové tabulky jen pro admina) — účetní by autora nevybral. Select
  dostane nabídku ze serveru ve formuláři, guard se neobchází.
- ✓ **D13** — Import uživatelů (`/_exchange/users/user/apply`) smí admin
  nebo API klíč; vždy neaktivní, bez hesla a bez admina; existujícího
  uživatele mění jen v `person`.

## Implementace

⟨doplní Claude Code⟩
