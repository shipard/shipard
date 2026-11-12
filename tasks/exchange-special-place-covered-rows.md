# Task: Zvláštní místo plnění u řádku pokrytého položkou — štítek z položky

**Stav:** hotovo — implementováno a ověřeno 2026-11-11 (D19–D21; cílené testy včetně stropu proti skutečnému applieru, `dataset-seed` + `dataset-verify` sady plátce bez nálezů na `jfpl-7`); náhled v prohlížeči neproklikán — korpus doklad od opakovaného zahraničního dodavatele se zvláštním místem plnění nemá

**Issue:** #89 (mezera v D3 zjištěná při ověření `mail-card-band-special-place.md`)

**Cíl:** Doklad od dodavatele mimo tuzemsko se zvláštním místem plnění
a bez vyčíslené daně vzniká z návrhu *Bez DPH* (#89 D3) — ale jen když má
návrh štítek dokladu. Ten se určuje jen u návrhu s nepokrytým řádkem.
U opakovaného dodavatele jsou řádky pokryté položkami z historie, štítek
chybí a doklad se beze slova odvodí jako běžná služba se samovyměřením.
Nově applier u pokrytého řádku čte štítky jeho položky: jednoznačné
zvláštní místo plnění se chová jako štítek řádku, nejednoznačná položka
dostane varování a karta jde do kontroly.

## Před implementací přečti

- `tasks/exchange-received-foreign-vat.md` — D3, tabulka „Kdy je doklad
  mimo české DPH“, upřesnění
- `tasks/mail-card-band-special-place.md` — D17, `SpecialPlaceSupply`,
  test oběma směry
- `tasks/exchange-received-supply-kind.md` — D2 (fallback druhu plnění ze
  štítku), D4 (veto `special`)
- `tasks/content-tag-enrichment.md` — štítky, položky se štítky, D14
- `docs/exchange-format.md` § 8.4 — *Druh plnění ze štítku řádku*,
  *Přijatý doklad mimo české DPH*; `docs/dashboard.md` — strop pásma

## Kontext — co je dnes

- **Štítek řádku existuje jen s blokem `_resolve.contentTag`.**
  `RowEnrichmentPipeline::run()` štítek dokladu určuje jen při nepokrytém
  položkovém řádku (`hasUncoveredItemRow()`); `rowContentTagOf()` bez bloku
  vrací `null`. Applier (`effectiveSupplyKinds()`, `outOfScopeKind()`)
  i strop pásma (`AnalysisConfidenceResolver::capsSpecialPlace()`) čtou
  štítek jen odtud, přes `SpecialPlaceSupply`.
- **Pokrytý řádek nese položku.** `RowHistoryEnricher` doplní
  `rows[].item.ourCode` (a kód DPH z historie do `rows[].vat.code`, pokud
  ho historie má). Položka (`economy_items.content_tags`, JSON list klíčů
  taxonomie) štítky nese — položka založená pro štítek právě jeden,
  nabídkové a ručně upravené i víc.
- **Položky se štítky nejsou jednoznačné.** Na sadě plátce: *Cestovné*
  (ubytování + jízdné — obojí `special`), *Dálniční poplatky, mýto
  a správní poplatky* (mýto `special`, správní poplatky bez určení),
  *Ostatní služby* (parkování a ostraha `special`, přeprava `services`,
  další bez určení).
- **Ponechaný kód z historie** (řádek s `matchedBy: "input"`) režim mimo
  DPH ruší — beze změny.

## Rozhodnutí k designu (potvrzená)

Číslování navazuje na D1–D18 (#89).

- ✓ **D19 — štítek z položky, tři stavy.** Když řádek nemá štítek z bloku
  `_resolve.contentTag` (výjimka řádku ani štítek dokladu) a nese
  `item.ourCode`, určí se z `content_tags` jeho položky — počítají se jen
  štítky, které mají v taxonomii `crossBorderSupply`:
  - **`special`** — aspoň jeden takový štítek a všechny jsou `special`;
  - **nejednoznačný** — mezi nimi je `special` i jiná hodnota;
  - **nic** — žádný `special` (nebo položka bez štítků / neexistuje).

  Štítek z bloku má vždy přednost. Druh plnění (`goods` / `services`) se
  z položky neodvozuje.
- ✓ **D20 — `special` z položky = štítek řádku.** Platí pro něj totéž co
  pro `special` z bloku: veto v derivaci a podmínka „každý položkový řádek
  je `special`“ pro doklad mimo české DPH (D3, warning `vat_special_place`)
  i pro strop pásma (D17).
- ✓ **D21 — nejednoznačná položka.** Chování applieru se nemění (řádek se
  odvodí jako dnes), ale u přijatého dokladu plátce s efektivním místem
  mimo tuzemsko, který nenese daň dodavatele, dostane řádek s **odvozeným**
  kódem samovyměření warning `vat_special_place_ambiguous`. Řádek
  s ponechaným kódem (z canonicalu / historie) ani s volbou uživatele
  varování nedostane. Karta návrhu má strop pásma *review*, když takový
  řádek v canonicalu nemá kód DPH (`rows[].vat.code` prázdné).

## Chování

Přijatý doklad plátce, efektivní místo ≠ tuzemsko, doklad nenese daň
dodavatele, bez voleb uživatele:

| Řádky | Výsledek |
|---|---|
| všechny `special` (blok nebo položka, i kombinace) | Bez DPH, `vat_special_place` (D3) |
| část `special`, část ne | jako dnes — `special` řádek bez kódu (`vat_code_unknown`) |
| nejednoznačná položka, kód odvozený | samovyměření jako dnes + `vat_special_place_ambiguous` na řádku |
| nejednoznačná položka, kód ponechaný z historie | samovyměření, bez varování |
| položka bez `special` štítku / bez štítků | beze změny |

Text varování D21: „Položka {kód} nese štítky s různým místem plnění —
nedá se poznat, jestli jde o službu se zvláštním místem plnění
(ubytování, doprava osob, parkování, mýto …). Návrh počítá se
samovyměřením; pokud se daň v ČR nepřiznává, přepni režim DPH na
Bez DPH.“ Cesta `rows.N.vat.code`.

## Co je potřeba udělat

Jeden commit — `feat(exchange): zvláštní místo plnění u pokrytého řádku ze štítků položky (#89 D19–D21)`:

1. **Štítky položek podle kódu** — jedno místo v `core.exchange`, které pro
   sadu kódů položek vrátí jejich `content_tags` (jen živé položky; stejný
   předpoklad jako `ContentTagResolver::liveItemsForTag()`). Jeden dotaz
   na doklad v applieru, jeden na sběr karet ve feedu — ne dotaz na řádek.
2. **`SpecialPlaceSupply`** — klasifikace řádku podle D19 (blok → položka)
   jako čistá funkce nad canonicalem, taxonomií a mapou kód → štítky;
   `allItemRowsSpecial()` ji používá. Druhá kopie pravidla nesmí vzniknout.
3. **`DocumentApplier`** — `effectiveSupplyKinds()` plní `tagSupply`
   i z položky (D20), takže veto i `outOfScopeKind()` fungují beze změny
   logiky; warning D21 tam, kde se rozhodují kódy řádků (jen odvozený kód
   samovyměření, viz tabulka). `_resolve` u řádku ať nese, odkud `special`
   je (`"tag"` / `"item"`) — zpráva `vat_special_place` ho zmíní.
4. **`AnalysisConfidenceResolver`** — `capsSpecialPlace()` s mapou štítků
   položek (D20) a strop D21. Mapu předává volající stejně jako taxonomii
   (feed jednou na sběr, detail zprávy na zprávu).
5. **Testy:** `SpecialPlaceSupplyTest` (tři stavy, štítky bez určení se
   nepočítají, přednost bloku), `DocumentApplierOutOfScopeTest` (tabulka
   výše), `SpecialPlaceCapTest` (strop není užší než applier — i pro
   `special` z položky), `AnalysisConfidenceResolverTest` (strop D21
   s kódem a bez kódu), `MailSuggestionsSourceTest` (jeden dotaz na sběr).
6. **Dokumentace:** `docs/exchange-format.md` (§ 8.4 a tabulka issues),
   `docs/dashboard.md` (strop pásma), `modules/economy/items/README.md`
   (štítky položky rozhodují i o místě plnění — sběrná položka
   s různorodými štítky dává nejednoznačný výsledek),
   `help/posta/kontrola-vytezeni.md`; `python3 scripts/help-index.py`.
7. `**Stav:**` tasku a `python3 scripts/tasks-index.py`.

## Upřesnění z implementace

- **Volba režimu potlačí varování D21 (potvrzeno 2026-11-11).** Zadání
  mluví jen o volbě na řádku. Kdo v náhledu zvolil režim s DPH, rozhodl
  se vědomě — rada „přepni na Bez DPH“ by byla šum; stejně se chová
  `vat_special_place`. Volba kódu na jednom řádku varování ostatních
  řádků neruší.
- **Řádek s volbou položky se z položky neklasifikuje (potvrzeno
  2026-11-11).** Když uživatel v náhledu zvolí jinou nebo novou položku
  (`_resolve.rows[i].item.userAction`), canonical dál nese původní
  `item.ourCode` — doklad by vznikl Bez DPH podle položky, která na něm
  není. Pravidlo je v `SpecialPlaceSupply`, uložený návrh volbu nenese,
  takže strop se nemění a applier je nanejvýš užší.
- **Štítky položek jen mimo tuzemsko.** `effectiveSupplyKinds()` se na
  mapu ptá, jen když je efektivní místo mimo tuzemsko — tuzemský doklad
  nedostane ani dotaz navíc. Mapa je memoizovaná podle sady kódů
  (`itemTagsFor()`), klíč cache `vatContext()` nese `item.ourCode` řádků
  i volby položek.
- **Štítky podle kódu:** `ItemContentTags::forCodes()` (`Enrich/`), klíč
  mapy malými písmeny — sloupec `code` se v databázi porovnává bez
  rozlišení velikosti. Na naseedované sadě plátce: 26 otagovaných
  položek, 8 `special`, 1 nejednoznačná (*Ostatní služby*), 17 nic.
- **Zdroj `special`:** `_resolve.rows[].vatCode.specialSource` (`"tag"` /
  `"item"`) u dokladu mimo české DPH i u veta smíšeného dokladu. Zpráva
  `vat_special_place` jmenuje položky, jen když `special` plyne z položky
  (text u štítku z bloku se nezměnil); u veta ji jmenuje
  `vat_code_unknown`.
- **Odvozený kód = samovyměření.** Mimo tuzemsko derivace jiný kód než
  samovyměření nevrací, proto varování D21 stačí `matchedBy: "derived"`
  a další kontrola číselníku není potřeba.
- **Strop:** `AnalysisConfidenceResolver::itemTagsFor()` sbírá kódy jen
  z návrhů přijatých dokladů a ptá se jednou; `capBand()` /
  `bandForAnalysis()` mají mapu povinným parametrem. Strop D21 je
  `capsAmbiguousItem()`. Test oběma směry (`SpecialPlaceCapTest`) čte
  štítky položek pro applier i strop z téže databáze.

## Mimo rozsah

- Štítek uložený na řádku dokladu a vrácený z historie (varianta B) —
  přesné i u sběrných položek, ale změna schématu.
- Druh plnění `goods` / `services` z položky u pokrytého řádku.
- Úprava nabídkových položek (rozdělení *Ostatních služeb*).

## Pasti

- **Strop D21 je užší než varování.** Když kontrola souladu kód z historie
  zahodí a applier odvodí nový, varování přijde, ale karta strop nemá
  (canonical kód nese). Je to vědomé — strop souladem neprochází; nenapravuj
  to kopií `conflict()` v resolveru. Test oběma směry platí pro
  `vat_special_place`, ne pro D21.
- **Štítky bez určení nejsou „ne“.** *Mýto + správní poplatky* je
  `special`, ne nejednoznačné — počítají se jen štítky
  s `crossBorderSupply`.
- **Neexistující nebo neaktivní položka** = bez štítků, ne chyba.
- **Výkon feedu.** Otagovaných položek jsou jednotky až desítky — mapu
  načti jednou, ne pro každou kartu.
- **Tuzemsko a doklad s daní dodavatele** se nesmí pohnout — ani varování,
  ani strop.

## Hotovo když

- [x] Pokrytý řádek čte štítky položky podle D19; `special` z položky dává
      doklad mimo české DPH i veto (D20); nejednoznačná položka varuje
      a karta jde do kontroly (D21).
- [x] Testy `SpecialPlaceSupply|DocumentApplierOutOfScope|SpecialPlaceCap|AnalysisConfidenceResolver|MailSuggestionsSource`
      a `DocumentApplierTest` zelené.
- [x] Dokumentace a help aktualizované; `help-index.py` a `tasks-index.py`
      prošly.
- [x] Ověření na dev zdroji: `dataset-seed` + `dataset-verify` sady plátce
      bez nálezů (regrese — doklady se zahraniční DPH beze změny).
