<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

/**
 * Už běží úloha, která novou vylučuje (D58: nejvýš jedna úloha na zdroj dat;
 * #129 U1: výlučná úloha `update` vylučuje všechny a naopak; D134: kolo
 * vylučuje jen druhé kolo). Nese id a zdroj dat **běžící** úlohy (prázdný
 * = úloha celého serveru). API odpovídá 409 s odkazem na ni.
 */
final class JobConflict extends \RuntimeException
{
    public function __construct(
        public readonly string $runningJobId,
        public readonly string $dataSourceId,
        public readonly ?string $runningType = null,
    ) {
        parent::__construct($dataSourceId === ''
            ? sprintf('A server-wide job %s (%s) is running — wait for it to finish', $runningJobId, $runningType ?? 'update')
            : "Data source {$dataSourceId} already has a running job {$runningJobId}");
    }
}
