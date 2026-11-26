# Evidence tržeb — fáze 1: základ doplňku, registrace, certifikát, odeslání (#171)

**Stav:** hotovo — kód, testy a dokumentace hotové 2026-11-26 v šesti commitech (#171 D1–D3, D9–D12, D16, D17, U1–U4), ověřeno živě proti playgroundu a ručním proklikem 2026-11-26; chybějící body rozšíření v #178

> Task pro Claude Code (6 commitů). Design: issue #171 (EET 2.0). Staví na
> bodech rozšíření z #172 (doplňky, `serverConfig`, `recordActions`,
> `alertChecks`). Fáze 2 = tržby a pokusy, napojení na doklady, opakování
> cronem; fáze 3 = Kontaktní platba a UI na dokladu. Tady **žádná vazba na
> doklady**.

## Kontext

**EET 2.0** (datové rozhraní v4.1, povinnost od 1. 1. 2027): synchronní
SOAP 1.1 + WS-Security přes HTTPS (HTTP/1.1, TLS ≥ 1.2), operace
`OdeslaniTrzby`, namespace `http://fs.gov.cz/eet/schema/v4`. Zpráva:
hlavička `uuid_zpravy`, `dat_odesl`, `prvni_zaslani`, `overeni`; data
`eic_popl`, `id_jednotky` (evidenční jednotka z DIS+), `id_pokl`,
`porad_cis`, `dat_trzby`, `celk_trzba` (+ nepovinné). Podepisuje se jen
`soap:Body` pokladním certifikátem (`.p12` od CA EET, CN = EIČ, platnost
366 dní). Úspěch = POK (podepsané potvrzení), ověřovací mód (`overeni=true`)
vrací chybu s kódem 0 bez POK a nic neeviduje. Chyby: -1 a 8 = zkusit
později, ostatní kritické; varování 1–7 (např. 1 = EIČ zprávy ≠ EIČ
certifikátu). Prostředí: playground `pg.trzbyeet.gov.cz` (POK končí `-ff`,
neplatný), produkce `trzbyeet.gov.cz`; cesta `/eet/services/EETServiceSOAP/v4`.
Produkční adresu ověř v dokumentu „Přístupové a provozní informace —
produkce“ na eet.gov.cz (Pro vývojáře → Dokumenty ke stažení) a zapiš do
implementačních poznámek.

**Knihovna `ondrejnov/eet`** (master od 2026-11-01, poslední commit
`cd26c32`): PHP 7.0–8.5, `ext-curl`, `ext-dom`, `ext-openssl`, bez
`ext-soap`. API: `Certificate::fromPem($keyPath, $certPath, $pass)` /
`fromPkcs12($path, $pass)` — **jen cesty k souborům**;
`new Dispatcher($url, $certificate, $verifier, $transport)`;
`send(Receipt)` → POK, `check(Receipt)` → bool (ověřovací mód);
`getWarnings()`, `getLastResponse()` (`transactionId`, `errorCode`, …),
`getLastRequest()`, `getLastResponseBody()`, `getLastResponseTime()`;
výjimky `ValidationException` (nic neodešlo), `TransportException` (stav
neznámý), `ServerException` (`isRetryable()` pro -1 a 8),
`ResponseVerificationException` (odpovědi nelze věřit).
`ResponseVerifier::forPlayground()` = řetěz I.CA z `resources/ica-signing-ca.pem`
+ `O = Generální finanční ředitelství`. README: ověřeno jen proti
playgroundu; `.p12` z playgroundu mají šifru RC2-40, kterou OpenSSL 3
bez `-legacy` odmítne.

## Rozhodnutí (#171)

- **D1** — doplněk `economy.salesRegistration` („Evidence tržeb“, `"kind": "addon"`).
- **D2 + D17** — data typu evidence deklaruje země (`world.cz` → cfgItem
  `world.cz.salesRegistrationTypes`, typ `cz.eet2`); **třídu engine k typu
  přiřazuje doplněk** (`economy.salesRegistration.engines`), aby modul
  jádra nejmenoval třídu doplňku (#170 D6). Rozhraní engine nepředpokládá
  online potvrzení každé tržby.
- **D3** — registrace (typ, identifikátor poplatníka, režim, platnost,
  certifikát) a podřízené evidenční jednotky.
- **D9** — pojistka serveru po vzoru `mail.safety`: sekce `salesRegistration`
  v `server.json` (deklaruje doplněk, #172 D7), režimy `off` / `playground` /
  `drop`; bez sekce posílá jen produkční server, jinak `drop`; zdroj dat ji
  nepřepíše; `playground` posílá se sdílenou testovací identitou, nikdy
  s certifikátem zdroje dat.
- **D10** — režim registrace: ostrý / ověřovací / playground.
- **D11** — `.p12` + heslo při nahrání převést na PEM; klíč šifrovaně
  (`encrypted_text`, `DsSecretCipher`), heslo se neukládá; EIČ (CN)
  a platnost do sloupců; záložní `openssl pkcs12 -legacy` přes `proc_open`
  s heslem na stdin.
- **D12** — knihovna `ondrejnov/eet` (pinovaný commit) za vlastním rozhraním.
- **D14** — mimo fázi: `urceno_cerp_zuct` / `cerp_zuct`, pověřující
  poplatník, automatická obnova certifikátu, POK na tisku, další země.
- **D16** — víc registrací různých typů v jednom zdroji dat (např. CZ EET
  + později slovenská evidence pro slovenské DIČ české firmy): identifikátor
  poplatníka je obecný sloupec (`taxpayer_id`, význam podle typu), výběr
  registrace pro doklad (fáze 2) podle pravidel typu. Nic z toho se teď
  nestaví navíc — jen se to nesmí zavřít.
- **O1 (otevřené)** — měna tržby a slučitelnost měny dokladu s typem
  evidence se řeší obecně u dokladů (měna na číselné řadě, u prodejky
  z pokladny), ne tady. Fáze 1 z dokladů nic neposílá; `currency: czk`
  u typu `cz.eet2` je jen údaj typu.
- **U1** — knihovna čte klíč jen ze souboru → pro každé odeslání dočasný
  soubor `0600` v privátním adresáři zdroje dat, smazaný hned po načtení
  (`finally`).
- **U2** — sdílené testovací certifikáty playgroundu jsou v repozitáři
  (veřejně publikované finanční správou pro všechny testery); u nich README
  se zdrojem a platností a test, který začne padat 30 dní před koncem
  platnosti (hlídání externích artefaktů obecně = samostatná issue později).
  Do repozitáře jdou jen certifikáty z veřejného ZIP, ne obsah dokumentu
  „Přístupové a provozní informace — playground“ (označený TLP:AMBER).
- **U3** — tabulky `economy_sales_registration_registrations` a `…_units`;
  identifikátor poplatníka a platnost se berou z certifikátu.
- **U4** — Ověřit spojení: výchozí jednotka registrace, `id_pokl`
  `SHIPARD-TEST`, `porad_cis` `TEST-<čas>`, částka 0,00 Kč, vždy ověřovací mód.

## Před implementací přečti

- issue #171 (celé), `tasks/module-record-actions.md` a
  `tasks/module-server-config.md` (implementační poznámky)
- `docs/modules.md` kap. 5 a 9 (doplňky, `serverConfig`, `recordActions`,
  `settingsSections`, `settingsItems`, `alertChecks`), `docs/documentation.md`
  (README a `docs/` u modulu)
- `docs/table-definitions.md` (typy sloupců, `encrypted_text`, child
  tabulky, `exchangeKey`), `docs/operations/secrets.md` (`DsSecretCipher`)
- `docs/document-system.md`, `docs/edit-forms.md` (sub-tabulka,
  `subtableTab`, citlivé sloupce ve formuláři), `docs/frontend.md`
  (detail, typy obsahu, akce `record_action`)
- `docs/mail/outbound.md` § Pojistka odchozí pošty a `src/Core/Mail/MailSafetyConfig.php`
  — vzor pojistky; `src/Core/Config/ServerConfigSection.php`
- `docs/alerts.md`; vzor upozornění `modules/economy/exchangeRates`
  (`Checks\ListMissingCheck`)
- `modules/world/cz/module.jsonc`, `config/exchangeRateLists.jsonc` — vzor
  dat typu v `world.cz`
- `src/Core/RecordAction/`, fixtura `tests/Fixtures/modules/test/uiExtensions`
- `docs/help-authoring.md` (stránka nápovědy v kroku 6)
- `PermissionSpec` / `fix-permissions` (nový adresář zdroje dat v kroku 3)

## 1. Modul a typ evidence (D1, D2, D17)

- `modules/economy/salesRegistration/` s `"kind": "addon"`; závislosti
  minimální (fáze 2 přidá `docs.core`).
- `world.cz` → `config/salesRegistrationTypes.jsonc`, cfgItem
  `world.cz.salesRegistrationTypes`, typ `cz.eet2`: název (cs / en), země
  `cz`, `validFrom` 2027-01-01, měna `czk`, prostředí `production`
  a `playground` (adresy), masky z XSD: identifikátor poplatníka
  `^CZ[0-9]{8,10}$` (popisek „EIČ“), jednotka `^[1-9][0-9]{0,8}$`,
  pokladna a pořadové číslo `[0-9a-zA-Z.,:;/#\-_ ]{1,20}` / `{1,25}`.
- Doplněk → cfgItem `economy.salesRegistration.engines`
  `{"cz.eet2": "<FQCN Eet2Engine>"}`; registr typů (např.
  `SalesRegistrationTypes`) složí data typů ze všech aktivních
  `world.*.salesRegistrationTypes` a mapu enginů; typ bez enginu se
  v registraci nenabízí.
- README a specifikace u modulu (`modules/economy/salesRegistration/README.md`,
  `docs/sales-registration.md` — model a rozhodnutí #171, doplňuje se
  v dalších krocích); řádek v `docs/roadmap.md` oddíl Doplňky
  a v `docs/features.md` oddíl Doplňky.

Commit 1 (`feat(sales-registration): doplněk a typ evidence cz.eet2 (#171 D1, D2, D17)`).

## 2. Registrace a evidenční jednotky (D3, D10, D16, U3)

- `economy_sales_registration_registrations`: `type` (typy z registru),
  `name`, `taxpayer_id` (systémový — z certifikátu), `mode`
  (`production` / `verification` / `playground`, **výchozí `verification`**),
  `valid_from`, `valid_to`, `default_unit` (FK na jednotku této registrace),
  certifikát — `cert_pem` (text, systémový), `cert_key` (`encrypted_text`,
  citlivý), `cert_valid_from`, `cert_valid_to`, `cert_serial`,
  `cert_uploaded_at`, `cert_uploaded_by` (systémové), `notice`, stavy
  `core.system.docStatesArchive`. `exchangeKey` podle `docs/table-definitions.md`.
- `economy_sales_registration_units` (child): `registration`, `unit_id`
  (text — maska podle typu), `name`; unikátní (`registration`, `unit_id`).
- Document třídy: masky podle typu (identifikátor, jednotka), výchozí
  jednotka musí patřit registraci, unikátní (`type`, `taxpayer_id`) mezi
  nearchivovanými registracemi. Víc registrací různých typů smí existovat
  vedle sebe (D16).
- Nastavení → vlastní sekce „Evidence tržeb“ (`settingsSections`)
  s položkou Registrace. Formulář: Základní (typ, název, režim, platnost,
  výchozí jednotka, poznámka; identifikátor a údaje certifikátu jen ke
  čtení), sub-tabulka Evidenční jednotky.
- Detail: Přehled (identita, režim, certifikát — EIČ, platnost do, nahráno
  kdy a kým), **kam zprávy skutečně jdou** podle pojistky a režimu
  (`notice`, krok 4), evidenční jednotky.

Commit 2 (`feat(sales-registration): registrace a evidenční jednotky (#171 D3, D10, D16)`).

## 3. Certifikát (D11, U1)

- Služba importu: vstup cesta k `.p12` + heslo → klíč a certifikát v PEM.
  `openssl_pkcs12_read`; když selže, záložně `openssl pkcs12 -legacy -nodes
  -in <soubor> -passin stdin` přes `proc_open` — **heslo jen na stdin**, nikdy
  v argumentech, proměnných prostředí ani logu. Kontroly: klíč patří
  k certifikátu, CN odpovídá masce identifikátoru typu, certifikát platí.
  Rozliš chyby „špatné heslo“, „nejde o certifikát“, „vypršel“, „jiný
  poplatník“.
- Akce záznamu `uploadCertificate` (`admin: true`) nad registrací:
  dialog `file` (`accept` `.p12,.pfx`, `maxBytes` 64 KiB) + `password`
  (povinné) + `notice` (co se s certifikátem stane). Provedení: import →
  `cert_pem`, `cert_key` šifrovaně (`DsSecretCipher::forConfig`), metadata,
  `taxpayer_id` = CN; registrace s jiným vyplněným identifikátorem →
  chyba „certifikát patří jinému poplatníkovi“. Heslo se neukládá ani
  neloguje. Výsledek: „Certifikát nahrán — EIČ …, platnost do …“.
  Nahrání nového certifikátu přepíše starý (obnova po roce).
- **Dočasný soubor klíče (U1):** pomocník zapíše dešifrovaný klíč
  a certifikát do privátního adresáře zdroje dat (adresář `0700`, soubory
  `0600`, ne systémový `/tmp`; adresář přidej do kontraktu práv
  `PermissionSpec`), vrátí cesty a smaže je ve `finally` — i při výjimce
  (test).
- Testy: testovací `.p12` playgroundu (šifra RC2-40 → záložní cesta),
  moderní `.p12` vytvořený v testu (`openssl_pkcs12_export`), špatné heslo,
  cizí klíč, CN mimo masku, vypršelý certifikát.

Commit 3 (`feat(sales-registration): nahrání certifikátu a šifrované uložení klíče (#171 D11, U1)`).

## 4. Pojistka serveru a testovací identita (D9, D10, U2)

- `serverConfig` doplňku: klíč `salesRegistration`, třída (např.
  `SalesRegistrationServerConfig`) — `safety.mode` `off` / `playground` /
  `drop`. Výchozí stav: sekce chybí a server je `production` → `off`; sekce
  chybí a jiný režim serveru → `drop`; neplatná hodnota → `drop` s popisem
  problému. Nikdy nevyhazuje (vzor `MailSafetyConfig`).
- **Testovací identita (U2):** `modules/economy/salesRegistration/resources/playground/`
  — klíč a certifikát v PEM pro sdílené testovací EIČ z oficiálního ZIP
  `CAEET_Playground_2026_v1.zip` (stačí jedno EIČ, víc nevadí); `README.md`
  se zdrojem (adresa ZIP na eet.gov.cz), datem stažení, převodem
  (`openssl pkcs12 -legacy`), platností a postupem obnovy. Test platnosti:
  selže 30 dní před koncem platnosti certifikátu v repozitáři.
- **Kam zpráva jde** (jedna funkce, tabulka testem):

  | Pojistka | Režim registrace | Prostředí | Identita | `overeni` |
  |----------|------------------|-----------|----------|-----------|
  | `off` | `production` | produkce | certifikát registrace | false |
  | `off` | `verification` | produkce | certifikát registrace | true |
  | `off` | `playground` | playground | testovací | podle zprávy |
  | `playground` | jakýkoli | playground | testovací | podle režimu |
  | `drop` | jakýkoli | nic se neodešle | — | — |

  S testovací identitou jde ve zprávě **EIČ testovacího certifikátu**, ne
  identifikátor registrace (jinak varování 1).
- Detail registrace ukazuje výsledek té funkce (`notice`): prostředí,
  identita, a když `drop`, proč.

Commit 4 (`feat(sales-registration): pojistka serveru a testovací identita playgroundu (#171 D9, D10, U2)`).

## 5. Engine CZ a Ověřit spojení (D12, U4)

- `composer.json`: `"ondrejnov/eet": "dev-master#cd26c32"` (ověř, že
  composer pin přijme bez změny `minimum-stability`; jinak napiš proč a jak);
  `ext-curl` a `ext-dom` do `require`, pokud chybí.
- **Obecné rozhraní** (návrh) `SalesRegistrationEngine` + hodnotové
  objekty: zpráva (identifikátor poplatníka, jednotka, pokladna, pořadové
  číslo, čas tržby, částka jako desetinný řetězec, měna, první zaslání,
  ověřovací mód, doplňky typu), identita (cesty k dočasným souborům U1,
  identifikátor), prostředí (adresa), výsledek (`confirmed` / `verified` /
  `rejected` / `retryLater` / `unknown` / `dropped`, kód potvrzení,
  kód a text chyby, varování, `transactionId`, odeslané a přijaté tělo,
  doba, příznak testu).
- **`Eet2Engine`** (např. `src/Country/Cz/`): `Certificate::fromPem`
  z dočasných souborů, `Dispatcher` s verifierem z řetězu I.CA knihovny
  a `O = Generální finanční ředitelství` **pro obě prostředí** (produkce
  zatím neověřená — poznámka do docs), transport knihovny (2,5 s / 2 s).
  Převod: POK → `confirmed`; `check()` true → `verified`; `ServerException`
  s `isRetryable()` → `retryLater`, jinak `rejected`; `TransportException` →
  `unknown`; `ResponseVerificationException` → `unknown` s příznakem
  nedůvěryhodné odpovědi (POK se nepoužije); `ValidationException` →
  `rejected` bez odeslání.
- **Odesílač** (obecný): typ → engine, pojistka + režim → prostředí
  a identita (krok 4), dočasný soubor klíče (U1), volání enginu, výsledek;
  `drop` → `dropped` bez sítě. Log `ErrorLogger::info`: registrace, typ,
  prostředí, stav, kódy, `transactionId`, doba — **bez klíče, hesla
  a těl zpráv**.
- **Akce záznamu `verifyConnection`** nad registrací: dostupná, když má
  registrace výchozí jednotku a certifikát (nebo jde na testovací
  identitu); zpráva podle U4, vždy ověřovací mód; výsledek: prostředí,
  „spojení ověřeno“ / kód a text chyby, varování. Úroveň hlášky podle
  výsledku (`success` / `warning`).
- Testy: engine nad oficiálními vzorovými zprávami a skutečnými odpověďmi
  playgroundu (fixtury knihovny `tests/fixtures/`) přes falešný transport;
  převod výjimek; odesílač — tabulka z kroku 4, úklid dočasného souboru;
  **volitelný integrační test proti živému playgroundu** jen s proměnnou
  `SHIPARD_EET_PLAYGROUND=1` (vzor `EET_PLAYGROUND` v knihovně).

Commit 5 (`feat(sales-registration): engine EET 2.0 a Ověřit spojení (#171 D12, U4)`).

## 6. Upozornění, nápověda, dokumentace

- `alertChecks` doplňku (id malými písmeny jako `economy.exchangerates.*`):
  certifikát vyprší do 30 dnů (warning), vypršel (error), platná
  registrace bez certifikátu (warning).
- `help/` — stránka „Evidence tržeb — registrace a certifikát“ podle
  `docs/help-authoring.md` (front matter `modules: [economy.salesRegistration]`):
  zapnutí doplňku, DIS+ a evidenční jednotky, pokladní certifikát z CA EET,
  nahrání, režimy, Ověřit spojení; vyplnění dokladů přijde ve fázi 2–3.
- `modules/economy/salesRegistration/docs/sales-registration.md` — model,
  tabulka „kam zpráva jde“, zacházení s klíčem, kontrakt enginu,
  testovací identita a její obnova, neověřené věci (produkční verifier).
- `**Stav:**`, `python3 scripts/tasks-index.py`, rozcestník modulů
  (`php scripts/modules-index.php`).

Commit 6 (`docs(sales-registration): upozornění, nápověda a specifikace fáze 1 (#171)`).

## Hotovo když

- [x] `shpd-ds ds-module enable economy.salesRegistration` na volném zdroji
      dat projde; v Nastavení je sekce Evidence tržeb s registracemi
- [x] registrace s jednotkami jde založit; masky typu `cz.eet2` se hlídají;
      víc registrací různých typů nic neblokuje
- [x] nahrání testovacího `.p12` playgroundu (RC2-40) i moderního `.p12`
      projde; klíč je v DB šifrovaně, heslo nikde; `taxpayer_id` a platnost
      z certifikátu; chybové stavy mají srozumitelné hlášky
- [x] dočasný soubor klíče po odeslání neexistuje ani po výjimce
- [x] tabulka „kam zpráva jde“ pokrytá testem; na dev serveru bez sekce
      `salesRegistration` se nic neodešle (`drop`)
- [x] s `"safety": {"mode": "playground"}` na dev serveru vrátí Ověřit
      spojení úspěch z playgroundu (ověřeno ručně 2026-11-26, poznámky
      § Ruční proklik)
- [x] engine převádí výsledky a výjimky podle kroku 5 (testy nad fixturami)
- [x] test platnosti testovacích certifikátů v repozitáři
- [x] upozornění na certifikát, nápověda, README a specifikace u modulu,
      roadmapa a features
- [x] produkční adresa ověřená z dokumentu finanční správy (poznámka)
- [x] celá sada PHPUnit lokálně zelená

## Implementační poznámky

Hotovo 2026-11-26 v šesti commitech na `stable` (nepushnuto). Specifikace:
`modules/economy/salesRegistration/docs/sales-registration.md`.

### Odchylky od zadání a upřesnění

- **Composer pin (krok 5).** `"dev-master#cd26c32"` přes Packagist nejde:
  Packagist má pod `dev-master` stále commit `968f463` (únor 2026, EET 1 —
  `ext-soap`, `robrichards/wse-php`) a Composer u pinu commitu bere metadata
  z hlavy větve v repozitáři balíků, takže by instalace chtěla `ext-soap`.
  Řešení: `repositories` typu `vcs` na GitHub + plný hash
  `cd26c32b573b0560ab8fe35ad2f3b8ff8fe096f8`. `minimum-stability` se nemění
  (výslovné `dev-master` v kořenovém `require` si stabilitu pro balík povolí
  samo). Přibylo `ext-curl` a `ext-dom`; hlídá je `doctor`.
- **`Dispatcher::check()` polyká `ServerException`** — ověřovací mód engine
  vyhodnocuje z `getLastResponse()->errorCode`, ne z výjimky.
- **UUID zprávy dává odesílač**, ne knihovna (`SalesMessage::$uuid`):
  potvrzení se na něj váže a fáze 2 ho potřebuje do pokusu před odesláním.
- **`type` je `varchar`**, ne `enumString` (ten chce jeden cfgItem; typy se
  skládají z dat zemí × mapy enginů). Hlídá `RegistrationDocument`.
- **Skládání typů:** `ConfigRuntime` cfgItemy vyjmenovat neumí — registr jde
  od mapy enginů, země je první část id typu (`cz.eet2` → `world.cz…`).
- **Bez `exchangeKey`** (krok 2): přirozený klíč je dvojice (typ, poplatník),
  identifikátor je do nahrání certifikátu prázdný a registrace
  s certifikátem se mezi zdroji dat nepřenáší. Unikátnost dvojice mezi
  živými stavy hlídá dokument — unikátní index by blokoval i archiv.
- **Výchozí stav registrace je Koncept**; akce záznamu běží nad živými stavy
  (i V pořádku — obnova certifikátu bez Opravit). Zámek řádků jednotek se
  nestaví: sub-tabulka se zamyká s registrací V pořádku.
- **Smazání výchozí jednotky** registraci výchozí jednotku odebere
  (`UnitDocument::afterDelete`) — výjimka z `beforeDelete` by v endpointu
  sub-tabulky skončila chybou 500.
- **Privátní dočasný adresář** zdroje dat neexistoval: nová obecná položka
  `PermissionSpec` `{ds}/tmp/` (`0700`, obsah nejvýš `0600`).
- **Záložní `openssl`** má v doplňku vlastní `proc_open` — `CapturedProcess`
  neumí stdin.
- **Detail a pojistka:** viewer `ServerConfig` nedostává, pojistku si načte
  `SalesRegistrationServerConfig::forServer()` (fail-closed).
- **Důvod „jiný poplatník“** řeší akce proti `taxpayer_id` registrace, ne
  služba importu; navíc je rozlišené „klíč nepatří k certifikátu“,
  „ještě neplatí“ a „zastaralá šifra bez `openssl`“.
- **Fixtury enginu** jsou kopie `tests/fixtures/` knihovny v
  `tests/Fixtures/Module/Economy/SalesRegistration/eet/` — testy nezávisí na
  tom, jestli instalace balíku adresář `tests/` nese.
- **Specifikace** je jen u modulu (`modules/…/docs/`), ne v kořenovém `docs/`.
- **Hlavička `modules:`** na stránce `help/` zatím nic nedělá (#170 D7 není
  implementované) — stránka ji nese a říká to první odstavec.
- **Id upozornění** malými písmeny: `economy.salesregistration.cert_*`.

### Produkční adresa

`https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4` — ověřeno
v dokumentu „Přístupové a provozní informace — produkce“, verze 1.2
z 11. 11. 2026 (eet.gov.cz → Pro vývojáře → Dokumenty ke stažení). Týž
dokument uvádí pro podpis potvrzení vydavatele I.CA Public CA/RSA 06/2022
a subjekt `O = Generální finanční ředitelství` — shodně s tím, co engine
ověřuje. Proti živé produkci nic ověřené není (nejsou certifikáty).

### Stárnoucí artefakty

| Artefakt | Platí do | Test, který začne padat 30 dní předem |
|---|---|---|
| testovací certifikát playgroundu `CZ00000019` (`resources/playground/`) | 2027-05-14 | `PlaygroundIdentityTest::testCertificateIsValidForAtLeast30Days` |
| podpisový certifikát ve fixtuře skutečného potvrzení | 2027-06-08 | `Eet2EngineTest::testRealConfirmationPassesTheProductionVerifier` |

Obnova: README v `resources/playground/` a u fixtur.

### Ověření

- **PHPUnit:** celá sada zelená po každém commitu (naposledy 13 145 testů).
- **Volný testovací zdroj dat `bgse-r`:** `ds-module enable` prošel (2 tabulky),
  sekce Evidence tržeb je v navigaci Nastavení; přes API formuláře založena
  registrace, jednotky (maska, duplicita), výchozí jednotka, přechody stavu,
  smazání výchozí jednotky; viewer a detail vrací očekávaný obsah.
- **Nahrání certifikátu** proti skutečné databázi (akce spuštěná v procesu —
  endpoint akcí chce přihlášeného správce): testovací `.p12` playgroundu
  (RC2-40, záložní cesta) i moderní `.p12`, registrace v Konceptu i V pořádku;
  v databázi `cert_key` s prefixem `v1:`, heslo nikde, formulář ani obecné
  API klíč nevracejí; špatné heslo a jiný poplatník dávají správné hlášky.
- **Dev server bez sekce `salesRegistration`:** detail registrace ukazuje
  „Zprávy se neodesílají“ a Ověřit spojení vrací varování pojistky — nic
  neodešlo.
- **Playground naživo:** `SHIPARD_EET_PLAYGROUND=1 … --filter Eet2PlaygroundTest`
  prošel — ověřovací mód `verified` za ~40 ms bez varování, ostrá zpráva
  `confirmed` s kódem končícím `-ff` přes verifier, který engine používá
  naostro, a celá cesta odesílače s pojistkou `playground`. Akce Ověřit
  spojení nad registrací ve zdroji dat se zkušebním `server.json`
  (`"safety": {"mode": "playground"}` předaný kontextu) vrátila „Spojení
  ověřeno — playground, sdílená testovací identita CZ00000019“; dočasný
  adresář `tmp/` vznikl s `0700` a zůstal prázdný.
- **Upozornění:** `alerts-run` nad zdrojem dat založil
  `economy.salesregistration.cert_expiring` pro registraci s certifikátem
  končícím do 30 dnů.

### Ruční proklik

2026-11-26 na dev serveru s přihlášeným správcem, všechny čtyři body
prošly: bez sekce pojistky zpráva zahozená, se skutečným
`"salesRegistration": {"safety": {"mode": "playground"}}`
v `/etc/shipard/server.json` dialog Nahrát certifikát (první skutečné pole
`file` akce záznamu) a Ověřit spojení úspěšně (playground, 40 a 123 ms).
Log serveru: akce záznamu i odeslání zapsané s id, typem, stavem,
prostředím, kódy a dobou — bez klíče, hesla a těl zpráv.

### Navazuje

- Chybějící body rozšíření (specifikace § 8) — issue #178.
- Na `bgse-r` zůstala zkušební registrace s jednotkou; využije ji fáze 2
  (`tasks/sales-registration-phase2.md`).
