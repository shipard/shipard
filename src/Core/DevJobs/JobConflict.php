<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

/**
 * Na zdroji dat už běží jiná úloha (D58: nejvýš jedna úloha na zdroj dat).
 * API odpovídá 409 s odkazem na běžící úlohu.
 */
final class JobConflict extends \RuntimeException
{
    public function __construct(
        public readonly string $runningJobId,
        public readonly string $dataSourceId,
    ) {
        parent::__construct("Data source {$dataSourceId} already has a running job {$runningJobId}");
    }
}
