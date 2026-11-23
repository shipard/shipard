# Task: Dokumentace ukládání dokladů po vrstvách — doklad, ukládací API, interpretace

**Stav:** naplánováno — zadání 2026-11-23 (#166 etapa 5, část 3; D56); až po `tasks/doc-interpretation-switch.md`

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

- [ ] tři soubory podle D56, každé pravidlo na jednom místě
- [ ] importní mód popsaný úplným seznamem na jednom místě
- [ ] obrázek cílového modelu v dokumentaci
- [ ] úklid podle U5, rozcestník podle U6
- [ ] odkazy na oddíly platné; stav tasku
