# Přehled funkcí

Mapa rozsahu Nového Shipardu: co aplikace umí, co umí částečně a kam směřuje.
Odpovídá na otázku **„co všechno"** — pořadí určuje [roadmap.md](roadmap.md),
uživatelský popis existujícího drží [help/co-shipard-umi.md](../help/co-shipard-umi.md).

Legenda: `[x]` hotovo · `[ ] 🟠` částečně · `[ ]` plánováno

---

## Prostředí

- [x] Dashboard — feed práce k vyřízení (nová pošta, upozornění, rozdělané doklady)
- [x] Chat — AI asistent nad daty a nápovědou (jen čtení)
- [x] Upozornění — automatické kontroly jako karty na Dashboardu
- [x] Úkoly — samostatná evidence
- [ ] 🟠 Anglické rozhraní — existuje, nepřeložené popisky

## Pošta a AI vytěžení

- [x] Příjem dokumentů e-mailem na adresu datového zdroje
- [x] AI vytěžení faktury a účtenky → návrh dokladu ke kontrole
- [x] Rozpoznání dokumentu pro Spisovnu z došlé pošty
- [x] Přímý import ISDOC bez AI
- [x] Zohlednění historie partnera při vytěžení
- [ ] 🟠 Opakovaná analýza — jen u zprávy analyzované nebo selhané
- [x] Ruční nahrání dokumentu do pošty — tlačítko Nahrát a drag-n-drop na Dashboardu
- [ ] Zobrazení adresy pro příjem pošty v aplikaci

## Spisovna

- [x] Evidence dokumentů v šanonech
- [x] Zařazení z došlé pošty — AI návrh i ručně
- [x] Hlídání platnosti (Platí do + upozornění)
- [x] Ruční založení dokumentu s přílohou mimo poštu

## Osoby a položky

- [x] Osoby — dodavatelé, odběratelé i fyzické osoby v jedné evidenci
- [x] Doplnění firmy z veřejného registru podle IČO
- [x] Kontakty, adresy, bankovní spojení
- [x] Položky — evidence s druhy a měrnými jednotkami

## Nákup a prodej

### Faktury přijaté

- [x] Vznik z došlé pošty i ručně, stavy Koncept → V pořádku
- [x] Zaúčtování a promítnutí do saldokonta

### Faktury vydané

- [x] Ruční vystavení, číselné řady, rekapitulace DPH, zaúčtování
- [ ] 🟠 Odeslání odběrateli e-mailem — odchozí pošta existuje, chybí napojení z dokladu s PDF
- [ ] Zálohové faktury a zúčtování záloh

### Prodej

- [x] Prodejky

## Zásoby

- [ ] Příjemky a výdejky
- [ ] Počáteční stavy zásob
- [ ] Skladové přehledy — stavy a pohyby po položkách
- [ ] Účtování zásob metodou A i B

## Zakázky

- [ ] Evidence zakázek — periodické, externí jednorázové, interní (režie i výrobní příkazy), číselné řady, nadřazená zakázka
- [ ] Periodická fakturace — nájemné a služby od konceptu ke kontrole po automatické odeslání (nahrazuje prodejní smlouvy)
- [ ] Přiřazení dokladů a pohybů k zakázce
- [ ] Účetní vyhodnocení zakázky — náklady a výnosy po zakázkách, se sčítáním podzakázek
- [ ] Faktura ze zakázky — dílčí a konečná fakturace

## Pokladna

- [x] Pokladní doklady — příjmové a výdajové
- [ ] Pokladní kniha

## Účtárna

- [x] Účetní doklady — ruční zápis MD/DAL s kontrolou vyrovnanosti
- [x] Účetní deník — účty se skládají automaticky z dokladů a transakcí
- [x] Účtový rozvrh ze šablon (podnikatel / nezisková organizace) s úpravami
- [ ] 🟠 Dimenze dokladů a deníku — majetek existuje; středisko a zakázka plánované
- [ ] Účetní závěrka — uzávěrka roku, rozvaha, výsledovka

## Banka a saldokonto

- [x] Import bankovních výpisů — CAMT, GPC, FIO
- [x] Párování plateb s fakturami, clearing účet
- [x] Saldokonto — pohyby po partnerech
- [ ] Platební příkazy
- [ ] Přímé API napojení na banky

## DPH

- [x] Evidence — období DPH, analytiky per kód DPH, sazby
- [ ] 🟠 Přiznání k DPH — živý výpočet z dokladů existuje; chybí podání a XML pro daňový portál
- [ ] 🟠 Kontrolní hlášení — sekce A/B, limity spočítané; chybí podání a XML
- [ ] 🟠 Souhrnné hlášení — živý výpočet; chybí podání a XML
- [x] Instance daňových tvrzení — období per typ výstupu s denní přesností
- [ ] Uzamčení období DPH po podání

## Majetek

- [ ] Evidence majetku
- [ ] Odpisy

## Tisk a výstupy

- [ ] Tisk / PDF dokladu
- [ ] Šablony dokladů
- [ ] Logo a podpis na dokladech

## Nastavení a přístupy

- [x] Číselné řady, druhy položek, měrné jednotky
- [ ] Samoobslužná správa uživatelů a přístupových práv

## Provoz a data

- [ ] 🟠 Import ze starého Shipardu — existuje, spouští vývojář; ověření beze ztráty na ostrých datech
- [ ] Záloha a obnova datového zdroje uživatelem
- [ ] Smazání datového zdroje z rozhraní
- [ ] Rate limiting a evidence neúspěšných přihlášení

---

## Vědomě mimo rozsah

Rozhodnuto, že se **nedělá** — ať se k tomu nevracíme.

| Co | Proč |
|---|---|
| Maloobchod / kasa, jídelna, rezervace, IT síť | mimo záběr účetní aplikace |
| Štítky | rozhodnuto nezavádět |
| Hromadné e-maily | mimo záběr |
| Mobilní nativní aplikace | responzivní web pokrývá potřebu |

---

## Jak se přehled udržuje

- Revize **po dokončení každého milníku**, společně s roadmapou; průběžné
  změny patří do tasků.
- Položka je `[x]` až když funguje celá; rozpracované a omezené věci jsou `[ ] 🟠`.
- Žádné citlivé údaje z reálných dat — dokument je ve veřejném repozitáři.

---

[← docs/README.md](README.md) · [roadmap.md](roadmap.md) · [help/co-shipard-umi.md](../help/co-shipard-umi.md)
