# Evidence tržeb — specifikace doplňku

Doplněk `economy.salesRegistration` (#171). Tento dokument popisuje, **jak je
to udělané**; návod pro uživatele je v `help/`, zadání v
`tasks/sales-registration-phase1.md`.

## 1. Rozsah

Evidence tržeb podle zákona účinného od 1. 1. 2027 (EET 2.0, datové rozhraní
v4.1). Zatím Česko; model je stavěný tak, aby unesl další státy a víc
registrací různých typů v jednom zdroji dat (#171 D16).

| Fáze | Obsah | Stav |
|---|---|---|
| 1 | typ evidence, registrace a evidenční jednotky, certifikát, pojistka serveru, engine, Ověřit spojení, upozornění na certifikát | rozpracováno |
| 2 | tržby a pokusy, napojení na doklady, opakování cronem | — |
| 3 | Kontaktní platba, záložka a štítky na dokladu | — |

Mimo rozsah (#171 D14): `urceno_cerp_zuct` / `cerp_zuct`, pověřující
poplatník, automatická obnova certifikátu, potvrzovací kód na tisku, další
země.

## 2. Typ evidence (D2, D17)

Typ evidence má dvě poloviny, každou u jiného modulu:

- **data typu** deklaruje modul země — cfgItem
  `world.<země>.salesRegistrationTypes`, klíč = id typu (`cz.eet2`): název,
  `country`, `validFrom`, `currency`, `environments` (`production`,
  `playground` → `url`), masky `taxpayerId` (s popiskem — v Česku „EIČ“),
  `unitId`, `cashRegisterId`, `serialNumber`;
- **třídu engine** přiřazuje doplněk — cfgItem
  `economy.salesRegistration.engines`, `{"<id typu>": "<FQCN>"}`.

Modul jádra tak nejmenuje třídu doplňku (#170 D6) a doplněk na modulu země
nezávisí.

**Registr** `SalesRegistrationTypes::fromConfig()` je jediné čtení obou
cfgItemů. Skládá se od mapy enginů: země je první část id typu (`cz.eet2` →
`world.cz.salesRegistrationTypes`) — `ConfigRuntime` cfgItemy vyjmenovat
neumí a ani to není potřeba. Důsledky:

- typ bez enginu se nenabízí;
- záznam mapy, jehož modul země není ve zdroji dat aktivní, se ignoruje;
- vadná položka (žádné prostředí přes HTTPS, maska, která není regulární
  výraz, chybějící měna) se přeskočí — nic nespadne, registrace takového typu
  jen neprojde validací.

Masky jsou regulární výrazy bez oddělovačů, jak je píše XSD; kontroluje se
celá hodnota (`SalesRegistrationType::matches()`).

Rozhraní engine nepředpokládá online potvrzení každé tržby — výsledek je
obecný stav a kód potvrzení, data specifická pro typ jsou v doplňcích zprávy.
