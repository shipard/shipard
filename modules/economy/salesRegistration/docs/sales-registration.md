# Evidence tržeb — specifikace doplňku

Doplněk `economy.salesRegistration` (#171). Tento dokument popisuje, **jak je
to udělané**; návod pro uživatele je v `help/`, zadání v
`tasks/sales-registration-phase1.md` a `tasks/sales-registration-phase2.md`.

Kapitoly 2–9 popisují základ z fáze 1 (typ, registrace, certifikát,
pojistka, engine), kapitoly 10–16 tržby z dokladů z fáze 2 (data,
vyhodnocení dokladu, rozdílové zasílání, fronta, záchranná síť, agenda,
upozornění).

## 1. Rozsah

Evidence tržeb podle zákona účinného od 1. 1. 2027 (EET 2.0, datové rozhraní
v4.1). Zatím Česko; model je stavěný tak, aby unesl další státy a víc
registrací různých typů v jednom zdroji dat (#171 D16).

| Fáze | Obsah | Stav |
|---|---|---|
| 1 | typ evidence, registrace a evidenční jednotky, certifikát, pojistka serveru, engine, Ověřit spojení, upozornění na certifikát | hotovo 2026-11-26 (`tasks/sales-registration-phase1.md`) |
| 2 | tržby a pokusy, napojení na doklady, fronta s opakováním, záchranná síť, agenda Evidence tržeb, upozornění na tržby | hotovo 2026-11-26 (`tasks/sales-registration-phase2.md`) |
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
  `unitId`, `cashRegisterId` (s popisem masky `hint` pro hlášku ve
  formuláři řady), `serialNumber` a `amount`, časová zóna místa tržby
  `timeZone` a pravidla `rules`, kdy doklad do evidence spadá (§ 11.1);
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

## 3. Registrace a evidenční jednotky (D3, D10, D16, U3)

### 3.1 Registrace — `economy_sales_registration_registrations`

| Sloupec | Popis |
|---|---|
| `type` | id typu evidence z registru (`cz.eet2`). `varchar`, ne `enumString` — nabídka se skládá ze dvou cfgItemů; hodnotu hlídá `RegistrationDocument` |
| `name` | název registrace |
| `taxpayer_id` | obecný identifikátor poplatníka, význam podle typu (EET 2.0: EIČ). **Systémový** — plní ho jen nahrání certifikátu (CN certifikátu) |
| `mode` | režim `production` / `verification` / `playground` (cfgItem `economy.salesRegistration.modes`), **výchozí `verification`** — nová registrace nic neeviduje |
| `valid_from`, `valid_to` | platnost registrace |
| `default_unit` | výchozí evidenční jednotka — musí patřit této registraci |
| `cert_pem` | certifikát v PEM (veřejná část), systémový |
| `cert_key` | soukromý klíč v PEM, `encrypted_text` — šifrovaný klíčem zdroje dat (`DsSecretCipher`), systémový a citlivý; heslo k `.p12` se neukládá |
| `cert_valid_from`, `cert_valid_to`, `cert_serial`, `cert_uploaded_at`, `cert_uploaded_by` | údaje certifikátu a nahrání, systémové |
| `notice` | poznámka |

Stavy: `core.system.docStatesArchive`, nová registrace je **Koncept**.
Registrace V pořádku je jen ke čtení (úprava přes V opravě), jednotky se
s ní zamykají taky.

**Bez `exchangeKey`.** Přirozený klíč je dvojice (typ, poplatník), kterou
jedním sloupcem vyjádřit nejde, identifikátor je do nahrání certifikátu
prázdný a registrace s certifikátem se mezi zdroji dat nepřenáší.

**Pravidla `RegistrationDocument`** (uložení přichází i jako částečná data,
pravidla se počítají nad uloženým řádkem doplněným o příchozí hodnoty):

- typ je z registru; typ, který z registru zmizel (vypnutý modul země),
  úpravu ostatních polí neblokuje, nově ho zvolit nejde;
- po nahrání certifikátu typ nejde změnit — certifikát patří typu;
- identifikátor poplatníka odpovídá masce typu;
- výchozí jednotka patří této registraci;
- **mezi živými registracemi** (Koncept, V pořádku, V opravě) je dvojice
  (typ, poplatník) jen jednou. Archiv dvojici nést smí — proto to hlídá
  dokument, ne unikátní index. Registrace různých typů (česká EET a později
  evidence jiné země) žijí vedle sebe (D16).

### 3.2 Evidenční jednotky — `economy_sales_registration_units`

`registration`, `unit_id` (text — tvar říká maska typu, u EET 2.0 číslo
z DIS+ 1 až 999999999), `name`. Unikátní index (`registration`, `unit_id`).
Bez stavů; spravují se jen ze sub-tabulky ve formuláři registrace
(`hideFromNavigation`). `UnitDocument` hlídá masku podle typu nadřízené
registrace a duplicitu srozumitelnou hláškou. Smazání jednotky, která byla
výchozí jednotkou, registraci výchozí jednotku odebere.

### 3.3 UI

Vlastní sekce Nastavení **Evidence tržeb** (`settingsSections`) s položkou
Registrace EET. Formulář `RegistrationsForm`: typ, název, režim, platnost,
výchozí jednotka (až u uložené registrace — nabízí její jednotky), poznámka;
sekce Certifikát ukazuje údaje jen ke čtení (blok `properties`). Soukromý
klíč formulář ani viewer nenačítají — viewer čte vyjmenované sloupce,
`getEditableSensitiveColumns()` je prázdné.

## 4. Certifikát (D11, U1)

### 4.1 Nahrání

Akce záznamu **Nahrát certifikát** (`recordActions` → `uploadCertificate`,
`admin: true`) nad registrací v živém stavu. Dialog: soubor (`.p12` / `.pfx`,
nejvýš 64 KiB — `accept` hlídá jen prohlížeč, obsah ověřuje import), heslo
a upozornění, co se stane. Postup `UploadCertificateAction`:

1. `Pkcs12Importer::import()` — `openssl_pkcs12_read`. Selže-li jinak než na
   hesle (typicky zastaralá šifra RC2-40, kterou mají `.p12` playgroundu
   a kterou OpenSSL 3 bez legacy provideru odmítá), zkusí se
   `LegacyPkcs12Reader`: `openssl pkcs12 -legacy -nodes -clcerts -in <soubor>
   -passin stdin` přes `proc_open`. **Heslo jde jen na stdin** — nikdy do
   argumentů, proměnných prostředí ani logu; prostředí podprocesu je pevné,
   binárka se hledá přes `UpdateEnvironment::findTool()`.
2. Kontroly: klíč patří k certifikátu, CN odpovídá masce identifikátoru typu,
   certifikát právě platí.
3. Registrace s jiným vyplněným identifikátorem certifikát odmítne.
4. Zápis přes `TableGateway` (platí pravidla `RegistrationDocument`):
   `cert_pem`, `cert_key` = `DsSecretCipher::forConfig()->encrypt(klíč v PEM)`,
   `taxpayer_id` = CN, platnost, sériové číslo, kdy a kdo nahrál. Nový
   certifikát přepíše starý (obnova po roce).

Heslo se neukládá ani neloguje. Nešifrovaný klíč žije jen v paměti mezi
převodem a zašifrováním. Do logu jde jen registrace, typ, identifikátor
a konec platnosti.

Chyby rozlišuje `CertificateImportException::$kind` a akce je vrací jako
chybu pole (422): špatné heslo (pole hesla), soubor není certifikát, klíč
nepatří k certifikátu, nejde o certifikát daného typu (CN mimo masku),
vypršel / ještě neplatí, jiný poplatník; chybějící `openssl` u zastaralé
šifry je chyba formuláře.

Šifra produkčních `.p12` z CA EET zatím není ověřená — obě cesty fungují,
takže na ní nezáleží.

### 4.2 Klíč při odeslání

Knihovna EET čte klíč jen ze souboru. `KeyFiles::with()` proto dešifrovaný
klíč a certifikát zapíše do privátního adresáře zdroje dat `tmp/` (adresář
`0700`, soubory `0600` vytvořené pod umask `0077` — nikdy systémový `/tmp`),
zavolá předanou funkci s cestami a soubory smaže ve `finally`, i po výjimce.
Soubor žije jen po dobu načtení klíče. Po tvrdém pádu procesu by soubor
zůstal, proto každé použití nejdřív uklidí vlastní soubory starší 10 minut.

Adresář `tmp/` je v kontraktu práv (`PermissionSpec::getDataSourceEntries()`,
`docs/operations/permissions.md`); při založení pod rootem dostane vlastníka
zdroje dat. Adresář přístupný skupině nebo ostatním se nepoužije.

## 5. Pojistka serveru a kam zpráva jde (D9, D10, U2)

### 5.1 Pojistka

Sekce `salesRegistration` v `/etc/shipard/server.json` (deklaruje doplněk
polem `serverConfig`, čte `SalesRegistrationServerConfig`), klíč
`safety.mode`:

| Režim | Chování |
|---|---|
| `off` | rozhoduje režim registrace |
| `playground` | všechno jde na neprodukční prostředí se sdílenou testovací identitou — certifikát zdroje dat ven nejde nikdy |
| `drop` | nic se neodešle |

Výchozí stavy po vzoru `mail.safety`: sekce chybí a server je `production`
→ `off`; sekce chybí a jiný režim serveru → `drop`; chybná hodnota → `drop`
s popisem problému (`source: invalid`). Třída **nikdy nevyhazuje** —
nečitelný `server.json` je taky `drop`. Pojistka je věc serveru, zdroj dat ji
nepřepíše. Smysl: kopie ostrých dat na dev nebo testovacím serveru nesmí
poslat tržbu správci daně pod certifikátem zákazníka.

`doctor` ani `/_app/info` režim pojistky zatím neukazují — pro sekce modulů
tam není bod rozšíření (#171 D13, mimo fázi 1). Režim je vidět v detailu
každé registrace.

### 5.2 Kam zpráva jde

Jedna funkce, `MessageRouteResolver::resolve(pojistka, režim registrace,
zpráva žádá ověření)`:

| Pojistka | Režim registrace | Prostředí | Identita | Ověřovací mód |
|----------|------------------|-----------|----------|---------------|
| `off` | `production` | produkce | certifikát registrace | podle zprávy |
| `off` | `verification` | produkce | certifikát registrace | vždy |
| `off` | `playground` | playground | testovací | podle zprávy |
| `playground` | jakýkoli | playground | testovací | podle režimu |
| `drop` | jakýkoli | nic se neodešle | — | — |

Ověřovací mód má jedno pravidlo: zpráva ho žádá (Ověřit spojení), nebo je
registrace v ověřovacím režimu. Registrace s neznámým režimem se neposílá
(`dropped`). Výsledek `MessageRoute` nese u `dropped` důvod (`configured` /
`default` / `invalid` / `unknownMode`) a popis problému konfigurace.

`MessageRouteText::describe()` říká trasu slovy — hláška nad Přehledem
v detailu registrace (blok `notice`, u `drop` tón `warning`) a později
výsledek akcí. Viewer `ServerConfig` nedostává, pojistku si načte
`SalesRegistrationServerConfig::forServer()` sama.

### 5.3 Testovací identita

S testovací identitou jde ve zprávě **EIČ testovacího certifikátu**, ne
identifikátor registrace — jinak služba vrací varování 1 (EIČ zprávy ≠ EIČ
certifikátu). Klíč a certifikát `CZ00000019` jsou v
`resources/playground/` (`PlaygroundIdentity`); pocházejí z veřejného balíku
finanční správy pro vývojáře, proto smí být v repozitáři — zdroj, převod,
platnost (do 14. 5. 2027) a postup obnovy jsou v tamním README. Obsah
dokumentu „Přístupové a provozní informace — playground“ (TLP:AMBER) do
repozitáře nepatří.

`PlaygroundIdentityTest::testCertificateIsValidForAtLeast30Days` začne padat
30 dní před koncem platnosti certifikátu — signál k obnově. Tentýž test
hlídá, že v doplňku není žádný jiný soukromý klíč.

## 6. Engine a odeslání zprávy (D12)

### 6.1 Kontrakt engine

`SalesRegistrationEngine::send(SalesMessage, SenderIdentity,
EngineEnvironment): SendResult` — třída z mapy
`economy.salesRegistration.engines`, instance bez argumentů konstruktoru.

- Engine pošle **jednu zprávu** do daného prostředí pod danou identitou.
  **Nikdy nevyhazuje** — každé selhání je stav výsledku.
- Nerozhoduje, kam zpráva jde, ani pod jakou identitou; nic neukládá
  a neopakuje. Klíč čte ze souborů identity jen po dobu volání.
- Nepředpokládá online potvrzení každé tržby: `confirmationCode` je obecný
  kód potvrzení, pokud ho protokol vydává.

| Objekt | Obsah |
|---|---|
| `SalesMessage` | `uuid` (generuje volající — pokus jde zapsat před odesláním a potvrzení se váže na toto UUID), jednotka, pokladna, pořadové číslo, čas tržby, částka jako desetinný řetězec, měna, první zaslání, ověřovací mód, identifikátor poplatníka (doplní odesílač), `extras` typu |
| `SenderIdentity` | cesty k dočasným souborům klíče a certifikátu, identifikátor poplatníka, příznak testovací identity |
| `EngineEnvironment` | název prostředí a adresa služby z dat typu |
| `SendResult` | `status`, `sent` (odešlo něco po síti?), kód potvrzení, kód a text chyby, varování `[kód → text]`, `transactionId`, odeslané a přijaté tělo, doba v ms, příznak testu, `untrusted`, `problem` (místní důvod), trasa (prostředí, testovací identita, ověření) |

Stavy `SendStatus`: `confirmed` (jediný, který plní evidenční povinnost),
`verified`, `rejected`, `retryLater`, `unknown`, `dropped`.

### 6.2 `Eet2Engine` — typ `cz.eet2`

Tenká vrstva nad knihovnou `ondrejnov/eet` (pinovaný commit, viz README
modulu): `Certificate::fromPem()` z dočasných souborů, `Dispatcher`,
`CurlTransport` s limity knihovny 2,5 s na požadavek a 2 s na spojení
(HTTP/1.1, TLS ≥ 1.2, ověřený certifikát serveru).

| Knihovna | Stav |
|---|---|
| `send()` vrátí POK | `confirmed` |
| `check()` vrátí true (chyba s kódem 0) | `verified` |
| chyba služby −1 nebo 8 | `retryLater` |
| jiná chyba služby | `rejected` |
| `TransportException` (timeout, výpadek, SOAP fault, nečitelná odpověď) | `unknown` |
| `ResponseVerificationException` | `unknown` + `untrusted`, POK se nepřebírá |
| `ValidationException`, nečitelný certifikát, `extras` | `rejected`, nic neodešlo |

- `Dispatcher::check()` chybu služby polyká a vrací `false` — ověřovací mód
  se proto vyhodnocuje z `getLastResponse()`, ne z výjimky.
- Čas tržby jde v zóně `Europe/Prague`.
- `extras` (čerpání a zúčtování, pověřující poplatník — #171 D14) engine
  zatím neumí a zprávu s nimi **odmítne**, aby z daňové zprávy nic tiše
  nezmizelo.
- Chybové odpovědi služba nepodepisuje, ověřuje se jen potvrzení: podpis,
  řetěz k I.CA (`resources/ica-signing-ca.pem` knihovny), platnost
  certifikátu, `O = Generální finanční ředitelství` a shoda UUID se zprávou.
  CRL ani OCSP se nekontrolují.

### 6.3 Odesílač

`SalesRegistrationSender::send(řádek registrace, SalesMessage)`:

1. trasa — `MessageRouteResolver` (§5.2); `drop` = `dropped` bez sítě, klíč
   se ani nedešifruje;
2. typ registrace → engine; adresa prostředí z dat typu;
3. identita: certifikát registrace (klíč dešifrovaný `DsSecretCipher` až
   tady), nebo `PlaygroundIdentity` — s ní jde ve zprávě její identifikátor;
4. `KeyFiles::with()` — dočasné soubory jen po dobu volání enginu (§4.2);
5. `ErrorLogger::info('sales registration: message <stav>')` — registrace,
   typ, UUID, prostředí, stav, kódy, `transactionId`, doba. **Bez klíče,
   hesla a těl zpráv** (`SendResult::logContext()`).

Místní důvody, proč nic neodešlo (`SendResult::$problem`): `safety`, `type`,
`environment`, `noCertificate`, `key`, `noTestIdentity`, `message`,
`internal`. Odesílač nic neukládá a neopakuje — žurnál pokusů a opakování
staví fáze 2 nad vráceným výsledkem.

### 6.4 Ověřit spojení (U4)

Akce záznamu `verifyConnection` (`admin: true`), dostupná u živé registrace
s výchozí jednotkou a certifikátem — bez certifikátu jen když trasa vede na
testovací identitu. Po potvrzení pošle přes odesílač zkušební zprávu:
výchozí jednotka registrace, pokladna `SHIPARD-TEST`, pořadové číslo
`TEST-<RRRRMMDDhhmmss>`, částka 0,00, **vždy ověřovací mód** — nic se
neeviduje. Výsledek: „Spojení ověřeno — prostředí, identita“ (`success`),
jinak `warning` s kódem a textem chyby; varování služby (např. 1 = EIČ
zprávy ≠ EIČ certifikátu) úspěch mění na `warning`. Pod pojistkou `drop`
řekne, proč se nic neodešle.

### 6.5 Testy

- `Eet2EngineTest` — falešný transport nad oficiálními vzorovými zprávami
  a skutečnými odpověďmi playgroundu (`tests/Fixtures/…/eet/`, kopie fixtur
  knihovny); převod každého výsledku a výjimky.
- `SalesRegistrationSenderTest` — tabulka tras, klíč registrace nikdy na
  playground, úklid dočasného souboru i po výjimce, log bez tajemství.
- `Eet2PlaygroundTest` (suite Integration) — živý playground, jen
  s `SHIPARD_EET_PLAYGROUND=1`.

## 7. Upozornění (D13)

Tři kontroly `alertChecks` nad společným základem `CertificateCheck`
(id malými písmeny — pravidlo `AlertCheckDefinition`):

| Id | Závažnost | Kdy |
|---|---|---|
| `economy.salesregistration.cert_expiring` | warning | certifikát vyprší do 30 dnů (a ještě platí) |
| `economy.salesregistration.cert_expired` | error | certifikát vypršel |
| `economy.salesregistration.cert_missing` | warning | registrace nemá certifikát |

Hlídají se jen **platné registrace**: stav V pořádku a dnešek v rozmezí
`valid_from` – `valid_to`. Koncept, registrace v opravě, archiv a registrace
mimo platnost upozornění nenesou. Jeden nález na registraci
(`findingKey` = id, předmět = řádek registrace), nálezy „vyprší“ a „vypršel“
se nepřekrývají. Kontroly soukromý klíč nenačítají. Upozornění na tržby
ve frontě a na doklady, které nejde zaevidovat, jsou v § 16.

## 8. Chybějící body rozšíření

Co fáze 1 obešla a co patří do obecné změny jádra s vlastní issue
(#170 D6), ne do úpravy na míru doplňku:

- **`doctor` a `/_app/info` neznají sekce `server.json` z modulů** — režim
  pojistky evidence tržeb ukazuje jen detail registrace (#171 D13).
- **Viewer ani `detailExtensions` nedostávají `ServerConfig`** — pojistku si
  doplněk načítá sám (`SalesRegistrationServerConfig::forServer()`).
- **Stránky `help/` nejsou vázané na aktivní moduly** — hlavička
  `modules:` na stránce doplňku zatím nic nedělá (#170 D7), stránka se
  nabízí i zdroji dat bez doplňku.
- **Podproces se vstupem na stdin** — `CapturedProcess` stdin neumí, záložní
  čtení `.p12` má vlastní `proc_open`.
- **Doplněk se nezapíná z aplikace** — jen `shpd-ds ds-module enable`
  (stránka Doplňky v Nastavení je cílový stav #170).

Fáze 2 k tomu přidala:

- **Handler událostí dokladů nedostává `ServerConfig`** — pojistku si čte
  sám stejně jako viewer (`DocsHeadsSalesHandler::queue()`).
- **Handler `beforeSave` neumí chybu k poli** — kontrola kódu číselné řady
  (§ 10.3) i zákaz koše (§ 12.3) končí `\DomainException`, tedy hláškou
  nad formulářem (422 `DOMAIN_ERROR`), ne u pole.
- **`beforeDelete` se pro hlavičku dokladu z aplikace nevolá** — doklad se
  „maže“ přechodem do stavu Smazáno; doplněk proto hlídá přechod
  v `beforeSave` (§ 12.3).
- **Handler nemá „po commitu“ uvnitř cizí transakce** — z applieru běží
  `stateChanged` před commitem; doplněk to pozná (`NestedTransaction::isActive()`)
  a odeslání nechá cronu (§ 13.4). Obecný bod „po commitu vnější transakce“
  jádro nemá.

## 9. Co není ověřené

- **Produkce.** Knihovna i doplněk jsou ověřené jen proti playgroundu —
  k produkci zatím nejsou certifikáty. Adresa produkce a vydavatel
  podpisového certifikátu potvrzení (I.CA Public CA/RSA 06/2022, subjekt
  `O = Generální finanční ředitelství`) jsou z dokumentu „Přístupové
  a provozní informace — produkce“ (verze 1.2 z 11. 11. 2026) a odpovídají
  tomu, co engine ověřuje; první ostré potvrzení to musí potvrdit.
- **Šifra produkčních `.p12`** z CA EET — import má obě cesty (§4.1).
- **Fixtury stárnou**: testovací certifikát playgroundu do 14. 5. 2027,
  podpisový certifikát skutečného potvrzení do 8. 6. 2027; testy začnou
  padat 30 dní předem.

Z fáze 2:

- **Časová zóna serveru.** Čas přechodu dokladu (`doc_state_changed_at`),
  a tedy i čas tržby, se ukládá v zóně PHP (`date()`); aplikace zónu
  nenastavuje. Převod do zóny místa tržby je správný, dokud mají PHP-FPM
  a CLI (cron) stejnou zónu — na vývojovém serveru UTC u obou. Rozdílná
  zóna by posunula čas tržeb založených záchrannou sítí a rozhodila
  upozornění; `doctor` to nehlídá.
- **Odmítnutí tržby službou naostro.** Stav `failed` a text chyby jsou
  ověřené jen na připravených výsledcích enginu — playground při ověření
  žádnou z našich zpráv neodmítl.
- **Výpadek služby naostro.** Backoff, jistič a přeskočení registrace
  v běhu cronu jsou ověřené testy, ne skutečným výpadkem.
- **Kód detekce duplicit.** Metodika klíč duplicit nedefinuje; opakování
  po nejasné odpovědi (`unknown`) posílá stejná data s novým UUID
  a `prvni_zaslani = false`. Jak se k tomu služba staví, playground
  neukázal.
- **Zátěž záchranné sítě.** Průchod posledních 7 dní je levný díky
  předvýběru (§ 14), na zdroji dat s tisíci prodejek denně ale změřený
  není.
- **Doklady importované před fází 2** nemají příznak `imported` (sloupec
  vznikl až teď, výchozí 0). Záchranná síť je vidí jen v okně 7 dní od
  importu a D8d je vyřadí, pokud jsou vystavené před začátkem registrace —
  ostrá registrace dřív než 1. 1. 2027 začít nesmí.

## 10. Data tržeb (D4, D5, D21)

### 10.1 Tržba — `economy_sales_registration_sales`

Závazek poslat správci daně jednu zprávu s pevnými daty. Vzniká z přechodu
dokladu rozdílovým výpočtem (§ 12); doklad může mít víc tržeb.

| Skupina | Sloupce |
|---|---|
| pevná data | `doc_head`, `doc_sequence` (pořadí tržby dokladu 1, 2, …), `registration`, `unit`, `unit_id` (označení jednotky v době vzniku), `cash_register_id` (kód řady), `serial_number`, `sold_at`, `amount` (se znaménkem), `currency`, `created` |
| fronta | `state`, `attempt_count`, `next_attempt`, `claimed_at`, `confirmation_code`, `confirmed_at`, `environment`, `test`, `last_problem`, `last_error` |

Indexy: unikátní (`doc_head`, `doc_sequence`) — pojistka proti dvojímu
rozdílu (U7), (`state`, `next_attempt`) pro frontu, `registration`,
`sold_at`. Data tržby se po vzniku nemění, mění se jen stav fronty.
Tabulka je `systemManaged` a všechny sloupce systémové — generické CRUD
záznam nezaloží, nesmaže ani nepřepíše.

`sold_at` je ve stejné zóně jako `doc_state_changed_at` dokladu (zóna PHP);
do zóny místa tržby (`timeZone` typu, U10) ho převádí až sestavení zprávy.

### 10.2 Pokus — `economy_sales_registration_attempts`

Jedna datová zpráva: `sale`, `uuid`, `started_at`, `first_sending`,
`verification`, `environment`, `test_identity`, `status` (`sending` nebo
hodnota `SendStatus`), `sent`, `problem`, `error_code`, `error_text`,
`warnings` (JSON), `confirmation_code`, `transaction_id`, `request_body`,
`response_body` (`mediumtext`), `duration_ms`.

Řádek vzniká **před odesláním** (UUID, čas, stav `sending`) a po odpovědi
se doplní — pád procesu uprostřed tak zanechá stopu, že zpráva odejít
mohla. Opakování je nový řádek s novým UUID. `first_sending` je pravda,
dokud tržba nemá pokus se `sent = 1`; pokusy zastavené pojistkou nebo
problémem na naší straně se nepočítají. Těla zpráv zůstávají jen tady —
v detailu tržby ani v logu nejsou.

### 10.3 Evidenční jednotka na číselné řadě (D21)

Pokladní zařízení = číselná řada: `id_pokl` je kód řady
(`docs_core_number_series.code`). Jednotku řadě přidává extension doplňku —
sloupec `sales_registration_unit` (`extensions/docs_core_number_series.jsonc`,
nesystémový, aby ho směla editovat záložka z modulu).

- **Záložka** `Series\SeriesUnitFormTab` (`formTabs`) — select jednotek
  registrací V pořádku (`registrace — číslo název`); vybraná jednotka zůstává
  v nabídce i po archivaci registrace. Bez jediné jednotky se záložka
  nezobrazí. První skutečné `formTabs` ve stromu.
- **Kontrola** `Series\SeriesUnitHandler` (`beforeSave` na řadě) — při
  přiřazení jednotky i při změně kódu řady s jednotkou musí kód projít
  maskou `cashRegisterId` typu (EET 2.0: 20 znaků, omezená sada); řada
  s jednotkou musí mít kód. Chyba je `\DomainException` s popisem masky
  z dat typu. Řada, která se nemění, se znovu nekontroluje.

## 11. Vyhodnocení dokladu (D8a, D8d, D8e, D18, D19, D22, U9, U11)

`Evaluation\DocSaleEvaluator` — čistá služba bez sítě, databáze a zápisu.
Vstup `DocFacts` (hlavička, řádky, řada, země) a registrace V pořádku
(`RegistrationFacts`), výstup `DocEvaluation`: **nespadá** / **spadá**
(částka, registrace, jednotka, označení pokladny) / **spadá, ale nejde
založit** (důvod). Databázi čte jen `DocFactsLoader`. Stejné vyhodnocení
používá handler, záchranná síť i upozornění.

### 11.1 Pravidla typu (D23)

`rules` u typu jsou data země; první pravidlo, které se hlavičky týká,
rozhodne (`SaleRule`, `SalesRegistrationType::ruleFor()`):

| Klíč | Význam |
|---|---|
| `docTypes` | typy dokladů (povinné) |
| `paymentMethods` | způsoby úhrady; chybí = jakýkoli |
| `cashDir` | směr pokladního dokladu; chybí = jakýkoli |
| `rowOperations` | pravidlo nad řádky — do evidence jde jen součet řádků s těmito pohyby (`sale.*` = prefix); chybí = celá částka dokladu |

`cz.eet2`: prodejka a faktura vydaná hotově nebo kartou celou částkou;
pokladní doklad příjem řádky `sale.*`, `payment.receivable`,
`advance.received`. Platební bránou (5) do evidence nespadá (D15),
`transfer.in` a `acc.entry` taky ne. Vadné pravidlo vyřadí celý typ —
tiše vynechané pravidlo by znamenalo neodeslané tržby. Odkazy pravidel na
existující typy dokladů, způsoby úhrady a pohyby hlídá
`SalesRegistrationTypesDataTest`.

### 11.2 Pořadí rozhodování

1. doklad převzatý importem → nespadá nikdy: trvalý příznak hlavičky
   `imported` (nastavuje `DocDocument` při vzniku dokladu v importním
   módu), nebo `source_kind` `import.*` (D8e, U11). Doklad založený
   pokynem přes API je skutečná tržba;
2. jen stavy V pořádku a Storno; Storno = částka 0;
3. registrace podle **země registrace DPH** dokladu (`vat_registration` →
   `country`, bez ní domácí země zdroje dat) a **platnosti k času tržby**
   v zóně místa tržby (D22), jejíž typ má pro hlavičku pravidlo a která
   nezačala až po vystavení dokladu (D8d: `issue_date` < `valid_from`).
   Žádná = nespadá, víc = nejde založit;
4. částka (D18): pravidlo nad hlavičkou bere `total_amount_dom` (obsahuje
   zaokrouhlení, cizí měna jde kurzem dokladu); pravidlo nad řádky součet
   `vat_total_dom` vyhovujících řádků — a když vyhovují všechny řádky
   s nenulovou částkou, zase `total_amount_dom`, aby šla hotovost
   zaokrouhleně. Nulová částka = nespadá;
5. domácí měna dokladu = měna typu, jednotka z řady, jinak výchozí jednotka
   registrace (musí patřit té registraci), označení pokladny = kód řady —
   vše přes masky typu.

Pořadové číslo (D19) je `SerialNumber::of()`: první tržba dokladu nese číslo
dokladu, další příponu `-2`, `-3`, … Pořadové číslo a částku proti maskám
typu kontroluje až `saleProblem()` nad konkrétní tržbou — číslo závisí na
pořadí tržby a částka je rozdíl.

**Doklad s tržbou zůstává u její registrace**: další vyhodnocení bere jen
tu registraci a platnost k času tržby ani D8d znovu neposuzuje. Oprava
dokladu po skončení registrace by jinak poslala storno platné tržby.

Důvody „nejde založit“ (U9): `multipleRegistrations`, `registrationGone`,
`currency`, `noUnit`, `foreignUnit`, `unitId`, `cashRegisterId`,
`serialNumber`, `amount`.

Kontaktní platba (fáze 3, D8b) přidá další zdroj v kroku 3–4; místo je
označené v `DocSaleEvaluator::matchingRegistrations()`.

## 12. Tržby z dokladů — rozdílové zasílání (D6, D20, U7, U12)

### 12.1 Rozdíl

`Sales\SaleSync::sync(docId)`: částka podle vyhodnocení − součet **všech**
tržeb dokladu (bez ohledu na stav); nenulový rozdíl = nová tržba `pending`
s `sold_at` = `doc_state_changed_at` dokladu (D20) a pořadím `n` = poslední
+ 1. Storno dá zápornou tržbu `-2`, oprava rozdíl, oprava beze změny částky
nic; součet tržeb dokladu = částka, která do evidence spadá. Koncept
a V opravě se nevyhodnocují.

Vyrovnávací tržba dokladu, který do evidence už nespadá (storno, změna
způsobu úhrady), bere registraci, jednotku a označení pokladny z poslední
tržby — vznikne i po archivaci registrace.

### 12.2 Souběh (U7)

Výpočet běží pod pojmenovaným zámkem dokladu (`GET_LOCK`, klíč
`<databáze>:salesreg:doc:<id>`, 5 s) — funguje i uvnitř cizí transakce.
(doklad, pořadí) je unikátní; duplicita znamená, že tržbu vytvořil jiný
proces, a výpočet se zopakuje. Doklad bez tržeb, který nespadá, se vyřídí
bez zámku. Výsledek `SyncResult`: `none` / `created` / `wouldCreate`
(nanečisto) / `unregistrable` / `busy`.

### 12.3 Handler dokladů

`Sales\DocsHeadsSalesHandler` na `docs_core_heads`:

- `stateChanged` — nový stav 40 nebo 30 (i starý stav 0: „Vystavit
  a uzavřít“, pokyn přes API) spustí `sync()`. Výjimky loguje; chybějící
  tržbu dorovná záchranná síť.
- `beforeSave` — doklad s tržbou nejde přesunout do koše (stav 90):
  `\DomainException`, formulář dostane 422 s hláškou „stornujte ho“.
- `beforeDelete` — pojistka pro tvrdé smazání řádku.

## 13. Fronta a stavy (D5, D7, D24, U5, U6)

### 13.1 Stavy tržby

```
pending ──claim──▶ sending ──▶ confirmed   (potvrzeno; povinnost splněna)
   ▲                  │   ├──▶ verified    (ověřovací režim; konečný)
   │                  │   ├──▶ dropped     (pojistka serveru; konečný, bez upozornění)
   │                  │   └──▶ failed      (odmítnuto službou / neplatná zpráva)
   └── backoff ───────┘                       └─ Odeslat znovu → pending
```

`Queue\SaleOutcome` mapuje `SendResult` na stav. Odesílač vrací problémy
na naší straně stavem „odmítnuto“ s vyplněným `problem`, proto se
rozhoduje podle něj:

| Výsledek | Stav tržby |
|---|---|
| `confirmed` | `confirmed` + kód, čas, `test` (playground / testovací identita) |
| `verified` | `verified` |
| `dropped` | `dropped` |
| `rejected` bez `problem`, nebo `problem = message` | `failed` |
| `retryLater`, `unknown` | `pending` + backoff |
| `rejected` s jiným `problem` (certifikát, klíč, testovací identita, typ, prostředí, registrace není V pořádku, interní chyba) | `pending` + backoff — zpráva neodešla a problém odstraní nastavení |

Backoff 1 min → 5 min → 15 min → 1 h, dál po hodině bez konce (D7).

### 13.2 Jeden pokus

`Queue\SaleQueue::attempt()` je jediná cesta odeslání — handler, cron
i akce Odeslat znovu:

1. **zámek odesílatele** (D24): `UPDATE … SET state = 'sending' WHERE id = ?
   AND state = 'pending'`; kdo řádek nezmění, neposílá;
2. pokus zapsaný před odesláním (§ 10.2);
3. `SalesRegistrationSender::send()` se zprávou z dat tržby — nové UUID,
   původní `sold_at` převedený do zóny typu, `firstSending` podle pokusů;
4. výsledek do pokusu, stav tržby podle § 13.1, log (id, stavy, kódy, doby —
   bez těl zpráv a klíčů).

SQL je v `Queue\SaleStore`; čas dostává parametrem z PHP, žádné `NOW()`.
`sending` starší 10 minut znamená pád procesu — `recoverStale()` vrací
tržbu na `pending` bez zvýšení počtu pokusů.

### 13.3 Jistič (U6)

`circuitOpen(registrace)`: skončil poslední dokončený pokus registrace
v posledních 60 s výpadkem (`retryLater`, `unknown`)? Handler pak neposílá
hned a tržbu nechá cronu. Běh cronu po výpadku přeskočí zbylé tržby téže
registrace (`skipped`).

### 13.4 Kdy se posílá

- **Handler** hned po založení tržby — jen mimo transakci a při zavřeném
  jističi. Z applieru běží `stateChanged` před commitem a doklad se ještě
  může vrátit (`totals_check_failed`); tržba tam vznikne v téže transakci
  a odešle ji cron. Okamžité odeslání se tak týká dokladů z formuláře.
- **Cron** `sales-registration-run` (slot `minute`, `--limit`, výchozí 20):
  obnova zaseknutých + splatné `pending` od nejstaršího termínu.
- **Akce Odeslat znovu** (§ 15).

Handler ani příkaz `ServerConfig` nedostávají — pojistku čtou přes
`SalesRegistrationServerConfig::forServer()` (fail-closed).

## 14. Záchranná síť (D7, D20, U9)

`sales-registration-reconcile` (slot `five-minutes`, před `alerts-run`;
`--days`, výchozí 7; `--dry-run`) → `Sales\ReconcileScanner`: doklady ve
stavu 40 a 30 se změnou stavu v okně projde týmž `SaleSync::sync()`. Tržba
dostane čas přechodu dokladu, ne čas nálezu, a pošle ji nejbližší běh
fronty.

Předvýběr v SQL (index `idx_doc_state_changed`): bez importovaných, typ
dokladu z pravidel registrací V pořádku nebo doklad, který už tržbu má,
a **bez dokladů s tržbou založenou po posledním přechodu** — u těch je
rozdíl hotový. `--dry-run` vypíše, co by vzniklo.

## 15. Agenda a akce (D25, U12)

- **Viewer** `SalesViewer` (`economy.salesRegistration.sales`, sekce
  Prodej): řádek = pořadové číslo, částka, jednotka, stav, štítek testu,
  čas tržby a konec kódu potvrzení. Filtry stav (vč. „Nevyřízené“),
  registrace a jednotka (jen když je z čeho vybírat), období (kalendářní
  měsíc). Tržby nemají `docState` — stav fronty je sloupec `state`, popisky
  a styl z `economy.salesRegistration.saleStates`. Bez akce Přidat.
- **Detail**: hlavička pořadové číslo + částka, štítek stavu; Přehled (tržba,
  odeslání) a záložka Pokusy (čas, prostředí, výsledek, kódy a varování,
  `transactionId`, doba). Akce **Otevřít doklad** (`open_detail` na
  `docs.core.heads`). Těla zpráv viewer nečte.
- **Akce záznamu Odeslat znovu** (`Actions\ResendSaleAction`,
  `recordActions`, bez `admin`): tržbu `failed` nebo `pending` vrátí do
  fronty s termínem hned a zkusí ji odeslat. Pojistku bere z kontextu akce.

## 16. Upozornění na tržby (D13, D25, U9)

| Check | Závažnost | Kdy |
|---|---|---|
| `economy.salesregistration.sale_unconfirmed` | warning | tržba `pending` / `sending` / `failed` starší než 1 h (a mladší než 48 h) |
| `economy.salesregistration.sale_overdue` | error | táž tržba starší než 48 h |
| `economy.salesregistration.sale_failed` | error | tržba `failed` |
| `economy.salesregistration.doc_unregistrable` | error | doklad spadá, ale tržbu založit nejde (§ 11.2) |

Stáří se měří od času tržby. První tři dávají **jeden nález na registraci**
s počtem a nejstarší tržbou (`Checks\SaleCheck`) — výpadek služby nedá
stovky upozornění. `doc_unregistrable` je průchod záchranné sítě nanečisto,
nález na doklad, nejvýš 30. `dropped` a `verified` upozornění nedávají.
