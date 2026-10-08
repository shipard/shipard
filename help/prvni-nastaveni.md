---
title: První nastavení zdroje dat
summary: Jak čerstvý zdroj dat nastavit přes kartu Dokončit nastavení — vlastní firma, DPH, bankovní účet, účtová osnova, fiskální rok a domácí měna.
keywords: [první nastavení, dokončit nastavení, průvodce nastavením, nastavení zdroje dat, vlastní firma, vlastní osoba, sídlo firmy, agenda DPH, plátce DPH, registrace DPH, bankovní účet, účtová osnova, fiskální rok, domácí měna, checklist nastavení, chybějící nastavení]
related: [zaciname.md, o-zdroji-dat.md, osoby/zalozeni-osoby.md]
---

# První nastavení zdroje dat

Čerstvě založený zdroj dat neví, kdo jsi — a bez toho v něm nedokončíš
žádný doklad. Karta **Dokončit nastavení** na Dashboardu tě dovede
k panelu, kde chybějící nastavení doplníš.

## Kdy to potřebuješ

- Právě sis založil zdroj dat a na Dashboardu svítí karta **Dokončit
  nastavení**.
- Karta se objevila později — třeba proto, že vlastní firma nemá sídlo
  nebo ses zatím nerozhodl o účtové osnově.
- Nejde ti dát doklad do **V pořádku** a hláška mluví o chybějící vlastní firmě,
  Registraci DPH nebo bankovním účtu.

## Postup

1. Na Dashboardu klikni na kartě **Dokončit nastavení** na **Otevřít
   nastavení**. Totéž místo najdeš kdykoli i ručně: **Nastavení
   aplikace** → sekce **Aplikace** → **Nastavení zdroje dat**.
2. Panel ukazuje seznam nastavení, která ještě chybí. Jdi odshora dolů —
   pořadí odpovídá závislostem (bez vlastní firmy nemá smysl řešit její
   sídlo a bez rozhodnutí o agendě DPH zase Registraci DPH).
3. **Chybí vlastní Osoba** — tvoje firma, pod kterou zdroj dat vede
   účetnictví. Klikni na **Načíst z registru**, zadej IČO a údaje se
   natáhnou samy; **Zadat ručně** je záložní cesta pro firmy, které
   v registru nejsou.
4. **Vlastní Osoba nemá sídlo** — adresa sídla se tiskne na doklady.
   Když ji registr nedodal, doplň ji ručně.
5. **Nerozhodnutá agenda DPH** — vyber **Vede agendu DPH** (plátce
   a identifikované osoby), nebo **Nevede (neplátce)**. Volba řídí
   výchozí režim DPH nových dokladů a to, jestli v navigaci uvidíš
   agendu DPH.
6. **Chybí Registrace DPH** — jen když firma agendu DPH vede. Tlačítko
   **Založit Registraci DPH** předvyplní formulář z DIČ vlastní firmy;
   do **Platí od** patří datum z rozhodnutí o registraci k DPH, ne
   dnešek.
7. **Chybí vlastní bankovní účet** — na vydanou fakturu jde účet
   z číselníku. **Převzít z vlastní Osoby** nabídne bankovní spojení
   firmy k zaškrtnutí; účty zveřejněné v Registru DPH jsou předvybrané.
8. **Nezvolena účtová osnova** — **Podnikatelská**, **Nezisková
   organizace**, nebo **Žádná (vlastní osnova)**. Po volbě se osnova
   založí i s účty.
9. **Nezvolen první měsíc fiskálního roku** a **Nezvolena domácí
   měna** — pro většinu firem leden a CZK. Po obou rozhodnutích se
   založí fiskální roky.

Položky mizí samy, jakmile je doplníš. Až zmizí všechny, zmizí
i karta z Dashboardu a panel hlásí, že je vše potřebné nastavené.

## Na co narazíš

- **Nic z toho není blokující** — panel můžeš kdykoli opustit a vrátit
  se. Bez vlastní firmy ale nedokončíš žádný doklad, bez Registrace DPH
  doklad s DPH a bez bankovního účtu vydanou fakturu. Začni proto
  vlastní firmou.
- **Položky nejde odklikat ani odložit.** Karta i seznam zmizí jedině
  tím, že nastavení doplníš — jinak by ti chybějící věci tiše utekly.
- **Účtová osnova se po založení nepřepíná** — účty už jsou ve zdroji
  dat. Vybírej s rozmyslem; vrácení volby na **Nerozhodnuto** osnovu
  nemaže.
- **Domácí měna platí jen pro nové záznamy** — existující doklady
  a fiskální roky mají měnu uloženou.
- **Agenda DPH není totéž co plátcovství.** Volba řídí jen výchozí
  chování a navigaci; o skutečném plátcovství rozhodují Registrace DPH
  a jejich platnost.
- Rozhodnuté parametry najdeš v panelu ve složené části **Už
  rozhodnuto** — a tam je můžeš i změnit.
- Sekce **Volitelné** dole nabízí startovní obsah — třeba sadu
  **Účetních položek** pro účtování přímo z řádku dokladu, ušitou na
  zvolenou účtovou osnovu. Nic z toho nechybí a nikde nesvítí, jen to
  šetří ruční práci.

## Souvisí

- [Začínáme](zaciname.md) — co dělat po prvním přihlášení
- [Informace o zdroji dat](o-zdroji-dat.md) — kde nastavené údaje
  zkontroluješ na jednom místě
- [Založení osoby](osoby/zalozeni-osoby.md) — stejné natažení z registru
  pro dodavatele a odběratele
