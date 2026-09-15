# Roadmap

Kam Nový Shipard směřuje a v jakém pořadí. Tento dokument odpovídá na otázku
**„co teď"** — celková mapa rozsahu (**„co všechno"**) je ve
[`features.md`](features.md), implementační zadání žijí v [`tasks/`](../tasks/README.md),
referenční specifikace subsystémů v [`docs/`](README.md).

Stav jednotlivých tasků: generovaný souhrn v
[`tasks/README.md`](../tasks/README.md).

---

## Pravidlo prioritizace

Milníky jsou definované **schopností uživatele**, ne modulem. Task se dělá,
když posouvá nejbližší otevřený milník. Ve sporu platí toto pořadí:

1. **Věcná správnost** — systém nesmí tvrdit nepravdu o penězích. Chyba ve
   výpočtu má přednost před jakoukoli funkcí.
2. **Blokátor použitelnosti** — bez čeho firma nemůže systém provozovat.
3. **Blokátor migrace** — bez čeho nelze přejít ze starého Shipardu.
4. **Vše ostatní** — pohodlí, vzhled, rozšíření.

Cokoli z kategorie 4 se nezačíná, dokud je otevřená položka z kategorie 1.

Milníky nemají termíny. Pořadí říká, co se dělá dřív; kdy je to hotové,
říká stav tasků.

---

## M0 — Věcná správnost výpočtů ✓ **hotovo** (15. 8. 2026)

Doklad vzniklý z došlé faktury musí mít správné částky. Dnes ne vždy má.
Nejmenší milník na roadmapě a zároveň blokátor všeho ostatního: dokud běží,
každý tester generuje data, která se budou muset opravovat.

| Co | Zadání |
|---|---|
| Faktury s jednotkovými cenami včetně DPH → daň se počítá dvakrát | `docs-vat-mode-derivation.md` |
| Dokončení zaokrouhlení celkové částky (ověření + nasazení promptu) | `mail-invoice-rounding.md` |
| Reverse charge v rekapitulaci DPH | `docs-vat-totals-reverse-charge.md` |
| Opravy schématu AI analýzy (`schema_error`, nedeklarované hodnoty) | `mail-analysis-schema-fixes.md` |

**Hotovo když:** u kontrolní sady faktur z testovacího prostředí odpovídá
součet rekapitulace DPH a celková částka výsledného dokladu předloze, a to
i u faktur s koncovými cenami a s reverse charge.

**Uzavřeno 15. 8. 2026** — GitHub issues #13–#16 zavřené. Kontrolní sada
(běžné doklady, koncové ceny, reverse charge, zaokrouhlení, bez DPH)
ověřena na dev DS — všechny doklady sedí s předlohou na haléř
a deníky jsou vyrovnané. Cestou odkryty a opraveny dvě další chyby
(rekapitulace v režimu z ceny celkem, doplňování pohybu při apply).
Potvrzení na reálných datech alfy přechází do M2 (závislé na
message-centric nasazení).

---

## M1 — Výstupy pro DPH ▸ **aktivní**

Bez přiznání k DPH a kontrolního hlášení nemůže být Shipard jedinou evidencí
firmy — účetní ho nepřevezme a systém zůstane doplňkem.

Stav po revizi 7. 9. 2026: **výpočet existuje, podání ne.** Modul
`economy.taxes` skládá přiznání, kontrolní hlášení i souhrnné hlášení
on-demand jako reporty nad instancemi daňových tvrzení
(`economy_vat_report_periods`) — issue #55, rozhodnutí D1–D13. Chybí
persistence podaného tvrzení, XML pro daňový portál a zámek období.

| Co | Zadání |
|---|---|
| Mapování kódů DPH → řádky přiznání / sekce KH / SH, živé reporty | `taxes-phase01.md` (částečně — zbývá ds-upgrade, proklik, ověření na alfě) |
| Instance daňových tvrzení místo mřížky období DPH | `vat-report-periods.md` (částečně — zbývá proklik UI, ověření po re-importu) |
| Podání — persistence podaného tvrzení, XML pro portál | — (issue #55, Fáze 2) |
| Uzamčení období po podání (zámek proti dodatečným změnám) | — |

**Hotovo když:** za uzavřené období lze vygenerovat přiznání i kontrolní
hlášení ve formátu přijatelném pro daňový portál a čísla souhlasí s deníkem.

---

## M2 — Uzavřený kruh na přijaté faktuře

Pošta → AI analýza → doklad → účetní deník → saldokonto → párování s bankovní
platbou, bez ručního zásahu mimo kontrolu. Tady je hotovo víc, než se zdá:
matcher, clearing účty i dávkový endpoint párování existují.

Zdrojem platby je v tomto milníku **importovaný výpis ze souboru**
(CAMT / GPC / FIO). Nahrání souboru je krok získání dat, ne kontrola
dokladu — kruh uzavírá. Automatické stahování přes bankovní API je v M4.

| Co | Zadání |
|---|---|
| Ověření celého toku na reálných datech testovacího prostředí | — |
| Ověření správnosti saldokonta na reálných datech — konkrétní chyba není známá, jde o kontrolu součtů proti deníku a proti starému systému | — |
| Zbytky po M0: dokončení message-centric nasazení na alfě (živá analýza, aktuální prompt — v4.2.0, `schema_error` v provozu), vzory koncových cen nad reálnými analýzami | `mail-message-centric.md` |
| Zaokrouhlovací módy dokladu: sloučení 0/2, matematicky na 0,05 (SK účtenky hrazené hotově končí s `totals_mismatch`), užší nabídka pro DPH | `doc-rounding-modes.md` |

**Hotovo když:** přijatá faktura z e-mailu projde až do spárované úhrady
a uživatel do toho zasáhne jen potvrzením návrhu; saldo po partnerech
souhlasí s deníkem.

---

## M3 — Migrace ze starého Shipardu na ostro

Výměnný formát a applier jsou hotové pro doklady, osoby, položky, účetní
doklady i bankovní výpisy. Chybí důkaz, že import proběhne beze ztráty na
všech ostrých datech.

| Co | Zadání |
|---|---|
| Kompletní import ověřený na všech datových zdrojích testovacího serveru | — |
| Kontrolní součty proti starému systému (doklady, saldo, deník) | — |

**Hotovo když:** import lze zopakovat z čistého stavu (`ds-reset`) a výsledné
součty souhlasí se starým systémem.

---

## M4 — Ostrý provoz

Firmy, které dnes běží na starém Shipardu, přejdou na nový a nevrátí se.
Migrace dat (M3) k tomu nestačí — starý systém umí věci, které nový
zatím nemá, a bez nich se firma za pár týdnů vrátí. Všechno níže je
**blokátor migrace** (kategorie 3), ne pohodlí.

| Co | Zadání |
|---|---|
| Bankovní API — automatické stahování transakcí (FIO token, plánovač, šifrované credentials); šev připravený v `docs/bank.md` §8 | — |
| Platební příkazy — nad stejným konektorem | — |
| Prodejní smlouvy — podklad pro opakovanou fakturaci | — |
| Tisk / PDF vydaného dokladu | — |
| Odeslání dokladu odběrateli e-mailem — nad existující odchozí poštou (`docs/mail/outbound.md`), chybí napojení z dokladu s přílohou | — |
| Majetek — evidence a odpisy | — |
| Saldokonto — přehlednost: chip bar saldokont, položky v sidebaru, grid po partnerech | `accbal-ledger-viewgroup-chips.md`, `accbal-nav-items.md`, `accbal-ledger-grid.md` |

**Hotovo když:** interní firmy vedou účetnictví výhradně v novém Shipardu
a do starého se pro žádnou běžnou operaci nevrací.

---

## M5 — Veřejná beta: provoz firmou, která není vývojář

Uživatel na pozvánku začíná s prázdným datovým zdrojem — nemigruje.
Neblokuje ho tedy chybějící funkce ze starého Shipardu, ale všechno, co
dnes za něj dělá vývojář z příkazové řádky, a bezpečnost cizích dat.

| Co | Zadání |
|---|---|
| Rate limiting a evidence neúspěšných přihlášení | `auth-phase0a-hardening.md` |
| Záloha a obnova datového zdroje | — |
| Samoobslužná správa uživatelů a přístupových práv | — |
| Zobrazení adresy pro příjem pošty v aplikaci | — |
| Příkazy `ds-delete` a `ds-list` | — |
| Servisní výmaz nepotřebných tabulek a sloupců | — |

**Hotovo když:** pozvaný uživatel založí firmu, pozve kolegu, pošle první
fakturu na adresu datového zdroje a nic z toho nevyžaduje zásah vývojáře.

---

## M6 — Pohodlí a vzhled

Vše, co systém zpříjemňuje, ale neblokuje jeho použití. Otevřená položka
z M0 má vždy přednost.

| Co | Zadání |
|---|---|
| Agregace alertů do skupinových karet feedu | `dashboard-alert-grouping.md` |
| Detekce chybějících překladů (validační nástroj) | — |

---

## Za horizontem — účetnictví pro celou EU

Milníky výše se týkají českého účetnictví a to je záměr: jedna země pořádně,
než přijde další. Dlouhodobý cíl je ale širší — Shipard má umět vést firmu
v **kterémkoli členském státě EU**.

Účetnictví je národní: sazby a přiznání k DPH, účtová osnova, formáty
podání, registr firem, náležitosti dokladu — každá země má své. „Další
země" proto není překlad rozhraní, ale **modul země** postavený na jádru,
které národní pravidla nemá zadrátovaná. Části jádra s tím už počítají
(registrace k DPH a sazby jsou vázané na zemi, ne na ČR), národní
specifika jsou dnes jen česká.

Co to znamená pro pořadí prací: **nic před M5.** Do té doby sbíráme lidi —
účetní a vývojáře z jiných zemí EU, kteří znají reálie své země zevnitř
a jsou ochotní nám je vysvětlit. Kontakt a anglický přehled projektu:
[shipard.dev](https://shipard.dev/en/).

---

## Vědomě odložené

Věci, o kterých se rozhodlo, že se **nedělají teď** — ať se k nim nevracíme
v každé diskuzi.

| Co | Proč |
|---|---|
| Zásoby (příjemky, výdejky, přehledy, účtování A/B) | pro ostrý provoz nejsou potřeba; budou se dělat, návrh zatím neexistuje |
| Zakázky | totéž — až po M4, po platebních příkazech |
| Zálohové faktury a zúčtování záloh | totéž; saldokonto na ně počítá (`docs/accbal.md` §5, mimo Fázi 3) |
| Účetní závěrka (uzávěrka roku, rozvaha, výsledovka) | není potřeba pro přechod na ostro; přijde s prvním uzavíraným rokem |
| PostgreSQL driver | MariaDB stačí; abstrakce v `DatabaseManager` je připravená |
| Další LLM poskytovatelé kromě Anthropic | až bude důvod, backendy jsou abstrahované |
| Mobilní nativní aplikace | responzivní web pokrývá potřebu |

---

## Jak se roadmapa udržuje

- Revize **po dokončení každého milníku**, ne průběžně. Průběžné změny patří
  do tasků, ne sem.
- Milník se nepovažuje za hotový, dokud není splněné jeho „Hotovo když".
- Nové zjištění, které nemá zadání, se zapíše do
  [`tasks/TODO.md`](../tasks/TODO.md) a přiřadí k milníku až při revizi.
- Platí konvence z [`tasks/README.md`](../tasks/README.md): žádné citlivé
  údaje z reálných dat. Tento dokument je ve veřejném repozitáři.

### Historie revizí

- **15. 9. 2026:** doplněn oddíl „Za horizontem — účetnictví pro celou EU"
  (dlouhodobý záměr, bez vlivu na pořadí M1–M6; vazba na vývojový web
  shipard.dev).
- **7. 9. 2026 (po M0):** aktualizován stav M1 (výpočet DPH výstupů existuje,
  chybí podání). Do M2 doplněn zdroj platby a ověření saldokonta. Vložen
  nový M4 „Ostrý provoz" — funkce, které starý Shipard má a bez kterých se
  firma po migraci vrátí; původní M4/M5 přečíslovány na M5/M6, M5 přejmenován
  na Veřejnou betu. Zásoby, Zakázky, Zálohy a Účetní závěrka zapsány jako
  vědomě odložené.

---

[← docs/README.md](README.md) · [tasks/](../tasks/README.md)
