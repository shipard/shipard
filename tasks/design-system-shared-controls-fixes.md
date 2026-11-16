# Design system — opravy z ověření sdílených ovládacích prvků

**Stav:** naplánováno — rozhodnutí D79, D80 potvrzená v #149 2026-11-16

## Kontext / Cíl

Ruční ověření `tasks/design-system-shared-controls.md` (#149, D75–D78,
Odchylky 14) našlo tři věci:

1. **Šipky v segmentovém přepínači rovnou mění volbu.** `SegmentedControl`
   s roving tabindexem vybírá už při přesunu šipkou — v Nastavení jazyk
   po první šipce znovu načte stránku, průvodce zdrojem dat volbu hned
   ukládá. Dřív přepínače šipky neuměly vůbec.
2. **Popis ve volitelném řádku je moc malý.** `OptionRow` kreslí snippet
   `description` v roli meta šedě (D76). V obou použitích nese běžné údaje
   — adresu, IČO a DIČ v dialogu Přidat firmu z registru, číslo účtu a
   měnu v převodu bankovních účtů — které §11 vede jako body barvou
   textu. Před #149 byly 14 px šedé, teď 12 px šedé.
3. **Tlačítko „Vytvořit z registru“ přetéká.** V kartě strany náhledu
   importu dokladu (patička `PartyCard`) má `Button` dlouhý popisek
   s názvem firmy a `white-space: nowrap`; na střední šířce (mezi
   desktopem a telefonem) text i „+“ vyjedou z tlačítka. S #149 nesouvisí,
   opravuje se tady, protože se našlo při jeho ověření.

K tomu drobnost z Odchylek 7: řádek dialogu z registru bez štítků má
vpravo prázdný prvek s mezerou md.

**Cíl:** D79 a D80 v komponentách a v `docs/design-system.md`, prázdný
`aside` pryč, tlačítko rychlého založení z registru se vejde na každé
šířce.

**Mimo rozsah:** tlačítko Export v liště reportů má jinou velikost než
ostatní ovládací prvky (sjednotit později, zapsáno v #149); kontrast
„aktuální volby“ v tmavém vzhledu (#153); štítky uvnitř řádků (badge
systém §5).

## Návaznost

- **#149** — D76, D77, D79, D80; komentář s výsledkem ručního ověření
- `tasks/design-system-shared-controls.md` — Odchylky 7, 8, 14

## Před implementací přečti

- `docs/design-system.md` §8 Volitelné řádky, Segmentový přepínač; §11
  Typografie (Pravidla — šedá je pro doplňky)
- `frontend/src/components/ui/SegmentedControl.svelte`, `OptionRow.svelte`,
  `Button.svelte`
- `frontend/src/components/registry/RegistryImportWizard.svelte`,
  `settings/BankAccountBridgeDialog.svelte`
- `frontend/src/components/exchange/DocumentExchangePreview.svelte`
  (snippet `partyFooter`), `exchange/ResolveDecisionPanel.svelte`
  (`.shpd-resolve__create`), `document/PartyCard.svelte`

## Rozhodnutí (potvrzená v #149)

- ✓ **D79** Segmentový přepínač vybírá až potvrzením: šipky (a Home / End)
  jen přesouvají fokus mezi volbami, volbu vybere mezerník, Enter nebo
  klik. Platí pro všechny segmentové přepínače stejně. Doplňuje D77.
- ✓ **D80** Popis ve volitelném řádku je **body barvou textu**, tloušťka
  normal — nese běžné údaje (adresa, identifikátory, účet; §11). Šedá
  meta jen pro skutečné doplňky a dává si ji obsah sám. Nahrazuje část
  D76 „popis meta šedě“.

## 1. Segmentový přepínač (D79)

`ui/SegmentedControl.svelte`:

- šipky vlevo / vpravo (nahoru / dolů) a Home / End přesouvají **jen
  fokus** (přes zakázané volby dokola jako dnes); `select()` se z
  `handleKeydown` nevolá.
- výběr: klik, mezerník, Enter — nativní `click` tlačítka, nic navíc.
- roving tabindex sleduje fokus: `tabindex="0"` má volba s fokusem;
  po odchodu fokusu ze skupiny (`focusout` mimo kořen) se vrací na
  vybranou volbu (bez výběru na první povolenou) — Tab zpět do skupiny
  vstoupí na vybranou.
- komentář v hlavičce komponenty přepsat podle D79.

## 2. Volitelný řádek (D80) a prázdný `aside`

`ui/OptionRow.svelte`:

- `.shpd-option-row__description`: velikost `--shpd-font-size-body`,
  barva `--shpd-color-text`, tloušťka normal; komentář v hlavičce podle
  D80.
- komentář v hlavičce: „import ze Spisovny“ → „dialog Přidat firmu
  z registru“ (chybný název z #149 — „registry“ je tu veřejný rejstřík,
  ne Spisovna).

Použití — ověř, že obsah popisu nemá vlastní šedou ani menší písmo, které
by D80 přebilo:

- `registry/RegistryImportWizard.svelte` — adresa (`__row-address`), IČO
  a DIČ (`__row-ids`, `__mono`).
- `settings/BankAccountBridgeDialog.svelte` — `__detail`, `__mono`,
  `__currency`.

Prázdný `aside`: `RegistryImportWizard` předává snippet `aside` jen tehdy,
když řádek má štítky (dnes je podmínka uvnitř snippetu a obal
`.shpd-option-row__aside` se kreslí vždy). Stejně zkontroluj
`BankAccountBridgeDialog` (radio „výchozí“ jen u zaškrtnutého účtu).

## 3. Tlačítko „Vytvořit z registru“

Dvě místa, stejný text `exchange.preview.registry.quickAdd`:

- `DocumentExchangePreview.svelte`, snippet `partyFooter` — `Button
  size="sm" variant="secondary"` s popiskem `+ Vytvořit z registru:
  {název}`.
- `ResolveDecisionPanel.svelte` — vlastní `<button class="shpd-resolve__create">`
  s „+ “ v textu.

Úprava:

- „+“ přestane být součástí textu: v `Button` ikona `iconAdd` (prop `icon`
  jako u ostatních tlačítek „přidat“); v `ResolveDecisionPanel` stejně
  — ideálně převést na `Button` (`size="sm"`, `variant="secondary"`,
  šířka 100 %), pokud tím nezmizí nic, co dnešní tlačítko umí; jinak
  `Icon` v obsahu tlačítka.
- dlouhý popisek se smí zalomit: `Button` dostane prop `wrap` (výchozí
  `false`) — `white-space: normal`, řádkování podle body, text zarovnaný
  vlevo, výška podle obsahu, ikona nahoře u prvního řádku. Použít jen
  na těchto dvou místech; ostatní tlačítka beze změny.
- tlačítko v patičce karty strany nesmí být širší než karta
  (`max-width: 100%`, kořen patičky `min-width: 0`).

## 4. Dokumentace

`docs/design-system.md`:

- §8 Segmentový přepínač — klávesnice podle D79 (místo „šipky přesouvají
  výběr“).
- §8 Volitelné řádky — popis body barvou textu (D80), věta o šedé jen
  pro doplňky.
- §8 Tlačítka / `Button`, je-li tam popis propů — prop `wrap`; jinak
  krátká zmínka v §13 Soubory u `Button.svelte`.

## Commity

1. `SegmentedControl` manuální výběr + docs (D79)
2. `OptionRow` popis body, prázdný `aside`, oprava názvu v komentáři + docs (D80)
3. `Button` `wrap`, rychlé založení z registru na dvou místech
4. stav tasku + index

## Ověření

V prohlížeči na volném dev zdroji dat, světlý i tmavý vzhled:

1. Nastavení → Jazyk: Tab na přepínač, šipka vpravo — fokus se přesune,
   jazyk se nezmění a stránka se nenačte; mezerník / Enter jazyk změní.
   Tab ven a zpět vstoupí na vybranou volbu.
2. Totéž u přepínače vzhledu, rozvržení, volby v průvodci zdrojem dat
   (šipka nic neuloží), formátu exportu reportu a režimu nahrání pošty.
3. Osoby → „Z registru“ → hledání: adresa, IČO a DIČ v roli body barvou
   textu; řádek bez štítků bez prázdného místa vpravo; zakázaný řádek
   (firma už v Osobách) beze změny.
4. Dialog převodu bankovních účtů (Nastavení zdroje dat, položka
   chybějícího vlastního bankovního účtu, „Převzít z vlastní Osoby“ —
   jen na zdroji dat, kde vlastní Osoba má bankovní spojení a číselník
   bankovních účtů je prázdný; jinak s podvrženou odpovědí API): číslo
   účtu a měna body barvou textu.
5. Náhled importu dokladu s partnerem, kterého nabízí registr: tlačítko
   „Vytvořit z registru“ s ikonou na šířkách 1500, ~1000, ~760 a 360 px —
   popisek se zalomí uvnitř tlačítka, nic nepřetéká kartu; totéž
   v rozhodovacím panelu partnera.

## Hotovo když

- [ ] D79 v `SegmentedControl`, ověření 1–2
- [ ] D80 v `OptionRow`, prázdný `aside` pryč, ověření 3–4
- [ ] tlačítko rychlého založení z registru se vejde, ověření 5
- [ ] `docs/design-system.md` podle kroku 4
- [ ] `**Stav:**` aktualizovaný, `scripts/tasks-index.py`

## Odchylky od zadání

(vyplní implementace)

## Pasti

- **ARIA vzor radio group** standardně vybírá šipkou; manuální výběr je
  záměrná odchylka (D79), protože volby mají okamžitý účinek (načtení
  stránky, uložení). Sémantika `radiogroup` / `radio` / `aria-checked`
  zůstává.
- **`OptionRow` s nativním radiem** (dialog z registru) se D79 netýká —
  nativní radia šipkou vybírají a výběr v dialogu nic neukládá, potvrzuje
  se tlačítkem.
- **`Button` `nowrap`** používají všechna tlačítka — `wrap` je opt-in,
  výchozí vzhled se nesmí změnit.
- **`data-testid`** na tlačítkách rychlého založení zachovat.
- **px v CSS** zastaví pre-commit.
