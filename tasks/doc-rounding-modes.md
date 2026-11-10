# Task: Zaokrouhlovací módy dokladu — sloučení 0/2, mód 0,05, užší nabídka pro DPH (Issue #63)

**Stav:** hotovo — kód, testy a docs 2026-09-10; proklik roletek na dev DS OK (Anna); alfa: 47 004 dokladů ve 4 DS má oba módy 0, migrace 2 → 0 je no-op a P4 prázdná
**Issue:** shipard/shpd#63 (Sebik)
**Návaznost:** #73 (hotovostní úhrada faktury na Kč — řeší účtování na 211,
tady se neřeší), #71 (`DocRowCalculator` — řádkový výpočet zaokrouhluje pevně
na 0,01 a `vat_rounding_mode` se ho netýká, viz `docs/vat-calculation.md` § 4).

## Cíl

Roletky **Zaokrouhlení částky** (`total_rounding_mode`) a **Zaokrouhlení DPH**
(`vat_rounding_mode`) nabízejí jen hodnoty, které mají odlišný a srozumitelný
význam:

- „Bez zaokrouhlení" (0) a „Matematicky na 0,01" (2) jsou dnes v
  `DocDocument::applyRounding` **totožné** (`round($amount, 2)`) — sloupce mají
  `scale: 2`, na méně než haléře se zaokrouhlit nedá. Sloučit do jedné hodnoty
  s pravdivým názvem.
- Přidat **matematicky na 0,05** — hotovostní zaokrouhlení v SK (povinné od
  7/2022), FI, NL, BE, IE, IT, EE, LT. Slovenské účtenky hrazené hotově v EUR
  dnes při AI importu končí s `totals_mismatch`, protože
  `DocumentApplier::deriveTotalRoundingMode` zkouší jen celé jednotky.
- **Zaokrouhlení DPH** dostane vlastní užší nabídku {na haléře, matematicky
  na 1} — nahoru/dolů/0,05 u daně nedávají smysl a jen matou.

Nepřidává se 0,10 (v EU nikde) ani 0,50 (jen DK) — model to umožní později
jedním řádkem v tabulce módů.

## Před implementací přečti

- `modules/docs/core/config/roundingModes.jsonc` — dnešních 5 módů.
- `modules/docs/core/src/DocDocument.php` ř. 1302–1323 (`applyTotalRounding`,
  `applyRounding` — **kód, který se přesouvá**; komentář o ceil/floor u
  záporných částek zachovat), ř. 841 a 977–1020 (`recapAmountsFromHeader` —
  kde se `vat_rounding_mode` skutečně používá: mode 1 na daň, mode 2 na základ).
- `modules/docs/core/src/DocsHeadsFormBase.php` ř. 410–415 (defaulty 1 / 2),
  ř. 548–552 (selecty v base hlavičce), ř. 1290–1306 (`resolveCfgItemOptions`
  — bere `name`, lokalizaci dělá kompilátor konfigurace).
- `modules/docs/core/tables/docs_core_heads.jsonc` ř. 393–414 (obě pole,
  `default: 0`, `cfgItem`).
- `modules/core/exchange/src/Document/DocumentApplier.php` ř. 1639–1728
  (`deriveTotalRoundingMode` — pořadí zkoušek 1 → 3 → 4, guard
  `diff < 0,005 || diff >= 1,00 → null`).
- `modules/core/exchange/src/Document/DocumentValidator.php` ř. 207–221
  (`$matches` — tolerance 0,01 nebo „declared celé a rozdíl < 1,00").
- `src/Command/DataSource/DsUpgradeCommand.php` ř. 267–272 (vzor
  idempotentního datového backfillu pod `isModuleActive('docs.core')`).
- Formuláře se selecty: `modules/docs/invoicesIn/src/ReceivedInvoiceForm.php`
  ř. 161, 204; `modules/docs/invoicesOut/src/IssuedInvoiceForm.php` ř. 208, 212;
  `modules/docs/cashDocs/src/CashDocForm.php` ř. 176, 180;
  `modules/docs/cashRegister/src/CashRegisterForm.php` ř. 150, 154.
- Testy: `tests/Unit/Module/Docs/Core/DocDocumentTotalsTest.php` ř. 50–82
  (`applyRoundingPub` per mód — mód 2 se ruší), ř. 89–140 (`applyTotalRounding`);
  `tests/Unit/Module/Core/Exchange/Document/DocumentApplierTest.php`
  ř. 1128–1230 (derivace módu); `DocDocumentVatRecapTest.php` ř. 468–480
  (`vat_rounding_mode = 1` v dokladové metodě).
- Docs k úpravě: `docs/vat-calculation.md` § 4 (ř. 55) a § 6 (ř. 136–140);
  `docs/docs-mvp.md` ř. 1116–1121, 1234–1235, 1984–1985; `docs/edit-forms.md`
  ř. 2161–2162 (ukázka s cfgItem); `docs/exchange-format.md` ř. 333.

## Rozhodnutí k designu (potvrzeno 2026-09-10)

- **D1 — Sloučit 0 a 2.** Kanonický kód **0** = „Na haléře (0,01)". Kód 2 se
  z nabídky ruší; data se migrují `2 → 0` v `ds-upgrade` (idempotentně, obě
  pole). Form default `vat_rounding_mode` 2 → 0. Tabulkový `default: 0` je
  pak konečně pravdivý i pro nové záznamy z formuláře.
- **D2 — Nový kód 5** = „Matematicky na 0,05". Bez 0,10 a 0,50.
- **D3 — Samostatný cfgItem `docs.core.vatRoundingModes`** s podmnožinou
  {0, 1}. Hodnoty sdílí s `roundingModes`, takže data v `vat_rounding_mode`
  zůstávají kompatibilní; mění se jen nabídka v selectech a `cfgItem` pole
  v tabulce.
- **D4 — Zobecnit výpočet.** Místo `match` na kódy tabulka
  `kód → (step, dir)` a jeden vzorec. **Upřesnění proti původnímu znění:**
  autoritou je PHP třída `RoundingModes` (konstantní tabulka), ne jsonc —
  `DocDocument` běží s `config = null` (unit testy, část cest applieru)
  a zaokrouhlení nesmí na konfiguraci záviset. Jsonc nese jen názvy pro UI;
  test hlídá, že oba zdroje mají shodnou množinu kódů.
- **D5 — Applier a validátor** rozumí módu 5 při importu: derivace zkusí
  0,05, když se celé jednotky nechytí; validátor propustí rozdíl < 0,05, když
  je deklarovaná částka násobkem 0,05.
- **D6 zamítnuto** — popisky „Zaokrouhlení částky" / „Zaokrouhlení DPH"
  zůstávají (delší se do modalů nevejdou).

## Datový tok

```
UI select (jsonc názvy, cfgItem)  ──►  docs_core_heads.total_rounding_mode / vat_rounding_mode (int)
                                              │
                        DocDocument::applyTotalRounding / recapAmountsFromHeader
                                              │
                                   RoundingModes::apply($amount, $mode)
                                     kód → (step, dir) → round(amount / step) * step
                                              │
                                     total_amount, total_rounding, recap base/tax

Import (AI / ISDOC / old Shipard):
  DocumentApplier::deriveTotalRoundingMode(declared vs computed) → 1 | 3 | 4 | 5 | null(=0)
  DocumentValidator::checkTotals — tolerance podle podoby declared (celé / násobek 0,05 / jinak 0,01)
```

Nic z toho nemění rekapitulaci ani řádky — zaokrouhlení celkové částky jde
do `total_rounding` (§ 6), `vat_rounding_mode` do dokladové metody rekapitulace
(§ 4). Řádkový `DocRowCalculator` zůstává na pevných 0,01.

## Rozsah

### 1. `modules/docs/core/src/RoundingModes.php` (nový)

`final class RoundingModes`, bez stavu:

```php
public const ON_CENT        = 0;  // 0,01 matematicky
public const MATH_UNIT      = 1;  // 1 matematicky
public const UP_UNIT        = 3;  // 1 nahoru (ceil)
public const DOWN_UNIT      = 4;  // 1 dolů (floor)
public const MATH_FIVE_CENT = 5;  // 0,05 matematicky

/** @var array<int, array{step: float, dir: 'math'|'up'|'down'}> */
private const TABLE = [
    0 => ['step' => 0.01, 'dir' => 'math'],
    1 => ['step' => 1.0,  'dir' => 'math'],
    3 => ['step' => 1.0,  'dir' => 'up'],
    4 => ['step' => 1.0,  'dir' => 'down'],
    5 => ['step' => 0.05, 'dir' => 'math'],
];

public static function apply(float $amount, int $mode): float
public static function isKnown(int $mode): bool
/** Módy povolené pro `vat_rounding_mode` = klíče cfgItem docs.core.vatRoundingModes. */
public const VAT_MODES = [0, 1];
```

`apply`: neznámý mód (včetně historického 2, kdyby migrace ještě neproběhla)
→ chová se jako 0. Výpočet: `$q = $amount / $step`; `math` → `round($q)`,
`up` → `ceil($q)`, `down` → `floor($q)`; výsledek `round($q * $step, 2)`.
Pro `step = 0.01` je to ekvivalent `round($amount, 2)`; pro `step = 1.0`
ekvivalent dnešního `round/ceil/floor` — stávající testy musí projít beze změny
hodnot. Komentář o matematické sémantice ceil/floor u záporných částek
(dobropisy) přesunout sem z `DocDocument`.

**Past floating point:** `1709.05 / 0.05` může vyjít `34180.999999…`. Před
`round/ceil/floor` normalizovat: `$q = round($amount / $step, 6)`. Bez toho by
`ceil` u módů 3/4 na step 1.0 nevadilo (dělení jedničkou), ale u 0,05 ano.
Testovat `1709.05 → 1709.05` v módu 5 (nula rozdílu).

### 2. `DocDocument::applyRounding` → tenká obálka

```php
protected function applyRounding(float $amount, int $mode): float
{
    return RoundingModes::apply($amount, $mode);
}
```

Zachovat metodu (subclassy a `applyRoundingPub` v testech ji volají). Komentář
u `applyTotalRounding` beze změny. Default `(int) ($data['vat_rounding_mode'] ?? 2)`
na ř. 841 změnit na `?? 0`.

### 3. Konfigurace

- `modules/docs/core/config/roundingModes.jsonc`: odstranit `"2"`, přejmenovat
  `"0"` na `name` „On cents (0.01)", `name:cs` „Na haléře (0,01)", `name:en`
  „On cents (0.01)"; přidat `"5"` „Round to 0.05" / „Matematicky na 0,05" /
  „Round to 0.05". Hlavičkový komentář aktualizovat, doplnit odkaz na
  `RoundingModes.php` jako autoritu sémantiky.
- `modules/docs/core/config/vatRoundingModes.jsonc` (nový): `"0"` a `"1"`
  se stejnými názvy jako v `roundingModes`.
- `modules/docs/core/module.jsonc` ř. 145: registrovat
  `{ "id": "docs.core.vatRoundingModes", "file": "config/vatRoundingModes.jsonc" }`.
- `modules/docs/core/tables/docs_core_heads.jsonc` ř. 412:
  `vat_rounding_mode.cfgItem` → `docs.core.vatRoundingModes`.

### 4. Formuláře

- `DocsHeadsFormBase` ř. 414: default `vat_rounding_mode` → `0`.
- `DocsHeadsFormBase` ř. 551 a čtyři formuláře (ReceivedInvoiceForm 204,
  IssuedInvoiceForm 212, CashDocForm 180, CashRegisterForm 154): select
  `vat_rounding_mode` → `resolveCfgItemOptions('docs.core.vatRoundingModes')`.
  Selecty `total_rounding_mode` beze změny (nabídka se mění v jsonc).

### 5. Exchange applier — `deriveTotalRoundingMode`

Za zkoušku módu 4 (floor) doplnit:

```php
// 5 = matematicky na 0,05 (hotovost SK a část eurozóny). Až po celých
// jednotkách: celá declared je násobek 0,05 taky, přednost má mód 1.
if (abs(RoundingModes::apply($computed, RoundingModes::MATH_FIVE_CENT) - $declared) <= $eps
    && abs($declared * 20 - round($declared * 20)) <= $eps) {
    return 5;
}
```

Guard `diff >= 1,00 → null` zůstává; `diff < 0,005 → null` zůstává (žádné
zaokrouhlení). Doc komentář metody rozšířit o mód 5. Rozdíl přesně 0,01 se
u declared-násobku-0,05 chytí jako 5 jen když `round5(computed) == declared`
— u 69,99 → 70,00 vyhraje mód 1 (zkouší se první), správně.

### 6. Exchange validator — `checkTotals`

`$matches` rozšířit o třetí větev:

```php
$declaredIsFiveCent = abs($declaredF * 20 - round($declaredF * 20)) <= 0.001;
… || ($declaredIsFiveCent && abs($v - $declaredF) < 0.05)
```

Komentář nad tolerancí doplnit („nebo násobek 0,05 v pásmu < 0,05 —
total_rounding_mode 5"). `declaredIsWhole` implikuje `declaredIsFiveCent`,
pořadí podmínek je jedno.

### 7. `DsUpgradeCommand` — datová migrace

Hned za backfill `doc_state_changed_at` (ř. 271), pod stejným
`isModuleActive('docs.core')`:

```php
// #63: mód 2 (matematicky na 0,01) sloučen do 0 (na haléře) — byly totožné.
// Idempotentní; po prvním běhu žádný řádek s 2 nezbývá.
$dsConnection->executeSQL('UPDATE docs_core_heads SET total_rounding_mode = 0 WHERE total_rounding_mode = 2');
$dsConnection->executeSQL('UPDATE docs_core_heads SET vat_rounding_mode = 0 WHERE vat_rounding_mode = 2');
```

Ověřit, že v `docs_core_heads` není trigger ani `modified`-stamp, který by
UPDATE měl obcházet (vzor ř. 271 to neřeší, chovat se stejně).

### 8. Testy

- `DocDocumentTotalsTest`: `testApplyRoundingTo001` (mód 2) → přepsat na mód
  0 nebo smazat (duplicita s `testApplyRoundingNoRounding`, ten přejmenovat na
  `…OnCents`). Přidat `testApplyRoundingToFiveCents`: `1709.03 → 1709.05`,
  `1709.02 → 1709.00`, `1709.05 → 1709.05`, `0.125 → 0.15`
  (`round` half away from zero), `-1709.03 → -1709.05`. Přidat
  `testApplyRoundingUnknownModeBehavesAsCents` (mód 2 a 99 → 0,01).
  `applyTotalRounding` v módu 5: `total_rounding = 0.02` pro `1709.03`.
- Nový `tests/Unit/Module/Docs/Core/RoundingModesTest.php`: shoda množiny
  klíčů `roundingModes.jsonc` s `RoundingModes::TABLE` (načíst jsonc přes
  `JsoncFormLoader`-style strip komentářů, viz vzor jinde v testech) a shoda
  klíčů `vatRoundingModes.jsonc` s `RoundingModes::VAT_MODES`.
- `DocumentApplierTest`: `testDeriveRoundingModeFiveCents` (computed 12,34,
  declared 12,35 → 5), `testDeriveRoundingModeWholePrefersMathOverFiveCents`
  (computed 69,99, declared 70,00 → 1), `testDeriveRoundingModeFiveCentsNotForOddCents`
  (computed 12,34, declared 12,36 → klíč chybí).
- Validator test (najít existující test `checkTotals` / `totals_mismatch`):
  declared 12,35 vs. Σ 12,33 → bez warningu; declared 12,36 vs. 12,33 →
  warning.
- `DocsHeadsFormTest`: doplnit assert, že options `vat_rounding_mode` mají
  právě hodnoty {0, 1} a `total_rounding_mode` neobsahuje 2 a obsahuje 5
  (pokud test má přístup ke konfiguraci; jinak jen jednotkový test cfgItem výše).

### 9. Dokumentace

- `docs/vat-calculation.md` § 4 ř. 55: „default na haléře (0)"; § 6: výčet
  „na celé jednotky, nahoru, dolů, **na 0,05**, na haléře", odkaz na
  `RoundingModes`, poznámka o užší nabídce pro DPH.
- `docs/docs-mvp.md` ř. 1116–1121 (`cfgItem` u `vat_rounding_mode`),
  ř. 1234–1235 (defaulty 1 / **0**), ř. 1984–1985 (výčet hodnot).
- `docs/edit-forms.md` ř. 2161–2162: cfgItem v ukázce.
- `docs/exchange-format.md` ř. 333: doplnit mód 5 do výčtu.
- `modules/docs/core/README.md`: pokud vyjmenovává cfgItems, přidat
  `vatRoundingModes`.

## Akceptace (hotovo když)

1. Select **Zaokrouhlení částky** nabízí: Na haléře (0,01) · Matematicky na 1 ·
   Nahoru na 1 · Dolů na 1 · Matematicky na 0,05. „Bez zaokrouhlení" ani
   „Matematicky na 0,01" nikde v UI.
2. Select **Zaokrouhlení DPH** nabízí jen: Na haléře (0,01) · Matematicky na 1.
3. Nový doklad z formuláře má `vat_rounding_mode = 0`, `total_rounding_mode = 1`.
4. Po `ds-upgrade` na alfě: `SELECT COUNT(*) FROM docs_core_heads WHERE
   total_rounding_mode = 2 OR vat_rounding_mode = 2` = 0; druhý běh no-op.
5. Doklad s `total_rounding_mode = 5` a Σ rekapitulace 1709,03 uloží
   `total_amount = 1709.05`, `total_rounding = 0.02`; invariant
   `total_base + total_vat + total_rounding == total_amount` platí (§ 8).
6. Import kanonického dokladu s `totalAmount = 12.35` a rekapitulací 12,33
   projde bez `totals_mismatch` a dostane `total_rounding_mode = 5`;
   `totalAmount = 70.00` vs. 69,99 dál dostává mód 1.
7. Všechny existující testy v `tests/Unit/Module/Docs/Core/` a
   `tests/Unit/Module/Core/Exchange/` zelené; `npm run build` OK (frontend se
   nemění, jen kontrola).
8. `python3 scripts/tasks-index.py --check` a `check-sensitive.py` OK.

## Pasti

- **P1 — Floating point u step 0,05.** Viz bod 1: `round($amount / $step, 6)`
  před `round/ceil/floor`. Test `1709.05 → 1709.05` musí dát rozdíl 0,00,
  ne −0,05.
- **P2 — Pořadí v derivaci.** Celá declared je vždy i násobek 0,05; kdyby se
  5 zkoušela před 1, importované faktury na celé Kč by dostaly mód 5. Mód 5
  jde až za 1/3/4.
- **P3 — Neznámý mód v datech.** Mezi nasazením kódu a `ds-upgrade` mohou v DB
  být řádky s 2. `RoundingModes::apply` na neznámý mód vrací 0,01 — tj.
  přesně to, co 2 dělal. Žádný jiný kód nesmí na módu 2 spadnout
  (`resolveCfgItemOptions` prostě 2 nenabídne; select s hodnotou mimo options
  se zobrazí prázdný — po migraci to nenastane, před ní je to přijatelné).
- **P4 — `vat_rounding_mode` mimo {0, 1}.** Data mohou mít (teoreticky) 3/4/5 z
  doby sdíleného enumu. `RoundingModes::apply` je spočítá dál správně;
  select je zobrazí prázdné. Nemigrovat — na alfě ověřit `SELECT vat_rounding_mode,
  COUNT(*) … GROUP BY`; pokud tam nic není, není co řešit.
- **P5 — § 36 odst. 5 ZDPH (mimo scope).** Zaokrouhlovací rozdíl je ze
  základu daně vyjmut jen při **hotovostní** platbě; u bezhotovostní by měl
  podle výkladu GFŘ (2019) vstupovat do základu. Dnes jde `total_rounding`
  mimo základ vždy, bez ohledu na `payment_method`. Patří k #73, tady se
  nemění — jen zaznamenáno. **#73 (2026-11-10):** bezhotovostní úhrada se
  zaokrouhlením dává varování `non_cash_rounding`; do základu se rozdíl dál
  nezahrnuje (`tasks/doc-total-rounding-auto.md` D7).
- **P6 — `DocsHeadsViewer` a exporty** čtou jen `total_rounding` (částku), ne
  mód — bez změny. `DocumentExporter` mód nevyváží (jen `totalRounding`),
  mód 5 se tedy do výměnného formátu nedostane a nemusí. Od #73 vyváží
  příznak automatiky `totals.totalRoundingAuto` (mód dál ne).

## Poznámky z implementace (2026-09-10)

- **Validátor přísněji než D5.** Místo pásma „násobek 0,05 a rozdíl < 0,05"
  třetí větev `round5(Σ) == declared` — tj. přesně podmínka, kterou používá
  `deriveTotalRoundingMode`. Pásmo < 0,05 by nechalo bez varování projít
  třeba Σ 12,31 vs. deklarováno 12,35 (rozdíl 0,04), kde applier mód 5
  nepřidělí (round5(12,31) = 12,30) a doklad by se uložil s jinou částkou
  než na účtence. Potvrdila Anna 2026-09-10; test
  `testTotalsFiveCentDeclaredNotReproducedByRoundingStillWarns`.
- **Derivace módu jako smyčka.** `deriveTotalRoundingMode` už neduplikuje
  `round/ceil/floor`, prochází kandidáty `[1, 3, 4, 5]` přes
  `RoundingModes::apply` v pořadí priority (P2). Kontrola „declared je
  násobek 0,05" ze zadání je redundantní (plyne z `round5(computed) ==
  declared`) a není v kódu.
- **Formulářový test s konfigurací.** `DocsHeadsFormTest` dostal mock
  `ConfigRuntime::cfgItem` nad reálnými jsonc (`createFormWithRoundingCfg`),
  takže bod 8 „pokud test má přístup ke konfiguraci" platí — options obou
  selectů se ověřují přímo na `FormDefinition`.
- **Roadmapa:** zařazeno do M2 (import SK účtenek je součást kruhu
  pošta → doklad).
- **`help/`** beze změny — žádná stránka nevyjmenovává hodnoty roletek.
