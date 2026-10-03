# Shipard — Výměnný formát osob

> Kanonický výměnný formát pro entitu **Osoba** (firma i fyzická osoba)
> včetně sub-kolekcí (adresy, bankovní účty, kontakty). Druhý konkrétní
> formát postavený nad infrastrukturou modulu `core.exchange`.
>
> Sourozenec specifikace pro doklady: [exchange-format.md](exchange-format.md).

## 1. Účel a kontext

`shpd.persons.person.v1` je kanonická JSON reprezentace entity osoby určená
pro:

1. **Import z registrů** — ARES (CZ), RPO (SK), Handelsregister (DE), vlastní
   Shipard registry — adaptér přečte registrový formát, transformuje na
   canonical, předá applieru.
2. **Periodickou synchronizaci** se zdrojovým registrem — re-import téže
   osoby s `mergeStrategy: fullSync` udrží lokální data v souladu s registrem
   (kanonický zdroj pravdy pro hlavičku + adresy).
3. **Vytvoření osoby z dokladu** — když `DocumentApplier` při `canCreate`
   pro Party potřebuje vytvořit `base_persons_persons` záznam, projektuje
   Party do Person formátu a deleguje na `PersonApplier`. Jedna code path
   pro každé vytvoření osoby.
4. **Import / export mezi Shipard datasetů** — uživatel může vyexportovat
   osobu z jednoho DS a naimportovat ji do druhého (např. při migraci
   nebo sdílení katalogu klientů).
5. **Manuální zadání přes API** — externí systém (např. webformulář partnera)
   posílá Person canonical do `/apply` místo komplikovaného mapování na
   interní strukturu.

### Vztah k `shpd.docs.document.v1` (Party je projekce Person)

Party objekt v dokladu je zjednodušený výřez Person s jednou adresou
a jedním bankovním účtem — vše, co se v dokumentové hlavičce snapshotuje.
Z toho plynou tři praktické důsledky:

- **Sub-schémata `Address` a `BankAccount`** (sekce 5 a 6) jsou sdílena
  mezi oběma formáty. JSON Schema fragmenty žijí v Person spec jako
  kanonické a Document spec na ně odkazuje.
- **PartyResolver** (existující) i **AddressResolver** / **ContactResolver**
  (nové) zůstávají samostatné a používají sdílené normalizační utility.
- **`DocumentApplier::create Party`** v rámci doc apply pipeline volá
  `PersonApplier::apply` s payloadem postaveným z Party. Vytvořená osoba
  prochází standardní validací `PersonDocument`.

## 2. Pojmosloví (rozšíření)

| Termín | Význam |
|--------|--------|
| **Header** | Hlavičková data Person — vše krom sub-kolekcí (`addresses`, `bankAccounts`, `contacts`). |
| **Sub-kolekce** | Pole adres, bankovních účtů a kontaktů náležejících osobě. |
| **Merge strategy** | Politika, jak `apply` zpracuje payload, když osoba (matched podle `companyId`/`vatId`/`taxId`) v DB **už existuje**. Viz sekce 9. |
| **Match-key** | Sada polí, podle kterých applier páruje sub-záznam payloadu s existujícím DB záznamem. Per typ sub-kolekce. |
| **Authoritative refresh** | Speciální chování pro adresy typu Provozovna / Zařízení: matched podle `place_reg_id` → adresní pole se vždy přepíšou z payloadu, nezávisle na `mergeStrategy`. Registr je zdroj pravdy. |

Zbytek pojmosloví (Canonical, Schema, Resolve, Apply, Lineage) viz
[exchange-format.md sekce 2](exchange-format.md#2-pojmosloví).

## 3. Specifikace `shpd.persons.person.v1`

```jsonc
{
  // ── Format meta ──────────────────────────────────────────────────────────
  "format": "shpd.persons.person",
  "formatVersion": "1.1",

  // ── Source (audit / lineage) ─────────────────────────────────────────────
  "source": {
    "kind":         "import.ares",     // viz cfgItem base.persons.sourceKinds
    "fetchedAt":    "2026-05-19T10:00:00Z",
    "registryRef":  "12345678",        // ID v daném registru (typicky IČO)
    "raw":          { /* opaque */ }   // optional source-specific payload
  },

  // ── Identifikace ─────────────────────────────────────────────────────────
  "personType": "company",             // company | person (required)
  "country":    "CZ",                  // ISO 3166-1 alpha-2; lowercase v canonical
                                       //   ("cz", "sk", "de", …)
  "personId":   null,                  // lidsky čitelný kód; optional
                                       //   pokud null → applier vygeneruje
                                       //   pokud vyplněn → applier zkusí použít
                                       //                  → kolize = error

  // Identifiers (alespoň jeden silně doporučený pro company)
  "companyId":         "12345678",
  "taxId":             "CZ12345678",
  "vatId":             "CZ12345678",
  "courtRegistration": "Obchodní rejstřík vedený MS v Praze, oddíl C, vložka 123456",
  "govEBoxId":         "abcd1ef",     // ID datové schránky (jen CZ; 7 znaků v praxi)

  // ── Jméno ────────────────────────────────────────────────────────────────
  "name": {
    "fullName":    "Novák Jan",        // u firmy = obchodní jméno
                                       // u osoby = výsledek skládání (může chybět;
                                       //   applier si ho dopočte podle PersonDocument)
    "titleBefore": "Ing.",
    "firstName":   "Jan",              // jen personType=person
    "middleName":  null,
    "lastName":    "Novák",
    "titleAfter":  null
  },

  // ── Osobní údaje (jen personType=person) ─────────────────────────────────
  "personal": {
    "birthDate":    "1980-01-01",
    "nationalId":   "8001011234",
    "idCardNumber": "AB123456"
  },

  // ── Hlavičkové kontakty ──────────────────────────────────────────────────
  // Zkratka pro nejdůležitější kontakty — propisují se na sloupce
  // base_persons_persons.{email, phone, web}. Strukturované kontaktní
  // osoby/místa jsou v `contacts[]`.
  "contact": {
    "email": "info@firma.cz",
    "phone": "+420 123 456 789",
    "web":   "https://firma.cz"
  },

  // ── Stav ─────────────────────────────────────────────────────────────────
  "status": {
    "isClosed":   false,
    "closedDate": null,
    "isOwn":      false,
    "docState":   10                   // optional; default 10 (Koncept)
                                       //   povolené: 10, 40, 70, 80, 90
  },

  // ── Dokumenty (od 1.1, volitelný blok — viz níže) ────────────────────────
  "documents": {
    "language":              "de",     // jazyk dokumentů; null = automaticky
    "sendAttachmentsMerged": false     // přílohy dokladu připojit do PDF dokladu
  },

  // ── Sub-kolekce ──────────────────────────────────────────────────────────
  "addresses":    [ { /* Address — viz sekce 5 */ } ],
  "bankAccounts": [ { /* BankAccount — viz sekce 6 */ } ],
  "contacts":     [ { /* Contact — viz sekce 7 */ } ],

  // ── Apply options ────────────────────────────────────────────────────────
  // Volitelný blok — řídí chování apply pipeline.
  // Když chybí, applier použije default `mergeStrategy: mergeAdd`,
  // `targetDocState: 10`.
  "applyOptions": {
    "mergeStrategy":    "fullSync",    // viz sekce 9
    "targetDocState":   40,            // 10 | 40 (cílový docState po apply)
    "createPersonId":   true,          // true = generovat pokud chybí
                                       // false = ponechat NULL (jen header update)
    "rejectOnIssues":   ["error"]      // ["error"] | ["error","warning"] | []
  },

  // ── Resolve state (populated by /preview, used by /apply) ────────────────
  "_resolve": { /* viz sekce 8 */ }
}
```

### Polymorfismus podle `personType`

`personType` určuje, která pole jsou relevantní a jak applier mapuje payload
na sloupce `base_persons_persons`:

- **`company`** — relevantní: `companyId`, `taxId`, `vatId`, `courtRegistration`,
  `govEBoxId`, `name.fullName`. Pole `personal` musí být `null` / vynecháno. Pole
  `name.firstName`/`lastName` se ignorují (applier je vyprázdní podle
  business logiky v `PersonDocument::beforeSave`).
- **`person`** — relevantní: `name.firstName`, `name.lastName`, případně
  ostatní jméno-pole. Pole `personal` může být vyplněno (datum narození,
  rodné číslo, číslo dokladu). `companyId`/`taxId`/`vatId` jsou validní
  (OSVČ má IČO i DIČ).

Validace polymorfismu probíhá v PHP (`PersonExchangeFormat::validate()`),
JSON Schema definuje pouze společnou strukturu.

### `documents` — volby osoby pro dokumenty (od 1.1)

Volitelný blok (#94): `language` → `base_persons_persons.language`,
`sendAttachmentsMerged` → `send_attachments_merged` (extension `docs.core`).

- **`language`** musí být jeden z jazyků dokumentů (cfgItem
  `world.base.documentLanguages`: `cs`, `en`, `sk`, `de`) — jiná hodnota je
  chyba `invalid_document_language` (`PersonValidator`). `null` = jazyk se
  odvodí automaticky podle země (`docs/prints.md` §3).
- **Blok chybí → sloupce se nemění.** Obnova z registru ani starší payload
  (`1.0`) tak nevynuluje ruční nastavení. `"documents": null` je totéž co
  chybějící blok.
- **Blok je → platí pro obě hodnoty**, i když v něm klíč chybí: chybějící
  `language` = `null` (smazat jazyk), chybějící `sendAttachmentsMerged` =
  `false`. Jak se zapíšou, řídí merge strategie (sekce 9): při založení
  a u `updateHeader` / `fullSync` se zapíšou obě; `mergeAdd` doplní jen
  to, co v databázi nastavené není (jazyk do prázdného sloupce, spojování
  jen zapne); `createOnly` existující osobu nemění.
- Zdroj dat bez modulu `docs.core` sloupec pro spojování nemá:
  `sendAttachmentsMerged: true` se ignoruje a výsledek nese varování
  `column_unavailable` na `documents.sendAttachmentsMerged`.
- **Export** (`PersonExporter`) blok píše vždy; spojování jako výslovné
  `true` / `false`, prázdný jazyk se prořezává. Bez sloupce pro spojování
  a bez jazyka blok z exportu vypadne (není co přenášet).

### `complex_name` — schované za applierem

Sloupec `complex_name` v DB řídí, jestli UI ukazuje rozšířená jména pole.
V exchange formátu se **nevyskytuje**. Applier ho odvozuje z payloadu:

- Pokud je vyplněn libovolný z `titleBefore`, `middleName`, `titleAfter`
  → `complex_name = 1`.
- Jinak → `complex_name = 0`.

Naopak při exportu (Phase 3) z `complex_name = 1` vyplývá, že v payloadu
budou všechna jména pole; z `complex_name = 0` jen `firstName` + `lastName`.

### `personId` — kdy generovat, kdy respektovat

| Vstup `personId` | Chování |
|---|---|
| `null` nebo vynecháno | Applier nechá `PersonDocument::beforeSave` ho vygenerovat (krátký alfanumerický hash). |
| Vyplněno, osoba neexistuje | Applier zkusí použít — kolize s jiným záznamem v DS = chyba `person_id_conflict` (`409`). |
| Vyplněno, header `matched` | Applier porovná s existujícím; lišící se hodnota = warning `person_id_mismatch`. Existující kód se neprepíše. |

## 4. Životní cyklus

```
Vstup (ARES JSON, Shipard registry, CSV, ruční zadání, …)
  │
  ▼
[Adaptér / parser]                  ← per-zdroj překlad na canonical
  │
  ▼
Canonical JSON  ──────►  /validate     → vrátí jen issues (no DB writes)
  │                       /preview     → vrátí canonical + _resolve (no DB writes)
  │                       /apply       → resolve → save → vrátí enriched canonical
  ▼
DB záznam v base_persons_persons + sub-tabulky
   (+ aktualizace stávajícího záznamu při mergeStrategy != createOnly)
```

`validate` a `preview` jsou idempotentní a bez vedlejších efektů. `apply`
podle `mergeStrategy` vytvoří, aktualizuje nebo uzavře záznamy.

## 4a. Stavy dokumentů a `valid_to` — dvě ortogonální osy

Všechny čtyři tabulky modulu `base.persons` (persons + addresses, bank_accounts,
contacts) používají standardní `docState` aparát (`core.system.docStatesArchive`).
Sloupec `valid_to` ve třech sub-tabulkách je samostatný mechanismus s jinou
sémantikou. Mít obě je vědomé rozhodnutí — řeší dva různé scénáře, které
by jedna osa modelovala špatně.

| Mechanismus | Sémantika | Příklad |
|---|---|---|
| `valid_to = <datum>` | Záznam **byl správný** a od daného data **přestal platit**. Historická data zůstávají korektní. | Účet `123/0100` zavřela banka; faktury z minulosti tu vazbu legitimně mají. |
| `docState = 90` (Smazáno) | Záznam je **vadný**, neměl být nikdy. | Uživatel zadal IBAN s překlepem; nebo vytvořil dvě stejné adresy. |
| `docState = 70` (Archív) | Záznam je validní, ale nepoužívá se. UI ho schová ze seznamů bez tvrzení, že byl chybný. | Stará adresa daleko v minulosti, kterou nechci dohledávat `valid_to`. |
| `docState = 40`, `valid_to = NULL` | Plně aktivní záznam. | Default stav nového záznamu. |

### Důsledky pro applier

- **`fullSync` closing** sub-záznamů zmizelých ze zdroje nastavuje
  `valid_to = today`, **ne** `docState = 90`. Z registru zmizelá adresa
  byla validní, jen už neplatí — to je sémanticky `valid_to`, ne mazání.
- **Nové sub-záznamy vytvořené applierem** dostanou `docState = 40`
  (V pořádku). Default `10` (Koncept) v DB schématu je pro ruční UI zadání,
  kde uživatel chce mít prostor pro rozmyšlenou; importovaný záznam je
  immediately validní.
- **Resolvery** filtrují `docState IN (10, 40, 80)` (aktivní stavy — Koncept,
  V pořádku, V opravě). Záznamy v Archívu (`70`) a Smazané (`90`) se
  nepáří. Stejný princip jako v `PartyResolver` pro persons.
- **Mazání přes UI** je transition na `docState = 90`. Mimo scope tohoto
  formátu — applier mazání nedělá, jen `fullSync` closing přes `valid_to`.

---

## 5. Address sub-object

```jsonc
{
  "addressType":    1,              // enumInt z base.persons.addressTypes
                                    //   1=Sídlo, 2=Doručovací, 3=Provozovna, 4=Zařízení
  "name":           "Sídlo firmy",  // popisný název, optional
  "placeRegType":   null,           // null | "ICP" | "ICZ"
                                    //   povinné pro addressType=3 (ICP) a 4 (ICZ)
  "placeRegId":     null,           // IČP / IČZ — povinné pro type 3,4

  "isStandardized": true,           // true = data z registru (RÚIAN apod.)
                                    // false = ruční zadání

  // Adresní pole — záleží na isStandardized (viz tabulková dokumentace)
  "street":            "Hlavní",
  "houseNumber":       "1",         // jen isStandardized=true
  "orientationNumber": "3",         // jen isStandardized=true
  "city":              "Praha",
  "cityPart":          "Nové Město", // jen isStandardized=true
  "district":          "Praha 1",   // jen isStandardized=true
  "zip":               "11000",
  "country":           "CZ",
  "registryCode":      "21794160",  // RÚIAN ADM kód
  "divisionCode":      "554782",    // ZÚJ / world_divisions.code
                                    //   applier mapuje na world_divisions.id

  // Geolokace
  "latitude":  50.0875,
  "longitude": 14.4214,
  "manualGps": false,

  // Display lines (informative; applier přepočte v beforeSave)
  "displayLine":  "Hlavní 1/3, 110 00 Praha 1",
  "displayBlock": "Hlavní 1/3\n110 00 Praha 1",

  // Platnost
  "orderPos":  0,
  "validFrom": null,
  "validTo":   null,
  "note":      null
}
```

Adresa má v DB i `docState` (default `10` — pro ruční UI; applier zapisuje
`40`). Mapování na sloupce systému stavů viz [sekce 4a](#4a-stavy-dokumentů-a-valid_to--dvě-ortogonální-osy).

**Mapování na sloupce `base_persons_addresses`:**
camelCase canonical → snake_case DB. Pole `divisionCode` se přes lookup
v `world_divisions` mapuje na `division` (FK). Pole `displayLine`/`displayBlock`
jsou v payloadu informativní — `PersonDocument`/`AddressDocument` je přepočítává.

## 6. BankAccount sub-object

```jsonc
{
  "name":          "Hlavní účet CZK",  // popisný název, optional
  "accountNumber": "1234567890/0100",  // CZ/SK domestic form s bank kódem
  "iban":          "CZ6508000000001234567890",
  "bic":           "GIBACZPX",
  "currency":      "CZK",              // ISO 4217 uppercase; applier lowercase
  "source":        0,                  // 0=manual, 1=transaction, 2=vatRegistry
                                       //   viz base.persons.bankAccountSources
  "orderPos":      0,
  "validFrom":     null,
  "validTo":       null
}
```

Identický fragment jako `Party.bankAccount` v dokladu — jen jako prvek pole.
Tabulka `base_persons_bank_accounts` má v DB `docState` (default `10`;
applier zapisuje `40`).

## 7. Contact sub-object

Tabulka `base_persons_contacts` má v DB `docState` (default `10`; applier
zapisuje `40`).

```jsonc
{
  "name":     "Jan Novák",            // jméno osoby NEBO funkční označení
  "role":     "Obchodní ředitel",     // funkce, optional
  "email":    "novak@firma.cz",
  "phone":    "+420 123 456 789",
  "note":     null,
  "orderPos": 0,
  "validFrom": null,
  "validTo":   null
}
```

## 8. Resolve

`PersonResolver` orchestruje hlavičku a sub-kolekce. Vstup: kompletní
Person canonical. Výstup: nested `_resolve` struktura.

### 8.1 Header resolve

Postup (shodný s `PartyResolver`, plus filter podle `personType`):

Všechny SQL probes filtrují `docState IN (10, 40, 80)` (aktivní stavy).

1. **`(personType, companyId)` exact** v `base_persons_persons` →
   `matched`, `matchedBy: "companyId"`.
2. **`(personType, vatId)` exact** → analogicky.
3. **`(personType, taxId)` exact** → analogicky.
4. **`personType + name.fullName` LIKE** filtered na `country` (přes
   join s adresou) → kandidáti.
5. Žádný kandidát → `canCreate` s payloadem pro `PersonDocument::saveDocument`.

`PartyResolver` existující (modul `core.exchange/src/Resolve/`) je možné
**rozšířit** o `personType` filter parametr (default `null` = bez filtru,
zpětně kompatibilní s doc flow). Alternativně PersonResolver zavolá vlastní
SQL — preference: rozšířit PartyResolver, ať máme jedno místo na změny.

> **Fáze 1 limit:** Country filter v step 4 (přes join na adresu) je
> follow-up. Phase 1 dělá fuzzy jen po `full_name`.

### 8.2 AddressResolver

Match-key zkouší v pořadí (první hit vyhrává). Všechny SQL probes filtrují
`docState IN (10, 40, 80)`.

1. **`placeRegId` vyplněno** → klíč `(person, place_reg_type, place_reg_id)`.
   - Pokrývá provozovny (IČP) a zařízení (IČZ).
   - Match má příznak `authoritativeRefresh = true` — adresní pole se
     vždy přepíšou z payloadu, nezávisle na `mergeStrategy`.
2. **`registryCode` vyplněno** → klíč `(person, address_type, registry_code)`.
   - Pokrývá sídla / doručovací adresy přes RÚIAN ADM.
   - Match má příznak `authoritativeRefresh = false` — adresa se aktualizuje
     podle `mergeStrategy`.
3. **`isStandardized = false`** → klíč `(person, address_type, displayLine)`
   exact match.
   - Pokrývá zahraniční / parcelní adresy.
   - `authoritativeRefresh = false`.

Žádný match → `canCreate` s payloadem připraveným pro `AddressDocument::saveDocument`
(nebo prostý insert, podle implementace).

### 8.3 ContactResolver

Match-key zkouší v pořadí. Všechny SQL probes filtrují
`docState IN (10, 40, 80)`.

1. **`(person, name, email)` exact** — když je email vyplněn.
2. **`(person, name)` exact** — fallback bez emailu.

Žádný match → `canCreate`. Kontakty mají tendenci se duplikovat — applier
preferuje vytvořit nový než přepsat existující u nejisté shody.

### 8.4 BankAccountResolver (reuse)

Existující `BankAccountResolver::resolvePartnerBank` se přepoužije, ale
Fáze 1 dokladů má v něm SQL filter `[docState] IN (10, 40, 80)` na tabulce,
která tuto kolonu doposud neměla — migrace `docState` do sub-tabulek
(viz task) ten **latentní bug** opraví. Logika resolveru zůstává:

1. **`(person, iban)` exact** → `matched`, `matchedBy: "iban"`.
2. **`(person, accountNumber)` exact** → `matched`, `matchedBy: "accountNumber"`.
3. Žádný match → `canCreate`.

### 8.5 ResolveResult + statusy

Sdílíme `ResolveResult` / `ResolveStatus` z `core.exchange` modulu. Pro Person
jsou v praxi tyto statusy:

| Status | Význam |
|--------|--------|
| `matched` | Jednoznačně napárováno, vrací `matchedId` a `matchedBy`. |
| `ambiguous` | Víc kandidátů (jen header), vrací `candidates`. |
| `canCreate` | Žádný match, applier vytvoří záznam podle `mergeStrategy`. |

## 9. Merge strategie

Klíčové rozhodnutí — co se stane s **existující osobou**, na kterou
payload matchnul? Strategie je řízena polem `applyOptions.mergeStrategy`.

| Strategie | Hlavička | Sub-kolekce |
|---|---|---|
| `createOnly` | Reject pokud existuje (`409 person_exists`) | — |
| `updateHeader` | Přepsat hlavičku | Beze změny |
| `mergeAdd` *(default)* | Aktualizovat jen prázdná pole v DB | Matched → nechat; missing v DB → přidat; existující nepřítomné v payloadu → nechat |
| `fullSync` | Přepsat hlavičku celou | Matched → aktualizovat (overwrite); missing v DB → přidat; existující nepřítomné v payloadu → uzavřít (`valid_to = today`) |

Blok `documents` se řídí strategií hlavičky s jedním rozdílem: `null`
jazyk při přepisu hodnotu **maže** (ostatní pole hlavičky `null` přeskakují).
Podrobně sekce 3 → `documents`.

### Authoritative refresh — výjimka pro Provozovna/Zařízení

Když AddressResolver vrátí match s příznakem `authoritativeRefresh = true`
(match podle `place_reg_id`), adresní pole se **vždy přepíšou z payloadu**,
nezávisle na `mergeStrategy`. Důvod: provozovna ani zařízení se nepřemisťují
— rozdíl mezi registrem a lokální DB je vždy oprava chyby, ne změna stavu.

To znamená, že i pro `mergeStrategy: mergeAdd` (která jinak "matched nechává")
adresa Provozovna matched podle IČP dostane refresh.

### `fullSync` a uzavírání záznamů

Při `fullSync` se existující sub-záznamy, které **nejsou v payloadu**, uzavřou
nastavením `valid_to = today`. Smazání ze stavu (`docState = 90`) **NE** —
záznam zůstává pro historické vazby (faktury, kontakty na minulé osoby).

Adresy se uzavírají per `address_type`: payload obsahuje pouze typ "Sídlo"
→ uzavřou se jen existující sídla, doručovací adresy zůstávají. Tím se dá
synchronizovat dílčí pohled (jen registr sídel) bez kolaterálních ztrát.

> **Pozn.:** `valid_to` je relevantní pro sub-kolekce, ne pro hlavičku.
> Hlavička má `is_closed` + `closed_date`, které se v rámci sync **nemění**
> automaticky — uzavření osoby zůstává explicitním uživatelským rozhodnutím.

## 10. `_resolve` state

```jsonc
{
  "summary": {
    "status":          "needsAttention",  // ok | needsAttention | hasErrors | applied
    "headerStatus":    "matched",         // matched | canCreate | ambiguous
    "addressCount":    { "matched": 1, "canCreate": 1, "closing": 0 },
    "bankAccountCount":{ "matched": 1, "canCreate": 0, "closing": 0 },
    "contactCount":    { "matched": 0, "canCreate": 2, "closing": 0 }
  },

  "header": {
    "status":     "matched",          // matched | ambiguous | canCreate
    "personId":   42,
    "matchedBy":  "companyId",
    "candidates": [],                 // jen pro ambiguous
    "userAction": null                // null | "useExisting:<id>" | "create" | "skip"
  },

  "addresses": [
    {
      "index":                 0,
      "status":                "matched",
      "addressId":             100,
      "matchedBy":             "place_reg_id",
      "authoritativeRefresh":  true,        // overwrite vždy
      "userAction":            null
    },
    {
      "index":      1,
      "status":     "canCreate",
      "userAction": null
    }
  ],

  "bankAccounts": [
    { "index": 0, "status": "matched", "bankAccountId": 200, "matchedBy": "iban" }
  ],

  "contacts": [
    { "index": 0, "status": "canCreate" },
    { "index": 1, "status": "canCreate" }
  ],

  // Sub-záznamy v DB, které payload neobsahuje a které applier při fullSync
  // uzavře (informativní; uživatel vidí v preview)
  "closingExisting": {
    "addresses":    [],
    "bankAccounts": [{ "id": 201, "name": "Starý EUR účet", "iban": "DE…" }],
    "contacts":     []
  },

  "issues": [
    {
      "severity": "warning",
      "path":     "personId",
      "code":     "person_id_mismatch",
      "message":  "Předaný personId 'A1B2C' neodpovídá existujícímu 'X9Y8Z'. Stávající zůstane zachován.",
      "declared": "A1B2C",
      "stored":   "X9Y8Z"
    }
  ]
}
```

### `userAction` slovník

| Hodnota | Význam |
|---------|--------|
| `null` | Default — applier postupuje podle `mergeStrategy` (matched → update / leave; canCreate → create). |
| `"useExisting:<id>"` | Použít konkrétního kandidáta z `candidates` (pouze ambiguous). |
| `"create"` | Vytvořit nový záznam i když existuje match — vede k duplicitě, je to vědomá volba. |
| `"skip"` | Přeskočit sub-záznam (jen sub-kolekce; pro header tato volba neplatí). |

## 11. Apply pipeline

```
POST /api/v1/_exchange/persons/person/apply
  │
  ├─ 1. Schema validation (statická struktura)
  │
  ├─ 2. Resolve (znovu i když /preview proběhl — idempotentní)
  │      - PersonResolver: header
  │      - AddressResolver per addresses[]
  │      - BankAccountResolver per bankAccounts[]
  │      - ContactResolver per contacts[]
  │      - Při fullSync: enumeruje existující sub-záznamy → closingExisting
  │
  ├─ 3. Reconcile s klientským _resolve
  │      - validate userAction
  │      - sestaví execution plan: header create/update, sub create/update/close
  │
  ├─ 4. Validation gate
  │      - severity=error v _resolve.issues → 422
  │      - createOnly + matched header → 409 person_exists
  │      - applyOptions.rejectOnIssues respektován
  │
  ├─ 5. BEGIN TRANSACTION
  │
  ├─ 6. Header upsert
  │      - create: PersonDocument::saveDocument($payload)
  │      - update (updateHeader/mergeAdd/fullSync): TableGateway saveDocument
  │        s aktualizovaným $data (per mergeStrategy fill rules)
  │      → PersonDocument::beforeSave doplní complex_name, full_name, person_id
  │
  ├─ 7. Sub-kolekce
  │      pro každou (addresses, bankAccounts, contacts):
  │      - matched + mergeStrategy in {mergeAdd}:
  │           - authoritativeRefresh=true → overwrite adresních polí
  │           - jinak → leave alone
  │      - matched + mergeStrategy in {fullSync}:
  │           - update všech polí z payloadu (docState netknut)
  │      - canCreate → insert nového záznamu s person FK
  │           - applier nastaví docState=40, docStateMain=2
  │      - closing (jen fullSync): UPDATE valid_to=today pro nepřítomné
  │           (docState zůstává — viz §4a)
  │
  ├─ 8. Lineage update
  │      - base_persons_persons.source_kind        = source.kind
  │      - base_persons_persons.source_ref         = source.registryRef
  │      - base_persons_persons.source_imported_at = now()
  │
  ├─ 9. COMMIT
  │
  └─ 10. Vrátí enriched canonical
        - _resolve.summary.status = "applied"
        - header.status = "matched" (s personId)
        - sub-kolekce mají vyplněné matchedId
        - savedPersonId v top-level
```

### Apply a doc state

`applyOptions.targetDocState`:

- `10` (default) — záznam zůstává v Konceptu (i u updatu — `docState`
  se nemění).
- `40` — applier provede state transition Koncept → V pořádku
  (`PersonDocument::processStateTransition`). Pokud chybí povinná pole
  podle validace, apply selže.

## 12. REST API

Všechny pod `/api/v1/_exchange/persons/person/`. Stejný error shape
i HTTP codes jako document endpointy.

### POST `/validate`

Statická + dynamická validace bez resolve a bez DB writes.

### POST `/preview`

Validate + plný resolve (header + sub-kolekce + closingExisting pro
fullSync). Bez DB writes.

### POST `/apply`

Validate + resolve + reconcile + uložit (transakční).

**Chybové stavy navíc proti dokumentům:**

- `409 person_exists` — `mergeStrategy: createOnly` + header `matched`.
- `409 person_id_conflict` — `personId` v payloadu kolikuje s jinou osobou.

## 13. Lineage

Nové sloupce v `base_persons_persons`:

| Sloupec | Typ | Význam |
|---------|-----|--------|
| `source_kind` | `varchar(40) nullable` | Klíč z cfgItem `base.persons.sourceKinds` (`import.ares`, `import.shipardRegistry`, `manual`, …) |
| `source_ref` | `varchar(60) nullable` | Identifikátor v daném registru. Často redundantní s `company_id`, ale může nést další info (např. ARES snapshot ID, timestamp verze). |
| `source_imported_at` | `datetime nullable` | Čas posledního importu/sync. |

Reverse lookup z osoby → původ. Manuálně pořízené osoby mají `source_kind = NULL`
(nebo `'manual'` podle preference; default `NULL`).

### cfgItem `base.persons.sourceKinds`

```jsonc
{
  "manual":              { "name": "Ruční zadání",         "stateStyle": "manual" },
  "aiExtraction":        { "name": "Z AI extrakce",        "stateStyle": "ai" },
  "import.ares":         { "name": "ARES",                 "stateStyle": "registry" },
  "import.rpo":          { "name": "RPO (SK)",             "stateStyle": "registry" },
  "import.handelsregister": { "name": "Handelsregister",   "stateStyle": "registry" },
  "import.shipardRegistry": { "name": "Shipard registr",   "stateStyle": "registry" },
  "import.csv":          { "name": "CSV import",           "stateStyle": "bulk" }
}
```

## 14. Verzování

Klíč `formatVersion` v top-level (aktuálně `"1.1"`). Strategie shodná s
`shpd.docs.document.v1` (sekce 13 [exchange-format.md](exchange-format.md#13-verzování)):

- `1.1` — volitelný blok `documents` (#94). Payload `1.0` zůstává platný.

- Drobná rozšíření (nová optional pole, nové enum value) — zachová major.
- Breaking changes — bump na novou major; per-verze applier.

## 15. Budoucí rozšíření (Fáze 3+)

- **Export** (DB row → canonical) — `PersonExporter::export($personId)`.
  Potřeba pro registry sync (porovnání lokálního stavu s registrem) a pro
  export mezi Shipard DS.
- **Batch apply** — víc osob v jednom requestu, využití pro CSV import.
- **Diff API** — `/diff?personId=X` proti canonical payloadu, vrátí seznam
  rozdílů bez apply. Slouží registry sync UI.
- **Subscription model** pro registry sync — pravidelný cron, který pro
  každou osobu s `source_kind = 'import.ares'` zavolá adaptér a aplikuje
  `fullSync`.

## 16. Reference

- [exchange-format.md](exchange-format.md) — sourozenec spec pro doklady;
  obsahuje obecnou architekturu a pojmosloví.
- [modules/base/persons/](../modules/base/persons/) — modul Osoby; tabulkové
  definice + PersonDocument.
- [modules/core/exchange/](../modules/core/exchange/) — implementace
  výměnných formátů.
- [modules/world/divisions/](../modules/world/divisions/) — administrativní
  členění; cíl FK `addresses.division`.
