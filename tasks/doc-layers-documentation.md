# Task: Dokumentace ukládání dokladů po vrstvách — doklad, ukládací API, interpretace

**Stav:** hotovo — 2026-11-23 (#166 etapa 5, část 3; D56); zbývá `ds-upgrade` zdrojů dat kvůli kopii schématu v profilu pošty

**Issue:** #166 (etapa 5)

**Cíl:** Jedno místo pravdy pro každou vrstvu. Kdo píše skript nebo
import, přečte formát a ukládací API a nepotřebuje nic vědět o tom, jak
se čte papírový doklad; kdo ladí analýzu pošty, najde pravidla
interpretace pohromadě.

## Před implementací přečti

- issue #166 — tělo („Otevřené body“ k dokumentaci) a komentáře etap 3–5
- `docs/ai-workflow.md` § 5 (kam co patří), `tasks/README.md` (konvence
  veřejných textů)
- `docs/exchange-format.md` (celé), `docs/document-system.md`,
  `docs/rest-api.md` § 17, `docs/datasets.md`, `docs/vat-calculation.md`
- `modules/core/exchange/README.md`, `modules/docs/core/README.md`,
  `CLAUDE.md`
- schéma `modules/core/exchange/schemas/shpd.docs.document.v1.jsonc`
  (+ `.json`, + kopie v profilu pošty)

## Rozhodnutí k designu (potvrzené, #166)

- ✓ **D56 — Dokumentace po vrstvách:**

  | soubor | vrstva | obsah |
  |---|---|---|
  | `docs/document-system.md` | doklad | pole, výchozí hodnoty, validace, výpočet, číslování |
  | `docs/exchange-format.md` | formát a ukládací API (pokyn) | pole formátu, reference, chybějící × `null`, importní mód, idempotence, REST, návod „Doklad skriptem“, kódy nálezů pokynu |
  | `docs/document-interpretation.md` (nový) | interpretace (návrh) | hledání podle údajů, pravidla DPH přijatých dokladů (dnešní § 8.4), volby uživatele `_resolve`, zakládání, učení, signály D39, heuristiky, kódy nálezů návrhu, `/interpret` |

## Upřesnění pro implementaci

- **U1 — Přesun, ne přepis obsahu.** Texty se stěhují na místo své
  vrstvy a zkracují o opakování; pravidla se nemění. Každé pravidlo je po
  tasku na jednom místě, ostatní na něj odkazují.
- **U2 — `docs/exchange-format.md`** po tasku popisuje jen to, co platí
  pro pokyn: § 8 (hledání podle údajů) a § 8.4 odcházejí do interpretace,
  § 9 `_resolve` se dělí (odpověď pokynu: nálezy, `computed`, `defaults`
  × volby a bloky návrhu). Nově na jednom místě:
  - **importní mód** — úplný seznam, co mění a co ne (D5, D22, D48, D49);
  - **výchozí hodnoty** — jen odkaz na `docs/document-system.md` a seznam
    polí formátu, kterých se týkají;
  - tabulka kódů nálezů rozdělená na pokyn a návrh (návrh v novém
    souboru).
- **U3 — `docs/document-interpretation.md`.** Vstup (papírová podoba,
  volby), výstup (hotový doklad, zpráva, `pending`), kroky, pravidla,
  kdo ji volá (pošta, ISDOC, REST), co se učí. Dočasné signály D39 jsou
  popsané tady.
- **U4 — Obrázek cílového modelu** (skript / import → pokyn; pošta /
  ISDOC → interpretace → pokyn; formulář → doklad) do
  `docs/exchange-format.md` § 3 jako SVG v `docs/images/`; stejný obrázek
  odkázaný z nového souboru.
- **U5 — Úklid.**
  - schéma: `applyOptions.rejectOnIssues` a
    `applyOptions.createMissingEntities` pryč (applier dokladů je nečte) —
    tři kopie schématu, strážní testy, ověř, že je neposílá žádná sada,
    fixtura ani generátor;
  - `docs/rest-api.md` § 17: příklad vytvoření faktury přes ukládací API
    dokladů místo generického CRUD;
  - odkazy na přečíslované oddíly v `docs/`, `tasks/` (jen živé tasky),
    README modulů a komentářích kódu (`§ 8.4` apod.) — oprav.
- **U6 — Rozcestník.** `CLAUDE.md` a `modules/core/exchange/README.md`:
  tři řádky „kam se podívat“ podle vrstvy.

## Co je potřeba udělat

1. Nový `docs/document-interpretation.md`, přesun z
   `docs/exchange-format.md` (U1–U3).
2. Importní mód a výchozí hodnoty na jednom místě (U2).
3. Obrázek (U4), úklid (U5), rozcestník (U6).
4. Stav tasku, `python3 scripts/tasks-index.py`,
   `python3 scripts/check-sensitive.py --all`.

## Ověření

- Každý odkaz `§` v `docs/` a v komentářích kódu, který task změnil, vede
  na existující oddíl (vypiš způsob kontroly do poznámek).
- Strážní testy schématu; unit sada.
- Veřejné texty bez názvů reálných zdrojů dat, firem a osob.

## Mimo rozsah

- Změny chování, formátu a kódu kromě U5.
- README importního modulu a popis opakovaného běhu importu (etapa 6).
- Uživatelská dokumentace.

## Hotovo když

- [x] tři soubory podle D56, každé pravidlo na jednom místě
- [x] importní mód popsaný úplným seznamem na jednom místě
- [x] obrázek cílového modelu v dokumentaci
- [x] úklid podle U5, rozcestník podle U6
- [x] odkazy na oddíly platné; stav tasku

## Poznámky k implementaci

### Co kde je

- **`docs/document-interpretation.md`** (nový, 12 oddílů): místo v modelu
  a volající, vstup, hotový doklad, kroky `DocumentProposal`, hledání podle
  údajů (§ 5), DPH přijatých dokladů (§ 6 — dřívější oddíl 8.4 výměnného
  formátu doslova), `_resolve` návrhu a slovník `userAction` (§ 7),
  zakládání a učení, pohyb řádku, kontroly předlohy, kódy nálezů návrhu,
  REST `interpret: true` a `/interpret`. Přesouvané texty šly skriptem
  podle čísel řádků původního souboru, ne opisem; autorský text je úvod
  a spojovací věty.
- **`docs/exchange-format.md`** (3 653 → 2 609 řádků) popisuje jen pokyn.
  **Čísla hlavních oddílů zůstala** (§ 3, 5, 9, 10, 11, 12 — odkazuje na ně
  `CLAUDE.md` a desítky dokumentů). § 8 je „Reference a hledání záznamů“
  **bez číslovaných pododdílů**: staré číslo 8.4 tak nedostalo jiný obsah
  a zapomenutý odkaz na něj kontrola chytí. § 9 je odpověď pokynu
  (příklad `_resolve` z náhledu skutečného pokynu) a kódy nálezů pokynu.
- **Kódy nálezů**: 27 kódů, které vznikají jen v návrhu, je v novém
  dokumentu § 11 (rozdělení ověřené podle místa vzniku v kódu); zbylých
  48 řádků tabulky zůstalo v `exchange-format.md` § 9. Kód společný oběma cestám má řádek v
  `exchange-format.md` § 9; kde se v návrhu chová jinak
  (`vat_code_unknown`, `unit_not_found`, `reference_missing`,
  `partner_not_supported`), říká to druhá tabulka § 11.
- **Importní mód**: vlastní oddíl `exchange-format.md` § 10 se dvěma
  seznamy — co mění (tabulka) a co nemění. Ostatní místa na něj odkazují.
- **Výchozí hodnoty**: pravidla jen v `docs/document-system.md` § 12c
  (doplněn směr pokladního dokladu a textový řádek, které měl jen výměnný
  formát); `exchange-format.md` § 3 nese seznam polí formátu, význam
  chybějícího klíče × `null` a `_resolve.defaults`.
- **Obrázek**: `docs/images/document-saving-model.svg`, ručně psané SVG
  s vlastním pozadím (čitelné ve světlém i tmavém GitHubu).

### Odchylky od plánu

- Kontrakt `POST /interpret` je celý v novém dokumentu (§ 12), jak říká
  tabulka D56; `exchange-format.md` § 11 má jen odkaz.
- Srovnávací tabulka „návrh vedle pokynu“ odešla do nového dokumentu
  (§ 4) celá, včetně sloupce Pokyn — je to přehled, pravidla pokynu má
  `exchange-format.md`.

### Úklid schématu (U5)

- `rejectOnIssues` a `createMissingEntities` jsou pryč ze tří kopií
  schématu dokladu. V tomto repozitáři je neposílá žádná sada, fixtura ani
  generátor; formáty osob a položek `rejectOnIssues` čtou a mají ho dál.
- Uložené návrhy z pošty klíče nenesou (dev server: 0 z 406 analýz ve 21
  zdrojích dat) a použití návrhu i náhled si `applyOptions` skládají znovu.
- **Pro etapu 6:** runner importu ve starém Shipardu oba klíče posílá
  (dvě místa v `DocsRunner.php`). `applyOptions` má
  `additionalProperties: false`, takže payload s nimi skončí chybou
  schématu — runner je musí přestat posílat spolu s přechodem na reference.
- Verze promptu se nemění (jako u předchozích změn schématu v této etapě);
  profil pošty je uložený ve zdroji dat a nové schéma model uvidí až po
  `ds-upgrade`.
- `docs/rest-api.md` § 17: krok 3 je minimální pokyn přes
  `/_exchange/docs/document/apply`, krok 4 načtení dokladu (změna stavu
  generickým CRUD nejde přes životní cyklus dokladu, #113), kroky 2 a 5
  mají existující tabulku `docs_core_heads`. Ilustrační `economy_docs_heads`
  v obecných kapitolách téhož dokumentu (stránkování, řazení, filtry) zůstává
  — je mimo rozsah.

### Kontrola odkazů

Jednorázový skript (není v repozitáři): z nadpisů `exchange-format.md`,
`document-interpretation.md` a `document-system.md` sestaví čísla a názvy
oddílů; v souborech `.md`, `.php`, `.js`, `.svelte`, `.jsonc` celého
repozitáře mimo hotové a zrušené tasky najde každý odkaz
`<dokument>.md § N[.M] [„název“]` (i řetěz „§ 3, § 10“) a holé `§ N`
uvnitř těchto tří dokumentů a ověří, že číslo existuje a uvedený název je
v nadpisu oddílu. Výsledek: 264 odkazů, všechny platné. K tomu přímý grep
na staré pododdíly (`§ 8.1`–`§ 8.6`) a zaniklé názvy oddílů — mimo hotové
tasky žádný výskyt. Hotové tasky se neopravují; `exchange-format.md` § 8
proto říká, kam dřívější oddíly 8.1–8.5 odešly.

### Ověření

- Strážní testy schématu (`SchemaDriftTest`, `ProfileSchemaDriftTest`,
  `SchemaValidatorTest` s novým testem odmítnutí obou klíčů) a testy
  profilu pošty: 139 testů bez selhání; celá unit sada bez selhání.
- Příklad odpovědi pokynu (§ 9) a dotazy z `rest-api.md` § 17 ověřené
  proti ukázkovému zdroji dat (`/preview` — nic nezapisuje).
- `check-sensitive.py --all`: žádný nález ve změněných souborech.
