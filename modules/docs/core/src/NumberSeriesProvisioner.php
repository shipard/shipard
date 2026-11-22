<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Idempotentní seed číselných řad — zajistí, že existuje alespoň jedna řada
 * pro každý typ dokladu z cfgItem docs.core.docTypes (kromě smazaných).
 *
 * Typy se `series_binding` přeskakuje — jejich řady vznikají per entitu
 * v BoundNumberSeriesProvisioner a nevázaná řada vázaného typu nesmí
 * existovat (NumberSeriesDocument by ji odmítl).
 *
 * Založené řady dostanou kód (`invno`, #166 D17) z NumberSeriesCodeBackfill.
 */
class NumberSeriesProvisioner
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
    ) {}

    /**
     * @return array{numberSeries: array{created: int, existing: int}}
     */
    public function provision(): array
    {
        $docTypes = $this->config->cfgItem('docs.core.docTypes');
        if (!is_array($docTypes)) {
            return ['numberSeries' => ['created' => 0, 'existing' => 0]];
        }

        $created = 0;
        $existing = 0;

        foreach ($docTypes as $docTypeKey => $docType) {
            if (!is_string($docTypeKey) || !is_array($docType)) {
                continue;
            }
            if (!empty($docType['series_binding'])) {
                continue;
            }

            $row = $this->db->fetchRow(
                'SELECT id FROM docs_core_number_series
                 WHERE doc_type = %s AND docState != %i
                 LIMIT 1',
                $docTypeKey,
                90,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $name    = (string) ($docType['name:cs'] ?? $docType['name'] ?? $docTypeKey);
            $pattern = (string) ($docType['doc_number_pattern_default'] ?? '%D%y%4');

            $this->db->insertRow('docs_core_number_series', [
                'doc_type'           => $docTypeKey,
                'name'               => $name,
                'doc_number_code'    => null,
                'doc_number_pattern' => $pattern,
                'reset_scope'        => 'fiscal_year',
                'docState'           => 40,
                'docStateMain'       => 3,
            ]);
            $created++;
        }

        // Řádky vznikají mimo Document — kód řady (#166 D17) doplní služba
        // hned po vložení: výchozí řada typu má kód typu dokladu.
        if ($created > 0) {
            (new NumberSeriesCodeBackfill($this->db->getDibiConnection()))->run();
        }

        return ['numberSeries' => ['created' => $created, 'existing' => $existing]];
    }
}
