<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Resolver mapovací konfigurace XML pro EPO (`economy.vat.xml.cz`,
 * `config/vat-xml-cz.jsonc`) — **jedna instance = jedna písemnost**
 * (přiznání / kontrolní hlášení / souhrnné hlášení).
 *
 * Writery se ptají jen tady: jaká věta, jaký atribut, kolik desetinných
 * míst, jaký kód formy. Číslo řádku ani jméno atributu v PHP nikde není
 * (rozhodnutí X4) — nové vydání formuláře je změna configu.
 *
 * Chybějící sekce nebo neznámý typ tvrzení je **výjimka**: generovat
 * podání z půlky mapování by znamenalo tiše vynechat řádky.
 *
 * Vedle mapování nese i **číselník Země daňového portálu**
 * (`world.cz.epoCountries`): hlavička drží ISO kód státu, formulář chce
 * název (`naz_zeme_c25`), a překlad je součást téže konfigurace písemnosti
 * — writer se ptá jen tady (#55 F3-3).
 */
final class VatXmlMapping
{
    public const CFG_ITEM_CZ = 'economy.vat.xml.cz';

    /** Číselník Země daňového portálu: ISO kód → `{name, epoName}`. */
    public const CFG_ITEM_COUNTRIES = 'world.cz.epoCountries';

    /** Typ tvrzení (`economy.vat.reportTypes`) → sekce configu. */
    public const DOCUMENT_BY_REPORT_TYPE = ['return' => 'dp3', 'cs' => 'kh1', 'rs' => 'shv'];

    /**
     * @param array<string, mixed> $document  sekce configu pro tuto písemnost
     * @param array<string, mixed> $countries číselník Země (`world.cz.epoCountries`)
     */
    private function __construct(
        public readonly string $reportType,
        private readonly array $document,
        private readonly array $countries,
    ) {}

    /**
     * Mapování pro typ tvrzení; `null` když cfgItem chybí (nezkompilovaná
     * konfigurace) — volající degraduje stejně jako u živých reportů.
     */
    public static function forReportType(
        ?ConfigRuntime $config,
        string $reportType,
        string $cfgItem = self::CFG_ITEM_CZ,
        string $countriesCfgItem = self::CFG_ITEM_COUNTRIES,
    ): ?self {
        $cfg = $config?->cfgItem($cfgItem);
        if (!is_array($cfg)) {
            return null;
        }
        // Chybějící číselník není důvod nevrátit mapování — projeví se až
        // u pole se státem, a to jako srozumitelná chyba validace.
        $countries = $config?->cfgItem($countriesCfgItem);
        return self::fromArray($cfg, $reportType, is_array($countries) ? $countries : []);
    }

    /**
     * @param array<string, mixed> $cfg       celý dekódovaný cfgItem mapování
     * @param array<string, mixed> $countries číselník Země (`world.cz.epoCountries`)
     */
    public static function fromArray(array $cfg, string $reportType, array $countries = []): self
    {
        $key = self::DOCUMENT_BY_REPORT_TYPE[$reportType] ?? null;
        if ($key === null) {
            throw new \DomainException("XML mapování: neznámý typ tvrzení '{$reportType}'");
        }
        if (!isset($cfg[$key]) || !is_array($cfg[$key])) {
            throw new \DomainException("XML mapování: chybí sekce '{$key}'");
        }
        return new self($reportType, $cfg[$key], $countries);
    }

    public function element(): string
    {
        return (string) $this->document['element'];
    }

    public function verzePis(): string
    {
        return (string) $this->document['verzePis'];
    }

    /** @return array<string, string> atributy věty D s pevnou hodnotou */
    public function constants(): array
    {
        return $this->document['constants'] ?? [];
    }

    public function formaAttribute(): string
    {
        return (string) $this->document['formaAttr'];
    }

    /**
     * Kód formy podání. `<druh>@<druh předchozího>` má přednost před holým
     * druhem — tak vzniká dodatečné/opravné (DP3 „E") a následné/opravné
     * (KH „E"). Neznámý druh je výjimka: forma je povinný atribut a tichá
     * prázdná hodnota by shodila až XSD validace.
     */
    public function forma(string $kind, ?string $previousKind): string
    {
        $forma = $this->document['forma'] ?? [];
        if ($previousKind !== null && isset($forma[$kind . '@' . $previousKind])) {
            return (string) $forma[$kind . '@' . $previousKind];
        }
        if (!isset($forma[$kind])) {
            throw new \DomainException(
                "XML mapování ({$this->reportType}): druh podání '{$kind}' nemá kód formy",
            );
        }
        return (string) $forma[$kind];
    }

    /**
     * Desetinná místa hodnot písemnosti (0 = celé Kč, 2 = haléře).
     *
     * Chybějící hodnota je **výjimka**, ne default: tiše zvolený počet
     * míst by vyrobil podání s haléři tam, kde úřad čeká koruny — a XSD
     * to nechytí (`fractionDigits` nepovinné atributy nekontroluje, když
     * hodnota sedí do rozsahu). Typicky znamená nezkompilovanou
     * konfiguraci po změně configu.
     */
    public function valueScale(): int
    {
        if (!isset($this->document['valueScale'])) {
            throw new \DomainException(
                "XML mapování ({$this->reportType}): chybí `valueScale` — spusťte ds-upgrade",
            );
        }
        return (int) $this->document['valueScale'];
    }

    /** Desetinná místa koeficientu v procentech. */
    public function percentScale(): int
    {
        return (int) ($this->document['percentScale'] ?? 2);
    }

    /** @return array<string, array<string, mixed>> číslo řádku → mapa slotů */
    public function rows(): array
    {
        return $this->document['rows'] ?? [];
    }

    /** @return list<int> řádky, které se vypisují i s nulou */
    public function alwaysEmit(): array
    {
        return array_map(intval(...), $this->document['alwaysEmit'] ?? []);
    }

    /** @return ?array<string, mixed> textová příloha (věta R přiznání) */
    public function note(): ?array
    {
        return $this->document['note'] ?? null;
    }

    /** @return array<string, array<string, mixed>> sekce KH → mapa atributů */
    public function sections(): array
    {
        return $this->document['sections'] ?? [];
    }

    /** @return ?array<string, mixed> kontrolní věta C hlášení */
    public function vetaC(): ?array
    {
        return $this->document['vetaC'] ?? null;
    }

    /** @return ?array<string, mixed> řádek souhrnného hlášení */
    public function row(): ?array
    {
        return $this->document['row'] ?? null;
    }

    /** @return array<string, mixed> rozdělení polí hlavičky mezi věty D a P */
    public function header(): array
    {
        return $this->document['header'] ?? [];
    }

    /** @return list<string> pole hlavičky s ISO kódem státu, vypisovaná jako název */
    public function countryNameFields(): array
    {
        return $this->document['header']['countryNameFields'] ?? [];
    }

    /**
     * Název státu pro EPO (`naz_zeme_c25`) podle ISO kódu; `null`, když kód
     * číselník nezná — nebo když cfgItem není zkompilovaný (ds-upgrade).
     * Kód se bere bez ohledu na velikost písmen (profil ukládá `cz`,
     * starší data `CZ`).
     */
    public function countryName(mixed $code): ?string
    {
        $key = strtolower(trim((string) ($code ?? '')));
        if ($key === '') {
            return null;
        }
        $name = $this->countries[$key]['epoName'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * ISO kód státu podle názvu pro EPO — reverz `countryName()` pro import
     * hlavičky z podaného XML (#55 D34). Porovnává se bez ohledu na
     * velikost písmen; neznámý název vrátí `null` (volající nechá hodnotu
     * z profilu).
     */
    public function countryCode(mixed $epoName): ?string
    {
        $needle = mb_strtoupper(trim((string) ($epoName ?? '')));
        if ($needle === '') {
            return null;
        }
        foreach ($this->countries as $code => $entry) {
            $name = is_array($entry) ? ($entry['epoName'] ?? null) : null;
            if (is_string($name) && mb_strtoupper($name) === $needle) {
                return strtolower((string) $code);
            }
        }
        return null;
    }
}
