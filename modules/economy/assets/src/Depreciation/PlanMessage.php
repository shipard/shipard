<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

/**
 * Hlášení plánu odpisů. Engine vrací jen kód, závažnost a parametry;
 * text skládá `PlanMessageTexts` z cfgItem `economy.assets.planMessages`.
 *
 * Chyba (`error`) znamená, že plán okruhu není spolehlivý — hromadné
 * odpisy kartu přeskočí. Varování (`warning`) jen upozorňuje.
 */
final readonly class PlanMessage
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    /** Potvrzený odpis se liší od spočteného. */
    public const MISMATCH = 'mismatch';
    /** Oprávky počátečního stavu neodpovídají pravidlům a počtu období. */
    public const OPENING_MISMATCH = 'openingMismatch';
    /** Před potvrzeným odpisem chybí odpis dřívějšího období. */
    public const MISSING_PERIOD = 'missingPeriod';
    /** Datum odpisu neleží v účetním roce konce období. */
    public const DATE_OUTSIDE_PERIOD = 'dateOutsidePeriod';
    /** Technické zhodnocení u metody, která ho odpisuje samostatně. */
    public const IMPROVEMENT_ON_SCHEDULE = 'improvementOnSchedule';
    /** Odpis není zaokrouhlený podle pravidel (mimo import). */
    public const NOT_WHOLE_UNITS = 'notWholeUnits';
    /** Pravidlo neplatí pro datum zařazení. */
    public const RULE_NOT_VALID = 'ruleNotValid';
    /** Kombinace metod na kartě nedává výpočet. */
    public const SETTINGS_INVALID = 'settingsInvalid';

    /** @param array<string, scalar|null> $params */
    public function __construct(
        public string $code,
        public string $severity,
        public array $params = [],
    ) {
    }

    /** @param array<string, scalar|null> $params */
    public static function error(string $code, array $params = []): self
    {
        return new self($code, self::SEVERITY_ERROR, $params);
    }

    /** @param array<string, scalar|null> $params */
    public static function warning(string $code, array $params = []): self
    {
        return new self($code, self::SEVERITY_WARNING, $params);
    }

    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }

    /** @return array{code: string, severity: string, params: array<string, scalar|null>} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'severity' => $this->severity, 'params' => $this->params];
    }
}
