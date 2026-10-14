# ds-setup — Task 12: Nabídka účetních položek pokrývá všechny obsahové štítky

**Stav:** hotovo — implementováno 2026-10-14 (#103 D31, D32); zbývá nasazení na alfu (`ds-upgrade` doplní účty, nové položky nabízí sekce „Volitelné“)

## Kontext / Cíl

Použít návrh přijatého dokladu mapuje řádek na účetní položku podle
obsahového štítku (`economy_items.content_tags`). Když žádná položka štítek
nemá, `autoCreateMode` založí novou položku pojmenovanou podle řádku —
bez účtu a bez štítku, takže řádky dokladu nemají účet. Našla to F2 testování
(#103): faktura se štítkem `goods.stock` založila tři položky podle řádků.

Příčina je v nabídkách účetních položek (`ds-setup-10`, `ds-setup-11`),
ze kterých průvodce nastavením i `dataset-seed` (`provision.accountingItems`)
položky generují:

- **`accountingItemsDefault.jsonc`** nepokrývá `goods.stock` a `people.benefits`;
- **`accountingItemsNpo.jsonc`** nepokrývá 18 ze 41 štítků — mimo jiné nájem,
  telefon, internet, kancelářské potřeby, PHM, účetní služby, pojištění.

**Cíl:** každý obsahový štítek kromě `admin.other` má v každé nabídce
právě jednu účetní položku a účet každé položky existuje v osnově své
varianty. Nezisková nabídka dostane **stejné nákladové analytiky jako
podnikatelská** — praxe u zapsaných spolků je účtovat tyto náklady
analyticky stejně jako u s.r.o. (D32; nahrazuje `ds-setup-11` D9
„NPO drží jednu analytiku“).

## Návaznost

- `tasks/ds-setup-10-accounting-items-offer.md`, `tasks/ds-setup-11-accounting-items-extended.md`
  — nabídky a jejich formát
- `tasks/content-tag-enrichment.md` — mapování řádků na položky podle štítku
- #103 F3 (`tasks/testing-phase3.md`) na tomto tasku stojí: ověřuje, že
  Použít nezaloží žádnou položku
- `docs/ds-setup.md` D18 — nabídky jsou jednorázové akce, ne provisionery

## Před implementací přečti

- `modules/economy/items/config/accountingItemsDefault.jsonc`, `accountingItemsNpo.jsonc`
- `modules/economy/accounting/config/accountChartDefault.jsonc`, `accountChartNpo.jsonc`
- `modules/economy/accounting/src/AccountChartProvisioner.php` (create-only, existující účty nemění)
- `modules/economy/items/src/AccountingItemsOffer.php`
- `modules/core/exchange/config/contentTags.jsonc`

## Změny

### Osnova `default` (`accountChartDefault.jsonc`)

- přidat **528100** Ostatní sociální náklady (vlastnosti jako sourozenci pod 528 / 527100)
- opravit překlep u **503100**: „Spořeba PHM“ → „Spotřeba PHM“ (`name` i `short_name`)

### Nabídka `default` (`accountingItemsDefault.jsonc`)

| Kód | Účet | Název | Změna |
|-----|------|-------|-------|
| 501100 | 501100 | Spotřeba materiálu | + štítek `goods.stock` |
| 528100 | 528100 | Ostatní sociální náklady | nová položka, skupina `payroll`, štítek `people.benefits` |

### Osnova `npo` (`accountChartNpo.jsonc`)

Přidat analytiky (názvy jako v `default`; vlastnosti jako sourozenci pod
stejnou syntetikou):

| Účet | Název |
|------|-------|
| 501201 | Evidovaný hmotný majetek |
| 501202 | Evidovaný nehmotný majetek |
| 502200 | Spotřeba energie (vodné a stočné) |
| 502300 | Spotřeba energie (plyn) |
| 511201 | Opravy a údržba nemovitého majetku |
| 511202 | Servis vozidel |
| 518201 | Telefonní poplatky |
| 518202 | Internetové služby |
| 518203 | Poštovné |
| 518204 | Reklama |
| 518205 | Nájemné |
| 518206 | Software a cloudové služby |
| 518211 | Účetní a daňové služby |
| 518212 | Právní a notářské služby |
| 549201 | Pojištění majetku |
| 549202 | Pojištění vozidel |

Pojištění jde v NNO pod **549** (Jiné ostatní náklady) — syntetika 548 je
v neziskové osnově Manka a škody, ne ostatní provozní náklady.

### Nabídka `npo` (`accountingItemsNpo.jsonc`)

Zrcadlí nákladové položky `default` na účtech neziskové osnovy:

| Kód | Účet | Název | Štítky |
|-----|------|-------|--------|
| 501100 | 501100 | Spotřeba materiálu | stávající + `goods.stock` |
| 501100S | 501100 | Kancelářské potřeby | `office.supplies` (nová) |
| 501201 | 501201 | Evidovaný hmotný majetek | `office.equipment`, `it.hardware` (nová) |
| 501202 | 501202 | Evidovaný nehmotný majetek | — (nová) |
| 502100 | 502100 | Spotřeba energie (elektřina) | jen `premises.electricity` (přejmenovat, ubrat plyn a vodu) |
| 502200 | 502200 | Spotřeba energie (vodné a stočné) | `premises.water` (nová) |
| 502300 | 502300 | Spotřeba energie (plyn) | `premises.gas` (nová) |
| 503100P | 503100 | Spotřeba PHM | `vehicle.fuel` (nová; stávající 503100 zůstává bez štítku) |
| 511100 | 511100 | Opravy a udržování | — (ubrat štítky) |
| 511201 | 511201 | Opravy a údržba nemovitého majetku | `premises.maintenance` (nová) |
| 511202 | 511202 | Servis vozidel | `vehicle.service` (nová) |
| 518201–518206, 518211, 518212 | stejné | jako `default` | jako `default` (nové) |
| 528100 | 528100 | Ostatní sociální náklady | `people.benefits` (nová) |
| 549201 | 549201 | Pojištění majetku | `admin.insurance` (nová) |
| 549202 | 549202 | Pojištění vozidel | `vehicle.insurance` (nová) |

Skupiny podle `default`. Pořadí položek ve skupině jako v `default`.

## Strážce (PHPUnit)

Rozšířit test nabídek (najdi existující test `AccountingItemsOffer`, jinak
nový `tests/Unit/Module/Economy/Items/AccountingItemsOfferCoverageTest.php`):

- každý štítek taxonomie kromě `admin.other` má v každé variantě nabídky
  **právě jednu** položku (štítek u dvou položek téže varianty = chyba);
- žádná položka nemá štítek, který v taxonomii není;
- účet každé položky existuje v osnově své varianty;
- výjimka `admin.other` je v testu jako pojmenovaná konstanta s důvodem
  („nezařazené — rozhoduje uživatel“).

## Existující zdroje dat

- `AccountChartProvisioner` je create-only: nové účty dostanou existující
  zdroje dat se svou osnovou při `ds-upgrade`, oprava názvu 503100 jen nové
  zdroje dat. To je v pořádku — název účtu si uživatel může upravit.
- Nabídka je jednorázová akce (D18): existující zdroje dat nové položky
  nedostanou samy, nabízí je sekce „Volitelné“ v Nastavení. Položky, které
  už vznikly ze staré nabídky, si štítky ponechají — u spolku tak může
  vzniknout stav, kdy staré 502100 nese vodu a plyn a nově vygenerovaná
  502200 vodu znovu. Do dokumentace: po vygenerování nových položek
  ve spolku zkontrolovat štítky u 502100 a 511100.

## Kroky

1. Osnovy (`default` 528100 a překlep, `npo` analytiky).
2. Nabídky.
3. Strážce.
4. Ověření: nový dev zdroj dat s osnovou `npo` a druhý s `default`,
   `dataset-seed tests/datasets/cz/association` a `…/vat-payer` —
   položky se vygenerují bez přeskočených kódů (účet chybí); ruční Použít
   jedné faktury se štítkem `goods.stock` a jedné s `office.supplies` ve
   spolku nezaloží žádnou položku a řádky mají účet.
5. Dokumentace a hlavička tasku.

## Hotovo když

- [x] strážce zelený (`--filter AccountingItemsOffer`), stávající testy nabídek a osnov zelené
- [x] `dataset-seed` obou sad bez přeskočených položek
- [x] Použít faktury `goods.stock` a `office.supplies` ve spolku nezaloží položku
- [x] `docs/ds-setup.md` (nabídky): pokrytí štítků jako pravidlo, výjimka `admin.other`,
      NNO se stejnými nákladovými analytikami, poznámka k existujícím zdrojům dat
- [x] `tasks/ds-setup-11-accounting-items-extended.md`: u D9 poznámka „nahrazeno ds-setup-12 D32“
- [x] hlavička tasku, `python3 scripts/tasks-index.py`

## Odchylky při implementaci (2026-10-14)

- **Karta `goods.stock` na Dashboardu** (`ContentTagSuggestionsSource`,
  content-tag-ui D7/D25) obcházela nabídku a nabízela volbu „Jako materiál
  (501…)“ / „Jako zboží (504…)“. S položkou 501100 v nabídce by akce „Jako
  zboží“ založila položku s kódem a názvem *Spotřeba materiálu*, pěti
  štítky a účtem 504 — výjimka proto zanikla, karta má jedinou akci
  „Založit položku“ jako ostatní štítky; kdo účtuje zboží na 504, změní
  účet položky ve formuláři. Texty `contentTag.chooseStock` / `asMaterial`
  / `asGoods` z katalogu `core.exchange.feedTexts` odstraněny. Endpoint
  `materialize` dál bere `account` pro štítek bez položky v nabídce
  (`admin.other`).
- **Skupiny neziskové nabídky** zrcadlí podnikatelskou sadu 1:1 (8 skupin;
  `sanctions` zanikla — pokuty jdou do `insurance`, dary do `operations`
  jako 543900 v podnikatelské).
- Osnova `npo` nechává účet 502100 s názvem „Spotřeba energie“; jen
  položka nabídky se jmenuje „(elektřina)“.
- Testy cesty „štítek bez položky v nabídce“ (materializer, controller)
  přešly z `goods.stock` na `admin.other`.

## Ověření (2026-10-14, dev)

- `dataset-seed` po resetu: spolek (`wp66-o`, osnova `npo`) založil 50 položek,
  plátce (`ob9b-7`, osnova `default`) 58 — bez `skipped`, žádná bez účtu.
- Ve spolku přes API Použít zprávy se štítkem `goods.stock` (čtyři řádky
  dřeva + doprava) a `office.supplies` (papír, toner, dvě předplatná):
  počet položek zůstal 50, řádky mají položku i účet — 501100 Spotřeba
  materiálu, 501100S Kancelářské potřeby, 518100 Ostatní služby pro
  řádkové výjimky (doprava, předplatná).
- Strážce i celá sada PHPUnit zelené (9394 testů).

## Rozhodnutí k designu (potvrzená)

- ✓ **D31 — `goods.stock` se mapuje na účetní položku** (501100 Spotřeba
  materiálu), nezakládá skladové položky podle řádků. Obecně: každý štítek
  kromě `admin.other` má v každé nabídce právě jednu položku.
- ✓ **D32 — Nezisková nabídka má stejné nákladové analytiky jako
  podnikatelská**, na účtech neziskové osnovy (pojištění pod 549).
  Nahrazuje `ds-setup-11` D9.
