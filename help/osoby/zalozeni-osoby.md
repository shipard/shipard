---
title: Založení osoby
summary: Jak přidat dodavatele nebo odběratele — natažením české firmy z registru podle IČO, nebo ručně.
keywords: [osoby, osoba, nová osoba, založit osobu, přidat osobu, dodavatele, dodavatel, odběratele, odběratel, partner, protistrana, firma, fyzická osoba, IČO, DIČ, z registru, natáhnout firmu, přidat firmu z registru, zahraniční dodavatel, slovenská firma, německá firma, firma z EU, vlastní firma, chybí vlastní osoba, splatnost, kontakty, adresy, bankovní účet osoby, kód osoby, jazyk dokumentů, jazyk faktury pro odběratele, faktura anglicky, doklad v angličtině, přílohy do PDF dokladu]
related: [osoby/kontakty-a-ucely.md, posta/kontrola-vytezeni.md, faktury-vydane/tisk-faktury.md, co-shipard-umi.md, slovnicek.md]
---

# Založení osoby

**Osoby** jsou jedna evidence pro firmy i fyzické osoby — dodavatele,
odběratele i kohokoli dalšího. Najdeš je v **Základní → Osoby**.

## Kdy to potřebuješ

Potřebuješ do systému partnera, kterého tam ještě nemáš. Nebo ti Shipard
u vytěžené faktury nabízí u dodavatele **Vytvořit novou osobu** a ty chceš
vědět, co se tím založí. Nebo ti karta **Dokončit nastavení** na Dashboardu
hlásí, že chybí vlastní Osoba.

## Postup

**České firmě údaje nepřepisuj ručně — natáhni ji z registru.**

1. V **Základní → Osoby** dej **Z registru**. Otevře se dialog **Přidat
   firmu z registru**.
2. Do vyhledávání napiš **IČO nebo název firmy**. Registr zná **jen
   české firmy** — zahraničního partnera zadej ručně (níž).
3. Vyber firmu z výsledků a dej **Pokračovat**.
4. Projdi náhled: **Základní údaje** (**Název**, **IČO**, **DIČ**, **DIČ
   pro DPH**), **Sídlo** a **Související záznamy** — tam je vidět, kolik
   se s firmou přinese adres, bankovních účtů a kontaktů.
5. Dej **Uložit**. Osoba i všechny její záznamy vzniknou najednou.

**Fyzickou osobu, zahraniční firmu — a českou firmu, kterou registr
nezná — zadáš ručně.**

1. Dej **Přidat** a vyber **Typ osoby**: *Firma*, nebo *Fyzická osoba*.
   Formulář se podle toho překlopí, tak si typ zvol jako první.
2. **U firmy** vyplň **Celý název** (povinný) a v sekci *Identifikace
   firmy* **IČO** a **DIČ**. **U fyzické osoby** jsou povinné **Jméno**
   a **Příjmení**; navíc je tam sekce *Osobní údaje*.
3. Doplň, co máš, do sekce *Kontaktní údaje* — **E-mail**, **Telefon**,
   **Webová stránka**.
4. Ulož: **Uložit jako koncept**, nebo **V pořádku**. Na dokladech se
   osoba nabízí v obou stavech.

## Na co narazíš

**Kód osoby vyplňovat nemusíš.** Shipard ho vygeneruje sám —
firmy dostanou `F` a číslo, fyzické osoby `O` a číslo. Najdeš ho na tabu
**Nastavení**.

**Kontakty, Adresy a Bankovní účty jsou taby na osobě.** Nejsou to
samostatné evidence; přidávají se až k uložené osobě.

**Zápis údajů Shipard při uložení sjednotí.** **IČO** dostane vedoucí
nuly, **DIČ** a **DIČ pro DPH** velká písmena a bez mezer, **PSČ** bez
mezery, **Telefon** s předvolbou státu (`+420 577 123 456` se uloží jako
`+420577123456`; pole s více čísly nebo s linkou zůstane, jak je zapsané),
**IBAN** a **BIC/SWIFT** velkými bez mezer a **Číslo
účtu** bez mezer a vedoucích nul. Zapíšeš-li IBAN do pole **Číslo účtu**,
přesune se do **IBAN** a u českého nebo slovenského účtu se číslo účtu
dopočítá. Ve formuláři pak vidíš uložený tvar; na faktuře a v detailu
osoby se PSČ, IBAN a telefon tisknou čitelně (`760 01`,
`CZ65 0800 0000 1920 0014 5399`, `+420 577 123 456`).

**Bankovní účet stačí s IBAN.** Zahraniční účet nemá české číslo účtu —
vyplň jen **IBAN** (a BIC/SWIFT), pole **Číslo účtu** nech prázdné.
Vyplněný musí být aspoň jeden z údajů **Číslo účtu** nebo **IBAN**; bez
obou se účet neuloží. Účet bez čísla se v nabídce účtů partnera i na
dokladu ukazuje IBANem.

**Neplatné IČO, DIČ, PSČ nebo účet Shipard pozná.** Kontroluje kontrolní
součet IČO (české a slovenské firmy), tvar DIČ podle státu, PSČ, číslo
účtu, IBAN a BIC, a to, zda DIČ odpovídá IČO a IBAN číslu účtu. U partnera
se záznam uloží a nad formulářem se objeví žlutý pruh **Uloženo, ale
zkontroluj:** s popisem, co nesedí — u nové adresy nebo účtu zůstane
dialog otevřený, aby šlo hodnotu rovnou opravit. U **Vlastní firmy**, jejích
adres a účtů a u vlastních účtů v **Nastavení** se chybná hodnota neuloží
vůbec — jde na doklady, do QR platby a do e-faktury. Zahraniční IČO se
nekontroluje, telefon a e-mail také ne.

**Číslo účtu, které „neprojde kontrolou mod 11“, je jen upozornění.**
Mod 11 je pravidlo, podle kterého banky čísla účtů tvoří; některé
zveřejněné účty ho přesto nesplňují. Shipard proto takové číslo uloží
i u **Vlastní firmy** a vlastních účtů, jen ho označí žlutým pruhem
s textem *neprojde kontrolou mod 11 — ověřte ho*. Zkontroluj číslo
proti smlouvě nebo výpisu; pokud je opsané správně, nech ho být. Když
k účtu zapíšeš i IBAN, hlídá se navíc, že IBAN a číslo účtu jsou týž
účet — jiný účet v IBAN je u vlastní firmy chyba.

**Registr existující osobu nepřepíše.** Když ve výsledcích hledání vidíš
u firmy odznak **Firma již existuje**, znamená to, že ji v evidenci máš.
Vybrat a uložit ji můžeš, ale **nic se nedoplní ani nepřepíše** — dostaneš
odkaz na záznam, který už máš. Chybějící údaje u dříve založené osoby si
proto doplň ručně.

**Odznak UKONČENO s datem** ve výsledcích znamená, že subjekt v registru
zanikl. Založit ho jde — historické doklady na zaniklou firmu jsou
normální — ale u nové spolupráce je to varování.

**Osoba z vytěžené faktury má jen to, co na faktuře bylo.** Typicky
název, IČO, DIČ a bankovní účet, ze kterého se bude platit. Sídlo,
kontakty ani další účty na faktuře nejsou, takže na osobě nebudou —
doplň si je, až je budeš potřebovat.

**Dodavatele z faktury Shipard hledá v tomhle pořadí:** **IČO**, pak
**DIČ pro DPH**, pak **DIČ**, a teprve nakonec podle **názvu**. Proto se
při kontrole vytěženého dokladu vyplatí kontrolovat právě IČO — podle
názvu se dá splést víc firem, podle IČO ne. Když Shipard najde víc
kandidátů nebo žádného, zeptá se tě: **Vybrat existujícího…** nebo
**Vytvořit novou osobu**. Když vytěžené IČO najde ve veřejném registru
a osobu ještě nemáš, nabídne navíc **Vytvořit z registru: název** —
jeden klik a dodavatel vznikne rovnou z registru, viz
[Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md).

**Splatnost (dny)** na tabu **Nastavení** je předplněná na 14. Z ní se na
faktuře dopočítá **Datum splatnosti** jako datum vystavení plus tolik dní,
takže se vyplatí ji u partnerů, se kterými máš dohodnuto něco jiného,
opravit.

**Jazyk dokumentů nastavuj, jen když automatika nevyhovuje.** Na tabu
**Nastavení** v sekci *Dokumenty* je **Jazyk dokumentů** s výchozí volbou
**Automaticky (podle země)**: doklad se tiskne v jazyce země z adresy
odběratele na dokladu. Jinou volbu — *čeština*, *angličtina*, *slovenština*,
*němčina* — dej tam, kde to nesedí, třeba u české firmy, která chce doklady
anglicky. Platí hned i pro už vystavené doklady: příští tisk je v novém
jazyce — viz [Tisk faktury](../faktury-vydane/tisk-faktury.md).

**Přílohy dokladu připojit do PDF dokladu** ve stejné sekci platí pro
odesílání e-mailem: PDF přílohy dokladu se přidají za doklad do jednoho
souboru, ostatní přílohy jdou dál zvlášť — viz
[Odeslání faktury e-mailem](../faktury-vydane/odeslani-faktury.md).

**Na které e-maily faktury chodí,** určují kontakty osoby a jejich účely —
viz [Kontakty a účely odesílání](kontakty-a-ucely.md). Bez kontaktu
s účelem jdou na **E-mail** osoby.

**Vlastní firma se zakládá z panelu Nastavení zdroje dat.** U čerstvého
datového zdroje ti Dashboard ukáže kartu **Dokončit nastavení**, která
panel otevře. U položky **Chybí vlastní Osoba** dej **Načíst z registru**
— otevře se dialog **Načíst vlastní firmu z registru**, vyhledej svou firmu
podle IČO a ulož. Vznikne najednou i se sídlem, bankovními účty a DIČ
a rovnou označená jako **Vlastní firma** (zaškrtnutí na tabu **Nastavení**).
Firmu, kterou registr nezná, zadáš přes **Zadat ručně** — formulář se otevře
se zaškrtnutou **Vlastní firmou** předem. Bez vlastní firmy systém neví,
pod čí hlavičkou doklady vznikají, takže je to první věc, kterou v novém
datovém zdroji udělej.

## Souvisí

- [Kontrola vytěženého dokladu](../posta/kontrola-vytezeni.md) — kde se
  dodavatel potvrzuje nebo zakládá z vytěžené faktury
- [Tisk faktury](../faktury-vydane/tisk-faktury.md) — v jakém jazyce se
  doklad odběrateli vytiskne
- [Co Shipard umí](../co-shipard-umi.md) — přehled ostatních agend
- [Slovníček](../slovnicek.md) — co znamenají stavy a názvy v rozhraní
