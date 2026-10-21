# Demo sada „IT servis“ — fáze 2c: majetek

**Stav:** hotovo — 2026-10-21, #131 D20, D38–D42

## Kontext / Cíl

Sada „IT servis“ zatím nemá žádný majetek. Fáze 2c přidá jednu kartu
dlouhodobého majetku — server pořízený přijatou fakturou od velkoobchodu,
zařazený, s plánem daňových i měsíčních účetních odpisů, **nic
nezaúčtované**. Formát sady se rozšíří o číselníky majetku v `setup/`,
sekci `assets/` a přenosnou dimenzi majetku na dokladech.

Po seedu je v aplikaci vidět: karta v agendě Majetek s plánem odpisů,
sekce Pořízení s odkazem na fakturu, tab Náklady a výnosy (hosting
serveru), štítek „Nezaúčtováno“ a dialog Odpisy za období, který nabídne
zaúčtování od měsíce zařazení. Žádné upozornění majetku nesvítí.

## Návaznost

- **#131** — D1–D37 (komentáře v issue), **D20** (majetek ve fázi 2),
  tento task **D38–D41**
- `tasks/demo-dataset-phase2b-work-orders.md` — vzor: `SetupReferences`,
  sekce s vlastní obálkou, seeder ve dvou krocích, přenosná dimenze
  (`DocumentExporter` `$sectionTables`), projekty s dimenzí na řádcích
  nákupu
- `docs/assets.md` §5.3–5.7; `tasks/assets-phase4.md` (vazba na doklady),
  `tasks/assets-phase6.md` (import)

## Před implementací přečti

- `docs/datasets.md` §1 (`setup/`, sekce `workOrders/` jako vzor), §3, §4
- `docs/assets.md` §5.3 (události, plán), §5.4 (zaúčtování), §5.5 (vazba
  na doklady), §5.6 (kontrola evidence × deník), §5.7 (import — proč ho
  sada nepoužije, D38)
- `docs/exchange-format.md` § Majetek
- `modules/economy/assets/src/` — `AssetDocument`, `AssetEventDocument`,
  `AssetNumberAllocator`, `AssetAcquisitionService`, `AssetJournalCheck`,
  `Checks/`, `Import/AssetImportApplier.php` (pořadí zápisu karty
  a událostí), `AccountingGroupsProvisioner.php`
- `modules/core/exchange/src/Dataset/SetupReferences.php`,
  `modules/economy/workOrders/src/Dataset/` (vzor sekce)
- `demo/datasets/stories/it-servis/`, `demo/datasets/README.md`,
  `tools/invoice-generator/invoicegen/story.py`, `expand.py`, `economy.py`

## Rozsah

**V rozsahu:**

- číselníky majetku v `setup/` (D39)
- sekce `assets/`: obálka, exportér, seeder karty a událostí (D38, D40)
- přenosná dimenze majetku v exportéru dokladů (D40)
- příběh: server, jeho nákup, zařazení, hosting s dimenzí karty (D41)
- nastavení: měsíční účetní odpisy, pole Majetek na dokladech
- generátor, souhrn ekonomiky, přegenerovaná sada, testy, dokumentace

**Mimo rozsah:**

- zaúčtování majetku v sadě a přenos zaúčtovacích dokladů (D38)
- drobný a nehmotný majetek, technické zhodnocení, vyřazení — plná
  historie patří do příběhu „Nemovitosti“ (D20)
- změny modulu majetku v PHP mimo datové sady — kdyby byly potřeba,
  zastav se, zapiš do Odchylek a zeptej se

## Návrh

### D38 — Evidence, ne zaúčtování

- Sada nese **kartu a potvrzené události** s původem, jaký mají
  v databázi. Demo má jen ruční zařazení (`origin: manual`).
- **Seeder nepoužívá importní mód.** `AssetImportApplier` zapisuje
  s `_import` a události dostanou původ `import` — kontrola evidence
  × deník je bere jako zaúčtované ve starém systému (D76), zařazení by
  se započítalo na účet majetku bez zápisu v deníku a report kontroly by
  ukázal nesoulad. Seeder proto zakládá kartu přes `AssetDocument`
  a události přes `AssetEventDocument` stejně jako uživatel (ve stejném
  pořadí jako applier importu: hlavička → události chronologicky).
  Kdyby byl kód applieru pro to znovupoužitelný bez importního módu,
  je to v pořádku; původ `import` ale vzniknout nesmí.
- **Dump nepřenáší zaúčtování:** doklady s řádky `asset.*` (dávka
  Odpisy za období) a systémové odpisy (`origin: system`) vynechá
  s varováním. Jsou odvozené z evidence — po seedu je vytvoří znovu
  Odpisy za období. `doc_head` události se nepřenáší vůbec.
- Události s původem `import` (zdroj dat po migraci) se přenášejí
  s původem `import` přes importní mód — pro demo se nepoužije, ale
  dump takového zdroje nesmí původ změnit. Pokud by to výrazně
  zvětšilo rozsah, stačí je dump vynechat s varováním (do Odchylek).

### D39 — Číselníky majetku v `setup/`

| Soubor | Tabulka | Klíč |
|--------|---------|------|
| `asset_accounting_groups.jsonc` | `economy_assets_accounting_groups` | `code` |
| `asset_type_groups.jsonc` | `economy_assets_type_groups` | `name` |
| `asset_types.jsonc` | `economy_assets_types` | `name` |

Cizí klíče přes `SetupReferences`: účty účetní skupiny číslem, typ →
skupina typů názvem, typ → výchozí účetní skupina kódem. Seed jde
abecedně: `accounts` → `asset_accounting_groups` → `asset_type_groups` →
`asset_types`. Účetní skupiny zakládá i provisioner — upsert podle kódu
je jen srovná.

Demo: skupina typů „Hmotný majetek“, typ „Výpočetní technika“ s výchozí
účetní skupinou `022`.

### D40 — Sekce `assets/`, obálka `shpd.dataset.asset.v1`

Struktura `shpd.assets.asset.v1`, liší se jen reference:

```jsonc
{
  "format": "shpd.dataset.asset.v1",
  "asset": {
    "assetNumber": "…",                 // beze změny, klíč párování
    "name": "Server …", "category": "tangible", "tracking": "single",
    "type": "Výpočetní technika",       // název typu
    "accountingGroup": "022",           // kód účetní skupiny
    "owner": null,                      // strana (identifikátory) jako u zakázky
    "taxMethod": "straight", "taxRule": "cz-1", "accMethod": "as_tax",
    "state": "confirmed"
  },
  "events": [
    { "kind": "activation", "scope": "both", "date": "…", "amount": 0, "origin": "manual" }
  ]
}
```

- **Pořadí seedu:** setup → osoby → položky → zakázky → **karty
  majetku** (hlavička, V pořádku) → doklady (`dimensions.asset` =
  inventární číslo) → evidence období zakázek → **události majetku** →
  banka → spisovna → pošta. Události jsou druhý krok jako evidence
  období zakázek (samostatný seeder bez souborů a `counts`).
- `DocumentExporter` bere dimenzi `asset` za přenosnou, když je na
  zdroji dat sekce majetku (`$sectionTables` jako u zakázek).
- Inventární číslo se převezme beze změny; `AssetNumberAllocator`
  ho nepřepíše (ověřit).
- Třídy v `modules/economy/assets/src/Dataset/`, schéma obálky u
  ostatních v `modules/core/exchange/schemas/`. Slug souboru =
  inventární číslo, pořadí podle něj. `manifest.counts.assets`.

### D41 — Server v příběhu

- **Nákup:** samostatná faktura velkoobchodu kolem měsíce −8 (přesný
  měsíc a den navrhne implementace). Řádek serveru má pohyb
  `purchase.asset` a kartu **na řádku**; případné další řádky faktury
  (montážní sada, kabely) jsou běžný nákup. Cena serveru kolem
  150 tisíc bez DPH. Faktura je jako ostatní přijaté: zpracovaná zpráva
  s PDF, úhrada ve výpisu podle chování dodavatele.
- **Zařazení:** pár dní po dodání, částka = základ řádku pořízení
  (sekce Pořízení na kartě ji předvyplní stejně). Karta V pořádku,
  1. odpisová skupina (`cz-1`), rovnoměrně, účetně jako daňově.
- **Nastavení v `setup/`:** `economy.assets.accPeriodicity: month`
  (měsíční účetní odpisy), `economy.accounting.dimension.asset` zapnuté
  (pole Majetek na dokladech).
- **Hosting:** měsíční faktura serverovny nese od měsíce zařazení
  dimenzi karty na řádku hostingu → tab Náklady a výnosy na kartě.
  Tvar v příběhu navrhne implementace (vzor `project` u řádků nákupu
  z 2b).
- **Ekonomika:** nic se nezaúčtuje, Výsledovka beze změny (cíle D25
  dál). Souhrn z `build.py` vypíše kartu: vstupní cenu, datum zařazení
  a plán účetních odpisů v okně příběhu (kolik by Odpisy za období
  zaúčtovaly) a výsledek po jejich zaúčtování v %.
- **Banka:** BU1 nesmí pod nulu, počáteční zůstatek nejvýš 300 tisíc
  (D25). Nevyjde-li to, levnější server nebo jiný měsíc nákupu — ne
  vyšší počáteční zůstatek.

## Kroky (commity)

1. **Setup (D39)** — tři číselníky majetku, reference přes
   `SetupReferences`; PHPUnit: dump a seed FK (skupina typů, výchozí
   účetní skupina, účty skupiny).
2. **Sekce `assets/` (D38, D40)** — schéma obálky, exportér (vynechání
   zaúčtování a systémových odpisů s varováním), seeder karty
   a seeder událostí, pořadí v seed a dump příkazu, přenosná dimenze;
   PHPUnit: round-trip karty se zařazením (původ `manual`, žádný
   `import`), dimenze na řádku pořízení, varování u zaúčtovaného zdroje.
3. **Příběh a generátor (D41)** — nákup, karta, zařazení, hosting
   s dimenzí, nastavení, souhrn ekonomiky; unit testy generátoru.
4. **Sada a dokumentace** — přegenerovaná sada, ověření,
   `docs/datasets.md` (setup, sekce `assets/`, co dump nepřenáší),
   `docs/assets.md` (odkaz na sadu), `demo/datasets/README.md`,
   `**Stav:**`, `python3 scripts/tasks-index.py`.

## Ověření

Čerstvý volný zdroj dat, `dataset-seed`, `dataset-dump`, `diff -r` jako
v 2b (jen `manifest.created`), a dál:

- seed bez nových varování; účetní skupina `022` existuje a je úplná
  (provisioner skupinu bez účtů v osnově přeskočí);
- karta V pořádku, zařazení potvrzené s původem `manual`, bez dokladu;
- sekce Pořízení na kartě: faktura velkoobchodu, součet = částka
  zařazení;
- upozornění majetku (`JournalMismatchCheck`, `AcquisitionMismatchCheck`,
  `AwaitingActivationCheck`, `PurchaseWithoutAssetCheck`) — 0 nálezů;
- report Kontrola evidence × deník za rok kotvy: žádný nesoulad účtů ani
  karet, zařazení jen jako nezaúčtovaná událost;
- dialog Odpisy za období (náhled, bez zaúčtování) nabídne zařazení
  a měsíční odpisy od měsíce zařazení; částky = plán ze souhrnu;
- tab Náklady a výnosy ukáže hosting od měsíce zařazení;
- Výsledovka, saldokonto a banka beze změny proti 2b kromě nákupu
  serveru (čísla do Odchylek);
- **zaúčtování na druhém zdroji dat:** na samostatném jednorázovém
  zdroji dat zaúčtovat první období (API `/_assets/posting` nebo
  integrační test), ověřit, že kontrola evidence × deník zůstane bez
  nesouladu, a že `dataset-dump` doklad `asset.*` vynechá s varováním.
  Ten zdroj dat pak smazat.

Ověřovací zdroj dat (bez zaúčtování) nech Anně a zapiš prefix.

## Pasti

- **Importní mód = špatný původ.** Kopírováním applieru importu se snadno
  přenese i `_import`; ověř původ událostí v databázi po seedu.
- **Účetní skupina bez účtů.** `AccountingGroupsProvisioner` přeskočí
  skupinu, jejíž účty v osnově nejsou; karta s neúplnou skupinou zůstane
  konceptem (`cardNotConfirmed`) a zařazení nepůjde potvrdit.
- **Řádek pořízení nedědí kartu hlavičky** — karta musí být na řádku
  `purchase.asset`, ne na hlavičce faktury (`docs/assets.md` §5.5).
- **Dlouhodobá karta nenese datum pořízení ani cenu** — nese je
  zařazení (D13, D38 modulu); obálka je pro dlouhodobý majetek nepřenáší.
- **Pořadí událostí v rámci dne** a pravidlo `notAtEnd` — u jednoho
  zařazení se neprojeví, ale seeder má události psát chronologicky.
- **Fiskální rok a měsíc zařazení** musí existovat (`outsideFiscalYear`);
  `FiscalYearsSeeder` je zakládá podle dat sady.
- **Posun kotvy** posune nákup i zařazení; plán a souhrn musí sedět
  i nad posunutou kotvou (test nad výchozí i posunutou).
- **Nová přijatá faktura** přidá PDF a zprávu a posune číslování
  a pořadí souborů v `docs/` a `mail/` — očekávané.
- **Fiktivní data** platí dál (žádné reálné subjekty, telefony `000`).

## Hotovo když

- [x] číselníky majetku v `setup/`, FK přes přirozený klíč, testy
- [x] sekce `assets/`, dump → seed → dump shodný, původ `manual`
      zachovaný, zaúčtování a systémové odpisy dump vynechá s varováním
- [x] dimenze majetku na dokladech přežije round-trip
- [x] karta serveru zařazená, nic nezaúčtované, upozornění majetku 0,
      kontrola evidence × deník bez nesouladu
- [x] Odpisy za období na ověřovacím zdroji nabídnou měsíční odpisy;
      zaúčtování prvního období na druhém zdroji dat bez nesouladu
- [x] souhrn ekonomiky splňuje D25, test cílů projde
- [x] druhý běh `build.py` nic nezmění; unit testy generátoru a dotčené
      PHPUnit testy projdou
- [x] ověření podle oddílu Ověření, výsledky v Odchylkách
- [x] dokumentace aktualizovaná
- [x] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

## Odchylky a zjištění při implementaci (2026-10-21)

- **D42 — id řad dokladů v nastavení sada nenese.** Ověření zaúčtování
  odhalilo, že `economy.assets.accountingSeries` (a stejně
  `economy.vat.filingAccountingSeries`) z provozu před resetem přežije
  reset (`core_system_settings` je `keepOnReset`): `AssetPostingSeriesProvisioner`
  vyplněné nastavení nepřepisuje, řadu „Majetek“ po resetu nezaloží
  a hodnota `6` ukáže na pokladní řadu, kterou při seedu založí applier —
  zaúčtování majetku skončí `series_missing`. Platilo to i pro sady fáze
  2a / 2b (nastavení `"6"` bylo v `setup/settings.jsonc` s chybným
  komentářem „6 = Majetek“). Oprava v datové vrstvě: `SetupExporter`
  obě nastavení nevypisuje (`SETTINGS_EXCLUDED`), `SetupSeeder` je ve
  starší sadě přeskočí a před resetem je smaže, provisionery `ds-upgrade`
  je po resetu nastaví znovu (řada Majetek id 6, DPH řada 5). Samotný
  `ds-reset` bez `dataset-seed` měl tutéž vlastnost dál — zdroj dat, kde
  `ds-upgrade` běžel před resetem, skončil bez řady „Majetek“.
  **Oprava v jádru (2026-10-21):** deklarace `clearSettingsOnReset:
  [{key, table}]` v `module.jsonc` (`economy.assets` a `economy.vat`, obě
  `docs_core_number_series`), `DsResetCommand` klíče s id řádku dropované
  tabulky smaže po DROP a před delegací na `ds-upgrade` (vypíše je i v
  `--dry-run`, s `--keep` tabulky klíč zůstává); provisionery a D54 beze
  změny, `SetupExporter::SETTINGS_EXCLUDED` zůstává jako kopie hlídaná
  testem parity. Zamítnutá varianta: provisioner přepíše neplatné id —
  mění D54, řadu pro DPH neřeší a opravuje jen důsledek. Ověřeno na
  čerstvém volném zdroji dat (`ds-create --no-cron` → `ds-upgrade` →
  `ds-reset --yes`): řada `MA` id 6 existuje, `accountingSeries = 6`,
  `filingAccountingSeries = 5`. Viz `docs/modules.md` →
  `clearSettingsOnReset`, `docs/cli.md` → `ds-reset`.
- **Hosting s kartou od první faktury po datu zařazení** (březen), ne od
  měsíce zařazení: únorová faktura serverovny má datum 3. 2., před
  zařazením 14. 2. Blok `costs {supplier}` u karty; `story.py` to tak
  validuje i dokumentuje.
- **Rozhodnutí implementace (plán 2026-10-21):** server 150 000 Kč bez
  DPH, faktura velkoobchodu `cipdeska` v měsíci −8 (10. 2. 2026,
  splatnost 10 dní, úhrada 19. 2.), na faktuře i montážní sada 1 290 Kč
  jako běžný nákup; zařazení 4 dny po dodání (14. 2.); nákup je
  samostatná faktura v měsíci, kdy dodavatel podle pravidla nefakturuje
  (loader to vynucuje), číslo dodavatele FV-2026-2210, běžné faktury se
  posunuly o jedna. Události původu `import` dump přenáší a seeder je
  ukládá markerem `_import` (preferovaná varianta D38). Seeder nepoužívá
  `AssetImportApplier` ani ho nemění — mapy sloupců obálky se liší
  (původ, počáteční stav), chronologii řadí přes `AssetEvent::kindOrder`.
  Mimo nové třídy jen dvě malé změny: `DocumentExporter` vynechá doklad
  s řádky `asset.*` (jeden dotaz na začátku, varování per doklad)
  a `FiscalYearsSeeder` bere i data událostí majetku (`outsideFiscalYear`
  při posunu kotvy).
- **Řazení nastavení v dumpu** je `ORDER BY key` v `utf8mb4_czech_ci`
  (bez ohledu na velikost písmen) — `economy.assets.accPeriodicity` by
  bylo až za `accountingSeries`; po D42 už v sadě jen jedno z nich.
- **Účetní skupiny v `setup/`** nese sada všech sedm, jak je založí
  provisioner (dump by je jinak vrátil navíc); typ „Výpočetní technika“
  má výchozí skupinu `022`.
- **Obálka** `shpd.dataset.asset.v1` oproti importnímu formátu: `type`
  názvem, `accountingGroup` kódem, `owner` štíhlá identita, stav
  `draft | confirmed | archived` (V opravě jako V pořádku; dlouhodobá
  vyřazená karta se ukládá V pořádku a do archivu ji přesune až
  potvrzené vyřazení), druhy událostí včetně `opening`, povinný `origin`
  (`manual | import`), booleany jen `true`; karta bez inventárního čísla
  se nepřenáší (koncept bez identity), systémové odpisy a nepotvrzené
  události vynechá dump s varováním per karta, `doc_head` nikdy. Karta,
  která už má události (`--no-reset`), je nedostane znovu (`skipped`).
- **Ekonomika (D25 dál platí):** výnosy 2 089 121, náklady 1 836 635
  (dodavatelé 696 610 = +1 290 za montážní sadu; server 150 000 na 042
  mimo náklady), zisk +252 486 = **12,1 %**, po zaúčtování účetních
  odpisů v okně (8 × 3 000 = 24 000) +228 486 = 10,9 %; BU1 300 000 →
  minimum 191 134 (19. 2. po úhradě serveru 183 060,90) → 397 807;
  plán: daňový odpis 2026 30 000, účetně 3 000 měsíčně březen–prosinec
  2026, od 2027 5 000 měsíčně (port ověřen i nad kotvou 2027-01-20:
  zařazení 14. 5., 4 286 / 4 285 Kč červen–prosinec = 30 000).
- **Ověření** (čerstvé volné zdroje dat, 2026-10-21): `twul-4…` (ponechán
  Anně) — seed bez nových varování (jen účetní položky z nabídky a dvě
  chybějící IČO zahraničních firem), `diff -r` jen `manifest.created`
  (i po opravě D42); setup 13, assets 1, assetEvents 1, docs 292,
  mail 63; řada „Majetek“ id 6 založená provisionerem; karta MA0001
  V pořádku, zařazení 14. 2. 2026 potvrzené s původem `manual` bez
  dokladu, datum pořízení karty dosazené ze zařazení; sekce Pořízení:
  jeden řádek faktury 2260011 (FV-2026-2210) 150 000 na 042100 =
  částka zařazení; 4 upozornění majetku 0 nálezů (`alerts-run --all`);
  report Kontrola evidence × deník 2026: stav `warnings` jen
  „Zařazení z 14. 2. 2026 (150 000,00) není zaúčtované“, 042100
  evidence 150 000 = deník 150 000; Odpisy za období (účetní okruh,
  měsíc): únor nic, březen MA0001 3 000, říjen vyloučen
  `earlierPeriodMissing`; náhled zaúčtování: únor `canPost` zařazení
  MD 022100 / D 042100 150 000, březen vyloučen `earlierPeriodUnposted`;
  daňový okruh 2026: 30 000; tab Náklady a výnosy: 8 řádků 518206
  hosting = 39 200; deník 1 364 řádků, 9 s kartou (042100 150 000,
  518206 39 200), hlavičky s kartou 0, 0 chybových dokladů, 221100 =
  397 807,21 = closing BU1; Výsledovka 2026: náklady 1 836 636,56,
  výnosy 2 089 127,49 → +252 490,93 (haléřová zaokrouhlení prodejek jako
  dřív); saldokonto ledger 485 řádků. **Druhý zdroj `68lo-f…`:**
  `AssetPostingService::post` únor → doklad `6026MA0001` (řada Majetek)
  s řádky `asset.activation` 022100 / 042100, zařazení s `doc_head`;
  Kontrola evidence × deník 2026 `ok` bez zpráv (022100 150 000 =
  150 000, 042100 0 = 0); `dataset-dump` doklad vynechal s varováním
  „docs 6026MA0001: doklad zaúčtování majetku (řádky asset.*) se
  nepřenáší“ a dump je dál shodný se sadou kromě `manifest.created`;
  zdroj smazán `ds-delete`. Ruční průchod v aplikaci (karta, Pořízení,
  štítek Nezaúčtováno, dialog Odpisy za období, tab Náklady a výnosy) —
  Anna na `twul-4…`.

## Rozhodnutí k designu (potvrzená, #131)

- ✓ **D20** — jedna karta (server) pořízená přijatou fakturou s kartou
  na řádku, zařazená; odpisy jen jako plán, nezaúčtované
- ✓ **D38** — sada nese evidenci (karta, potvrzené události s původem),
  ne zaúčtování; seeder bez importního módu; dump zaúčtování
  a systémové odpisy vynechá s varováním
- ✓ **D39** — účetní skupiny, skupiny typů a typy majetku v `setup/`
- ✓ **D40** — obálka `shpd.dataset.asset.v1`, karta před doklady,
  události po nich, dimenze majetku přenosná
- ✓ **D41** — server od velkoobchodu kolem měsíce −8, `cz-1`
  rovnoměrně, účetně jako daňově, měsíční účetní odpisy, hosting
  s dimenzí karty
- ✓ **D42** — nastavení s id řad dokladů (`economy.assets.accountingSeries`,
  `economy.vat.filingAccountingSeries`) sada nenese; seed je před resetem
  smaže a provisionery je po resetu nastaví (rozhodnuto při
  implementaci, zapsat do #131); jádro: `ds-reset` je maže podle
  `clearSettingsOnReset` modulů (viz Odchylky)
