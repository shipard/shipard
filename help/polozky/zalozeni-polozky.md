---
title: Založení položky
summary: Jak přidat položku do katalogu, co je povinné, co Shipard doplní sám a co na položce vědomě není.
keywords: [položky, položka, nová položka, založit položku, přidat položku, katalog položek, druh položky, typ položky, jednotka, prodejní cena, cena položky, kód položky, SKU, EAN, ceník, sazba DPH u položky, účet u položky, účetní položka]
related: [posta/kontrola-vytezeni.md, co-shipard-umi.md, slovnicek.md]
---

# Založení položky

Položka je to, co se fakturuje — jeden řádek dokladu je jedna položka.
Katalog najdeš v **Základní → Položky**.

## Kdy to potřebuješ

Fakturuješ nebo přijímáš na faktuře něco, co v katalogu ještě není. Nebo
ti Shipard u vytěžené faktury z pošty nabízí u řádku **Vytvořit novou**
a ty chceš vědět, co se tím vlastně založí.

## Postup

1. **Otevři Základní → Položky** a dej **Přidat**.

2. **Vyplň Název.** Jediné pole, které za tebe Shipard nedoplní — a to,
   které se pak objeví na dokladu.

3. **Zkontroluj Druh položky.** Povinný, u nové položky předvybraný na
   **Ostatní**. Zakládat nic nemusíš: Shipard má připravené čtyři druhy,
   **Služba**, **Zásoba**, **Účetní položka** a **Ostatní**, a ty na
   začátek stačí. Vlastní druhy — pojmenované kbelíky jako *Konzultace IT*,
   *Materiál* nebo *Energie* — si můžeš přidat v Nastavení, když chceš
   položky členit podrobněji. Podle druhu se sám vyplní **Typ položky**;
   ten se zadat přímo nedá.

4. **Zkontroluj Jednotku.** Povinná, u nové položky předplněná na **Kus**.

5. **Cenu vyplň, jen když ji máš.** **Prodejní cena bez DPH** povinná
   není — u položek, které jen přijímáš na fakturách od dodavatelů, nemá
   co dělat.

6. **Ulož.** Tlačítka jsou **Uložit jako koncept** a **V pořádku**.
   Na dokladech se položka nabízí v obou stavech, takže tě Koncept
   nezdrží.

Nepovinné věci, které se snadno přehlédnou: **Popis** má vlastní tab,
**Kód** je na tabu **Nastavení** a **Platnost od** / **Platnost do**
v sekci *Platnost* na prvním tabu.

## Na co narazíš

**Připravené druhy se jmenují stejně jako typy.** Je jeden na každý typ,
proto ta shoda — druh *Služba* má typ *Služba*. Jak si založíš vlastní
druh, název a typ se rozejdou: *Konzultace IT* může být typu *Služba*.

**Typ položky se u používaného druhu už nezmění.** Dokud druh nemá ani
jednu položku, typ mu přepsat můžeš. Jak ho jednou něco používá, Shipard
změnu odmítne — typ ovlivňuje, jak se položka chová na dokladu, a měnit
ho pod hotovými doklady by přepsalo historii. Potřebuješ-li jiný typ,
založ nový druh.

**Sazba DPH na položce není.** Zadává se na řádku dokladu, ne tady. Táž
položka tak může jít na doklad v různých sazbách.

**Cena je jen jedna, jen bez DPH a jen v korunách.** Cena s daní, cena
v cizí měně ani ceníky v Shipardu nejsou — cenu na konkrétním dokladu
přepíšeš přímo na řádku.

**Sekce Účetnictví se objeví jen u typu Účetní položka.** Jen u tohoto
typu má položka pole **Účet**; u ostatních typů se sekce vůbec
nezobrazí — a je to tak správně. **U běžné faktury účet neurčuje
položka, ale Pohyb řádku** (*Nákup zboží a materiálu*, *Nákup služeb*,
*Ostatní nákup*, u vydaných *Prodej služeb*, *Prodej zboží*). Účet
z položky se použije jedině u řádku s pohybem **Účetní položka**.
Pohyb se na řádku předvolí podle **typu položky**, kterou vybereš —
*Služba* dá nákup nebo prodej služeb, *Zásoba* zboží, *Účetní položka*
pohyb *Účetní položka*; změnit ho můžeš.

**Kód nevyplňuj, pokud nemusíš.** Necháš-li ho prázdný, Shipard
vygeneruje vlastní. Zadaný kód musí být v katalogu jedinečný; kód se
ukazuje ve výběru položky na dokladu před názvem, takže se vyplatí jen
tam, kde podle něj skutečně hledáš.

**SKU a EAN jsou pro importy.** Slouží k napárování položek při přenosu
dat odjinud. Když nic neimportuješ, nech je prázdné.

**Položku nemaž — ukonči jí platnost.** **Ukončit platnost** ji převede
do stavu **V archívu**: ve výběru na dokladu se přestane nabízet, ale
doklady, které ji už mají, zůstanou v pořádku. Smazat sice jde, ale
u položky, která je na hotovém dokladu, to nedělej.

**Položky vznikají i z došlé pošty.** Když AI na faktuře najde řádek,
který nemá v katalogu obdobu, nabídne ti u něj **Vytvořit novou** —
založí se tím položka se stejnými poli jako tady a rovnou se na doklad
připojí. Podrobnosti jsou v
[Kontrole vytěženého dokladu](../posta/kontrola-vytezeni.md).

## Souvisí

- [Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md) — kde se
  položky zakládají z vytěžené faktury
- [Co Shipard umí](../co-shipard-umi.md) — přehled ostatních agend
- [Slovníček](../slovnicek.md) — co znamenají stavy a názvy v rozhraní
