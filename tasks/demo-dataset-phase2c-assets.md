# Demo sada „IT servis“ — fáze 2c: majetek

**Stav:** naplánováno — #131 D20, D38–D41

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

- [ ] číselníky majetku v `setup/`, FK přes přirozený klíč, testy
- [ ] sekce `assets/`, dump → seed → dump shodný, původ `manual`
      zachovaný, zaúčtování a systémové odpisy dump vynechá s varováním
- [ ] dimenze majetku na dokladech přežije round-trip
- [ ] karta serveru zařazená, nic nezaúčtované, upozornění majetku 0,
      kontrola evidence × deník bez nesouladu
- [ ] Odpisy za období na ověřovacím zdroji nabídnou měsíční odpisy;
      zaúčtování prvního období na druhém zdroji dat bez nesouladu
- [ ] souhrn ekonomiky splňuje D25, test cílů projde
- [ ] druhý běh `build.py` nic nezmění; unit testy generátoru a dotčené
      PHPUnit testy projdou
- [ ] ověření podle oddílu Ověření, výsledky v Odchylkách
- [ ] dokumentace aktualizovaná
- [ ] `**Stav:**` aktualizovaný, `python3 scripts/tasks-index.py --check`
      a `python3 scripts/check-sensitive.py` projdou

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
