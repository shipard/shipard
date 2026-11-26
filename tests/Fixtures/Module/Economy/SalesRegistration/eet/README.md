# Vzorové zprávy EET 2.0

Testovací vektory enginu `cz.eet2` (`Eet2EngineTest`). Převzaté beze změny
z knihovny [`ondrejnov/eet`](https://github.com/ondrejnov/eet), adresář
`tests/fixtures/`, commit `cd26c32b573b0560ab8fe35ad2f3b8ff8fe096f8`
(licence MIT). Kopie je tu proto, aby testy nezávisely na tom, jestli
instalace balíku adresář `tests/` nese.

| Soubor | Co to je |
|---|---|
| `CZ00000019.eet.v4.req.xml`, `CZ683555118.eet.v4.req.xml`, `CZ8551015704.eet.v4.req.xml` | oficiální vzorové podepsané zprávy finanční správy (eet.gov.cz → Pro vývojáře → Dokumenty ke stažení) pro tři testovací identity |
| `playground-confirmation.xml` | skutečné podepsané potvrzení playgroundu (POK končí `-ff`), zpráva `4a4b8444-dd2d-4cf1-9a45-1743f06cfb89` |
| `playground-verification.xml` | skutečná odpověď playgroundu v ověřovacím módu (chyba s kódem 0, nepodepsaná) |

## Stárnutí

Potvrzení je podepsané certifikátem playgroundu „NEOSTRE - playground
prostredi EET“ (vydavatel I.CA), platným **do 8. 6. 2027**. Verifier
s řetězem I.CA kontroluje platnost k dnešnímu dni — test
`Eet2EngineTest::testRealConfirmationPassesTheProductionVerifier` proto
začne padat 30 dní před koncem platnosti. Obnova: nové potvrzení
z playgroundu (živý test `Eet2PlaygroundTest` s `SHIPARD_EET_PLAYGROUND=1`
vypíše, jak ho získat), nebo nové fixtury z knihovny.

Ostatní testy převodu výsledků používají verifier bez řetězu (podpis a `O`),
na čase nezávisí.
