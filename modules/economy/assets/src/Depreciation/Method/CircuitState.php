<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation\Method;

/**
 * Průběžný stav jednoho okruhu při průchodu událostmi. Společná část —
 * počitadla let a rozpisy si drží jednotlivé metody.
 *
 * @internal
 */
final class CircuitState
{
    public bool $started = false;
    /** Start okruhu počátečním stavem (D16), ne zařazením. */
    public bool $opening = false;
    /** Datum zařazení nebo počátečního stavu. */
    public string $startDate = '';
    /** Datum prvního zařazení — volí sazby pravidel (D43). */
    public string $acquiredDate = '';

    public float $entryPrice = 0.0;
    public float $accumulated = 0.0;
    public float $residual = 0.0;

    public ?int $disposalMonth = null;
    public ?string $disposalDate = null;
    public bool $disposalHalfYear = false;

    /** Poslední měsíc pokrytý odpisem nebo přerušením. */
    public ?int $lastCoveredMonth = null;
    /** V plánu už je nepotvrzený řádek. */
    public bool $hasPlanned = false;
}
