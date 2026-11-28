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

## M1 — Výstupy pro DPH ✓ **hotovo** (17. 11. 2026)

Bez přiznání k DPH a kontrolního hlášení nemůže být Shipard jedinou evidencí
firmy — účetní ho nepřevezme a systém zůstane doplňkem.

| Co | Zadání |
|---|---|
| Mapování kódů DPH → řádky přiznání / sekce KH / SH, živé reporty | `taxes-phase01.md` |
| Modul `economy.vat` (přejmenování z `economy.taxes`) | `vat-rename.md` |
| Instance daňových tvrzení místo mřížky období DPH | `vat-report-periods.md` |
| Podání — snapshot podaného tvrzení, řádné / opravné / dodatečné | `vat-filings.md` |
| XML pro daňový portál (DPHDP3, DPHKH1, DPHSHV), hlavička, PDF opis | `vat-filing-xml.md` |
| Uzamčení období po podání (zámek proti dodatečným změnám) | `vat-period-lock.md` |
| Zaúčtování přiznání, kontrola zůstatků 343 | `vat-filing-accounting.md` |
| Import starých podání ze starého Shipardu | `vat-filings-import.md` |

**Hotovo když:** za uzavřené období lze vygenerovat přiznání i kontrolní
hlášení ve formátu přijatelném pro daňový portál a čísla souhlasí s deníkem.

**Uzavřeno 17. 11. 2026** — issue #55 zavřené (rozhodnutí D1–D39). Modul
`economy.vat` skládá přiznání, kontrolní hlášení i souhrnné hlášení nad
instancemi daňových tvrzení, podání drží snapshot, z něj generuje XML pro
daňový portál, období se po podání zamyká a přiznání se účtuje. Na kopii
reálných dat sedí přiznání 01–04/2026 na podaná XML i na staré účetní doklady
přiznání a kontrolní hlášení 01/2026 až na jednu známou výjimku. Porovnání
s historií podání na dalších kopiích reálných dat (zůstatky 343, rozdíly
proti původním XML, souhrnné hlášení) pokračuje v #158.

---

## M2 — Uzavřený kruh na přijaté faktuře ▸ **aktivní**

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
| Obsahové štítky i pro ISDOC faktury — import v runneru předzpracování, karta Nová kategorie, učení pravidel (#81) | `mail-isdoc-content-tags.md` (hotovo) |
| Zálohová faktura přijatá jako typ dokladu: výzvy k platbě dnes jdou z pošty do sekce K vyřízení jako `other` / `action` (#105 D8); až typ vznikne (navazuje na #79), prompt je přeřadí z `other` na doklad — účetně bezpečnější, člověk hlídá, co platí | `mail-other-attention.md` (D8), #79 |

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
| Zakázky s periodickou fakturací — jedna evidence místo prodejních smluv (nájemné, služby, od konceptu ke kontrole po automatické odeslání); středisko a zakázka jako standardní dimenze deníku (`docs/work-orders.md`, #110) | `dimensions-core.md` |
| Tisk / PDF dokladu — faktura vydaná, zálohová faktura, pokladní doklad, prodejka, Kontace, vodoznak storna a jazyky tisku `cs` / `en` / `sk` / `de` hotové (`docs/prints.md`), stejně jako vzhled hlavičky a vlastní texty na tiscích včetně textů e-mailu (#90 fáze 3, `docs/prints.md` §12); zbývá revize slovenských a německých formulací a nasazení fáze 3 (`ds-upgrade` na zdrojích dat) | `prints-phase1.md`, `prints-phase2.md`, `prints-languages.md`, `prints-phase3.md` |
| Odeslání dokladu odběrateli e-mailem — ruční odeslání z detailu hotové: účely kontaktů, příjemci, odesílatel podle číselné řady, Odeslaná pošta s Odeslat znovu (`docs/prints.md` §9, `docs/mail/sent.md`); zbývá ověření proti skutečnému SMTP a nasazení, hromadné a automatické odesílání (D11); pojistka přesměrování pošty na dev a testovacích serverech je hotová (#95, `mail.safety`, `docs/mail/outbound.md`) — zbývá ji nastavit na testovacím serveru | `prints-phase4.md`, `mail-safety.md` |
| Majetek — evidence a odpisy | — |
| AI modely — odchod ze Sonnetu 4.5 (končí 30. 11. 2026) přemostěním přes Sonnet 4.6 (#85 fáze 0), katalog modelů `core.ai.models` s tvarováním požadavku, cenami a přepisem vyřazených modelů (fáze 1a), AI připojení vs. AI funkce (1b), sekce AI v Nastavení (1c), Amazon Bedrock (EU) (3a), thinking bloky v tool smyčce chatu (fáze 4) a **přechod výchozích modelů úrovní na řadu 5 po evaluaci #103** (16. 10. 2026: Sonnet 5.5 pro `fast` / `balanced`, Opus 5.5 pro `strong`, `docs/ai.md` §5.2) hotové včetně ověřovacích běhů (`baseline` v rámci šumu, Haiku 5.5 pro štítky nestojí za přepnutí) — termín Sonnetu 4.6 (aktivní nejméně do 17. 2. 2027) už neblokuje; zbývá nasazení (`ds-upgrade` zdrojů dat); **prohlížeč výsledků evaluací** ve vývojářském dashboardu (sekce Testování na `/_dev/`, celý dashboard ve Svelte; #103 F6a, D51–D53, D57) hotový 16. 10. 2026, spouštění evaluací z dashboardu (F6b, D54–D56) až po #126 | `ai-models-phase0.md`, `ai-model-catalog.md`, `ai-functions.md`, `ai-settings-section.md`, `ai-bedrock-eu.md`, `chat-thinking-blocks.md`, `ai-tiers-series5.md`, `testing-phase6a.md` |
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
| Záloha a obnova datového zdroje — obnova na neprodukční server vypne periodické úlohy a zneplatní přihlašovací údaje ostrého zdroje (AI připojení, výhledově banka) (#115 D12; do té doby ruční krok v `docs/migration-guide.md`) | — |
| Samoobslužná správa uživatelů a přístupových práv | — |
| Zobrazení adresy pro příjem pošty v aplikaci | — |
| Příkaz `ds-delete` pro produkci a hosting (záloha před smazáním, vazba na portál) — pro vývoj hotový 2026-10-17: volné zdroje dat mimo produkci z CLI i z vývojářského dashboardu (#103 D68–D71, `tasks/ds-delete.md`); `ds-list` hotový dřív (#126) | — (vývoj: `ds-delete.md`) |
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
| Vývojářský dashboard: vzhled, verze, čeština a angličtina, aktualizace (#129) | `dev-dashboard-look.md` (fáze 1), `dev-dashboard-i18n.md` (fáze 2) a `dev-dashboard-update.md` (fáze 3, D16–D22) hotové 2026-10-17 |
| Design system — detail záznamu (#133, #137): sdílený nadpis sekce a seznam vlastností, karty, HTML z backendu, karta návrhu | `design-system-shared-sections.md` (task 1, D28–D29) hotové 2026-10-26; tasky 2–4 dle #137 |

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

## Doplňky

Doplňky — moduly mimo `install.base`, které se zapínají ve zdroji dat
([`docs/modules.md`](modules.md) §9, #170) — jsou **samostatný oddíl mimo
milníky**. Nečekají na žádný milník a žádný milník nečeká na ně.

Z pravidla prioritizace pro ně platí jen kategorie 1: otevřená položka
**věcné správnosti** blokuje i práci na doplňku. Kategorie 2 a 3 (blokátor
použitelnosti, blokátor migrace) doplňky neblokují. Bod rozšíření, který
doplňku v jádru chybí, se dělá v koleji doplňku — jako obecná změna jádra
s vlastní issue, ne na míru jednomu doplňku.

| Doplněk | Stav |
|---|---|
| Správa budovy — nájemní smlouvy, virtuální kanceláře, měřiče, fakturace | první doplněk (#170); stojí na obecném číselníku míst v jádru (#169) |
| Evidence tržeb (EET 2.0, povinná od 1. 1. 2027) — `economy.salesRegistration` | fáze 1–3 hotové 2026-11-26 (#171): typ evidence, registrace, certifikát, pojistka serveru, odeslání zprávy, Ověřit spojení; tržby z prodejek, pokladních dokladů a faktur placených hotově nebo kartou (rozdílové zasílání, storno a oprava), fronta s opakováním, záchranná síť, agenda Evidence tržeb a upozornění; Kontaktní platba (faktura Převodem zaplacená na místě — formulář i akce na dokladu V pořádku), záložka a štítky evidence na dokladu — ověřeno proti playgroundu. Zbývá ruční proklik fáze 3 a ověření v produkci; mimo rozsah kontaktní platba přes API a kód potvrzení na tisku (U20) |
| Správa počítačové sítě | bez návrhu |

---

## Nativní aplikace

Nativní aplikace Shipardu — okno s WebView, ve kterém běží běžný Shipard,
a k tomu přístup k periferiím: tisk na POS tiskárny, čtečky, sdílená
pracoviště s PINem (#177). Žije ve vlastním repozitáři `shipard-app`;
webová strana a server v tomto. Je to **samostatný oddíl mimo milníky**,
se stejným pravidlem jako doplňky: z pravidla prioritizace pro ni platí
jen kategorie 1 — otevřená položka věcné správnosti blokuje i práci na
aplikaci, kategorie 2 a 3 ne.

Shipard v prohlížeči zůstává beze změny; aplikace ho nenahrazuje.

| Platforma | Stav |
|---|---|
| macOS a iPadOS | kontrakt mostu verze 1 ([`native-bridge.md`](native-bridge.md), task 1), most ve frontendu se simulací pro vývoj (task 2) a zařízení s registrací a podepsanými voláními na serveru (`core.devices`, task 3) hotové 2026-11-27, skládání ESC/POS s katalogem profilů tiskáren a účtenkou prodejky (task 4a, [`prints.md`](prints.md) §13) 2026-11-28 — čeká na ověření na skutečné tiskárně; tiskové úlohy se směrováním podle role a tisk z UI (task 4b, [`prints.md`](prints.md) kap. 14) 2026-11-28 — ověřeno se simulací mostu; zbývá aplikace, profily a PIN, přihlášení přes systémový prohlížeč, vydávání (#177) |
| Windows, Android | bez zadání |

---

## Vědomě odložené

Věci, o kterých se rozhodlo, že se **nedělají teď** — ať se k nim nevracíme
v každé diskuzi.

| Co | Proč |
|---|---|
| Zásoby (příjemky, výdejky, přehledy, účtování A/B) | pro ostrý provoz nejsou potřeba; budou se dělat, návrh zatím neexistuje |
| Projektové funkce zakázek (faktura ze zakázky dílčí i konečná, termíny, přehled stavu se sčítáním podzakázek), výkazy práce, plánování | až po M4; evidence zakázek a periodická fakturace jsou v M4 (#110 D19) |
| Zálohové faktury a zúčtování záloh | totéž; saldokonto na ně počítá (`docs/accbal.md` §5, mimo Fázi 3) |
| Účetní závěrka (uzávěrka roku, rozvaha, výsledovka) | není potřeba pro přechod na ostro; přijde s prvním uzavíraným rokem |
| PostgreSQL driver | MariaDB stačí; abstrakce v `DatabaseManager` je připravená |
| Další LLM poskytovatelé kromě Anthropic | až bude důvod, backendy jsou abstrahované |

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

- **27. 11. 2026:** nativní aplikace se z Vědomě odložených přesunula do
  vlastního oddílu vedle Doplňků — mimo milníky, blokuje ji jen
  kategorie 1 (#177).
- **17. 11. 2026:** M1 uzavřen (#55); ověření výstupů DPH proti historii
  podání na kopiích reálných dat pokračuje v #158. Aktivní milník je M2.
- **16. 10. 2026:** M4 „AI modely“ — výchozí modely úrovní na řadě 5
  po evaluaci #103 (`ai-tiers-series5.md`); termín Sonnetu 4.6 přestal
  být blokátor.
- **8. 10. 2026:** v M4 nahrazeny Prodejní smlouvy Zakázkami s periodickou
  fakturací (#110 D1, D19) — evidence zakázek se z Vědomě odložených
  přesunula do M4, odložené zůstávají projektové funkce zakázek.
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
