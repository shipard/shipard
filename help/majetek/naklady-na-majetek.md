---
title: Majetek na dokladech
summary: Jak přiřadit fakturu, pokladní nebo účetní doklad ke kartě majetku, jak z řádku pořízení rovnou založit kartu a kde na kartě uvidíš pořízení, náklady a výnosy.
keywords: [náklady na majetek, nákladů na majetek, sledovat náklady na majetek, výnosy z majetku, majetek na faktuře, majetek na dokladu, majetek na řádku, karta majetku na faktuře, přiřadit fakturu k majetku, oprava auta, servis stroje, náklady na auto, kolik stál majetek, pořízení majetku, pořízení majetku bez karty, řádek pořízení, účet 042, založit kartu z faktury, karta z faktury, majetek z hlavičky, majetek čeká na zařazení, zařadit z faktury, náklady a výnosy, otevřít v deníku, deník podle majetku, analytika majetku]
related: [majetek/evidence-majetku.md, majetek/odpisy-majetku.md, majetek/zauctovani-majetku.md, majetek/nastaveni-majetku.md, co-dnes-nejde.md]
---

# Majetek na dokladech

Doklad jde přiřadit ke **kartě majetku** — faktura za pořízení stroje,
oprava auta, pojistka, nájemné z pronajaté věci. Karta pak ukáže, čím byla
pořízena a kolik na ní za jednotlivé roky bylo nákladů a výnosů.

## Kdy to potřebuješ

Přišla faktura za majetek a chceš, aby karta věděla, čím byl pořízen —
a aby se z ní předvyplnilo zařazení. Nebo chceš sledovat, kolik stojí
provoz auta nebo stroje: opravy, servis, pojištění. Nebo hledáš v deníku
všechno, co se k jedné věci účtovalo.

## Postup

**Pořízení majetku na přijaté faktuře**

1. V **Faktury přijaté** otevři fakturu a na záložce **Řádky** přidej
   řádek s **Pohybem** *Pořízení majetku*.
2. Vyber **Účet** — účet pořízení (042…) u dlouhodobého majetku, nákladový
   účet (501…) u drobného majetku, který jde rovnou do nákladů.
3. V poli **Majetek** vyber kartu. Když ještě neexistuje, dej v nabídce
   **Vytvořit nový záznam**: karta se otevře s názvem podle textu řádku
   a s druhem podle účtu — *Dlouhodobý hmotný* u účtu pořízení, *Drobný
   majetek* u nákladového účtu (ten dostane i cenu ze základu řádku
   a datum pořízení z účetního data faktury). Doplň zbytek a ulož.
4. Dokonči fakturu a dej **V pořádku**.

Pole **Majetek** je na řádku pořízení vždy a není povinné — fakturu
uložíš i bez karty, Shipard na to ale upozorní (viz níže).

**Zařazení majetku z pořízení**

1. Otevři kartu v **Majetek → Majetek**. V **Přehledu** je sekce
   **Pořízení**: řádky faktur, které kartu nesou, s datem, účtem, částkou
   a součtem. Kliknutím na číslo dokladu si ho prohlédneš.
2. Dej **Zařadit**. **Vstupní cena** je předvyplněná součtem pořízení na
   účtu pořízení a **Datum** datem poslední faktury.
3. Uprav, co nesedí — typicky datum uvedení do užívání nebo cenu o DPH
   bez nároku na odpočet — a dej **Potvrdit**.

**Náklady a výnosy na majetek**

1. V **Nastavení aplikace → Majetek → Majetek na dokladech** nastav
   **Sledovat náklady na majetek** na *Ano*.
2. Na **Faktuře přijaté**, **Faktuře vydané**, **Pokladním dokladu**
   a **Účetním dokladu** se objeví pole **Majetek** — na hlavičce i na
   každém řádku.
3. Týká-li se celý doklad jedné věci, vyber kartu na hlavičce. Řádky bez
   vlastní karty ji převezmou; pole řádku to ukazuje nápisem *Z hlavičky: …*.
4. Týká-li se věci jen část dokladu, vyber kartu jen na tom řádku. Karta
   na řádku má přednost před kartou z hlavičky.
5. Po uložení dokladu **V pořádku** má karta v detailu záložku **Náklady
   a výnosy**: souhrn po účetních letech (náklady, výnosy, ostatní účty)
   a pod ním řádky deníku s odkazem na doklad.
6. Tlačítko **Otevřít v deníku** otevře **Účetní deník** vyfiltrovaný na
   tuto kartu přes všechny roky.

## Na co narazíš

**Pořízení je věc řádku.** Karta vybraná na hlavičce faktury se na řádek
*Pořízení majetku* nepřenáší — do sekce **Pořízení** se počítá jen karta
vybraná přímo na řádku.

**Upozornění „Pořízení majetku bez karty“.** Faktura ve stavu
**V pořádku** má řádek pořízení bez karty. Otevři ji, dej **Opravit**,
doplň kartu na řádek a vrať ji do **V pořádku**; upozornění zmizí samo.

**Upozornění „Majetek čeká na zařazení“.** Dlouhodobá karta má pořízení na
účtu pořízení, ale není zařazená — neodepisuje se. Zmizí po potvrzeném
zařazení. U majetku, který ještě není v užívání, je to v pořádku.

**Drobný majetek se nezařazuje.** Pořízení na nákladovém účtu se v sekci
**Pořízení** ukáže, ale **Zařadit** z něj nic nepředvyplní.

**Záložka Náklady a výnosy chybí.** Ukáže se až s prvním zaúčtovaným
řádkem, který kartu nese. Vlastní zaúčtování majetku (zařazení, odpisy,
vyřazení) v ní není — to je v plánu odpisů.

**V ostatních účtech uvidíš i DPH a závazek.** Když je karta na hlavičce
faktury, nesou ji i řádky deníku za DPH a za závazek vůči dodavateli;
náklady a výnosy jsou ve vlastních sloupcích.

**Vypnutím nastavení se nic neztratí.** Pole **Majetek** z dokladů zmizí,
ale už vybrané karty zůstanou a do deníku se propisují dál. Zálohová
faktura vydaná pole nemá.

**Záložka ukazuje nejvýš 200 posledních řádků.** Zbytek najdeš přes
**Otevřít v deníku**.

## Souvisí

- [Evidence majetku](evidence-majetku.md)
- [Odpisy majetku](odpisy-majetku.md)
- [Zaúčtování majetku](zauctovani-majetku.md)
- [Nastavení majetku](nastaveni-majetku.md)
- [Co Shipard dnes neumí](../co-dnes-nejde.md)
