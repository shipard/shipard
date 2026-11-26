# Testovací identita playgroundu EET 2.0

Klíč a certifikát **sdílené testovací identity** neprodukčního prostředí
evidence tržeb (playground, `pg.trzbyeet.gov.cz`). Doplněk s ní podepisuje
každou zprávu, která jde na playground — z pojistky serveru `playground`
i z registrace v režimu Playground (`PlaygroundIdentity`, #171 D9, U2).
Certifikát zdroje dat se na playground nikdy neposílá.

> **Není to tajemství.** Certifikáty finanční správa zveřejňuje pro všechny
> vývojáře, kteří rozhraní testují; potvrzení z playgroundu (kód končí `-ff`)
> není platné a nic se jím neeviduje. Proto smí být klíč v repozitáři.
> **Žádný jiný soukromý klíč sem nepatří.**

| Soubor | Obsah |
|---|---|
| `CZ00000019.key` | soukromý klíč v PEM (nešifrovaný) |
| `CZ00000019.pem` | certifikát v PEM — CN `CZ00000019` (testovací EIČ právnické osoby) |

## Zdroj

- Balík `CAEET_Playground_2026_v1.zip` ze stránky
  [eet.gov.cz → Pro vývojáře → Dokumenty ke stažení](https://eet.gov.cz/cs/pro-vyvojare/dokumenty-ke-stazeni):
  `https://eet.gov.cz/assets/cs/cmsmedia/pro-vyvojare/CAEET_Playground_2026_v1.zip`
- Staženo 2026-11-26, SHA-256 balíku
  `711ca591e2e58e906212befab55ea72cbc5d960af49e444765b0428751002491`.
- Z balíku je tu jen soubor `CA_EET-Playground-CZ00000019.p12` (převedený)
  a heslo k němu používá test importu. Balík nese ještě identity
  `CZ683555118` a `CZ8551015704` — stačí jedna.
- Do repozitáře jde **jen obsah veřejného balíku**. Dokument „Přístupové
  a provozní informace — playground“ je označený TLP:AMBER a jeho obsah sem
  ani jinam do repozitáře nepatří.

## Platnost

Certifikát platí **od 13. 5. 2026 do 14. 5. 2027** (sériové číslo
`15584C0A86BB6E65CC5C7097792CB26481A9A6AC`). Test
`PlaygroundIdentityTest::testCertificateIsValidForAtLeast30Days` začne padat
30 dní před koncem platnosti — to je signál k obnově, ne k úpravě testu.

## Převod

Soubory `.p12` z balíku používají zastaralou šifru RC2-40, proto `-legacy`
(OpenSSL 3). Heslo je v balíku v souboru `password_pokladni_cert_playground.txt`.

```sh
openssl pkcs12 -legacy -in CA_EET-Playground-CZ00000019.p12 \
    -passin file:password_pokladni_cert_playground.txt -nocerts -nodes \
  | sed -n '/-----BEGIN PRIVATE KEY-----/,/-----END PRIVATE KEY-----/p' > CZ00000019.key
openssl pkcs12 -legacy -in CA_EET-Playground-CZ00000019.p12 \
    -passin file:password_pokladni_cert_playground.txt -nokeys -clcerts \
  | sed -n '/-----BEGIN CERTIFICATE-----/,/-----END CERTIFICATE-----/p' > CZ00000019.pem
```

## Obnova

1. Stáhnout nový balík ze stránky Dokumenty ke stažení (jméno souboru se
   s rokem mění) a ověřit, že pochází z `eet.gov.cz`.
2. Převést podle postupu výše a přepsat oba soubory; jiné EIČ = upravit
   `PlaygroundIdentity::FILES`.
3. Nahradit i původní `.p12` ve fixturách testů
   (`tests/Fixtures/Module/Economy/SalesRegistration/`) a údaje
   v `TestCertificates` (heslo, EIČ, okamžik uvnitř platnosti, sériové číslo).
4. Aktualizovat v tomto README zdroj, datum stažení, otisk a platnost.
5. `vendor/bin/phpunit --filter 'SalesRegistration'` a ruční Ověřit spojení
   na serveru s pojistkou `playground`.
