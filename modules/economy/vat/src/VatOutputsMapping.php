<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Resolver mapovací konfigurace `economy.vat.reports.{country}`
 * (config/vat-reports-cz.jsonc): pro kód DPH vrátí jeho zařazení do
 * výstupů — řádek DPHDP3, sekci DPHKH1, kód plnění DPHSHV. Neznámý kód
 * je tvrdá chyba (mapování musí pokrývat celý číselník — hlídá
 * VatReportsMappingCompletenessTest), explicitní vyloučení je `null`.
 *
 * Sekce `reportTypes` nese národní pravidla per typ výstupu: zákonný
 * počátek (`validFrom`, #58 — před ním doklad do výstupu nespadá
 * a instance se negenerují), povolené druhy podání (`filingKinds`), režim
 * dodatečného podání (`supplementaryMode`), druhy vyžadující datum
 * zjištění (`dateFoundRequiredFor`) a jednotku zaokrouhlení podaných
 * hodnot (`roundingUnit`) — #55 D16/D17. Neznámý typ, neznámý druh podání
 * i špatný formát hodnoty jsou chyba konstruktoru (překlep v configu nesmí
 * projít tiše).
 */
final class VatOutputsMapping
{
    public const CFG_ITEM_CZ = 'economy.vat.reports.cz';

    private const REPORT_TYPES = ['return', 'cs', 'rs'];

    /** Druhy podání dle legislativy (#55 D16); názvosloví v config/filingKinds.jsonc. */
    public const FILING_KINDS = ['regular', 'corrective', 'supplementary', 'subsequent'];

    private const SUPPLEMENTARY_MODES = ['diff', 'full'];

    /** Bez `roundingUnit` v configu se podané hodnoty neposouvají. */
    private const DEFAULT_ROUNDING_UNIT = 0.01;

    /**
     * @var array<string, array{validFrom: ?string, filingKinds: list<string>,
     *      supplementaryMode: string, dateFoundRequiredFor: list<string>,
     *      roundingUnit: float, accounting: ?array{payableDueDays: int, refundDueDays: int,
     *      specificSymbolPrefix: string, constantSymbol: string, excludeAccounts: list<string>}}>
     */
    private readonly array $reportTypes;

    /** @param array<string, mixed> $config Dekódovaný cfgItem (`vatOutputs` + `dp3Rows` + `reportTypes`). */
    public function __construct(private readonly array $config)
    {
        if (!isset($config['vatOutputs']) || !is_array($config['vatOutputs'])) {
            throw new \InvalidArgumentException("VAT outputs mapping: missing 'vatOutputs' section");
        }
        $this->reportTypes = self::parseReportTypes($config['reportTypes'] ?? []);
    }

    /**
     * Mapping z kompilovaného configu DS; null, chybí-li cfgItem (config
     * nezkompilovaný) — volající pak pracuje bez mapování jako dnes.
     */
    public static function fromConfig(?ConfigRuntime $config, string $cfgItem = self::CFG_ITEM_CZ): ?self
    {
        $cfg = $config?->cfgItem($cfgItem);
        return is_array($cfg) ? new self($cfg) : null;
    }

    /** Zákonný počátek typu výstupu (ISO datum) nebo null bez omezení. */
    public function validFrom(string $type): ?string
    {
        return $this->reportTypes[$type]['validFrom'] ?? null;
    }

    /** @return array<string, string> jen typy s omezením, typ → ISO datum */
    public function validFromByType(): array
    {
        $out = [];
        foreach ($this->reportTypes as $type => $entry) {
            if ($entry['validFrom'] !== null) {
                $out[$type] = $entry['validFrom'];
            }
        }
        return $out;
    }

    /**
     * Druhy podání povolené u typu výstupu (#55 D16). Prázdné pole = typ
     * v configu druhy nedeklaruje, podání se pro něj nesestavuje.
     *
     * @return list<string>
     */
    public function filingKinds(string $type): array
    {
        return $this->reportTypes[$type]['filingKinds'] ?? [];
    }

    /**
     * Režim dodatečného podání: `diff` = rozdíly proti předchozímu podání
     * (DP3, ř. 66), `full` = plný obsah znovu (default).
     */
    public function supplementaryMode(string $type): string
    {
        return $this->reportTypes[$type]['supplementaryMode'] ?? 'full';
    }

    /**
     * Druhy podání, u kterých je povinné datum zjištění důvodů.
     *
     * @return list<string>
     */
    public function dateFoundRequiredFor(string $type): array
    {
        return $this->reportTypes[$type]['dateFoundRequiredFor'] ?? [];
    }

    /**
     * Jednotka podaných hodnot: 1 = celé Kč, 0.01 = beze změny (#55 D17).
     */
    public function roundingUnit(string $type): float
    {
        return $this->reportTypes[$type]['roundingUnit'] ?? self::DEFAULT_ROUNDING_UNIT;
    }

    /**
     * Parametry zaúčtování přiznání (#55 D29): splatnosti od konce období,
     * symboly saldo řádku a saldo účty mimo vypořádání analytik 343.
     * Null = typ se neúčtuje (KH, SH) nebo config blok nemá.
     *
     * @return ?array{payableDueDays: int, refundDueDays: int, specificSymbolPrefix: string,
     *         constantSymbol: string, excludeAccounts: list<string>}
     */
    public function accounting(string $type): ?array
    {
        return $this->reportTypes[$type]['accounting'] ?? null;
    }

    /**
     * @param mixed $section
     * @return array<string, array{validFrom: ?string, filingKinds: list<string>,
     *         supplementaryMode: string, dateFoundRequiredFor: list<string>,
     *         roundingUnit: float}>
     */
    private static function parseReportTypes(mixed $section): array
    {
        if (!is_array($section)) {
            throw new \InvalidArgumentException("VAT outputs mapping: 'reportTypes' must be an object");
        }
        $out = [];
        foreach ($section as $type => $entry) {
            if (!in_array($type, self::REPORT_TYPES, true)) {
                throw new \InvalidArgumentException("VAT outputs mapping: unknown report type '{$type}' in 'reportTypes'");
            }
            if (!is_array($entry)) {
                throw new \InvalidArgumentException("VAT outputs mapping: reportTypes.{$type} must be an object");
            }

            $filingKinds = self::parseFilingKinds($type, 'filingKinds', $entry['filingKinds'] ?? []);
            // Podmnožina povolených druhů — překlep („subsequnt", druh, který
            // typ vůbec nezná) by jinak tiše znamenal „datum nikdy nepovinné".
            $dateFound = self::parseFilingKinds($type, 'dateFoundRequiredFor', $entry['dateFoundRequiredFor'] ?? []);
            foreach ($dateFound as $kind) {
                if (!in_array($kind, $filingKinds, true)) {
                    throw new \InvalidArgumentException(
                        "VAT outputs mapping: reportTypes.{$type}.dateFoundRequiredFor contains"
                        . " '{$kind}', which is not among filingKinds",
                    );
                }
            }

            $out[$type] = [
                'validFrom'            => self::parseValidFrom($type, $entry),
                'filingKinds'          => $filingKinds,
                'supplementaryMode'    => self::parseSupplementaryMode($type, $entry),
                'dateFoundRequiredFor' => $dateFound,
                'roundingUnit'         => self::parseRoundingUnit($type, $entry),
                'accounting'           => self::parseAccounting($type, $entry),
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $entry */
    private static function parseValidFrom(string $type, array $entry): ?string
    {
        if (!array_key_exists('validFrom', $entry)) {
            return null;
        }
        $validFrom = $entry['validFrom'];
        if (!is_string($validFrom) || !self::isIsoDate($validFrom)) {
            throw new \InvalidArgumentException(
                "VAT outputs mapping: reportTypes.{$type}.validFrom must be an ISO date (YYYY-MM-DD)",
            );
        }
        return $validFrom;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function parseFilingKinds(string $type, string $key, mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException(
                "VAT outputs mapping: reportTypes.{$type}.{$key} must be an array of filing kinds",
            );
        }
        foreach ($value as $kind) {
            if (!is_string($kind) || !in_array($kind, self::FILING_KINDS, true)) {
                $shown = is_string($kind) ? $kind : get_debug_type($kind);
                throw new \InvalidArgumentException(
                    "VAT outputs mapping: unknown filing kind '{$shown}' in reportTypes.{$type}.{$key}",
                );
            }
        }
        return array_values($value);
    }

    /** @param array<string, mixed> $entry */
    private static function parseSupplementaryMode(string $type, array $entry): string
    {
        $mode = $entry['supplementaryMode'] ?? 'full';
        if (!is_string($mode) || !in_array($mode, self::SUPPLEMENTARY_MODES, true)) {
            throw new \InvalidArgumentException(
                "VAT outputs mapping: reportTypes.{$type}.supplementaryMode must be 'diff' or 'full'",
            );
        }
        return $mode;
    }

    /** @param array<string, mixed> $entry */
    private static function parseRoundingUnit(string $type, array $entry): float
    {
        if (!array_key_exists('roundingUnit', $entry)) {
            return self::DEFAULT_ROUNDING_UNIT;
        }
        $unit = $entry['roundingUnit'];
        if (!is_int($unit) && !is_float($unit)) {
            throw new \InvalidArgumentException(
                "VAT outputs mapping: reportTypes.{$type}.roundingUnit must be a number",
            );
        }
        $unit = (float) $unit;
        if ($unit <= 0.0) {
            throw new \InvalidArgumentException(
                "VAT outputs mapping: reportTypes.{$type}.roundingUnit must be greater than zero",
            );
        }
        return $unit;
    }

    /**
     * @param array<string, mixed> $entry
     * @return ?array{payableDueDays: int, refundDueDays: int, specificSymbolPrefix: string,
     *         constantSymbol: string, excludeAccounts: list<string>}
     */
    private static function parseAccounting(string $type, array $entry): ?array
    {
        if (!array_key_exists('accounting', $entry)) {
            return null;
        }
        $acc = $entry['accounting'];
        if (!is_array($acc)) {
            throw new \InvalidArgumentException("VAT outputs mapping: reportTypes.{$type}.accounting must be an object");
        }
        $days = [];
        foreach (['payableDueDays', 'refundDueDays'] as $key) {
            $value = $acc[$key] ?? null;
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException(
                    "VAT outputs mapping: reportTypes.{$type}.accounting.{$key} must be a non-negative integer",
                );
            }
            $days[$key] = $value;
        }
        $symbols = [];
        foreach (['specificSymbolPrefix' => 10, 'constantSymbol' => 10] as $key => $maxLength) {
            $value = $acc[$key] ?? '';
            if (!is_string($value) || preg_match('/^\d{0,' . $maxLength . '}$/', $value) !== 1) {
                throw new \InvalidArgumentException(
                    "VAT outputs mapping: reportTypes.{$type}.accounting.{$key} must be a string of at most"
                    . " {$maxLength} digits",
                );
            }
            $symbols[$key] = $value;
        }
        $exclude = $acc['excludeAccounts'] ?? [];
        if (!is_array($exclude) || !array_is_list($exclude)) {
            throw new \InvalidArgumentException(
                "VAT outputs mapping: reportTypes.{$type}.accounting.excludeAccounts must be an array of account numbers",
            );
        }
        foreach ($exclude as $number) {
            if (!is_string($number) || $number === '') {
                throw new \InvalidArgumentException(
                    "VAT outputs mapping: reportTypes.{$type}.accounting.excludeAccounts must contain account numbers as strings",
                );
            }
        }
        return [
            'payableDueDays'       => $days['payableDueDays'],
            'refundDueDays'        => $days['refundDueDays'],
            'specificSymbolPrefix' => $symbols['specificSymbolPrefix'],
            'constantSymbol'       => $symbols['constantSymbol'],
            'excludeAccounts'      => array_values($exclude),
        ];
    }

    private static function isIsoDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @return array{dp3: ?array<string, mixed>, kh: ?array<string, mixed>, sh: ?array<string, mixed>} */
    public function forCode(string $vatCode): array
    {
        $record = $this->config['vatOutputs'][$vatCode] ?? null;
        if (!is_array($record)) {
            throw new \DomainException(
                "Kód DPH '{$vatCode}' nemá záznam v mapování výstupů (economy.vat.reports)",
            );
        }
        return $record;
    }

    /** @return ?array{row: int, col?: string} */
    public function dp3(string $vatCode): ?array
    {
        return $this->forCode($vatCode)['dp3'];
    }

    /** @return ?array{group: string} Skupina KH; předmět plnění A.1 / B.1 nese rekapitulace dokladu (#89 D13). */
    public function kh(string $vatCode): ?array
    {
        return $this->forCode($vatCode)['kh'];
    }

    /** @return ?array{kod: int} */
    public function sh(string $vatCode): ?array
    {
        return $this->forCode($vatCode)['sh'];
    }

    /** Popisek řádku DPHDP3 (kompilovaný config je už lokalizovaný). */
    public function dp3RowLabel(int $row): ?string
    {
        $entry = $this->config['dp3Rows'][(string) $row] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $label = (string) ($entry['label'] ?? '');
        return $label !== '' ? $label : null;
    }
}
